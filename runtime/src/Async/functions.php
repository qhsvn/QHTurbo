<?php

declare(strict_types=1);

namespace QHTurbo\Runtime\Async;

use QHTurbo\Runtime\EventLoop\Loop;

/**
 * Run $fn in a new Fiber. Inside it, await()/delay()/read()/write() suspend
 * only that fiber, so other requests keep running on the same worker.
 */
function async(callable $fn, mixed ...$args): Future
{
    $future = new Future();
    $fiber = new \Fiber(static function () use ($fn, $args, $future): void {
        try {
            $future->resolve($fn(...$args));
        } catch (\Throwable $e) {
            $future->fail($e);
        }
    });
    Loop::get()->defer(static fn () => $fiber->start());
    return $future;
}

/**
 * Wait for a future. In a fiber this suspends the fiber; outside any fiber
 * it drives the event loop until the future completes.
 */
function await(Future $future): mixed
{
    if ($future->isDone()) {
        return $future->result();
    }
    $fiber = \Fiber::getCurrent();
    if ($fiber === null) {
        $loop = Loop::get();
        if ($loop->isRunning()) {
            throw new \LogicException('await() outside a fiber while the loop is running; wrap the code in async()');
        }
        $loop->runUntil(static fn (): bool => $future->isDone());
        if (!$future->isDone()) {
            throw new \LogicException('Event loop stopped before the future completed (deadlock?)');
        }
        return $future->result();
    }
    // Resume from the loop, not from inside whoever resolved the future.
    $future->onDone(static fn () => Loop::get()->defer(static fn () => $fiber->resume()));
    \Fiber::suspend();
    return $future->result();
}

/**
 * Await several futures that already run concurrently.
 *
 * @param array<array-key, Future> $futures
 * @return array<array-key, mixed>
 */
function all(array $futures): array
{
    $results = [];
    foreach ($futures as $key => $future) {
        $results[$key] = await($future);
    }
    return $results;
}

/** Non-blocking sleep. */
function delay(float $seconds): void
{
    $future = new Future();
    Loop::get()->delay($seconds, static fn () => $future->resolve());
    await($future);
}

/** Fails with TimeoutException if $future is not done within $seconds. */
function timeout(Future $future, float $seconds): Future
{
    if ($future->isDone()) {
        return $future;
    }
    $result = new Future();
    $timer = Loop::get()->delay($seconds, static function () use ($result, $seconds): void {
        if (!$result->isDone()) {
            $result->fail(new TimeoutException(sprintf('Timed out after %.3f s', $seconds)));
        }
    });
    $future->onDone(static function (?\Throwable $error, mixed $value) use ($result, $timer): void {
        Loop::get()->cancel($timer);
        if ($result->isDone()) {
            return;
        }
        $error === null ? $result->resolve($value) : $result->fail($error);
    });
    return $result;
}

/** @param resource $stream */
function readable($stream, ?float $timeoutSeconds = null): void
{
    waitFor($stream, true, $timeoutSeconds);
}

/** @param resource $stream */
function writable($stream, ?float $timeoutSeconds = null): void
{
    waitFor($stream, false, $timeoutSeconds);
}

/** @param resource $stream */
function waitFor($stream, bool $forRead, ?float $timeoutSeconds): void
{
    $loop = Loop::get();
    $future = new Future();
    $watch = static function () use (&$id, $future, $loop): void {
        $loop->cancel($id);
        if (!$future->isDone()) {
            $future->resolve();
        }
    };
    $id = $forRead ? $loop->onReadable($stream, $watch) : $loop->onWritable($stream, $watch);
    if ($timeoutSeconds !== null) {
        $future = timeout($future, $timeoutSeconds);
        $future->onDone(static fn () => $loop->cancel($id));
    }
    await($future);
}

/**
 * Read up to $length bytes once the stream is readable.
 * Returns '' at end of stream.
 *
 * @param resource $stream
 */
function read($stream, int $length = 65536, ?float $timeoutSeconds = null): string
{
    readable($stream, $timeoutSeconds);
    $data = @fread($stream, $length);
    return $data === false ? '' : $data;
}

/** @param resource $stream */
function write($stream, string $data, ?float $timeoutSeconds = null): void
{
    while ($data !== '') {
        writable($stream, $timeoutSeconds);
        $written = @fwrite($stream, $data);
        if ($written === false || ($written === 0 && feof($stream))) {
            throw new \RuntimeException('Write failed: connection closed');
        }
        $data = (string) substr($data, $written);
    }
}

/**
 * Non-blocking TCP connect, e.g. connect('tcp://127.0.0.1:6379').
 *
 * @return resource
 */
function connect(string $uri, float $timeoutSeconds = 10.0)
{
    $stream = @stream_socket_client($uri, $errno, $errstr, 0, STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT);
    if ($stream === false) {
        throw new \RuntimeException("Connect to $uri failed: $errstr ($errno)");
    }
    stream_set_blocking($stream, false);
    writable($stream, $timeoutSeconds);
    if (stream_socket_get_name($stream, true) === false) {
        fclose($stream);
        throw new \RuntimeException("Connect to $uri failed: connection refused");
    }
    return $stream;
}
