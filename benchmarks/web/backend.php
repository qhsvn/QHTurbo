<?php

declare(strict_types=1);

// Simulated backend service (database/API) that answers after 20 ms.
use QHTurbo\Runtime\Http\Request;

use function QHTurbo\Runtime\Async\delay;

return static function (Request $request): string {
    delay(0.020);
    return 'data' . $request->path();
};
