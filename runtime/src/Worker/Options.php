<?php

declare(strict_types=1);

namespace QHTurbo\Runtime\Worker;

/** Settings for qhturbo-serve. Defaults are safe for production. */
final class Options
{
    public function __construct(
        public string $host = '127.0.0.1',
        public int $port = 8080,
        /** Worker processes; 0 = serve in the master process (no pcntl needed). */
        public int $workers = 0,
        /** Requests in flight per worker. 1 = sequential (safest). */
        public int $concurrency = 1,
        /** Recycle a worker after this many requests (0 = never). */
        public int $maxRequests = 10000,
        /** Recycle a worker when its memory exceeds this many bytes (0 = never). */
        public int $memoryLimitBytes = 256 * 1024 * 1024,
        /** Fill $_GET/$_POST/$_SERVER/$_COOKIE for legacy code (needs concurrency 1). */
        public bool $superglobals = false,
        /** Throw on state-rule violations instead of logging them. */
        public bool $strictState = false,
        /** Check static properties/memory every N requests (0 = off). */
        public int $stateCheckEvery = 100,
        public bool $debug = false,
        public float $keepAliveTimeout = 5.0,
        public float $readTimeout = 30.0,
        public float $writeTimeout = 30.0,
        public int $maxHeaderBytes = 65536,
        public int $maxBodyBytes = 8 * 1024 * 1024,
        public int $backlog = 1024,
        /** Run a framework application directory: '' | 'laravel' | 'symfony'. */
        public string $framework = '',
    ) {
    }

    /** @param array<string, string|false|list<string|false>> $opts getopt() result */
    public static function fromCli(array $opts): self
    {
        $o = new self();
        $str = static fn (string $k, string $d): string => isset($opts[$k]) ? (string) (is_array($opts[$k]) ? end($opts[$k]) : $opts[$k]) : $d;
        $o->host = $str('host', $o->host);
        $o->port = (int) $str('port', (string) $o->port);
        $o->workers = (int) $str('workers', (string) $o->workers);
        $o->concurrency = max(1, (int) $str('concurrency', (string) $o->concurrency));
        $o->maxRequests = (int) $str('max-requests', (string) $o->maxRequests);
        $o->memoryLimitBytes = self::bytes($str('memory-limit', '256M'));
        $o->superglobals = isset($opts['superglobals']);
        $o->strictState = isset($opts['strict-state']);
        $o->stateCheckEvery = (int) $str('state-check-every', $o->strictState ? '1' : (string) $o->stateCheckEvery);
        $o->debug = isset($opts['debug']);
        $o->framework = strtolower($str('framework', ''));
        if (isset($opts['max-body'])) {
            $o->maxBodyBytes = self::bytes($str('max-body', '8M'));
        }
        if (!in_array($o->framework, ['', 'laravel', 'symfony'], true)) {
            throw new \InvalidArgumentException("--framework must be laravel or symfony, got {$o->framework}");
        }
        if ($o->framework !== '' && $o->concurrency > 1) {
            throw new \InvalidArgumentException("--framework={$o->framework} needs --concurrency=1 (the framework container is shared by the whole worker)");
        }
        if ($o->superglobals && $o->concurrency > 1) {
            throw new \InvalidArgumentException('--superglobals requires --concurrency=1 (superglobals are shared by all requests of a worker)');
        }
        return $o;
    }

    public static function bytes(string $value): int
    {
        $value = trim($value);
        $unit = strtoupper(substr($value, -1));
        $n = (int) $value;
        return match ($unit) {
            'G' => $n * 1024 ** 3,
            'M' => $n * 1024 ** 2,
            'K' => $n * 1024,
            default => $n,
        };
    }
}
