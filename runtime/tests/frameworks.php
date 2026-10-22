<?php

declare(strict_types=1);

// Framework adapter tests: runs real Laravel / Symfony applications through
// qhturbo-serve and checks behaviour plus state isolation between requests.
//
//   QHT_TEST_LARAVEL=/path/to/laravel QHT_TEST_SYMFONY=/path/to/symfony \
//     php runtime/tests/frameworks.php [filter]
//
// The applications are modified: test routes/controllers are copied in.
// Use throw-away projects (composer create-project laravel/laravel ...).
// Needs ext-curl. A suite is skipped when its path is not set.

require __DIR__ . '/lib.php';

$fixtures = __DIR__ . '/fixtures';
$port = 18700;

/** Minimal browser: one cookie jar per instance. */
final class Browser
{
    private string $jar;

    public function __construct(private int $port)
    {
        $this->jar = (string) tempnam(sys_get_temp_dir(), 'qhtjar');
    }

    /**
     * @param array<int, mixed> $curlOptions
     * @return array{0: int, 1: string, 2: string} status, raw headers, body
     */
    public function req(string $method, string $path, array $curlOptions = []): array
    {
        $ch = curl_init("http://127.0.0.1:{$this->port}$path");
        curl_setopt_array($ch, $curlOptions + [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_COOKIEFILE => $this->jar, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_TIMEOUT => 20,
        ]);
        $raw = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        unset($ch); // writes the cookie jar
        return [$status, substr($raw, 0, $headerSize), substr($raw, $headerSize)];
    }

    public function get(string $path): string
    {
        [$status, , $body] = $this->req('GET', $path);
        check($status === 200, "GET $path -> $status: " . substr($body, 0, 300));
        return $body;
    }
}

// ------------------------------------------------------------- Laravel ----

function laravelApp(): string
{
    $dir = getenv('QHT_TEST_LARAVEL') ?: '';
    if ($dir === '' || !is_file("$dir/artisan")) {
        throw new Skip('set QHT_TEST_LARAVEL to a throw-away Laravel app');
    }
    copy(__DIR__ . '/fixtures/laravel/qhturbo-test-routes.php', "$dir/routes/qhturbo-test-routes.php");
    $web = (string) file_get_contents("$dir/routes/web.php");
    if (!str_contains($web, 'qhturbo-test-routes.php')) {
        file_put_contents("$dir/routes/web.php", $web . "\nrequire __DIR__ . '/qhturbo-test-routes.php';\n");
    }
    // A route cache (e.g. left by a benchmark) would hide the test routes.
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$dir/artisan") . ' route:clear -q 2>&1', $out, $code);
    check($code === 0, 'route:clear failed: ' . implode("\n", $out));
    return $dir;
}

function laravelServer(int $port, array $args = []): TestServer
{
    return new TestServer(laravelApp(), array_merge(['--framework=laravel', '--state-check-every=1'], $args), $port, [],
        ['APP_DEBUG' => 'false', 'APP_ENV' => 'production', 'LOG_CHANNEL' => 'stderr', 'LOG_LEVEL' => 'error']);
}

test('laravel: boots once, serves routes, JSON, 404, safe 500', function () use (&$port): void {
    $srv = laravelServer(++$port);
    try {
        $b = new Browser($srv->port);
        check($b->get('/qht/hello') === 'hello', 'hello');
        $pids = array_unique([$b->get('/qht/pid'), $b->get('/qht/pid'), $b->get('/qht/pid')]);
        check(count($pids) === 1, 'one long-lived worker expected');
        $json = json_decode($b->get('/qht/json'), true);
        check(($json['framework'] ?? '') === 'laravel' && count($json['items']) === 10, 'json');
        [$s] = $b->req('GET', '/qht/nope');
        check($s === 404, "404 expected, got $s");
        [$s, , $body] = $b->req('GET', '/qht/boom');
        check($s === 500 && !str_contains($body, 'secret detail'), "500 without details, got $s");
        [$s, , $body] = $b->req('GET', '/');
        check($s === 200 && stripos($body, 'laravel') !== false, "welcome page $s");
    } finally {
        $srv->stop();
    }
});

test('laravel: sessions are isolated between users', function () use (&$port): void {
    $srv = laravelServer(++$port);
    try {
        $alice = new Browser($srv->port);
        $bob = new Browser($srv->port);
        foreach ([1, 2, 3] as $n) {
            check($alice->get('/qht/session/inc') === (string) $n, "alice $n");
        }
        check($bob->get('/qht/session/inc') === '1', 'bob sees alice\'s session');
        check($alice->get('/qht/session/inc') === '4', 'alice lost her session');
    } finally {
        $srv->stop();
    }
});

test('laravel: auth, queued cookies, config, locale do not leak', function () use (&$port): void {
    $srv = laravelServer(++$port);
    try {
        $b = new Browser($srv->port);
        $b->get('/qht/user/set/alice');
        check($b->get('/qht/user/whoami') === 'guest', 'authenticated user leaked into the next request');
        [, $headers] = $b->req('GET', '/qht/cookie');
        check(str_contains($headers, 'qht_leak='), 'queued cookie missing');
        [, $headers] = (new Browser($srv->port))->req('GET', '/qht/hello');
        check(!str_contains($headers, 'qht_leak='), 'queued cookie leaked to another user');
        $b->get('/qht/config/set');
        check($b->get('/qht/config/get') === 'default', 'runtime config change leaked');
        $b->get('/qht/config/add');
        check($b->get('/qht/config/new') === 'null', 'config key added during a request leaked');
        $b->get('/qht/hello');
        $b->get('/qht/locale/set');
        check($b->get('/qht/locale/get') === 'en', 'locale leaked');
    } finally {
        $log = $srv->stop();
    }
    // Restored once per changing request (config set, config add, setLocale
    // also writes app.locale), not again on every later request.
    check(substr_count($log, 'config changed during a request') <= 3, "config restored too often:\n$log");
});

test('laravel: open DB transaction is rolled back after the request', function () use (&$port): void {
    $srv = laravelServer(++$port);
    try {
        $b = new Browser($srv->port);
        $b->get('/qht/db/open-tx');
        $state = json_decode($b->get('/qht/db/state'), true);
        check($state === ['level' => 0, 'tx_rows' => 0], 'state ' . json_encode($state));
    } finally {
        $log = $srv->stop();
    }
    check(str_contains($log, 'left a transaction open: rolled back'), 'rollback not logged');
});

test('laravel: file upload and JSON body', function () use (&$port): void {
    $srv = laravelServer(++$port);
    try {
        $b = new Browser($srv->port);
        $file = tempnam(sys_get_temp_dir(), 'qhtdoc');
        file_put_contents($file, "Xin chào\n");
        [$s, , $body] = $b->req('POST', '/qht/upload', [CURLOPT_POSTFIELDS => [
            'title' => 'Báo cáo', 'doc' => new CURLFile($file, 'text/plain', 'bao-cao.txt')]]);
        $j = json_decode($body, true);
        check($s === 200 && $j === ['title' => 'Báo cáo', 'name' => 'bao-cao.txt', 'size' => 10, 'valid' => true, 'content' => "Xin chào\n"],
            "upload $s $body");
        [$s, , $body] = $b->req('POST', '/qht/json-body', [CURLOPT_POSTFIELDS => '{"a":5}', CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
        check($s === 200 && json_decode($body, true) === ['got' => 5], "json body $s $body");
        unlink($file);
    } finally {
        $log = $srv->stop();
    }
    check(!str_contains($log, 'connection error') && !str_contains($log, 'uncaught'), "errors in log:\n$log");
});

// ------------------------------------------------------------- Symfony ----

function symfonyApp(): string
{
    $dir = getenv('QHT_TEST_SYMFONY') ?: '';
    if ($dir === '' || !is_file("$dir/bin/console")) {
        throw new Skip('set QHT_TEST_SYMFONY to a throw-away Symfony app');
    }
    foreach (['QhtController.php', 'QhtCounter.php'] as $f) {
        copy(__DIR__ . "/fixtures/symfony/$f", "$dir/src/Controller/$f");
    }
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$dir/bin/console") . ' cache:clear --env=prod --no-debug -q 2>&1', $out, $code);
    check($code === 0, 'cache:clear failed: ' . implode("\n", $out));
    return $dir;
}

function symfonyServer(int $port): TestServer
{
    return new TestServer(symfonyApp(), ['--framework=symfony'], $port, [], ['APP_ENV' => 'prod', 'APP_DEBUG' => '0']);
}

test('symfony: routes, services reset between requests, cookies, upload, safe 500', function () use (&$port): void {
    $srv = symfonyServer(++$port);
    try {
        $b = new Browser($srv->port);
        check($b->get('/qht/hello') === 'hello', 'hello');
        check(count(array_unique([$b->get('/qht/pid'), $b->get('/qht/pid')])) === 1, 'one long-lived worker expected');
        check([$b->get('/qht/counter'), $b->get('/qht/counter'), $b->get('/qht/counter')] === ['1', '1', '1'],
            'ResetInterface services were not reset between requests');
        [, $headers] = $b->req('GET', '/qht/cookie');
        check(preg_match_all('/^Set-Cookie: (a=1|b=2)/mi', $headers) === 2, "two Set-Cookie headers expected:\n$headers");
        $file = tempnam(sys_get_temp_dir(), 'qhtdoc');
        file_put_contents($file, 'abc');
        [$s, , $body] = $b->req('POST', '/qht/upload', [CURLOPT_POSTFIELDS => ['title' => 't', 'doc' => new CURLFile($file, 'text/plain', 'a.txt')]]);
        check(json_decode($body, true) === ['title' => 't', 'name' => 'a.txt', 'size' => 3, 'valid' => true], "upload $s $body");
        unlink($file);
        [$s] = $b->req('GET', '/qht/nope');
        check($s === 404, "404 expected, got $s");
        [$s, , $body] = $b->req('GET', '/qht/boom');
        check($s === 500 && !str_contains($body, 'secret detail'), "500 without details, got $s");
        [$s, , $body] = $b->req('GET', '/qht/stream');
        check($s === 200 && $body === 'abc', "streamed response with ob_flush(): $s '$body'");
        [$s, $headers, $body] = $b->req('HEAD', '/qht/hello', [CURLOPT_NOBODY => true]);
        // Symfony drops the body of HEAD responses: no length rather than a wrong "0".
        check($s === 200 && $body === '' && stripos($headers, 'Content-Length: 0') === false, "HEAD Content-Length:\n$headers");
    } finally {
        $log = $srv->stop();
    }
    check(!str_contains($log, 'abc'), "streamed output leaked to the worker's stdout:\n$log");
});

run_tests();
