<?php

declare(strict_types=1);

namespace QHTurbo\Runtime\Http;

use function QHTurbo\Runtime\Async\connect;
use function QHTurbo\Runtime\Async\read;
use function QHTurbo\Runtime\Async\write;

/**
 * Minimal non-blocking HTTP/1.1 client for plain-HTTP internal services
 * (other microservices, Elasticsearch, ...). One request per connection.
 * No TLS, redirects or chunked responses: use it inside the data centre,
 * or put a TLS-terminating proxy in front of external APIs.
 */
final class Client
{
    /**
     * @param array<string, string> $headers
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public static function request(string $method, string $url, string $body = '', array $headers = [], float $timeout = 10.0): array
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'http' || !isset($parts['host'])) {
            throw new \InvalidArgumentException("Only http:// URLs are supported: $url");
        }
        $host = $parts['host'];
        $port = $parts['port'] ?? 80;
        $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');

        $conn = connect("tcp://$host:$port", $timeout);
        try {
            $headers += ['Host' => $host, 'Connection' => 'close', 'Content-Length' => (string) strlen($body)];
            $raw = "$method $path HTTP/1.1\r\n";
            foreach ($headers as $name => $value) {
                $raw .= "$name: $value\r\n";
            }
            write($conn, $raw . "\r\n" . $body, $timeout);

            $response = '';
            while (true) {
                $chunk = read($conn, 65536, $timeout);
                if ($chunk === '' && feof($conn)) {
                    break;
                }
                $response .= $chunk;
            }
        } finally {
            fclose($conn);
        }

        [$head, $responseBody] = array_pad(explode("\r\n\r\n", $response, 2), 2, '');
        $lines = explode("\r\n", $head);
        $status = (int) (explode(' ', $lines[0])[1] ?? 0);
        $responseHeaders = [];
        foreach (array_slice($lines, 1) as $line) {
            [$k, $v] = array_pad(explode(':', $line, 2), 2, '');
            $responseHeaders[strtolower(trim($k))] = trim($v);
        }
        if (isset($responseHeaders['content-length'])) {
            $responseBody = substr($responseBody, 0, (int) $responseHeaders['content-length']);
        }
        return ['status' => $status, 'headers' => $responseHeaders, 'body' => $responseBody];
    }

    /** @return array{status: int, headers: array<string, string>, body: string} */
    public static function get(string $url, float $timeout = 10.0): array
    {
        return self::request('GET', $url, '', [], $timeout);
    }
}
