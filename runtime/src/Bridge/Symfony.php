<?php

declare(strict_types=1);

namespace QHTurbo\Runtime\Bridge;

use QHTurbo\Runtime\Http\Request;
use QHTurbo\Runtime\Http\Response;

/**
 * Runs a Symfony (5.4-7.x) application in QHturbo Performance mode.
 *
 *   qhturbo-serve /var/www/app --framework=symfony --workers=4
 *
 * The kernel boots once per worker. Symfony resets its own services
 * between requests (services tagged kernel.reset, via services_resetter),
 * which is how symfony/runtime workers behave too.
 *
 * Kernel class: App\Kernel, or set QHT_SYMFONY_KERNEL.
 */
final class Symfony
{
    /** @var \Symfony\Component\HttpKernel\KernelInterface&\Symfony\Component\HttpKernel\TerminableInterface */
    private object $kernel;

    public function __construct(string $basePath)
    {
        $basePath = rtrim($basePath, '/');
        if (!is_file("$basePath/vendor/autoload.php")) {
            throw new \RuntimeException("$basePath/vendor/autoload.php missing; run composer install");
        }
        require_once "$basePath/vendor/autoload.php";
        // Tell Symfony this is a web worker, not a CLI command. Otherwise,
        // because PHP_SAPI is "cli", it picks the CLI error renderer and
        // dumps exception details into HTTP responses even in prod.
        if (!isset($_SERVER['APP_RUNTIME_MODE'])) {
            $_SERVER['APP_RUNTIME_MODE'] = $_ENV['APP_RUNTIME_MODE'] = 'web=1&worker=1';
            putenv('APP_RUNTIME_MODE=web=1&worker=1');
        }
        if (is_file("$basePath/.env") && class_exists(\Symfony\Component\Dotenv\Dotenv::class)) {
            (new \Symfony\Component\Dotenv\Dotenv())->bootEnv("$basePath/.env");
        }
        $env = (string) ($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? 'prod');
        $debug = filter_var($_SERVER['APP_DEBUG'] ?? $_ENV['APP_DEBUG'] ?? ($env !== 'prod'), FILTER_VALIDATE_BOOL);
        $class = getenv('QHT_SYMFONY_KERNEL') ?: 'App\Kernel';
        if (!class_exists($class)) {
            throw new \RuntimeException("Kernel class $class not found (set QHT_SYMFONY_KERNEL)");
        }
        $this->kernel = new $class($env, $debug);
        $this->kernel->boot();
    }

    public function handle(Request $request): Response
    {
        $symfonyRequest = HttpFoundation::toSymfony($request);
        $response = $this->kernel->handle($symfonyRequest);
        $out = HttpFoundation::fromSymfony($response);
        $this->kernel->terminate($symfonyRequest, $response);
        return $out;
    }
}
