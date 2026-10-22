<?php

declare(strict_types=1);

namespace QHTurbo\Runtime\Http;

final class Response
{
    private const REASONS = [
        200 => 'OK', 201 => 'Created', 202 => 'Accepted', 204 => 'No Content', 206 => 'Partial Content',
        301 => 'Moved Permanently', 302 => 'Found', 303 => 'See Other', 304 => 'Not Modified',
        307 => 'Temporary Redirect', 308 => 'Permanent Redirect', 400 => 'Bad Request',
        401 => 'Unauthorized', 402 => 'Payment Required', 403 => 'Forbidden', 404 => 'Not Found',
        405 => 'Method Not Allowed', 406 => 'Not Acceptable', 408 => 'Request Timeout', 409 => 'Conflict',
        410 => 'Gone', 411 => 'Length Required', 413 => 'Payload Too Large', 415 => 'Unsupported Media Type',
        419 => 'Page Expired', 422 => 'Unprocessable Content', 423 => 'Locked', 429 => 'Too Many Requests',
        431 => 'Request Header Fields Too Large', 500 => 'Internal Server Error', 501 => 'Not Implemented',
        502 => 'Bad Gateway', 503 => 'Service Unavailable', 504 => 'Gateway Timeout',
    ];

    /** @param array<string, string|list<string>> $headers several values = several header lines (Set-Cookie) */
    public function __construct(
        public int $status = 200,
        public array $headers = [],
        public string $body = '',
    ) {
    }

    /** Accepts a Response, a string (HTML) or an array/JsonSerializable (JSON). */
    public static function from(mixed $result): self
    {
        if ($result instanceof self) {
            return $result;
        }
        if (is_string($result)) {
            return new self(200, ['Content-Type' => 'text/html; charset=utf-8'], $result);
        }
        if (is_array($result) || $result instanceof \JsonSerializable) {
            return self::json($result);
        }
        throw new \UnexpectedValueException('Handler must return Response, string or array, got ' . get_debug_type($result));
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return new self($status, ['Content-Type' => 'application/json'],
            json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public static function text(string $text, int $status = 200): self
    {
        return new self($status, ['Content-Type' => 'text/plain; charset=utf-8'], $text);
    }

    public function toRaw(bool $keepAlive, bool $headOnly = false): string
    {
        $out = sprintf("HTTP/1.1 %d %s\r\n", $this->status, self::REASONS[$this->status] ?? 'Status');
        $headers = $this->headers;
        $present = array_change_key_case($headers);
        // The server owns framing headers; the app may add Date if it wants.
        unset($headers['Content-Length'], $headers['content-length'], $headers['Connection'], $headers['connection']);
        $headers['Content-Length'] = (string) strlen($this->body);
        $headers['Connection'] = $keepAlive ? 'keep-alive' : 'close';
        if (!isset($present['date'])) {
            $headers['Date'] = gmdate('D, d M Y H:i:s') . ' GMT';
        }
        foreach ($headers as $name => $value) {
            foreach ((array) $value as $one) {
                $out .= "$name: $one\r\n";
            }
        }
        return $out . "\r\n" . ($headOnly ? '' : $this->body);
    }
}
