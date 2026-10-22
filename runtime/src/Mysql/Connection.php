<?php

declare(strict_types=1);

namespace QHTurbo\Runtime\Mysql;

use function QHTurbo\Runtime\Async\await;
use function QHTurbo\Runtime\Async\timeout;

/**
 * One MySQL/MariaDB connection running queries asynchronously (mysqlnd
 * MYSQLI_ASYNC). Parameters use "?" placeholders and are escaped with the
 * connection charset, like PDO's default emulated prepares for MySQL.
 */
final class Connection
{
    private int $txDepth = 0;
    public bool $broken = false;

    private function __construct(private readonly \mysqli $link, private readonly float $queryTimeout)
    {
    }

    public static function open(Config $c): self
    {
        $link = mysqli_init();
        if ($link === false) {
            throw new QueryException('mysqli_init failed');
        }
        $link->options(MYSQLI_OPT_CONNECT_TIMEOUT, (int) ceil($c->connectTimeout));
        $link->options(MYSQLI_OPT_INT_AND_FLOAT_NATIVE, 1);
        try {
            // Connecting is blocking (mysqlnd has no async connect); pooled
            // connections make this a one-off cost per worker.
            $ok = @$link->real_connect($c->host, $c->user, $c->password, $c->database, $c->port, $c->socket, $c->flags);
        } catch (\mysqli_sql_exception $e) {
            throw new QueryException('Connect failed: ' . $e->getMessage(), $e->getCode(), null, $e);
        }
        if (!$ok) {
            throw new QueryException('Connect failed: ' . mysqli_connect_error(), mysqli_connect_errno());
        }
        if (!$link->set_charset($c->charset)) {
            throw new QueryException("Unknown charset {$c->charset}");
        }
        return new self($link, $c->queryTimeout);
    }

    /** @param array<int, mixed> $params */
    public function query(string $sql, array $params = []): Result
    {
        $sql = $params ? $this->interpolate($sql, $params) : $sql;
        try {
            // Sending: an error here means the server never got the query.
            try {
                $sent = $this->link->query($sql, MYSQLI_ASYNC);
            } catch (\mysqli_sql_exception $e) {
                throw new QueryException($e->getMessage(), $e->getCode(), $sql, $e, sent: false);
            }
            if ($sent === false) {
                throw new QueryException($this->link->error, $this->link->errno, $sql, null, sent: false);
            }
            $result = await(timeout(Poller::wait($this->link), $this->queryTimeout));
        } catch (QueryException $e) {
            if ($e->isConnectionError()) {
                $this->broken = true;
            }
            throw new QueryException($e->getMessage(), $e->getCode(), $sql, $e->getPrevious(), $e->sent);
        } catch (\Throwable $e) {
            // Timeout: the query may still be running on the server; this
            // connection can never be reused.
            Poller::forget($this->link);
            $this->broken = true;
            throw $e;
        }
        $this->trackTransaction($sql);
        if ($result instanceof \mysqli_result) {
            $rows = $result->fetch_all(MYSQLI_ASSOC);
            $result->free();
            return new Result($rows);
        }
        return new Result([], (int) $this->link->affected_rows, $this->link->insert_id);
    }

    public function begin(): void
    {
        $this->query('START TRANSACTION');
    }

    public function commit(): void
    {
        $this->query('COMMIT');
    }

    public function rollback(): void
    {
        $this->query('ROLLBACK');
    }

    /** Follow transaction statements, including ones written as raw SQL. */
    private function trackTransaction(string $sql): void
    {
        if (preg_match('/^\s*(START\s+TRANSACTION|BEGIN)\b/i', $sql)) {
            $this->txDepth = 1;
        } elseif (preg_match('/^\s*(COMMIT|ROLLBACK)(\s+WORK)?(\s+AND\s+(NO\s+)?CHAIN)?(\s+(NO\s+)?RELEASE)?\s*;?\s*$/i', $sql, $m)) {
            // "AND CHAIN" starts a new transaction at once; "ROLLBACK TO
            // SAVEPOINT x" does not match and keeps the transaction open.
            $this->txDepth = !empty($m[3]) && empty($m[4]) ? 1 : 0;
        }
    }

    /**
     * Is this idle connection still usable? The server closes connections
     * idle for longer than wait_timeout; finding that out with a real query
     * would fail the request (and a write cannot be retried safely).
     */
    public function isAlive(): bool
    {
        if ($this->broken) {
            return false;
        }
        try {
            return $this->link->query('DO 1') === true; // blocking, one round trip
        } catch (\Throwable) {
            return false;
        }
    }

    public function inTransaction(): bool
    {
        return $this->txDepth > 0;
    }

    public function quote(mixed $value): string
    {
        return match (true) {
            $value === null => 'NULL',
            is_bool($value) => $value ? '1' : '0',
            is_int($value) => (string) $value,
            is_float($value) => is_finite($value) ? sprintf('%.17g', $value) : throw new \InvalidArgumentException('Cannot bind INF/NAN'),
            $value instanceof \DateTimeInterface => "'" . $value->format('Y-m-d H:i:s.u') . "'",
            $value instanceof \BackedEnum => $this->quote($value->value),
            is_array($value) => $value === [] ? 'NULL' : implode(', ', array_map($this->quote(...), $value)),
            $value instanceof \Stringable, is_string($value) => "'" . $this->link->real_escape_string((string) $value) . "'",
            default => throw new \InvalidArgumentException('Cannot bind value of type ' . get_debug_type($value)),
        };
    }

    public function close(): void
    {
        $this->broken = true;
        try {
            $this->link->close();
        } catch (\Throwable) {
        }
    }

    /**
     * Replace "?" placeholders outside quoted strings, identifiers and
     * comments. Arrays expand to lists: "WHERE id IN (?)" with [1, 2, 3].
     *
     * @param array<int, mixed> $params
     */
    private function interpolate(string $sql, array $params): string
    {
        $params = array_values($params);
        $out = '';
        $n = 0;
        $len = strlen($sql);
        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];
            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $end = $i + 1;
                while ($end < $len && $sql[$end] !== $ch) {
                    $end += $sql[$end] === '\\' && $ch !== '`' ? 2 : 1;
                }
                $out .= substr($sql, $i, $end - $i + 1);
                $i = $end;
            } elseif ($ch === '#' || ($ch === '-' && ($sql[$i + 1] ?? '') === '-' && ($i + 2 >= $len || ctype_space($sql[$i + 2]) || ctype_cntrl($sql[$i + 2])))) {
                // MySQL: "-- " is a comment only when followed by a space or
                // control character ("5--?" is 5 minus a negative number).
                $end = strpos($sql, "\n", $i);
                $end = $end === false ? $len : $end;
                $out .= substr($sql, $i, $end - $i);
                $i = $end - 1;
            } elseif ($ch === '/' && ($sql[$i + 1] ?? '') === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $end = $end === false ? $len : $end + 2;
                $out .= substr($sql, $i, $end - $i);
                $i = $end - 1;
            } elseif ($ch === '?') {
                if (!array_key_exists($n, $params)) {
                    throw new \InvalidArgumentException('Not enough parameters for the placeholders in: ' . $sql);
                }
                $out .= $this->quote($params[$n++]);
            } else {
                $out .= $ch;
            }
        }
        if ($n !== count($params)) {
            throw new \InvalidArgumentException(sprintf('%d parameters given, %d placeholders found', count($params), $n));
        }
        return $out;
    }
}
