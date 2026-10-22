<?php

declare(strict_types=1);

// Reload test application: copied to a temporary directory together with a
// version.php that the test rewrites between SIGHUPs.

$version = require __DIR__ . '/version.php';

return static fn () => \QHTurbo\Runtime\Http\Response::text($version);
