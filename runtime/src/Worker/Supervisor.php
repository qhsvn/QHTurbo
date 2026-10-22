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
        if (!function_exists('pcntl_fork')) {
            throw new \RuntimeException('--workers needs the pcntl extension; use --workers=0');
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
        $address = sprintf('tcp://%s:%d', $this->options->host, $this->options->port);
        $listener = @stream_socket_server($address, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
        if ($listener === false) {
            throw new \RuntimeException("Cannot listen on $address: $errstr ($errno)");
        }
        return $listener;
    }

    /** @param resource $listener */
    private function spawn($listener): void
    {
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new \RuntimeException('fork failed');
        }
        if ($pid === 0) {
            // Child: fresh signal handlers and event loop, then serve.
            pcntl_signal(SIGHUP, SIG_IGN);
            Loop::reset();
            exit((new Worker($this->appFile, $this->options))->run($listener));
        }
        $this->workers[$pid] = microtime(true);
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
     * serving and new code is loaded without dropping requests.
     */
    private function reload(): void
    {
        $this->log('reload: restarting workers one by one');
        if (function_exists('opcache_reset')) {
            opcache_reset();
        }
        foreach (array_keys($this->workers) as $pid) {
            if ($this->shuttingDown) {
                return;
            }
            $this->spawn($this->listener);
            posix_kill($pid, SIGTERM);
            pcntl_waitpid($pid, $status);
            unset($this->workers[$pid]);
        }
    }

    private function log(string $message): void
    {
        fwrite(STDERR, sprintf("[qhturbo %s master pid=%d] %s\n", date('H:i:s'), getmypid(), $message));
    }
}
