<?php

declare(strict_types=1);

// Runtime test suite (no dependencies): php runtime/tests/run.php [filter]
// Unit tests run in-process; server tests start bin/qhturbo-serve with the
// same PHP binary and talk to it over TCP.

require __DIR__ . '/lib.php';

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

// --------------------------------------------------- redis / mysql ----
// Need servers: QHT_TEST_REDIS (default redis://127.0.0.1:6379/15) and
// QHT_TEST_MYSQL (default mysql://qht:qhtpass@127.0.0.1:3306/qht).
// Skipped (not failed) when the server is unreachable.

function redisOrSkip(int $pool = 4): QHTurbo\Runtime\Redis\Client
{
    $uri = getenv('QHT_TEST_REDIS') ?: 'redis://127.0.0.1:6379/15';
    $client = new QHTurbo\Runtime\Redis\Client($uri, $pool, 2.0);
    try {
        await(async(fn () => $client->ping()));
    } catch (Throwable $e) {
        throw new Skip("no Redis at $uri");
    }
    return $client;
}

function mysqlOrSkip(int $pool = 4, float $queryTimeout = 30.0, ?Closure $log = null): QHTurbo\Runtime\Mysql\Client
{
    $cfg = QHTurbo\Runtime\Mysql\Config::fromUri(getenv('QHT_TEST_MYSQL') ?: 'mysql://qht:qhtpass@127.0.0.1:3306/qht');
    $cfg->poolSize = $pool;
    $cfg->queryTimeout = $queryTimeout;
    $client = new QHTurbo\Runtime\Mysql\Client($cfg, $log);
    try {
        await(async(fn () => $client->query('SELECT 1')));
    } catch (Throwable $e) {
        throw new Skip('no MySQL: ' . $e->getMessage());
    }
    return $client;
}

test('redis: commands, binary data, errors keep the connection', function (): void {
    Loop::reset();
    $r = redisOrSkip(1);
    await(async(function () use ($r) {
        $r->del('qht:a', 'qht:list');
        check($r->set('qht:a', "bin\r\n\0ary ✓") === 'OK', 'SET');
        check($r->get('qht:a') === "bin\r\n\0ary ✓", 'binary-safe GET');
        check($r->get('qht:missing') === null, 'missing key is null');
        check($r->incr('qht:n') >= 1, 'INCR returns int');
        $r->rpush('qht:list', 'x', 'y');
        check($r->lrange('qht:list', 0, -1) === ['x', 'y'], 'array reply');
        try {
            $r->lpush('qht:a', 'z'); // WRONGTYPE
            check(false, 'no error');
        } catch (QHTurbo\Runtime\Redis\RedisException $e) {
            check(str_starts_with($e->getMessage(), 'WRONGTYPE'), $e->getMessage());
        }
        check($r->get('qht:a') !== null && $r->poolStats()['size'] === 1, 'connection reused after an error reply');
        $replies = $r->pipeline(array_map(fn ($i) => ['SET', "qht:p$i", (string) $i], range(1, 500)));
        check(count($replies) === 500 && $replies[499] === 'OK', 'pipeline');
    }));
});

test('redis: pool runs commands concurrently and limits connections', function (): void {
    foreach ([5 => [0.15, 0.45], 1 => [0.9, 3.0]] as $pool => [$min, $max]) {
        Loop::reset();
        $r = redisOrSkip($pool);
        $t = microtime(true);
        await(async(fn () => all(array_map(fn ($i) => async(fn () => $r->blpop("qht:empty$i", '0.2')), range(1, 5)))));
        $el = microtime(true) - $t;
        check($el >= $min && $el <= $max, sprintf('pool %d: 5 x BLPOP 0.2 s took %.2f s', $pool, $el));
        check($r->poolStats()['size'] <= $pool, 'pool exceeded its size');
    }
});

test('redis: recovers after the server kills the connection', function (): void {
    Loop::reset();
    $r = redisOrSkip(1);
    $admin = redisOrSkip(1);
    await(async(function () use ($r, $admin) {
        $id = $r->client('ID');
        $admin->client('KILL', 'ID', (string) $id);
        try {
            $r->ping();
        } catch (QHTurbo\Runtime\Redis\RedisException) {
            // first use of the dead connection fails and discards it
        }
        check($r->ping() === 'PONG', 'no reconnect');
    }));
});

test('mysql: params, types, escaping, errors', function (): void {
    Loop::reset();
    $db = mysqlOrSkip(1);
    await(async(function () use ($db) {
        $db->query('DROP TABLE IF EXISTS qht_t');
        $db->query('CREATE TABLE qht_t (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) UNIQUE, score DOUBLE, flag TINYINT, note TEXT NULL)');
        $evil = "O'Brien \"quoted\" \\ backslash ? not-a-placeholder; DROP TABLE qht_t; -- ✓ tiếng Việt";
        $ins = $db->query('INSERT INTO qht_t (name, score, flag, note) VALUES (?, ?, ?, ?)', [$evil, 1.5, true, null]);
        check($ins->insertId === 1 && $ins->affectedRows === 1, 'insert id / affected rows');
        $db->query("INSERT INTO qht_t (name, score, flag) VALUES ('lit?eral', ?, ?)", [2, false]);
        $row = $db->query('SELECT * FROM qht_t WHERE name = ?', [$evil])->first();
        check($row['name'] === $evil && $row['score'] === 1.5 && $row['flag'] === 1 && $row['note'] === null && $row['id'] === 1, 'round trip: ' . json_encode($row));
        check($db->query('SELECT name FROM qht_t WHERE id = 2')->value() === 'lit?eral', '? inside a string literal untouched');
        check(count($db->query('SELECT id FROM qht_t WHERE id IN (?)', [[1, 2, 99]])) === 2, 'IN (?) with an array');
        try {
            $db->query('INSERT INTO qht_t (name) VALUES (?)', [$evil]);
            check(false, 'duplicate accepted');
        } catch (QHTurbo\Runtime\Mysql\QueryException $e) {
            check($e->getCode() === 1062, 'error code ' . $e->getCode());
        }
        check($db->query('SELECT COUNT(*) FROM qht_t')->value() === 2 && $db->poolStats()['size'] === 1, 'connection reused after an SQL error');
    }));
});

test('mysql: queries from concurrent requests run in parallel', function (): void {
    foreach ([5 => [0.15, 0.6], 1 => [0.95, 3.0]] as $pool => [$min, $max]) {
        Loop::reset();
        $db = mysqlOrSkip($pool);
        $t = microtime(true);
        $r = await(async(fn () => all(array_map(fn ($i) => async(fn () => $db->query('SELECT SLEEP(0.2) AS s, ? AS i', [$i])->first()['i']), range(1, 5)))));
        $el = microtime(true) - $t;
        check($r === [1, 2, 3, 4, 5], 'results in order');
        check($el >= $min && $el <= $max, sprintf('pool %d: 5 x SLEEP(0.2) took %.2f s', $pool, $el));
    }
});

test('mysql: transactions commit, roll back, never leak', function (): void {
    Loop::reset();
    $logged = [];
    $db = mysqlOrSkip(1, 30.0, function (string $m) use (&$logged) { $logged[] = $m; });
    await(async(function () use ($db, &$logged) {
        $db->query('DROP TABLE IF EXISTS qht_acc');
        $db->query('CREATE TABLE qht_acc (id INT PRIMARY KEY, bal INT) ENGINE=InnoDB');
        $db->query('INSERT INTO qht_acc VALUES (1, 100), (2, 0)');
        $db->transaction(function ($tx) {
            $tx->query('UPDATE qht_acc SET bal = bal - 30 WHERE id = 1');
            $tx->query('UPDATE qht_acc SET bal = bal + 30 WHERE id = 2');
        });
        try {
            $db->transaction(function ($tx) {
                $tx->query('UPDATE qht_acc SET bal = bal - 1000 WHERE id = 1');
                throw new DomainException('insufficient funds');
            });
        } catch (DomainException) {
        }
        $db->withConnection(fn ($c) => $c->query('BEGIN') && $c->query('UPDATE qht_acc SET bal = 0'));
        $bal = array_column($db->query('SELECT id, bal FROM qht_acc ORDER BY id')->rows, 'bal');
        check($bal === [70, 30], 'balances ' . json_encode($bal));
        check(count($logged) === 1 && str_contains($logged[0], 'rolled back'), 'open transaction not reported');
    }));
});

test('mysql: query timeout discards the connection, loop survives', function (): void {
    Loop::reset();
    $db = mysqlOrSkip(1, 0.3);
    await(async(function () use ($db) {
        try {
            $db->query('SELECT SLEEP(2)');
            check(false, 'no timeout');
        } catch (QHTurbo\Runtime\Async\TimeoutException) {
        }
        check($db->poolStats()['size'] === 0, 'timed-out connection kept');
        check($db->query('SELECT 42')->value() === 42, 'pool did not recover');
        delay(0.1); // the poller must not touch the closed connection
    }));
});

test('mysql: recovers after the server kills the connection', function (): void {
    Loop::reset();
    $db = mysqlOrSkip(1);
    $admin = mysqlOrSkip(1);
    await(async(function () use ($db, $admin) {
        $id = $db->query('SELECT CONNECTION_ID()')->value();
        $admin->query('KILL ?', [$id]);
        try {
            $db->query('SELECT 1');
        } catch (QHTurbo\Runtime\Mysql\QueryException) {
            // allowed: lost connection mid-query is not retried automatically
        }
        check($db->query('SELECT 7')->value() === 7, 'no reconnect');
    }));
});

// -------------------------------------------------------------- server ----

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

run_tests();
