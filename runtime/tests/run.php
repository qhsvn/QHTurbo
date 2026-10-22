<?php

declare(strict_types=1);

// Runtime test suite (no dependencies): php runtime/tests/run.php [filter]
// Unit tests run in-process; server tests start bin/qhturbo-serve with the
// same PHP binary and talk to it over TCP.

require dirname(__DIR__) . '/src/autoload.php';

use QHTurbo\Runtime\Async\TimeoutException;
use QHTurbo\Runtime\EventLoop\Loop;

use function QHTurbo\Runtime\Async\all;
use function QHTurbo\Runtime\Async\async;
use function QHTurbo\Runtime\Async\await;
use function QHTurbo\Runtime\Async\connect;
use function QHTurbo\Runtime\Async\delay;
use function QHTurbo\Runtime\Async\read;
use function QHTurbo\Runtime\Async\timeout;
use function QHTurbo\Runtime\Async\write;

$tests = [];
function test(string $name, callable $fn): void
{
    $GLOBALS['tests'][$name] = $fn;
}
function check(bool $cond, string $message): void
{
    if (!$cond) {
        throw new RuntimeException($message);
    }
}

// ---------------------------------------------------------------- unit ----

test('loop runs timers in due order and cancels', function (): void {
    Loop::reset();
    $loop = Loop::get();
    $order = [];
    $loop->delay(0.03, function () use (&$order) { $order[] = 'b'; });
    $loop->delay(0.01, function () use (&$order) { $order[] = 'a'; });
    $id = $loop->delay(0.02, function () use (&$order) { $order[] = 'cancelled'; });
    $loop->cancel($id);
    $loop->defer(function () use (&$order) { $order[] = 'deferred'; });
    $n = 0;
    $loop->repeat(0.005, function (string $rid) use (&$n, $loop) { if (++$n === 3) { $loop->cancel($rid); } });
    $loop->run();
    check($order === ['deferred', 'a', 'b'], 'order: ' . implode(',', $order));
    check($n === 3, "repeat ran $n times");
});

test('cancelled timers do not pile up in the queue', function (): void {
    Loop::reset();
    $loop = Loop::get();
    for ($i = 0; $i < 50_000; $i++) {
        $loop->cancel($loop->delay(30.0, static fn () => null)); // like a read timeout
    }
    $stats = $loop->stats();
    check($stats['timers'] === 0, 'live timers left');
    check($stats['queued_timers'] <= 1100, "queue holds {$stats['queued_timers']} dead timers");
});

test('async tasks run concurrently', function (): void {
    Loop::reset();
    $start = hrtime(true);
    $results = await(async(function () {
        return all([
            async(function () { delay(0.1); return 1; }),
            async(function () { delay(0.1); return 2; }),
            async(function () { delay(0.1); return 3; }),
        ]);
    }));
    $elapsed = (hrtime(true) - $start) / 1e9;
    check($results === [1, 2, 3], 'results');
    check($elapsed < 0.2, sprintf('3 x 100 ms took %.3f s (should overlap)', $elapsed));
});

test('timeout fails slow futures and errors propagate', function (): void {
    Loop::reset();
    try {
        await(timeout(async(fn () => delay(0.2)), 0.05));
        check(false, 'no timeout');
    } catch (TimeoutException) {
    }
    try {
        await(async(function () { throw new DomainException('boom'); }));
        check(false, 'no exception');
    } catch (DomainException $e) {
        check($e->getMessage() === 'boom', 'message');
    }
});

test('non-blocking stream read/write', function (): void {
    Loop::reset();
    [$a, $b] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    stream_set_blocking($a, false);
    stream_set_blocking($b, false);
    $payload = str_repeat('x', 1 << 20); // bigger than the socket buffer
    $got = await(async(function () use ($a, $b, $payload) {
        $reader = async(function () use ($b, $payload) {
            $data = '';
            while (strlen($data) < strlen($payload)) {
                $data .= read($b);
            }
            return $data;
        });
        write($a, $payload);
        return await($reader);
    }));
    check($got === $payload, 'payload mismatch (' . strlen($got) . ' bytes)');
});

test('connect to a closed port fails', function (): void {
    Loop::reset();
    try {
        await(async(fn () => connect('tcp://127.0.0.1:1', 1.0)));
        check(false, 'connected?');
    } catch (RuntimeException $e) {
        check(str_contains($e->getMessage(), 'failed'), $e->getMessage());
    }
});

// -------------------------------------------------------------- server ----

final class TestServer
{
    /** @var resource */
    private $proc;
    public string $log = '';
    private string $logFile;

    /** @param list<string> $phpArgs e.g. ['-d', 'disable_functions=...'] */
    public function __construct(string $app, array $args, public int $port, array $phpArgs = [])
    {
        $this->logFile = tempnam(sys_get_temp_dir(), 'qht');
        $cmd = array_merge([PHP_BINARY], $phpArgs, [dirname(__DIR__) . '/bin/qhturbo-serve', $app, "--port=$port"], $args);
        $this->proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $this->logFile, 'w'], 2 => ['file', $this->logFile, 'w']], $pipes);
        for ($i = 0; $i < 100; $i++) {
            $s = @stream_socket_client("tcp://127.0.0.1:$port", $e, $m, 0.1);
            if ($s) {
                fclose($s);
                return;
            }
            usleep(50_000);
        }
        throw new RuntimeException("server did not start:\n" . file_get_contents($this->logFile));
    }

    public function signal(int $sig): void
    {
        proc_terminate($this->proc, $sig);
    }

    public function stop(): string
    {
        proc_terminate($this->proc, SIGTERM);
        for ($i = 0; $i < 100 && proc_get_status($this->proc)['running']; $i++) {
            usleep(50_000);
        }
        proc_close($this->proc);
        $this->log = (string) file_get_contents($this->logFile);
        unlink($this->logFile);
        return $this->log;
    }
}

/** Blocking raw HTTP exchange; returns [status, headers, body]. */
function http(int $port, string $raw, &$sock = null): array
{
    $sock ??= stream_socket_client("tcp://127.0.0.1:$port", $errno, $errstr, 5);
    stream_set_timeout($sock, 10);
    fwrite($sock, $raw);
    $head = '';
    while (!str_contains($head, "\r\n\r\n") && !feof($sock)) {
        $head .= fread($sock, 1);
    }
    [$headText] = explode("\r\n\r\n", $head, 2);
    $lines = explode("\r\n", $headText);
    $status = (int) (explode(' ', $lines[0])[1] ?? 0); // 0 = connection closed without a response
    $headers = [];
    foreach (array_slice($lines, 1) as $l) {
        [$k, $v] = explode(':', $l, 2);
        $headers[strtolower($k)] = trim($v);
    }
    $len = (int) ($headers['content-length'] ?? 0);
    $body = '';
    while (strlen($body) < $len && !feof($sock)) {
        $body .= fread($sock, $len - strlen($body));
    }
    return [$status, $headers, $body];
}

function get(int $port, string $path): array
{
    return http($port, "GET $path HTTP/1.1\r\nHost: t\r\nConnection: close\r\n\r\n");
}

$fixtures = __DIR__ . '/fixtures';
$port = 18100;

test('server: GET, POST, HEAD, 404, keep-alive', function () use ($fixtures, &$port): void {
    $srv = new TestServer("$fixtures/app.php", ['--workers=2'], ++$port);
    try {
        [$s, , $b] = get($srv->port, '/hello?name=QH');
        check($s === 200 && $b === 'hello QH', "GET: $s $b");
        [$s, $h, $b] = http($srv->port, "POST /echo HTTP/1.1\r\nHost: t\r\nContent-Type: application/json\r\nContent-Length: 13\r\nConnection: close\r\n\r\n{\"a\":[1,2,3]}");
        check($s === 200 && json_decode($b, true) === ['a' => [1, 2, 3]], "POST: $s $b");
        check($h['content-type'] === 'application/json', 'content-type');
        [$s, $h, $b] = http($srv->port, "HEAD /hello HTTP/1.1\r\nHost: t\r\nConnection: close\r\n\r\n");
        check($s === 200 && $b === '' && $h['content-length'] === '11', 'HEAD: ' . json_encode([$s, $b, $h]));
        [$s] = get($srv->port, '/missing');
        check($s === 404, "404: $s");
        $sock = null;
        [$s1, $h1] = http($srv->port, "GET /pid HTTP/1.1\r\nHost: t\r\n\r\n", $sock);
        [$s2, , $b2] = http($srv->port, "GET /hello HTTP/1.1\r\nHost: t\r\n\r\n", $sock);
        check($s1 === 200 && $h1['connection'] === 'keep-alive' && $s2 === 200 && $b2 === 'hello world', 'keep-alive reuse');
        fclose($sock);
    } finally {
        $srv->stop();
    }
});

test('server: protocol errors', function () use ($fixtures, &$port): void {
    $srv = new TestServer("$fixtures/app.php", [], ++$port);
    try {
        [$s] = http($srv->port, "NONSENSE\r\n\r\n");
        check($s === 400, "malformed: $s");
        [$s] = http($srv->port, "POST /echo HTTP/1.1\r\nHost: t\r\nTransfer-Encoding: chunked\r\n\r\n");
        check($s === 411, "chunked: $s");
        [$s] = http($srv->port, "GET / HTTP/1.1\r\nX-Big: " . str_repeat('a', 70000) . "\r\n\r\n");
        check($s === 431, "big header: $s");
        [$s, , $b] = get($srv->port, '/throw');
        check($s === 500 && !str_contains($b, 'secret'), 'exceptions are not leaked to clients');
    } finally {
        $log = $srv->stop();
    }
    check(str_contains($log, 'uncaught LogicException: secret'), 'exception logged');
});

test('server: concurrency > 1 overlaps async I/O in one worker', function () use ($fixtures, &$port): void {
    foreach ([1 => [0.9, 99], 8 => [0, 0.45]] as $conc => [$min, $max]) {
        $srv = new TestServer("$fixtures/app.php", ["--concurrency=$conc"], ++$port);
        try {
            $start = microtime(true);
            $socks = [];
            for ($i = 0; $i < 5; $i++) { // 5 requests that each wait 200 ms
                $s = stream_socket_client("tcp://127.0.0.1:$srv->port");
                fwrite($s, "GET /slow HTTP/1.1\r\nHost: t\r\nConnection: close\r\n\r\n");
                $socks[] = $s;
            }
            foreach ($socks as $s) {
                $r = stream_get_contents($s);
                check(str_contains($r, '200 OK'), 'slow response');
            }
            $t = microtime(true) - $start;
            check($t >= $min && $t <= $max, sprintf('concurrency %d: 5 x 200 ms took %.2f s', $conc, $t));
        } finally {
            $srv->stop();
        }
    }
});

test('server: workers are recycled after --max-requests', function () use ($fixtures, &$port): void {
    $srv = new TestServer("$fixtures/app.php", ['--workers=1', '--max-requests=3'], ++$port);
    try {
        $pids = [];
        for ($i = 0; $i < 7; $i++) {
            [$s, , $b] = get($srv->port, '/pid');
            check($s === 200, "status $s");
            $pids[] = $b;
            usleep(150_000); // let the recycle timer see the count
        }
        check(count(array_unique($pids)) >= 3, 'pids: ' . implode(',', $pids));
    } finally {
        $log = $srv->stop();
    }
    check(str_contains($log, 'draining (served 3 requests)'), 'recycle logged');
});

test('server: single-process mode keeps serving past --max-requests', function () use ($fixtures, &$port): void {
    $srv = new TestServer("$fixtures/app.php", ['--max-requests=2'], ++$port);
    try {
        for ($i = 0; $i < 5; $i++) {
            usleep(100_000);
            [$s] = get($srv->port, '/hello');
            check($s === 200, "request $i: status $s");
        }
    } finally {
        $log = $srv->stop();
    }
    check(str_contains($log, 'recycling disabled'), 'warning not logged');
});

test('server: SIGHUP reload replaces workers without failed requests', function () use ($fixtures, &$port): void {
    $srv = new TestServer("$fixtures/app.php", ['--workers=2'], ++$port);
    try {
        $before = [];
        for ($i = 0; $i < 6; $i++) {
            $before[] = get($srv->port, '/pid')[2];
        }
        $srv->signal(SIGHUP);
        $failures = 0;
        $after = [];
        $end = microtime(true) + 1.5;
        while (microtime(true) < $end) {
            [$s, , $b] = get($srv->port, '/pid');
            $s === 200 ? $after[] = $b : $failures++;
        }
        check($failures === 0, "$failures failed requests during reload");
        check(!array_intersect(array_unique(array_slice($after, -6)), array_unique($before)), 'workers were not replaced');
    } finally {
        $srv->stop();
    }
});

test('server: pcntl blocked by disable_functions (aaPanel default)', function () use ($fixtures, &$port): void {
    $blocked = ['-d', 'disable_functions=pcntl_fork,pcntl_signal,pcntl_async_signals'];
    $srv = new TestServer("$fixtures/app.php", [], ++$port, $blocked);
    try {
        [$s, , $b] = get($srv->port, '/hello');
        check($s === 200 && $b === 'hello world', "single process: $s $b");
    } finally {
        $log = $srv->stop();
    }
    check(str_contains($log, 'pcntl signals unavailable'), 'missing warning');
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . implode(' ', array_map('escapeshellarg', $blocked)) . ' '
        . escapeshellarg(dirname(__DIR__) . '/bin/qhturbo-serve') . ' ' . escapeshellarg("$fixtures/app.php")
        . ' --port=' . ++$port . ' --workers=2 2>&1');
    check(str_contains((string) $out, 'blocked by disable_functions'), "unclear error: $out");
});

test('state: leaked globals are removed; strict mode reports them', function () use ($fixtures, &$port): void {
    $srv = new TestServer("$fixtures/app.php", [], ++$port);
    try {
        get($srv->port, '/leak-global');
        [, , $b] = get($srv->port, '/read-global');
        check($b === 'absent', "global survived: $b");
    } finally {
        $log = $srv->stop();
    }
    check(str_contains($log, 'global variable $qht_leak created during a request'), 'violation logged');

    $srv = new TestServer("$fixtures/app.php", ['--strict-state'], ++$port);
    try {
        [$s] = get($srv->port, '/leak-global');
        check($s === 500, "strict mode status $s");
    } finally {
        $srv->stop();
    }
});

test('state: growing static property is detected', function () use ($fixtures, &$port): void {
    $srv = new TestServer("$fixtures/app.php", ['--state-check-every=1'], ++$port);
    try {
        for ($i = 0; $i < 5; $i++) {
            get($srv->port, '/leak-static');
        }
    } finally {
        $log = $srv->stop();
    }
    check(str_contains($log, 'static property LeakyCache::$items keeps growing'), 'static leak not reported');
});

test('state: superglobals for legacy code, reset hooks and ini restore', function () use ($fixtures, &$port): void {
    $srv = new TestServer("$fixtures/app.php", ['--superglobals'], ++$port);
    try {
        [$s, , $b] = http($srv->port, "POST /legacy?x=1 HTTP/1.1\r\nHost: t\r\nCookie: sid=abc\r\nContent-Type: application/x-www-form-urlencoded\r\nContent-Length: 3\r\nConnection: close\r\n\r\ny=2");
        check($s === 200 && json_decode($b, true) === ['get' => ['x' => '1'], 'post' => ['y' => '2'], 'cookie' => ['sid' => 'abc'], 'method' => 'POST'], "legacy: $b");
        [, , $b] = get($srv->port, '/legacy');
        check(json_decode($b, true)['post'] === [], 'POST data leaked into next request');
        get($srv->port, '/set-user?u=alice');
        [, , $b] = get($srv->port, '/user');
        check($b === 'guest', "reset hook did not run: $b");
        get($srv->port, '/change-tz');
        [, , $b] = get($srv->port, '/tz');
        check($b === 'UTC', "ini leaked: $b");
    } finally {
        $srv->stop();
    }
});

// ------------------------------------------------------------------ run ----

$filter = $argv[1] ?? '';
$failed = 0;
foreach ($tests as $name => $fn) {
    if ($filter !== '' && !str_contains($name, $filter)) {
        continue;
    }
    $t = microtime(true);
    try {
        $fn();
        printf("ok    %-62s %.2fs\n", $name, microtime(true) - $t);
    } catch (Throwable $e) {
        $failed++;
        printf("FAIL  %s\n      %s\n", $name, $e->getMessage());
        if (getenv('GITHUB_ACTIONS')) {
            // Annotation: readable in the run summary and through the API.
            $msg = str_replace(["%", "\r", "\n"], ['%25', '%0D', '%0A'], $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            printf("::error title=PHP %s %s::%s\n", PHP_VERSION, $name, $msg);
        }
    }
}
printf("\n%s (PHP %s)\n", $failed ? "$failed FAILED" : 'all passed', PHP_VERSION);
exit($failed ? 1 : 0);
