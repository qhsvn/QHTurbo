<?php

declare(strict_types=1);

// Application whose bootstrap takes a while (SIGTERM-during-boot test).

$until = microtime(true) + 3.0;
while (microtime(true) < $until) {
    usleep(50_000);
}

return static fn () => 'ok';
