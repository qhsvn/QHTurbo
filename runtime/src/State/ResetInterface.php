<?php

declare(strict_types=1);

namespace QHTurbo\Runtime\State;

/**
 * Services that keep per-request data (current user, unit of work, request
 * logs...) implement this; the worker calls reset() after every request.
 */
interface ResetInterface
{
    public function reset(): void;
}
