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
     * @param (\Closure(): void)|null $onReady called once the application has booted
     * @return int exit code
     */
    public function run($listener, ?\Closure $onReady = null): int
    {
        $log = static fn (string $msg) => fwrite(STDERR, sprintf("[qhturbo %s pid=%d] %s\n", date('H:i:s'), getmypid(), $msg));
        $guard = new StateGuard($this->options->strictState, $this->options->stateCheckEvery, \Closure::fromCallable($log));

        $handler = match ($this->options->framework) {
            'laravel' => self::register(new \QHTurbo\Runtime\Bridge\Laravel($this->appFile, \Closure::fromCallable($log)), $guard),
            'symfony' => self::register(new \QHTurbo\Runtime\Bridge\Symfony($this->appFile), $guard),
            default => self::bootstrap($this->appFile, $guard),
        };
        $guard->captureBaseline();

        $loop = Loop::get();
        $server = new Server($handler, $this->options, $guard, \Closure::fromCallable($log));
        $server->listen($listener);
        if ($onReady !== null) {
            $onReady();
        }

        $stopping = false;
        $stop = static function (string $why) use (&$stopping, $server, $loop, $log): void {
            if ($stopping) {
                return;
            }
            $stopping = true;
            $log("draining ($why)");
            $server->drain(static fn () => $loop->stop());
        };

        if (Supervisor::missingFunctions(['pcntl_async_signals', 'pcntl_signal']) === []) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, static fn () => $stop('SIGTERM'));
            pcntl_signal(SIGINT, static fn () => $stop('SIGINT'));
        } else {
            $log('pcntl signals unavailable (extension missing or disable_functions): SIGTERM stops immediately, without draining');
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
        return self::register($app, $guard, $appFile);
    }

    private static function register(mixed $app, StateGuard $guard, string $appFile = 'bridge'): callable
    {
        $handler = is_object($app) && !is_callable($app) && method_exists($app, 'handle') ? [$app, 'handle'] : $app;
        if (!is_callable($handler)) {
            throw new \UnexpectedValueException("$appFile must return a callable or an object with handle()");
        }
        if ($app instanceof ResetInterface) {
            $guard->addResetter($app);
        }
        return $handler;
    }
}
