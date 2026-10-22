<?php

declare(strict_types=1);

// QHturbo Performance mode: the framework boots ONCE per worker process;
// backend calls are async so one worker overlaps many requests' I/O.
use QHTurbo\Runtime\Http\Client;
use QHTurbo\Runtime\Http\Request;
use QHTurbo\Runtime\Http\Response;

use function QHTurbo\Runtime\Async\all;
use function QHTurbo\Runtime\Async\async;

$app = require __DIR__ . '/app/bootstrap.php';

$backend = static function (string $url): array {
    $calls = [];
    foreach (['/a', '/b', '/c'] as $p) {
        $calls[] = async(static fn () => Client::get(str_replace('/a', $p, $url))['body']);
    }
    return all($calls); // the three calls run concurrently
};

return static function (Request $request) use ($app, $backend): Response {
    [$status, $type, $body] = $app->handle($request->path(), $backend);
    return new Response($status, ['Content-Type' => $type], $body);
};
