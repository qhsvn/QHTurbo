<?php

declare(strict_types=1);

namespace QHTurbo\Runtime\Worker;

use QHTurbo\Runtime\EventLoop\Loop;
use QHTurbo\Runtime\Http\Server;
use QHTurbo\Runtime\State\ResetInterface;
use QHTurbo\Runtime\State\StateGuard;

/**
 * One long-lived worker: bootstraps the application ONCE, then serves
 * requests until it is told to stop or reaches a recycling limit.
 */
final class Worker
{
    public function __construct(
        private readonly string $appFile,
        private readonly Options $options,
    ) {
    }

    /**
     * @param resource $listener
     * @return int exit code
     */
    public function run($listener): int
    {
        $log = static fn (string $msg) => fwrite(STDERR, sprintf("[qhturbo %s pid=%d] %s\n", date('H:i:s'), getmypid(), $msg));
        $guard = new StateGuard($this->options->strictState, $this->options->stateCheckEvery, \Closure::fromCallable($log));

        $handler = self::bootstrap($this->appFile, $guard);
        $guard->captureBaseline();

        $loop = Loop::get();
        $server = new Server($handler, $this->options, $guard, \Closure::fromCallable($log));
        $server->listen($listener);

        $stopping = false;
        $stop = static function (string $why) use (&$stopping, $server, $loop, $log): void {
            if ($stopping) {
                return;
            }
            $stopping = true;
            $log("draining ($why)");
            $server->drain(static fn () => $loop->stop());
        };

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, static fn () => $stop('SIGTERM'));
            pcntl_signal(SIGINT, static fn () => $stop('SIGINT'));
        }

        // Recycling limits are checked between requests.
        $loop->repeat(0.05, function () use ($server, $stop): void {
            if ($this->options->maxRequests > 0 && $server->served() >= $this->options->maxRequests) {
                $stop("served {$server->served()} requests");
            } elseif ($this->options->memoryLimitBytes > 0 && memory_get_usage(true) > $this->options->memoryLimitBytes) {
                $stop(sprintf('memory %.1f MiB over limit', memory_get_usage(true) / 1048576));
            }
        });

        $loop->run();
        return 0;
    }

    /**
     * The app file is required inside this function, so its variables are
     * not globals. It must return the request handler:
     *   - a callable(Request): Response|string|array, or
     *   - an object with handle(Request) (and optionally reset()).
     */
    private static function bootstrap(string $appFile, StateGuard $guard): callable
    {
        $app = require $appFile;
        if (is_object($app) && !is_callable($app) && method_exists($app, 'handle')) {
            if ($app instanceof ResetInterface) {
                $guard->addResetter($app);
            }
            return [$app, 'handle'];
        }
        if (!is_callable($app)) {
            throw new \UnexpectedValueException("$appFile must return a callable or an object with handle()");
        }
        if ($app instanceof ResetInterface) {
            $guard->addResetter($app);
        }
        return $app;
    }
}
