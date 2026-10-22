<?php

declare(strict_types=1);

// QHturbo Compatible mode (PHP-FPM): the whole framework boots on EVERY
// request, like any classic PHP application.
$app = require __DIR__ . '/../app/bootstrap.php';

[$status, $type, $body] = $app->handle(
    parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH),
    // Blocking I/O: each backend call holds this FPM child for its duration.
    static function (string $url): array {
        $out = [];
        foreach (['/a', '/b', '/c'] as $p) {
            $body = @file_get_contents(str_replace('/a', $p, $url));
            if ($body === false) {
                throw new RuntimeException('backend unavailable'); // -> HTTP 500, counted as an error
            }
            $out[] = $body;
        }
        return $out;
    },
);
http_response_code($status);
header("Content-Type: $type");
echo $body;
