<?php

declare(strict_types=1);

namespace QHTurbo\Runtime\Redis;

use function QHTurbo\Runtime\Async\connect;
use function QHTurbo\Runtime\Async\read;
use function QHTurbo\Runtime\Async\write;

/** One non-blocking Redis connection speaking RESP2. */
final class Connection
{
    private string $buffer = '';
    public bool $broken = false;

    /** @param resource $stream */
    private function __construct(private $stream, private readonly float $timeout)
    {
    }

    public static function open(string $host, int $port, ?string $username, ?string $password, int $database, float $timeout): self
    {
        $stream = $host[0] === '/'
            ? connect("unix://$host", $timeout)
            : connect("tcp://$host:$port", $timeout);
        $conn = new self($stream, $timeout);
        if ($password !== null && $password !== '') {
            $conn->command($username !== null && $username !== '' ? ['AUTH', $username, $password] : ['AUTH', $password]);
        }
        if ($database !== 0) {
            $conn->command(['SELECT', (string) $database]);
        }
        return $conn;
    }

    /** @param list<string|int|float> $args */
    public function command(array $args): mixed
    {
        $reply = $this->pipeline([$args])[0];
        if ($reply instanceof RedisException) {
            throw $reply;
        }
        return $reply;
    }

    /**
     * Send several commands in one round trip. Error replies are returned
     * in place as RedisException objects.
     *
     * @param list<list<string|int|float>> $commands
     * @return list<mixed>
     */
    public function pipeline(array $commands): array
    {
        $payload = '';
        foreach ($commands as $args) {
            $payload .= '*' . count($args) . "\r\n";
            foreach ($args as $arg) {
                $arg = (string) $arg;
                $payload .= '$' . strlen($arg) . "\r\n" . $arg . "\r\n";
            }
        }
        try {
            write($this->stream, $payload, $this->timeout);
            $replies = [];
            foreach ($commands as $_) {
                $replies[] = $this->readReply();
            }
            return $replies;
        } catch (\Throwable $e) {
            $this->broken = true; // unknown protocol state: never reuse
            throw $e instanceof RedisException ? $e : new RedisException('Redis connection failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function close(): void
    {
        $this->broken = true;
        @fclose($this->stream);
    }

    private function readReply(): mixed
    {
        $line = $this->readLine();
        $type = $line[0] ?? '';
        $rest = substr($line, 1);
        switch ($type) {
            case '+':
                return $rest;
            case '-':
                return new RedisException($rest);
            case ':':
                return (int) $rest;
            case '$':
                $len = (int) $rest;
                if ($len < 0) {
                    return null;
                }
                $data = $this->readBytes($len + 2);
                return substr($data, 0, $len);
            case '*':
                $count = (int) $rest;
                if ($count < 0) {
                    return null;
                }
                $items = [];
                for ($i = 0; $i < $count; $i++) {
                    $item = $this->readReply();
                    $items[] = $item;
                }
                return $items;
        }
        throw new RedisException('Protocol error: unexpected reply ' . substr($line, 0, 40));
    }

    private function readLine(): string
    {
        while (($pos = strpos($this->buffer, "\r\n")) === false) {
            $this->fill();
        }
        $line = substr($this->buffer, 0, $pos);
        $this->buffer = (string) substr($this->buffer, $pos + 2);
        return $line;
    }

    private function readBytes(int $n): string
    {
        while (strlen($this->buffer) < $n) {
            $this->fill();
        }
        $data = substr($this->buffer, 0, $n);
        $this->buffer = (string) substr($this->buffer, $n);
        return $data;
    }

    private function fill(): void
    {
        $chunk = read($this->stream, 65536, $this->timeout);
        if ($chunk === '' && feof($this->stream)) {
            throw new RedisException('Connection closed by Redis');
        }
        $this->buffer .= $chunk;
    }
}
