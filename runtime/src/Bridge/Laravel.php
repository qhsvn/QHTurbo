<?php

declare(strict_types=1);

namespace QHTurbo\Runtime\Bridge;

use QHTurbo\Runtime\Http\Request;
use QHTurbo\Runtime\Http\Response;
use QHTurbo\Runtime\State\ResetInterface;

/**
 * Runs a Laravel (9-13) application in QHturbo Performance mode.
 *
 *   qhturbo-serve /var/www/app --framework=laravel --workers=4
 *
 * The framework boots ONCE per worker (env, config, service providers,
 * routes). After every request the state that Laravel keeps in its
 * long-lived services is reset, similar to Laravel Octane: auth guards,
 * session data, queued cookies, scoped instances, view sections, DB
 * transactions/query log, translator locale, log context, string caches
 * and runtime config changes.
 *
 * Requests must run one at a time per worker (--concurrency=1): the
 * Laravel container is shared by everything in the process.
 */
final class Laravel implements ResetInterface
{
    /** @var \Illuminate\Foundation\Application */
    private object $app;
    /** @var \Illuminate\Contracts\Http\Kernel */
    private object $kernel;
    /** @var array<string, mixed> */
    private array $config;
    /** @var \Closure(string): void */
    private \Closure $log;

    public function __construct(string $basePath, ?\Closure $log = null)
    {
        $this->log = $log ?? static fn (string $m) => error_log("[qhturbo laravel] $m");
        $basePath = rtrim($basePath, '/');
        if (!is_file("$basePath/bootstrap/app.php") || !is_file("$basePath/vendor/autoload.php")) {
            throw new \RuntimeException("$basePath is not a Laravel application (bootstrap/app.php or vendor/ missing; run composer install)");
        }
        require_once "$basePath/vendor/autoload.php";
        $this->app = require "$basePath/bootstrap/app.php";
        $this->kernel = $this->app->make(\Illuminate\Contracts\Http\Kernel::class);
        $this->kernel->bootstrap();
        // Resolve the common services now, so every request reuses them.
        foreach (['router', 'url', 'view', 'auth', 'session', 'cookie', 'db', 'cache', 'translator',
            'log', 'events', 'encrypter', 'validator', 'hash'] as $service) {
            if ($this->app->bound($service)) {
                try {
                    $this->app->make($service);
                } catch (\Throwable) {
                    // optional service not configured (e.g. no DB): fine
                }
            }
        }
        $this->config = $this->app['config']->all();
        // In-memory drivers keep data for the life of the process: in a
        // long-lived worker they grow without bound and are not shared
        // between workers.
        foreach (['session.driver' => 'SESSION_DRIVER', 'cache.default' => 'CACHE_STORE'] as $key => $env) {
            if ($this->app['config']->get($key) === 'array') {
                ($this->log)("$key is 'array': data accumulates in each worker's memory and is not shared between workers; use file, redis or database ($env)");
            }
        }
    }

    public function handle(Request $request): Response
    {
        $laravelRequest = HttpFoundation::toSymfony($request, \Illuminate\Http\Request::class, \Illuminate\Http\UploadedFile::class);
        $response = $this->kernel->handle($laravelRequest);
        $out = HttpFoundation::fromSymfony($response);
        $this->kernel->terminate($laravelRequest, $response);
        return $out;
    }

    public function reset(): void
    {
        $app = $this->app;
        if ($app->resolved('auth')) {
            $app['auth']->forgetGuards();
        }
        if ($app->resolved('cookie')) {
            $app['cookie']->flushQueuedCookies();
        }
        if ($app->resolved('session')) {
            // The store object is reused; without flush the next request's
            // session would be merged with this one's data.
            $driver = $app['session']->driver();
            $driver->flush();
            $driver->regenerate();
        }
        if ($app->resolved('db')) {
            foreach ($app['db']->getConnections() as $name => $connection) {
                if ($connection->transactionLevel() > 0) {
                    ($this->log)("DB connection '$name' left a transaction open: rolled back (rule R7)");
                    $connection->rollBack(0);
                }
                $connection->forgetRecordModificationState();
                $connection->flushQueryLog();
            }
        }
        if ($app->resolved('view')) {
            $app['view']->flushState();
        }
        if ($app->resolved('translator')) {
            $app['translator']->setLocale($this->config['app']['locale'] ?? 'en');
        }
        if ($app->resolved('log') && method_exists($app['log'], 'withoutContext')) {
            $app['log']->withoutContext();
        }
        $context = 'Illuminate\Log\Context\Repository';
        if (class_exists($context) && $app->resolved($context)) {
            $app[$context]->flush();
        }
        if (method_exists(\Illuminate\Support\Str::class, 'flushCache')) {
            \Illuminate\Support\Str::flushCache();
        }
        if ($app['config']->all() !== $this->config) {
            ($this->log)('config changed during a request: restored');
            $app['config']->set($this->config);
        }
        if (method_exists($app, 'forgetScopedInstances')) {
            $app->forgetScopedInstances();
        }
    }
}
