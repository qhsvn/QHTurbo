<?php

declare(strict_types=1);

namespace QHTurbo\Runtime\Worker;

use QHTurbo\Runtime\EventLoop\Loop;

/**
 * Master process: owns the listening socket, forks workers, restarts them
 * when they exit (recycling or crash), reloads on SIGHUP and stops
 * gracefully on SIGTERM/SIGINT.
 */
final class Supervisor
{
    /** Seconds a new worker may take to boot during a reload. */
    private const BOOT_TIMEOUT = 60.0;
    /** Seconds an old worker may take to finish its requests during a reload. */
    private const DRAIN_TIMEOUT = 60.0;

    /** @var array<int, float> pid => start time */
    private array $workers = [];
    private bool $shuttingDown = false;
    private bool $reloadRequested = false;
    private int $quickExits = 0;
    /** @var resource|null */
    private $listener = null;

    public function __construct(
        private readonly string $appFile,
        private readonly Options $options,
    ) {
    }

    public function run(): int
    {
        $listener = $this->listener = $this->listen();
        $this->log(sprintf('listening on http://%s:%d (%s, concurrency %d, PHP %s)',
            $this->options->host, $this->options->port,
            $this->options->workers > 0 ? "{$this->options->workers} workers" : 'single process',
            $this->options->concurrency, PHP_VERSION));

        if ($this->options->workers === 0) {
            // Nobody would restart a recycled single process, so recycling
            // limits would take the service down. Disable them.
            if ($this->options->maxRequests > 0 || $this->options->memoryLimitBytes > 0) {
                $this->log('single-process mode: --max-requests/--memory-limit recycling disabled (use --workers>=1 to enable it)');
                $this->options->maxRequests = 0;
                $this->options->memoryLimitBytes = 0;
            }
            return (new Worker($this->appFile, $this->options))->run($listener);
        }
        $missing = self::missingFunctions(['pcntl_fork', 'pcntl_wait', 'pcntl_waitpid', 'pcntl_signal',
            'pcntl_async_signals', 'pcntl_wifexited', 'pcntl_wexitstatus', 'posix_kill']);
        if ($missing) {
            throw new \RuntimeException('--workers needs ' . implode(', ', $missing) . '. They are '
                . (extension_loaded('pcntl') ? 'blocked by disable_functions in php.ini (aaPanel blocks pcntl_* by default); '
                    . 'run workers with the Performance ini (PHP_INI_SCAN_DIR=<prefix>/etc/conf.d:<prefix>/etc/performance.d) '
                    : 'missing: build PHP with --enable-pcntl and posix ')
                . 'or use --workers=0');
        }

        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, fn () => $this->shutdown());
        pcntl_signal(SIGINT, fn () => $this->shutdown());
        pcntl_signal(SIGHUP, function (): void {
            $this->reloadRequested = true;
        });

        for ($i = 0; $i < $this->options->workers; $i++) {
            $this->spawn($listener);
        }

        while ($this->workers) {
            if ($this->reloadRequested) {
                $this->reloadRequested = false;
                $this->reload();
            }
            $pid = pcntl_wait($status, WNOHANG);
            if ($pid <= 0) {
                usleep(50_000);
                continue;
            }
            $started = $this->workers[$pid] ?? microtime(true);
            unset($this->workers[$pid]);
            if ($this->shuttingDown) {
                continue;
            }
            $code = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : -1;
            if ($code !== 0) {
                $this->log("worker $pid died (status $code), restarting");
            }
            // Crash-loop protection: back off when workers die right away.
            $this->quickExits = microtime(true) - $started < 1.0 ? $this->quickExits + 1 : 0;
            if ($this->quickExits >= 5) {
                $this->log('workers keep exiting immediately; waiting 5 s before restarting');
                sleep(5);
                $this->quickExits = 0;
                if ($this->shuttingDown) { // SIGTERM arrived during the sleep
                    continue;
                }
            }
            $this->spawn($listener);
        }
        $this->log('all workers stopped');
        return 0;
    }

    /** @return resource */
    private function listen()
    {
        $context = stream_context_create(['socket' => ['backlog' => $this->options->backlog, 'so_reuseaddr' => true]]);
        $host = $this->options->host;
        $address = sprintf(str_contains($host, ':') && $host[0] !== '[' ? 'tcp://[%s]:%d' : 'tcp://%s:%d', $host, $this->options->port);
        $listener = @stream_socket_server($address, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
        if ($listener === false) {
            throw new \RuntimeException("Cannot listen on $address: $errstr ($errno)");
        }
        return $listener;
    }

    /**
     * @param resource $listener
     * @return array{0: int, 1: resource|null} pid, and the read end of a pipe
     *         that receives one byte once the worker is ready to serve
     */
    private function spawn($listener, bool $withReady = false): array
    {
        $pair = $withReady ? stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP) : false;
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new \RuntimeException('fork failed');
        }
        if ($pid === 0) {
            // Child: drop the master's handlers first (a SIGTERM during the
            // bootstrap must stop this worker, not run the master's
            // shutdown()), then a fresh event loop, then serve.
            pcntl_signal(SIGTERM, SIG_DFL);
            pcntl_signal(SIGINT, SIG_DFL);
            pcntl_signal(SIGHUP, SIG_IGN);
            Loop::reset();
            $onReady = null;
            if ($pair) {
                fclose($pair[0]);
                $onReady = static function () use ($pair): void {
                    @fwrite($pair[1], '1');
                    @fclose($pair[1]);
                };
            }
            exit((new Worker($this->appFile, $this->options))->run($listener, $onReady));
        }
        $this->workers[$pid] = microtime(true);
        if ($pair) {
            fclose($pair[1]);
            return [$pid, $pair[0]];
        }
        return [$pid, null];
    }

    private function shutdown(): void
    {
        if ($this->shuttingDown) {
            return;
        }
        $this->shuttingDown = true;
        $this->log('shutting down: draining workers');
        foreach (array_keys($this->workers) as $pid) {
            posix_kill($pid, SIGTERM);
        }
    }

    /**
     * Rolling restart: replace workers one at a time so the others keep
     * serving and new code is loaded without dropping requests. An old
     * worker is stopped only after its replacement has booted; if the new
     * code cannot boot, the reload stops and the old workers keep serving.
     */
    private function reload(): void
    {
        $this->log('reload: restarting workers one by one');
        $this->invalidateOpcache();
        foreach (array_keys($this->workers) as $pid) {
            if ($this->shuttingDown) {
                return;
            }
            [$newPid, $ready] = $this->spawn($this->listener, true);
            if (!$this->waitReady($newPid, $ready)) {
                $this->log("reload aborted: new worker $newPid did not start (see its errors above); old workers keep serving the previous code");
                return;
            }
            posix_kill($pid, SIGTERM);
            $this->waitExit($pid, self::DRAIN_TIMEOUT);
        }
        $this->log('reload: done');
    }

    /** @param resource $ready */
    private function waitReady(int $pid, $ready): bool
    {
        $deadline = microtime(true) + self::BOOT_TIMEOUT;
        try {
            while (microtime(true) < $deadline && !$this->shuttingDown) {
                $read = [$ready];
                $write = $except = null;
                if (@stream_select($read, $write, $except, 0, 100_000) > 0) {
                    if (fread($ready, 1) === '1') {
                        return true;
                    }
                    break; // closed without the ready byte: the worker died while booting
                }
                if (pcntl_waitpid($pid, $status, WNOHANG) === $pid) {
                    unset($this->workers[$pid]);
                    return false;
                }
            }
        } finally {
            fclose($ready);
        }
        if (isset($this->workers[$pid]) && !$this->shuttingDown) { // on shutdown it already got SIGTERM
            posix_kill($pid, SIGKILL);
            $this->waitExit($pid, 5.0);
        }
        return false;
    }

    /** Wait for $pid to exit; SIGKILL it after $timeout seconds. */
    private function waitExit(int $pid, float $timeout): void
    {
        $deadline = microtime(true) + $timeout;
        while (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
            if (microtime(true) > $deadline) {
                $this->log("worker $pid did not stop within {$timeout} s: killed");
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
                break;
            }
            usleep(20_000);
        }
        unset($this->workers[$pid]);
    }

    /**
     * Make the next workers compile the application files again.
     * opcache_reset() does not work here: in a CLI process the reset only
     * happens at the next request startup, which never comes, and until
     * then OPcache stops caching. Invalidating each cached script works
     * immediately in the shared memory that all workers use (with
     * opcache.validate_timestamps=0 old code would otherwise stay).
     */
    private function invalidateOpcache(): void
    {
        if (!function_exists('opcache_get_status') || !function_exists('opcache_invalidate')) {
            return;
        }
        $status = @opcache_get_status(true);
        if (!is_array($status)) {
            return;
        }
        foreach (array_keys($status['scripts'] ?? []) as $file) {
            @opcache_invalidate((string) $file, true);
        }
    }

    /**
     * Functions from $names that cannot be called: extension missing or
     * listed in disable_functions (PHP 8 removes disabled functions).
     *
     * @param list<string> $names
     * @return list<string>
     */
    public static function missingFunctions(array $names): array
    {
        return array_values(array_filter($names, static fn (string $f): bool => !function_exists($f)));
    }

    private function log(string $message): void
    {
        fwrite(STDERR, sprintf("[qhturbo %s master pid=%d] %s\n", date('H:i:s'), getmypid(), $message));
    }
}
