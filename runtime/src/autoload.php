<?php

declare(strict_types=1);

// Standalone autoloader so the runtime works without Composer.
// With Composer, the PSR-4 + files entries in composer.json do the same.
spl_autoload_register(static function (string $class): void {
    $prefix = 'QHTurbo\\Runtime\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
require_once __DIR__ . '/Async/functions.php';
