<?php

declare(strict_types=1);

namespace QHTurbo\Runtime\Mysql;

/** MySQL error; getCode() is the MySQL error number (e.g. 1062 duplicate key). */
final class QueryException extends \RuntimeException
{
    /**
     * Errors after which the connection is unusable: 1053 server shutdown,
     * 2002 can't connect, 2006 server has gone away, 2013/2055 lost
     * connection, 2014 commands out of sync, 4031 disconnected for
     * inactivity (MySQL 8.0.24+).
     */
    public const CONNECTION_ERRORS = [1053, 2002, 2006, 2013, 2014, 2055, 4031];

    /**
     * @param bool $sent false when the query never reached the server
     *                   (failed while sending), so retrying cannot run it twice
     */
    public function __construct(string $message, int $code = 0, public readonly ?string $sql = null, ?\Throwable $previous = null,
        public readonly bool $sent = true)
    {
        parent::__construct($message, $code, $previous);
    }

    /** Connection-level failure: the connection must not be reused. */
    public function isConnectionError(): bool
    {
        return in_array($this->getCode(), self::CONNECTION_ERRORS, true);
    }
}
