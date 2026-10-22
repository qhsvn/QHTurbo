<?php

declare(strict_types=1);

namespace QHTurbo\Runtime\Http;

use QHTurbo\Runtime\Async\TimeoutException;
use QHTurbo\Runtime\EventLoop\Loop;
use QHTurbo\Runtime\State\StateGuard;
use QHTurbo\Runtime\State\StateViolation;
use QHTurbo\Runtime\Worker\Options;

use function QHTurbo\Runtime\Async\async;
use function QHTurbo\Runtime\Async\await;
use function QHTurbo\Runtime\Async\read;
use function QHTurbo\Runtime\Async\write;

/**
 * Non-blocking HTTP/1.1 server running inside one worker process.
 *
 * Every connection is served by its own fiber. With concurrency = 1 the
 * worker runs one request at a time (like an FPM child that never forgets
 * its bootstrap); with concurrency > 1 requests interleave whenever a
 * handler waits on async I/O.
 */
final class Server
{
    /**
     * With concurrency > 1 the state guard (reset hooks, cleanup) runs when
     * no request is in flight. Under constant load that moment may never
     * come, so after this many requests new ones wait until the in-flight
     * ones finish and the guard has run.
     */
    private const GUARD_BARRIER = 64;

    /** @var resource|null */
    private $listener = null;
    private ?string $acceptWatcher = null;
    private int $inFlight = 0;
    private int $served = 0;
    private bool $draining = false;
    /** Requests finished since the state guard last ran (concurrency > 1). */
    private int $unguarded = 0;
    /** Accepting is paused because the connection limit was reached. */
    private bool $acceptPaused = false;
    /** @var list<\QHTurbo\Runtime\Async\Future> waiting for a concurrency slot */
    private array $slotQueue = [];
    /** @var array<int, resource> */
    private array $connections = [];
    /** @var array<int, true> keep-alive connections waiting for their next request */
    private array $idle = [];
    /** @var \Closure(Request): mixed */
    private \Closure $handler;
    /** @var \Closure(): void|null */
    private ?\Closure $onDrained = null;

    /** @param \Closure(string): void $log */
    public function __construct(
        callable $handler,
        private readonly Options $options,
        private readonly StateGuard $guard,
        private readonly \Closure $log,
    ) {
        $this->handler = \Closure::fromCallable($handler);
    }

    /** @param resource $listener a listening socket (shared by all workers) */
    public function listen($listener): void
    {
        $this->listener = $listener;
        stream_set_blocking($listener, false);
        $this->acceptWatcher = Loop::get()->onReadable($listener, fn () => $this->accept());
    }

    public function served(): int
    {
        return $this->served;
    }

    /**
     * Stop accepting connections, finish in-flight requests, then call
     * $onDrained. Idle keep-alive connections are closed.
     */
    public function drain(callable $onDrained): void
    {
        if ($this->draining) {
            return;
        }
        $this->draining = true;
        $this->onDrained = \Closure::fromCallable($onDrained);
        if ($this->acceptWatcher !== null) {
            Loop::get()->cancel($this->acceptWatcher);
            $this->acceptWatcher = null;
        }
        $this->checkDrained();
    }

    private function accept(): void
    {
        // Several workers wait on the same socket; losing the race is normal.
        $conn = @stream_socket_accept($this->listener, 0, $peer);
        if ($conn === false) {
            return;
        }
        stream_set_blocking($conn, false);
        stream_set_read_buffer($conn, 0);
        $this->connections[(int) $conn] = $conn;
        // stream_select() cannot watch descriptors above FD_SETSIZE (1024)
        // and accept() fails in a busy loop once the descriptor limit is
        // reached: stop accepting until a connection closes. Other workers,
        // or the kernel backlog, take the extra connections meanwhile.
        if (count($this->connections) >= $this->options->maxConnections && $this->acceptWatcher !== null) {
            Loop::get()->cancel($this->acceptWatcher);
            $this->acceptWatcher = null;
            $this->acceptPaused = true;
        }
        async(fn () => $this->serveConnection($conn, (string) $peer));
    }

    private function resumeAccept(): void
    {
        if ($this->acceptPaused && !$this->draining && count($this->connections) < $this->options->maxConnections) {
            $this->acceptPaused = false;
            $this->acceptWatcher = Loop::get()->onReadable($this->listener, fn () => $this->accept());
        }
    }

    /** @param resource $conn */
    private function serveConnection($conn, string $peer): void
    {
        $buffer = '';
        $first = true;
        try {
            // A freshly accepted connection always gets its request served,
            // even if draining started between accept() and the first read.
            while ($first || !$this->draining || $buffer !== '') {
                if (!$first && $buffer === '') {
                    $this->idle[(int) $conn] = true;
                }
                $first = false;
                $request = $this->readRequest($conn, $buffer, $peer);
                unset($this->idle[(int) $conn]);
                if ($request === null) {
                    break;
                }
                $this->acquireSlot();
                try {
                    $response = $this->dispatch($request);
                    $keepAlive = $this->keepAlive($request) && !$this->draining;
                    $raw = $response->toRaw($keepAlive, $request->method === 'HEAD');
                    unset($response);
                } finally {
                    $this->releaseSlot();
                }
                // The slot is free while the bytes go out: a slow client
                // does not hold up the next request of this worker.
                write($conn, $raw, $this->options->writeTimeout);
                unset($raw);
                if (!$keepAlive) {
                    break;
                }
            }
        } catch (BadRequest $e) {
            @fwrite($conn, (new Response($e->getCode(), ['Content-Type' => 'text/plain'], $e->getMessage() . "\n"))->toRaw(false));
        } catch (TimeoutException) {
            // idle keep-alive or slow client: just close
        } catch (\Throwable $e) {
            ($this->log)('connection error: ' . $e->getMessage());
        } finally {
            unset($this->connections[(int) $conn], $this->idle[(int) $conn]);
            @fclose($conn);
            $this->resumeAccept();
            $this->checkDrained();
        }
    }

    private function dispatch(Request $request): Response
    {
        $legacy = $this->options->superglobals;
        if ($legacy) {
            $this->guard->populateSuperglobals($request->query, $request->parsedBody(), $request->cookies(), $request->server, $request->uploadedFiles());
        }
        try {
            $response = Response::from(($this->handler)($request));
        } catch (\Throwable $e) {
            ($this->log)(sprintf('uncaught %s: %s in %s:%d', $e::class, $e->getMessage(), $e->getFile(), $e->getLine()));
            $response = Response::text($this->options->debug ? (string) $e : "Internal Server Error\n", 500);
        }
        $request->cleanup();
        $this->served++;
        // Per-request state must not survive. With concurrency > 1 other
        // requests may still be using it: the guard runs in releaseSlot()
        // once no request is in flight.
        if ($this->options->concurrency === 1) {
            try {
                $this->guard->afterRequest();
            } catch (StateViolation $e) {
                ($this->log)($e->getMessage());
                $response = Response::text($this->options->debug ? $e->getMessage() . "\n" : "Internal Server Error\n", 500);
            }
        } else {
            $this->unguarded++;
        }
        return $response;
    }

    /**
     * @param resource $conn
     */
    private function readRequest($conn, string &$buffer, string $peer): ?Request
    {
        $timeout = $buffer === '' ? $this->options->keepAliveTimeout : $this->options->readTimeout;
        while (($end = strpos($buffer, "\r\n\r\n")) === false) {
            if (strlen($buffer) > $this->options->maxHeaderBytes) {
                throw new BadRequest('Request header too large', 431);
            }
            $chunk = read($conn, 65536, $timeout);
            if ($chunk === '') {
                if (feof($conn)) {
                    return null; // client closed
                }
                continue;
            }
            $buffer .= $chunk;
            $timeout = $this->options->readTimeout;
        }
        if ($end > $this->options->maxHeaderBytes) {
            throw new BadRequest('Request header too large', 431);
        }
        $head = substr($buffer, 0, $end);
        $buffer = (string) substr($buffer, $end + 4);

        $lines = explode("\r\n", $head);
        $parts = explode(' ', array_shift($lines));
        if (count($parts) !== 3 || !str_starts_with($parts[2], 'HTTP/1.')) {
            throw new BadRequest('Malformed request line', 400);
        }
        [$method, $uri, $protocol] = $parts;
        $headers = [];
        foreach ($lines as $line) {
            $colon = strpos($line, ':');
            if ($colon === false || $colon === 0) {
                throw new BadRequest('Malformed header', 400);
            }
            $name = strtolower(substr($line, 0, $colon));
            if ($name !== trim($name)) {
                throw new BadRequest('Malformed header', 400); // "Name :" is forbidden (RFC 9112 5.1)
            }
            $value = trim(substr($line, $colon + 1));
            if (!isset($headers[$name])) {
                $headers[$name] = $value;
            } elseif ($name === 'content-length' || $name === 'host') {
                // Several different lengths = request smuggling attempt.
                if ($headers[$name] !== $value) {
                    throw new BadRequest("Conflicting $name headers", 400);
                }
            } else {
                // Repeated headers are one list (RFC 9110 5.3); cookies use "; ".
                $headers[$name] .= ($name === 'cookie' ? '; ' : ', ') . $value;
            }
        }
        if (isset($headers['transfer-encoding'])) {
            throw new BadRequest('Chunked request bodies are not supported; send Content-Length', 411);
        }
        $rawLength = $headers['content-length'] ?? '0';
        if (!ctype_digit($rawLength) || strlen($rawLength) > 18) {
            throw new BadRequest('Invalid Content-Length', 400);
        }
        $length = (int) $rawLength;
        if ($length > $this->options->maxBodyBytes) {
            throw new BadRequest('Request body too large', 413);
        }
        if (isset($headers['expect'])) {
            if (strtolower($headers['expect']) !== '100-continue') {
                throw new BadRequest('Unsupported Expect header', 417);
            }
            // The client waits for this before sending the body (curl sends
            // "Expect: 100-continue" for bodies over 1 KiB).
            if ($protocol !== 'HTTP/1.0' && strlen($buffer) < $length) {
                write($conn, "HTTP/1.1 100 Continue\r\n\r\n", $this->options->writeTimeout);
            }
        }
        while (strlen($buffer) < $length) {
            $chunk = read($conn, 65536, $this->options->readTimeout);
            if ($chunk === '' && feof($conn)) {
                throw new BadRequest('Incomplete request body', 400);
            }
            $buffer .= $chunk;
        }
        $body = (string) substr($buffer, 0, $length);
        $buffer = (string) substr($buffer, $length);

        // "1.2.3.4:5678" or "[2001:db8::1]:5678"
        $colon = strrpos($peer, ':');
        [$remoteAddr, $remotePort] = $colon === false ? [$peer, ''] : [trim(substr($peer, 0, $colon), '[]'), substr($peer, $colon + 1)];
        $path = (string) parse_url($uri, PHP_URL_PATH);
        $server = [
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $uri,
            'SCRIPT_NAME' => '',
            'PATH_INFO' => $path,
            'QUERY_STRING' => (string) parse_url($uri, PHP_URL_QUERY),
            'SERVER_PROTOCOL' => $protocol,
            'REMOTE_ADDR' => $remoteAddr,
            'REMOTE_PORT' => $remotePort,
            'REQUEST_TIME' => time(),
            'REQUEST_TIME_FLOAT' => microtime(true),
            'HTTP_HOST' => $headers['host'] ?? '',
        ];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }
        if (isset($headers['content-type'])) {
            $server['CONTENT_TYPE'] = $headers['content-type'];
        }
        $server['CONTENT_LENGTH'] = (string) $length;

        return new Request($method, $uri, $protocol, $headers, $body, $server);
    }

    private function keepAlive(Request $request): bool
    {
        $connection = strtolower((string) $request->header('connection'));
        if ($request->protocol === 'HTTP/1.0') {
            return $connection === 'keep-alive';
        }
        return $connection !== 'close';
    }

    private function acquireSlot(): void
    {
        while ($this->inFlight >= $this->options->concurrency || $this->unguarded >= self::GUARD_BARRIER) {
            $wait = new \QHTurbo\Runtime\Async\Future();
            $this->slotQueue[] = $wait;
            await($wait);
        }
        $this->inFlight++;
    }

    private function releaseSlot(): void
    {
        $this->inFlight--;
        if ($this->unguarded > 0 && $this->inFlight === 0) {
            $this->unguarded = 0;
            try {
                $this->guard->afterRequest();
            } catch (StateViolation $e) {
                ($this->log)($e->getMessage()); // the responses are already built
            }
            // Wake every waiter; those that find no free slot queue again.
            $queue = $this->slotQueue;
            $this->slotQueue = [];
            foreach ($queue as $wait) {
                $wait->resolve();
            }
        } elseif ($this->slotQueue && $this->unguarded < self::GUARD_BARRIER) {
            array_shift($this->slotQueue)->resolve();
        }
        $this->checkDrained();
    }

    private function checkDrained(): void
    {
        if (!$this->draining || $this->inFlight > 0 || $this->onDrained === null) {
            return;
        }
        // Idle keep-alive connections: close them so their fibers finish.
        // New connections still waiting for their first request are left
        // alone; they are served (or time out after keepAliveTimeout).
        foreach ($this->idle as $id => $_) {
            if (isset($this->connections[$id])) {
                @stream_socket_shutdown($this->connections[$id], STREAM_SHUT_RDWR);
            }
        }
        if ($this->connections) {
            return; // their fibers call checkDrained() again when they exit
        }
        // Finish on the next tick, and only if still idle then: the signal
        // that started draining may have interrupted accept() between
        // stream_socket_accept() and registering the new connection.
        Loop::get()->defer(function (): void {
            if ($this->onDrained === null || $this->inFlight > 0 || $this->connections) {
                return; // a connection appeared; its fiber re-checks on exit
            }
            $done = $this->onDrained;
            $this->onDrained = null;
            $done();
        });
    }
}
