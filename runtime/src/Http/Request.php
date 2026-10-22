<?php

declare(strict_types=1);

namespace QHTurbo\Runtime\Http;

/** Immutable HTTP request. Request state lives here, not in globals. */
final class Request
{
    /** @var array<string, string> */
    public readonly array $query;
    /** @var array{0: array<string, mixed>, 1: array<string, mixed>}|null multipart fields + $_FILES-style files */
    private ?array $multipart = null;
    /** @var list<string> temp files created for uploads; removed by cleanup() */
    private array $tempFiles = [];

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
        if (str_starts_with($type, 'multipart/form-data')) {
            return $this->multipart()[0];
        }
        return [];
    }

    /**
     * Uploaded files in PHP's $_FILES layout (nested names such as
     * "photos[]" included). Files live in the system temp directory and
     * are deleted after the response is sent.
     *
     * @return array<string, mixed>
     */
    public function uploadedFiles(): array
    {
        return str_starts_with(strtolower((string) $this->header('content-type')), 'multipart/form-data')
            ? $this->multipart()[1]
            : [];
    }

    /** Delete temporary upload files (called by the server after the response). */
    public function cleanup(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        $this->tempFiles = [];
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function multipart(): array
    {
        if ($this->multipart !== null) {
            return $this->multipart;
        }
        $fields = [];
        $files = [];
        if (!preg_match('/boundary=(?:"([^"]+)"|([^;]+))/i', (string) $this->header('content-type'), $m)) {
            return $this->multipart = [[], []];
        }
        $boundary = '--' . ($m[1] !== '' ? $m[1] : trim($m[2]));
        $pairs = [];
        foreach (array_slice(explode($boundary, $this->body), 1) as $part) {
            if (str_starts_with($part, '--')) {
                break; // closing boundary
            }
            $part = (string) substr($part, str_starts_with($part, "\r\n") ? 2 : 0);
            $part = str_ends_with($part, "\r\n") ? substr($part, 0, -2) : $part;
            [$rawHeaders, $content] = array_pad(explode("\r\n\r\n", $part, 2), 2, '');
            $headers = [];
            foreach (explode("\r\n", $rawHeaders) as $line) {
                [$k, $v] = array_pad(explode(':', $line, 2), 2, '');
                $headers[strtolower(trim($k))] = trim($v);
            }
            $disposition = $headers['content-disposition'] ?? '';
            if (!preg_match('/\bname="([^"]*)"/i', $disposition, $nm)) {
                continue;
            }
            $name = $nm[1];
            if (preg_match('/\bfilename="([^"]*)"/i', $disposition, $fm)) {
                $info = ['name' => basename($fm[1]), 'full_path' => $fm[1], 'type' => '', 'tmp_name' => '',
                    'error' => UPLOAD_ERR_NO_FILE, 'size' => 0];
                if ($fm[1] !== '') {
                    $tmp = tempnam(sys_get_temp_dir(), 'qhtup');
                    $ok = $tmp !== false && file_put_contents($tmp, $content) !== false;
                    if ($ok) {
                        $this->tempFiles[] = $tmp;
                    }
                    $info = ['name' => basename($fm[1]), 'full_path' => $fm[1],
                        'type' => $headers['content-type'] ?? 'application/octet-stream',
                        'tmp_name' => $ok ? $tmp : '', 'error' => $ok ? UPLOAD_ERR_OK : UPLOAD_ERR_CANT_WRITE,
                        'size' => strlen($content)];
                }
                self::addFile($files, $name, $info);
            } else {
                $pairs[] = rawurlencode($name) . '=' . rawurlencode($content);
            }
        }
        parse_str(implode('&', $pairs), $fields);
        return $this->multipart = [$fields, $files];
    }

    /**
     * Place one upload into the $_FILES layout: "doc" -> $files['doc'][prop],
     * "docs[a][]" -> $files['docs'][prop]['a'][] like PHP does.
     *
     * @param array<string, mixed> $files
     * @param array<string, mixed> $info
     */
    private static function addFile(array &$files, string $name, array $info): void
    {
        if (!preg_match('/^([^\[]+)((?:\[[^\]]*\])*)$/', $name, $m)) {
            return;
        }
        $base = $m[1];
        preg_match_all('/\[([^\]]*)\]/', $m[2], $km);
        $path = $km[1];
        foreach ($info as $prop => $value) {
            if (!$path) {
                $files[$base][$prop] = $value;
                continue;
            }
            $ref = &$files[$base][$prop];
            foreach ($path as $key) {
                if (!is_array($ref)) {
                    $ref = [];
                }
                if ($key === '') {
                    $ref[] = null;
                    $key = array_key_last($ref);
                }
                $ref = &$ref[$key];
            }
            $ref = $value;
            unset($ref);
        }
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
