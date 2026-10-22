<?php

declare(strict_types=1);

namespace QHTurbo\Runtime\Redis;

use QHTurbo\Runtime\Pool\Pool;

/**
 * Non-blocking Redis client with a connection pool.
 *
 *   $redis = new Client('redis://:secret@127.0.0.1:6379/0', poolSize: 16);
 *   $redis->set('key', 'value', 'EX', 60);
 *   $value = $redis->get('key');
 *   [$a, $b] = $redis->pipeline([['INCR', 'a'], ['GET', 'b']]);
 *
 * Inside a fiber (a request in QHturbo Performance mode) each call only
 * suspends that request; outside a fiber it simply blocks until done.
 * Any Redis command works through __call() or command().
 *
 * @method mixed get(string $key)
 * @method mixed set(string $key, string|int|float $value, string|int ...$options)
 * @method int del(string ...$keys)
 * @method int incr(string $key)
 * @method int expire(string $key, int $seconds)
 */
final class Client
{
    /** @var Pool<Connection> */
    private Pool $pool;

    /**
     * @param string $uri redis://[user:pass@]host[:port][/db]
     *                    or unix:///path/redis.sock[?db=N&password=...&user=...]
     */
    public function __construct(string $uri = 'redis://127.0.0.1:6379', int $poolSize = 16, float $timeout = 5.0)
    {
        if (preg_match('#^unix:(?://)?(/[^?]*)(?:\?(.*))?$#', $uri, $m)) {
            parse_str($m[2] ?? '', $q);
            $host = $m[1];
            $port = 0;
            $user = isset($q['user']) ? (string) $q['user'] : null;
            $pass = isset($q['password']) ? (string) $q['password'] : null;
            $db = (int) ($q['db'] ?? 0);
        } else {
            $parts = parse_url($uri);
            if ($parts === false || ($parts['scheme'] ?? '') !== 'redis') {
                throw new \InvalidArgumentException("Invalid Redis URI: $uri (expected redis://[user:pass@]host[:port][/db] or unix:///path/redis.sock)");
            }
            $host = (string) ($parts['host'] ?? '127.0.0.1');
            $port = (int) ($parts['port'] ?? 6379);
            // rawurldecode: "+" in a password is a plus, not a space.
            $user = isset($parts['user']) ? rawurldecode($parts['user']) : null;
            $pass = isset($parts['pass']) ? rawurldecode($parts['pass']) : null;
            $db = (int) ltrim((string) ($parts['path'] ?? ''), '/');
        }

        $this->pool = new Pool(
            factory: static fn () => Connection::open($host, $port, $user, $pass, $db, $timeout),
            maxSize: $poolSize,
            closer: static fn (Connection $c) => $c->close(),
            check: static fn (Connection $c) => !$c->broken,
            acquireTimeout: $timeout,
        );
    }

    public function command(string|int|float ...$args): mixed
    {
        return $this->pool->use(
            static fn (Connection $c) => $c->command(array_values($args)),
            // An error reply (WRONGTYPE...) leaves the connection usable.
            static fn (\Throwable $e, Connection $c) => $c->broken,
        );
    }

    /**
     * @param list<list<string|int|float>> $commands
     * @return list<mixed> replies in order; error replies as RedisException objects
     */
    public function pipeline(array $commands): array
    {
        return $this->pool->use(static fn (Connection $c) => $c->pipeline($commands));
    }

    /** @param list<mixed> $args */
    public function __call(string $name, array $args): mixed
    {
        return $this->command(strtoupper($name), ...$args);
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
}
