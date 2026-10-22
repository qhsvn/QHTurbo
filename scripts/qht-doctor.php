<?php

// QHturbo doctor: checks a PHP installation for performance and safety
// settings, and scans application code for patterns that break in
// Performance mode (long-lived workers). Runs on PHP 7.4+.
//
//   php qht-doctor.php                    check the PHP running this script
//   php -c /path/php.ini qht-doctor.php   check with a specific php.ini
//   php qht-doctor.php --scan=/var/www/app  also scan code for worker-mode risks
//   php qht-doctor.php --json             machine-readable output

$opts = getopt('', ['scan:', 'json', 'help']);
if (isset($opts['help'])) {
    fwrite(STDOUT, "usage: php qht-doctor.php [--scan=DIR] [--json]\n");
    exit(0);
}

$results = [];
function report(string $level, string $topic, string $message, string $fix = ''): void
{
    $GLOBALS['results'][] = ['level' => $level, 'topic' => $topic, 'message' => $message, 'fix' => $fix];
}

function ini_bytes(string $value): int
{
    $value = trim($value);
    if ($value === '' || $value === '-1') {
        return -1;
    }
    $n = (int) $value;
    switch (strtoupper(substr($value, -1))) {
        case 'G': return $n * 1024 * 1024 * 1024;
        case 'M': return $n * 1024 * 1024;
        case 'K': return $n * 1024;
    }
    return $n;
}

// ------------------------------------------------------------ version ----
// Upstream security support end dates (https://www.php.net/supported-versions.php).
$eol = ['7.4' => '2022-11-28', '8.0' => '2023-11-26', '8.1' => '2025-12-31', '8.2' => '2026-12-31',
        '8.3' => '2027-12-31', '8.4' => '2028-12-31', '8.5' => '2029-12-31'];
$branch = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
if (isset($eol[$branch]) && $eol[$branch] < date('Y-m-d')) {
    report('FAIL', 'version', "PHP " . PHP_VERSION . " is end-of-life since {$eol[$branch]}: no security fixes",
        'Plan an upgrade to PHP 8.3+; until then keep it behind staging and a WAF');
} elseif (isset($eol[$branch])) {
    report('OK', 'version', "PHP " . PHP_VERSION . " (security support until {$eol[$branch]})");
} else {
    report('INFO', 'version', "PHP " . PHP_VERSION . " (unknown branch for this tool)");
}

// ------------------------------------------------------------ opcache ----
$cli = PHP_SAPI === 'cli';
if (!extension_loaded('Zend OPcache')) {
    report('FAIL', 'opcache', 'OPcache is not loaded: every request recompiles every PHP file',
        'Add zend_extension=opcache and opcache.enable=1');
} else {
    $enable = (bool) ini_get('opcache.enable');
    report($enable ? 'OK' : 'FAIL', 'opcache', 'opcache.enable=' . ($enable ? '1' : '0') . ' (web/FPM)',
        $enable ? '' : 'Set opcache.enable=1');
    if ($cli) {
        report('INFO', 'opcache', 'opcache.enable_cli=' . (int) ini_get('opcache.enable_cli')
            . ' (needs 1 only for long-lived CLI workers, i.e. QHturbo Performance mode)');
    }
    $mem = (int) ini_get('opcache.memory_consumption');
    report($mem >= 128 ? 'OK' : 'WARN', 'opcache', "opcache.memory_consumption={$mem}M",
        $mem >= 128 ? '' : 'Use >= 128 (256 for large frameworks); check opcache_get_status()["cache_full"]');
    $files = (int) ini_get('opcache.max_accelerated_files');
    report($files >= 20000 ? 'OK' : 'WARN', 'opcache', "opcache.max_accelerated_files=$files",
        $files >= 20000 ? '' : 'Use >= 20000: Composer projects easily have 10k+ files');
    $interned = (int) ini_get('opcache.interned_strings_buffer');
    report($interned >= 16 ? 'OK' : 'WARN', 'opcache', "opcache.interned_strings_buffer={$interned}M",
        $interned >= 16 ? '' : 'Use >= 16 (32 for Laravel/Symfony)');
    if (!ini_get('opcache.save_comments')) {
        report('WARN', 'opcache', 'opcache.save_comments=0 breaks annotation-based libraries (Doctrine, PHPUnit)', 'Set opcache.save_comments=1');
    }
    if (ini_get('opcache.validate_timestamps')) {
        report('INFO', 'opcache', 'validate_timestamps=1, revalidate_freq=' . ini_get('opcache.revalidate_freq')
            . ': safe for in-place edits; set 0 with atomic deploys + FPM reload for a few % more speed');
    } else {
        report('INFO', 'opcache', 'validate_timestamps=0: remember to reload PHP-FPM / workers after every deploy');
    }
    if (PHP_VERSION_ID >= 80000) {
        $jit = (string) ini_get('opcache.jit');
        $buffer = ini_bytes((string) ini_get('opcache.jit_buffer_size'));
        $on = !in_array(strtolower($jit), ['', '0', 'off', 'disable'], true) && $buffer > 0;
        report('INFO', 'jit', 'JIT ' . ($on ? "on ($jit, buffer " . ($buffer >> 20) . 'M)' : 'off')
            . ': gains 1.5-4x on CPU-heavy PHP, little on typical DB/IO-bound pages; measure before enabling');
    }
}

// ---------------------------------------------------------- debuggers ----
foreach (['xdebug' => 'Xdebug', 'pcov' => 'PCOV', 'blackfire' => 'Blackfire'] as $ext => $name) {
    if (extension_loaded($ext)) {
        $level = $ext === 'xdebug' ? 'FAIL' : 'WARN';
        report($level, 'debug', "$name is loaded: it slows every request (Xdebug: often 2-5x)",
            "Do not load $name in production");
    }
}

// ------------------------------------------------------------ runtime ----
$realpath = ini_bytes((string) ini_get('realpath_cache_size'));
report($realpath >= 4 << 20 ? 'OK' : 'WARN', 'runtime', 'realpath_cache_size=' . ini_get('realpath_cache_size'),
    $realpath >= 4 << 20 ? '' : 'Use 4096K and realpath_cache_ttl=600 for Composer projects');
if (!$cli && ini_get('display_errors')) {
    report('WARN', 'security', 'display_errors is on: leaks paths and data to visitors', 'display_errors=Off, log_errors=On');
}
if (ini_get('expose_php')) {
    report('INFO', 'security', 'expose_php=On adds an X-Powered-By header', 'expose_php=Off');
}
$memoryLimit = ini_bytes((string) ini_get('memory_limit'));
report('INFO', 'runtime', 'memory_limit=' . ini_get('memory_limit') . ($memoryLimit === -1 ? ' (unlimited)' : ''));
if (!ini_get('date.timezone')) {
    report('WARN', 'runtime', 'date.timezone is not set (defaults to UTC)', 'Set it explicitly, e.g. date.timezone=Asia/Ho_Chi_Minh');
}

// ---------------------------------------------------------- extensions ----
$wanted = ['openssl' => 'HTTPS, Composer', 'curl' => 'HTTP clients', 'mbstring' => 'UTF-8 strings',
    'pdo_mysql' => 'MySQL (PDO)', 'mysqli' => 'MySQL (WordPress)', 'intl' => 'i18n, Laravel/Symfony',
    'zip' => 'Composer archives', 'gd' => 'images', 'sodium' => 'modern crypto', 'json' => 'JSON',
    'fileinfo' => 'uploads', 'pcntl' => 'Performance mode workers (CLI)'];
$missing = [];
foreach ($wanted as $ext => $why) {
    if (!extension_loaded($ext)) {
        $missing[] = "$ext ($why)";
    }
}
report($missing ? 'WARN' : 'OK', 'extensions', $missing ? 'missing: ' . implode(', ', $missing) : 'all common extensions present');

// --------------------------------------------------------------- scan ----
if (isset($opts['scan'])) {
    scan_code((string) $opts['scan']);
}

/**
 * Patterns that are fine under PHP-FPM (state dies with the request) but
 * leak between requests in a long-lived worker.
 */
function scan_code(string $dir): void
{
    if (!is_dir($dir)) {
        report('FAIL', 'scan', "not a directory: $dir");
        return;
    }
    $rules = [
        'global-state' => ['/\bglobal\s+\$|\$GLOBALS\s*\[/', 'global variables persist across requests'],
        'static-var' => ['/\bstatic\s+\$\w+\s*(=|;)/', 'function-level static variables persist across requests'],
        'static-prop-write' => ['/\b(self|static|[A-Z]\w*)::\$\w+(\[[^\]]*\])?\s*(=|\[\]\s*=)/', 'writes to static properties persist across requests'],
        'exit' => ['/\b(exit|die)\s*(\(|;)/', 'exit()/die() would kill the worker; return a response instead'],
        'superglobals' => ['/\$_(GET|POST|COOKIE|FILES|REQUEST|SERVER|SESSION)\b/', 'superglobals need --superglobals (concurrency 1) or the Request object'],
        'header' => ['/\b(header|setcookie|http_response_code|headers_sent)\s*\(/', 'header()/setcookie() do not reach the client in worker mode; set them on the Response'],
        'session' => ['/\bsession_(start|regenerate_id|destroy)\s*\(/', 'native sessions rely on per-request globals; use a session service'],
        'output' => ['/^\s*(echo|print)\b|\bob_start\s*\(/m', 'direct output must be captured into the Response'],
        'set-handler' => ['/\bset_(error|exception)_handler\s*\(|\bregister_shutdown_function\s*\(/', 'handlers registered per request accumulate in a worker'],
        'ini-set' => ['/\b(ini_set|date_default_timezone_set|setlocale)\s*\(/', 'runtime ini/locale changes leak into later requests'],
    ];
    $counts = array_fill_keys(array_keys($rules), 0);
    $examples = [];
    $files = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        $path = $file->getPathname();
        if (substr($path, -4) !== '.php' || preg_match('#/(vendor|node_modules|tests?|storage|cache)/#', $path)) {
            continue;
        }
        $files++;
        $lines = @file($path) ?: [];
        foreach ($lines as $no => $line) {
            if (preg_match('#^\s*(//|\*|/\*|\#)#', $line)) {
                continue;
            }
            foreach ($rules as $name => [$regex]) {
                if (preg_match($regex, $line)) {
                    $counts[$name]++;
                    if (!isset($examples[$name])) {
                        $examples[$name] = substr($path, strlen(rtrim($dir, '/')) + 1) . ':' . ($no + 1);
                    }
                }
            }
        }
    }
    report('INFO', 'scan', "$files PHP files scanned in $dir (vendor/ and tests/ excluded)");
    $total = 0;
    foreach ($rules as $name => [, $why]) {
        if ($counts[$name] > 0) {
            $total += $counts[$name];
            report('WARN', 'scan', sprintf('%-17s %4d  %s (first: %s)', $name, $counts[$name], $why, $examples[$name]));
        }
    }
    if ($total === 0) {
        report('OK', 'scan', 'no worker-mode risks found; still run staging with --strict-state');
    } else {
        report('INFO', 'scan', 'Compatible mode (PHP-FPM) runs this code as is. For Performance mode fix the items above, '
            . 'or start with --concurrency=1 --superglobals and --strict-state on staging.');
    }
}

// ------------------------------------------------------------- output ----
if (isset($opts['json'])) {
    echo json_encode(['php' => PHP_VERSION, 'sapi' => PHP_SAPI, 'results' => $results], JSON_PRETTY_PRINT), "\n";
} else {
    $tty = function_exists('posix_isatty') && posix_isatty(STDOUT);
    $color = ['OK' => "\033[32m", 'INFO' => "\033[36m", 'WARN' => "\033[33m", 'FAIL' => "\033[31m"];
    echo 'QHturbo doctor — PHP ', PHP_VERSION, ' (', PHP_SAPI, '), ini: ', php_ini_loaded_file() ?: '(none)', "\n\n";
    foreach ($results as $r) {
        $tag = sprintf('%-4s', $r['level']);
        echo $tty ? $color[$r['level']] . $tag . "\033[0m" : $tag, '  ', sprintf('%-10s', $r['topic']), ' ', $r['message'], "\n";
        if ($r['fix'] !== '') {
            echo '                 -> ', $r['fix'], "\n";
        }
    }
    $levels = array_count_values(array_column($results, 'level'));
    printf("\n%d OK, %d WARN, %d FAIL\n", $levels['OK'] ?? 0, $levels['WARN'] ?? 0, $levels['FAIL'] ?? 0);
}
exit(in_array('FAIL', array_column($results, 'level'), true) ? 1 : 0);
