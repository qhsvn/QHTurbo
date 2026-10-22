<?php

declare(strict_types=1);

// Minimal app: bootstrap code runs once per worker, the closure per request.
use QHTurbo\Runtime\Http\Request;
use QHTurbo\Runtime\Http\Response;

$bootedAt = microtime(true);

return static function (Request $request) use ($bootedAt): Response|array {
    return match ($request->path()) {
        '/' => Response::text("Hello from QHturbo\n"),
        '/json' => ['message' => 'hello', 'worker_pid' => getmypid(), 'uptime' => round(microtime(true) - $bootedAt, 3)],
        default => Response::text("Not Found\n", 404),
    };
};
