<?php

declare(strict_types=1);

namespace QHTurbo\Runtime\Bridge;

use QHTurbo\Runtime\Http\Request;
use QHTurbo\Runtime\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Converts between the runtime's Request/Response and Symfony
 * HttpFoundation (used by Symfony and Laravel).
 */
final class HttpFoundation
{
    /**
     * @template R of SymfonyRequest
     * @param class-string<R> $class
     * @param class-string<UploadedFile> $fileClass Laravel needs its own subclass, otherwise
     *        it re-creates the files without "test" mode and isValid() fails
     * @return R
     */
    public static function toSymfony(Request $r, string $class = SymfonyRequest::class, string $fileClass = UploadedFile::class): SymfonyRequest
    {
        $host = (string) $r->header('host', 'localhost');
        [$name, $port] = str_contains($host, ':') && !str_ends_with($host, ']')
            ? explode(':', $host, 2) + [1 => '80']
            : [$host, '80'];
        $server = $r->server + [
            'SERVER_NAME' => $name,
            'SERVER_PORT' => $port,
            'SERVER_SOFTWARE' => 'qhturbo',
            'SCRIPT_NAME' => '',
            'SCRIPT_FILENAME' => '',
            'PHP_SELF' => '',
        ];
        $type = strtolower((string) $r->header('content-type'));
        $form = str_starts_with($type, 'application/x-www-form-urlencoded') || str_starts_with($type, 'multipart/form-data')
            ? $r->parsedBody()
            : [];
        return new $class($r->query, $form, [], $r->cookies(), self::files($r->uploadedFiles(), $fileClass), $server, $r->body);
    }

    public static function fromSymfony(SymfonyResponse $response): Response
    {
        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            ob_start();
            try {
                $response->sendContent();
            } finally {
                $body = (string) ob_get_clean();
            }
        } else {
            $body = (string) $response->getContent();
        }
        $headers = [];
        foreach ($response->headers->allPreserveCaseWithoutCookies() as $name => $values) {
            $headers[$name] = count($values) === 1 ? (string) $values[0] : array_map('strval', $values);
        }
        foreach ($response->headers->getCookies() as $cookie) {
            $headers['Set-Cookie'][] = (string) $cookie;
        }
        return new Response($response->getStatusCode(), $headers, $body);
    }

    /**
     * $_FILES layout -> UploadedFile objects. "test" mode is required
     * because the files were not created by PHP's own upload handling
     * (is_uploaded_file() would reject them).
     *
     * @param array<string, mixed> $files
     * @param class-string<UploadedFile> $fileClass
     * @return array<string, mixed>
     */
    private static function files(array $files, string $fileClass): array
    {
        $out = [];
        foreach ($files as $key => $info) {
            $out[$key] = self::convert($info['name'] ?? null, $info['type'] ?? null, $info['tmp_name'] ?? null,
                $info['error'] ?? null, $fileClass);
        }
        return $out;
    }

    /** @param class-string<UploadedFile> $fileClass */
    private static function convert(mixed $name, mixed $type, mixed $tmp, mixed $error, string $fileClass): mixed
    {
        if (is_array($name)) {
            $out = [];
            foreach ($name as $k => $n) {
                $out[$k] = self::convert($n, $type[$k] ?? null, $tmp[$k] ?? null, $error[$k] ?? null, $fileClass);
            }
            return $out;
        }
        if ((int) $error === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        return new $fileClass((string) $tmp, (string) $name, $type ? (string) $type : null, (int) $error, true);
    }
}
