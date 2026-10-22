<?php

declare(strict_types=1);

namespace QHTurbo\Runtime\Http;

/** Immutable HTTP request. Request state lives here, not in globals. */
final class Request
{
    /** @var array<string, string> */
    public readonly array $query;

    /**
     * @param array<string, string> $headers lower-case names
     * @param array<string, mixed>  $server  $_SERVER-style values
     */
    public function __construct(
        public readonly string $method,
        public readonly string $uri,
        public readonly string $protocol,
        public readonly array $headers,
        public readonly string $body,
        public readonly array $server = [],
    ) {
        $q = [];
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $q);
        $this->query = $q;
    }

    public function path(): string
    {
        return (string) parse_url($this->uri, PHP_URL_PATH);
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    /** @return array<string, mixed> parsed form or JSON body */
    public function parsedBody(): array
    {
        $type = strtolower((string) $this->header('content-type'));
        if (str_starts_with($type, 'application/json')) {
            $data = json_decode($this->body, true);
            return is_array($data) ? $data : [];
        }
        if (str_starts_with($type, 'application/x-www-form-urlencoded')) {
            $data = [];
            parse_str($this->body, $data);
            return $data;
        }
        return [];
    }

    /** @return array<string, string> */
    public function cookies(): array
    {
        $cookies = [];
        foreach (explode(';', (string) $this->header('cookie')) as $pair) {
            [$name, $value] = array_pad(explode('=', trim($pair), 2), 2, '');
            if ($name !== '') {
                $cookies[$name] = urldecode($value);
            }
        }
        return $cookies;
    }
}
