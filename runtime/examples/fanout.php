<?php

declare(strict_types=1);

// Async fan-out: each request calls three internal services concurrently.
// Run with --concurrency > 1 so one worker overlaps many requests' waits.
//   php bin/qhturbo-serve examples/fanout.php --port=8080 --concurrency=64
use QHTurbo\Runtime\Http\Client;
use QHTurbo\Runtime\Http\Request;
use QHTurbo\Runtime\Http\Response;

use function QHTurbo\Runtime\Async\all;
use function QHTurbo\Runtime\Async\async;
use function QHTurbo\Runtime\Async\delay;
use function QHTurbo\Runtime\Async\timeout;

return static function (Request $request): Response|array {
    if ($request->path() === '/backend') {
        delay(0.05); // pretend to be a slow service
        return ['value' => random_int(1, 100)];
    }
    $port = $request->server['HTTP_HOST'] ? explode(':', $request->server['HTTP_HOST'])[1] ?? '80' : '80';
    $start = microtime(true);
    $results = all([
        'users' => timeout(async(fn () => Client::get("http://127.0.0.1:$port/backend")), 1.0),
        'orders' => timeout(async(fn () => Client::get("http://127.0.0.1:$port/backend")), 1.0),
        'stats' => timeout(async(fn () => Client::get("http://127.0.0.1:$port/backend")), 1.0),
    ]);
    return [
        'took_ms' => round((microtime(true) - $start) * 1000), // ~50, not ~150
        'results' => array_map(static fn ($r) => json_decode($r['body'], true), $results),
    ];
};
