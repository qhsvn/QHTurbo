<?php

declare(strict_types=1);

namespace QHTurbo\Runtime\Redis;

/** Error reply from Redis (e.g. WRONGTYPE) or a connection failure. */
class RedisException extends \RuntimeException
{
}
