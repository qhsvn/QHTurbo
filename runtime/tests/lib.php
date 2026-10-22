<?php

declare(strict_types=1);

// Shared helpers for the runtime test suites (run.php, frameworks.php).

require_once dirname(__DIR__) . '/src/autoload.php';

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

final class Skip extends RuntimeException
{
}

final class TestServer
{
    /** @var resource */
    private $proc;
    public string $log = '';
    private string $logFile;

    /** @param list<string> $phpArgs e.g. ['-d', 'disable_functions=...'] */
    /** @param array<string, string> $env extra environment variables */
    public function __construct(string $app, array $args, public int $port, array $phpArgs = [], array $env = [])
    {
        $this->logFile = tempnam(sys_get_temp_dir(), 'qht');
        $cmd = array_merge([PHP_BINARY], $phpArgs, [dirname(__DIR__) . '/bin/qhturbo-serve', $app, "--port=$port"], $args);
        $this->proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $this->logFile, 'w'], 2 => ['file', $this->logFile, 'w']], $pipes, null, $env ? $env + getenv() : null);
        for ($i = 0; $i < 300; $i++) { // frameworks may take a few seconds to boot
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

    /** Wait until the server process exits; false on timeout. */
    public function waitExit(float $timeout): bool
    {
        $end = microtime(true) + $timeout;
        while (proc_get_status($this->proc)['running']) {
            if (microtime(true) > $end) {
                return false;
            }
            usleep(20_000);
        }
        return true;
    }

    public function logSoFar(): string
    {
        return (string) file_get_contents($this->logFile);
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

/** Run the registered tests (optionally filtered by $argv[1]); exits. */
function run_tests(): never
{
    global $tests, $argv;
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
        } catch (Skip $e) {
            printf("skip  %-62s %s\n", $name, $e->getMessage());
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
}
