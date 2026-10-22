<?php

declare(strict_types=1);

namespace QHTurbo\Runtime\Mysql;

use QHTurbo\Runtime\Async\Future;
use QHTurbo\Runtime\EventLoop\Loop;

/**
 * Waits for MYSQLI_ASYNC queries without blocking the event loop.
 * mysqli does not expose its socket to stream_select(), so while queries
 * are pending a short timer polls them with mysqli_poll(..., 0): extra
 * latency is at most the poll interval, and there is no polling when idle.
 */
final class Poller
{
    private const INTERVAL = 0.0005; // 0.5 ms

    /** @var array<int, array{0: \mysqli, 1: Future}> */
    private static array $pending = [];
    private static ?string $timer = null;
    private static ?Loop $loop = null;

    public static function wait(\mysqli $link): Future
    {
        $loop = Loop::get();
        if (self::$loop !== $loop) { // the loop was reset (fork, tests)
            self::$pending = [];
            self::$timer = null;
            self::$loop = $loop;
        }
        $future = new Future();
        self::$pending[spl_object_id($link)] = [$link, $future];
        self::$timer ??= $loop->repeat(self::INTERVAL, static fn () => self::poll());
        $loop->defer(static fn () => self::poll()); // fast queries finish without waiting a tick
        return $future;
    }

    /** Stop waiting for $link (its query timed out and it is being closed). */
    public static function forget(\mysqli $link): void
    {
        unset(self::$pending[spl_object_id($link)]);
    }

    private static function poll(): void
    {
        if (!self::$pending) {
            return;
        }
        $links = $errors = $rejects = array_map(static fn (array $p) => $p[0], array_values(self::$pending));
        if (@mysqli_poll($links, $errors, $rejects, 0, 0) === false) {
            return;
        }
        foreach ($links as $link) {
            $id = spl_object_id($link);
            if (!isset(self::$pending[$id])) {
                continue;
            }
            [, $future] = self::$pending[$id];
            unset(self::$pending[$id]);
            try {
                $result = $link->reap_async_query();
                $result === false
                    ? $future->fail(new QueryException($link->error ?: 'Query failed', $link->errno))
                    : $future->resolve($result);
            } catch (\mysqli_sql_exception $e) {
                $future->fail(new QueryException($e->getMessage(), $e->getCode(), null, $e));
            } catch (\Throwable $e) {
                $future->fail(new QueryException($e->getMessage(), 2013, null, $e));
            }
        }
        foreach ([...$errors, ...$rejects] as $link) {
            $id = spl_object_id($link);
            if (isset(self::$pending[$id])) {
                [, $future] = self::$pending[$id];
                unset(self::$pending[$id]);
                $future->fail(new QueryException('Connection error: ' . ($link->error ?: 'rejected'), $link->errno ?: 2013));
            }
        }
        if (!self::$pending && self::$timer !== null) {
            Loop::get()->cancel(self::$timer);
            self::$timer = null;
        }
    }
}
