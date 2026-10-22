<?php

declare(strict_types=1);

namespace QHTurbo\Runtime\Mysql;

use QHTurbo\Runtime\Pool\Pool;

/**
 * Non-blocking MySQL/MariaDB client with a connection pool.
 *
 *   $db = new Client('mysql://app:secret@127.0.0.1:3306/shop?pool=10');
 *   $rows = $db->query('SELECT * FROM users WHERE id IN (?) AND active = ?', [[1, 2, 3], true])->rows;
 *   $id = $db->query('INSERT INTO logs (msg) VALUES (?)', ['hi'])->insertId;
 *   $db->transaction(function (Connection $tx) {
 *       $tx->query('UPDATE accounts SET balance = balance - ? WHERE id = ?', [100, 1]);
 *       $tx->query('UPDATE accounts SET balance = balance + ? WHERE id = ?', [100, 2]);
 *   });
 *
 * Each query holds a pooled connection only while it runs, so N
 * concurrent requests of one worker can run N queries in parallel.
 * A connection is never handed back with an open transaction: it is
 * rolled back (rule R7) and the incident is reported.
 */
final class Client
{
    /** Seconds after which an idle pooled connection is checked before use. */
    private const CHECK_AFTER_IDLE = 30.0;

    /** @var Pool<Connection> */
    private Pool $pool;
    private Config $config;
    /** @var \Closure(string): void */
    private \Closure $log;

    public function __construct(string|Config $config, ?\Closure $log = null)
    {
        $this->config = is_string($config) ? Config::fromUri($config) : $config;
        $this->log = $log ?? static fn (string $m) => error_log("[qhturbo mysql] $m");
        $cfg = $this->config;
        $this->pool = new Pool(
            factory: static fn () => Connection::open($cfg),
            maxSize: $cfg->poolSize,
            closer: static fn (Connection $c) => $c->close(),
            // Connections idle for a while are checked with one round trip.
            check: static fn (Connection $c, float $idleFor) => !$c->broken && ($idleFor < self::CHECK_AFTER_IDLE || $c->isAlive()),
            acquireTimeout: $cfg->connectTimeout + $cfg->queryTimeout,
        );
    }

    /** @param array<int, mixed> $params */
    public function query(string $sql, array $params = []): Result
    {
        try {
            return $this->withConnection(static fn (Connection $c) => $c->query($sql, $params));
        } catch (QueryException $e) {
            // The connection died before the query could be sent (server
            // restarted, idle timeout): it never ran, so one retry on a
            // fresh connection is safe. A failure after sending is never
            // retried: an INSERT/UPDATE might already have been applied.
            if (!$e->sent && $e->isConnectionError()) {
                return $this->withConnection(static fn (Connection $c) => $c->query($sql, $params));
            }
            throw $e;
        }
    }

    /**
     * Run $fn inside a transaction on one connection: COMMIT on success,
     * ROLLBACK if it throws.
     *
     * @template R
     * @param callable(Connection): R $fn
     * @return R
     */
    public function transaction(callable $fn): mixed
    {
        return $this->withConnection(static function (Connection $c) use ($fn) {
            $c->begin();
            try {
                $result = $fn($c);
            } catch (\Throwable $e) {
                if (!$c->broken) {
                    $c->rollback();
                }
                throw $e;
            }
            $c->commit();
            return $result;
        });
    }

    /**
     * Borrow a connection for several statements (e.g. a manual
     * transaction). Any transaction still open afterwards is rolled back.
     *
     * @template R
     * @param callable(Connection): R $fn
     * @return R
     */
    public function withConnection(callable $fn): mixed
    {
        $conn = $this->pool->acquire();
        try {
            $result = $fn($conn);
        } catch (\Throwable $e) {
            $this->giveBack($conn);
            throw $e;
        }
        $this->giveBack($conn);
        return $result;
    }

    /** @return array{size: int, idle: int, busy: int, waiting: int, max: int} */
    public function poolStats(): array
    {
        return $this->pool->stats();
    }

    public function close(): void
    {
        $this->pool->close();
    }

    private function giveBack(Connection $conn): void
    {
        if ($conn->broken) {
            $this->pool->discard($conn);
            return;
        }
        if ($conn->inTransaction()) {
            ($this->log)('transaction left open when the connection was released: rolled back (rule R7)');
            try {
                $conn->rollback();
            } catch (\Throwable) {
                $this->pool->discard($conn);
                return;
            }
        }
        $this->pool->release($conn);
    }
}
