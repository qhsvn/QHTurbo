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
    /** @var resource|null */
    private $listener = null;
    private ?string $acceptWatcher = null;
    private int $inFlight = 0;
    private int $served = 0;
    private bool $draining = false;
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
        async(fn () => $this->serveConnection($conn, (string) $peer));
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
                $keepAlive = false;
                try {
                    $keepAlive = $this->keepAlive($request) && !$this->draining;
                    $response = $this->dispatch($request);
                    write($conn, $response->toRaw($keepAlive, $request->method === 'HEAD'), $this->options->writeTimeout);
                } finally {
                    $this->releaseSlot();
                }
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
        // Per-request state must not survive; with concurrency > 1 the guard
        // only runs when no other request is in flight.
        if ($this->inFlight === 1) {
            try {
                $this->guard->afterRequest();
            } catch (StateViolation $e) {
                ($this->log)($e->getMessage());
                $response = Response::text($this->options->debug ? $e->getMessage() . "\n" : "Internal Server Error\n", 500);
            }
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
            if ($colon === false) {
                throw new BadRequest('Malformed header', 400);
            }
            $headers[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, $colon + 1));
        }
        if (isset($headers['transfer-encoding'])) {
            throw new BadRequest('Chunked request bodies are not supported; send Content-Length', 411);
        }
        $length = (int) ($headers['content-length'] ?? 0);
        if ($length > $this->options->maxBodyBytes) {
            throw new BadRequest('Request body too large', 413);
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

        [$remoteAddr, $remotePort] = array_pad(explode(':', $peer, 2), 2, '');
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
        while ($this->inFlight >= $this->options->concurrency) {
            $wait = new \QHTurbo\Runtime\Async\Future();
            $this->slotQueue[] = $wait;
            await($wait);
        }
        $this->inFlight++;
    }

    private function releaseSlot(): void
    {
        $this->inFlight--;
        if ($this->slotQueue) {
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
