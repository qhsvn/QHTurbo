<?php

declare(strict_types=1);

namespace QHTurbo\Runtime\State;

/**
 * Enforces the Performance-mode state rules between requests:
 *   - runs registered reset hooks,
 *   - restores superglobals, output buffers and changed ini settings,
 *   - detects state that leaks from one request into the next
 *     (new global variables, growing static properties, memory growth).
 *
 * In strict mode a violation throws, so it fails loudly in development and
 * CI; otherwise it is logged and the worker keeps running.
 */
final class StateGuard
{
    /** INI settings code commonly changes at runtime and must not leak. */
    private const WATCHED_INI = [
        'date.timezone', 'error_reporting', 'display_errors', 'memory_limit',
        'max_execution_time', 'default_charset', 'precision', 'serialize_precision',
    ];
    private const SUPERGLOBALS = ['_GET', '_POST', '_COOKIE', '_FILES', '_REQUEST', '_SERVER', '_ENV', '_SESSION'];

    /** @var list<ResetInterface|callable> */
    private array $resetters = [];
    /** @var array<string, true> */
    private array $baselineGlobals = [];
    /** @var array<string, string|false> */
    private array $baselineIni = [];
    private int $baselineObLevel = 0;
    /** @var array<string, int> "Class::$prop" => size at last check */
    private array $staticSizes = [];
    /** @var array<string, int> consecutive growth count */
    private array $staticGrowth = [];
    private int $lastMemory = 0;
    private int $memoryGrowthStreak = 0;
    private int $requests = 0;
    /** @var array<string, mixed> */
    private array $serverBaseline;

    /** @param \Closure(string): void $log */
    public function __construct(
        private readonly bool $strict = false,
        private readonly int $checkEvery = 1,
        private readonly ?\Closure $log = null,
    ) {
        $this->serverBaseline = $_SERVER;
    }

    public function addResetter(ResetInterface|callable $resetter): void
    {
        $this->resetters[] = $resetter;
    }

    /** Snapshot the clean state right after the application bootstrap. */
    public function captureBaseline(): void
    {
        $this->baselineGlobals = array_fill_keys(array_keys($GLOBALS), true);
        foreach (self::WATCHED_INI as $name) {
            $this->baselineIni[$name] = ini_get($name);
        }
        $this->baselineObLevel = ob_get_level();
        $this->lastMemory = memory_get_usage();
        $this->staticSizes = $this->measureStatics();
    }

    /** Called after every request; returns the list of violations found. */
    public function afterRequest(): array
    {
        $violations = [];
        $this->requests++;

        foreach ($this->resetters as $resetter) {
            try {
                $resetter instanceof ResetInterface ? $resetter->reset() : $resetter();
            } catch (\Throwable $e) {
                // A failing reset must not lose the response already built.
                $violations[] = sprintf('reset hook failed: %s: %s', $e::class, $e->getMessage());
            }
        }

        while (ob_get_level() > $this->baselineObLevel) {
            ob_end_clean();
            $violations[] = 'output buffer left open by the request (cleaned)';
        }

        foreach ($this->baselineIni as $name => $value) {
            if ($value !== false && ini_get($name) !== $value) {
                $violations[] = "ini setting $name changed during the request (restored)";
                ini_set($name, (string) $value);
            }
        }

        foreach (array_keys($GLOBALS) as $name) {
            if (!isset($this->baselineGlobals[$name]) && !in_array($name, self::SUPERGLOBALS, true)) {
                $violations[] = "global variable \$$name created during a request (removed)";
                unset($GLOBALS[$name]);
            }
        }
        $this->resetSuperglobals();

        if ($this->checkEvery > 0 && $this->requests % $this->checkEvery === 0) {
            array_push($violations, ...$this->checkStatics(), ...$this->checkMemory());
        }

        if ($violations) {
            $message = 'State rule violation: ' . implode('; ', array_unique($violations));
            if ($this->strict) {
                throw new StateViolation($message);
            }
            if ($this->log) {
                ($this->log)($message);
            }
        }
        return $violations;
    }

    /**
     * Fill superglobals for legacy code. Only safe when a worker handles one
     * request at a time (concurrency = 1).
     *
     * @param array<string, string> $query
     * @param array<string, mixed>  $post
     * @param array<string, string> $cookies
     * @param array<string, mixed>  $server
     * @param array<string, mixed>  $files
     */
    public function populateSuperglobals(array $query, array $post, array $cookies, array $server, array $files = []): void
    {
        $_GET = $query;
        $_POST = $post;
        $_COOKIE = $cookies;
        $_FILES = $files;
        $_REQUEST = $query + $post;
        $_SERVER = $server + $this->serverBaseline;
    }

    private function resetSuperglobals(): void
    {
        $_GET = $_POST = $_COOKIE = $_FILES = $_REQUEST = [];
        $_SERVER = $this->serverBaseline;
        if (isset($_SESSION)) {
            $_SESSION = [];
        }
    }

    /** @return list<string> */
    private function checkStatics(): array
    {
        $violations = [];
        $current = $this->measureStatics();
        foreach ($current as $key => $size) {
            $before = $this->staticSizes[$key] ?? 0;
            if ($size > $before) {
                $this->staticGrowth[$key] = ($this->staticGrowth[$key] ?? 0) + 1;
                // Caches warm up; only keep-growing statics look like leaks.
                if ($this->staticGrowth[$key] >= 3) {
                    $violations[] = "static property $key keeps growing ($before -> $size elements)";
                }
            } else {
                $this->staticGrowth[$key] = 0;
            }
        }
        $this->staticSizes = $current;
        return $violations;
    }

    /** @return list<string> */
    private function checkMemory(): array
    {
        $now = memory_get_usage();
        $this->memoryGrowthStreak = $now > $this->lastMemory + 1024 ? $this->memoryGrowthStreak + 1 : 0;
        $this->lastMemory = $now;
        if ($this->memoryGrowthStreak >= 10) {
            $this->memoryGrowthStreak = 0;
            return [sprintf('memory grew after 10 consecutive checks (now %.1f MiB): possible leak', $now / 1048576)];
        }
        return [];
    }

    /** @return array<string, int> */
    private function measureStatics(): array
    {
        $sizes = [];
        foreach (get_declared_classes() as $class) {
            $ref = new \ReflectionClass($class);
            if ($ref->isInternal() || str_starts_with($class, 'QHTurbo\\Runtime\\')) {
                continue;
            }
            foreach ($ref->getProperties(\ReflectionProperty::IS_STATIC) as $prop) {
                if (!$prop->isInitialized()) {
                    continue;
                }
                $value = $prop->getValue();
                $sizes[$class . '::$' . $prop->getName()] = is_countable($value) ? count($value) : 0;
            }
        }
        return $sizes;
    }
}
