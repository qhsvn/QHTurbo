<?php

namespace App\Controller;

use Symfony\Contracts\Service\ResetInterface;

/** Per-request state; Symfony must reset it between requests. */
final class QhtCounter implements ResetInterface
{
    public int $n = 0;

    public function reset(): void
    {
        $this->n = 0;
    }
}
