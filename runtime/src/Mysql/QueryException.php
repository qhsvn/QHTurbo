<?php

declare(strict_types=1);

namespace QHTurbo\Runtime\Mysql;

/** MySQL error; getCode() is the MySQL error number (e.g. 1062 duplicate key). */
final class QueryException extends \RuntimeException
{
    public function __construct(string $message, int $code = 0, public readonly ?string $sql = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    /** Connection-level failure: the connection must not be reused. */
    public function isConnectionError(): bool
    {
        // 2002 can't connect, 2006 server has gone away, 2013 lost connection, 2055 lost (reading)
        return in_array($this->getCode(), [2002, 2006, 2013, 2055], true);
    }
}
