<?php

declare(strict_types=1);

namespace QHTurbo\Runtime\Mysql;

final class Config
{
    public function __construct(
        public string $host = '127.0.0.1',
        public string $user = 'root',
        public string $password = '',
        public string $database = '',
        public int $port = 3306,
        public ?string $socket = null,
        public string $charset = 'utf8mb4',
        public int $poolSize = 10,
        public float $connectTimeout = 5.0,
        public float $queryTimeout = 30.0,
        public int $flags = 0,
    ) {
    }

    /** mysql://user:pass@host:port/db?charset=utf8mb4&pool=10&socket=/run/mysqld/mysqld.sock */
    public static function fromUri(string $uri): self
    {
        $p = parse_url($uri);
        if ($p === false || ($p['scheme'] ?? '') !== 'mysql') {
            throw new \InvalidArgumentException("Invalid MySQL URI: $uri");
        }
        parse_str($p['query'] ?? '', $q);
        return new self(
            host: $p['host'] ?? '127.0.0.1',
            // rawurldecode: "+" in a password is a plus, not a space.
            user: rawurldecode($p['user'] ?? 'root'),
            password: rawurldecode($p['pass'] ?? ''),
            database: rawurldecode(ltrim($p['path'] ?? '', '/')),
            port: (int) ($p['port'] ?? 3306),
            socket: isset($q['socket']) ? (string) $q['socket'] : null,
            charset: (string) ($q['charset'] ?? 'utf8mb4'),
            poolSize: (int) ($q['pool'] ?? 10),
            queryTimeout: (float) ($q['timeout'] ?? 30),
        );
    }
}
