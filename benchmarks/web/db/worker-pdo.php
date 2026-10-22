<?php
// Long-lived worker, still blocking PDO (concurrency 1): no bootstrap cost,
// but a worker waits while MySQL works.
$pdo = new PDO(getenv('BENCH_PDO_DSN') ?: 'mysql:host=127.0.0.1;dbname=qht', getenv('BENCH_DB_USER') ?: 'qht',
    getenv('BENCH_DB_PASS') ?: 'qhtpass', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
return static function () use ($pdo): array {
    $rows = [];
    foreach ([1, 2, 3] as $i) {
        $rows[] = $pdo->query("SELECT SLEEP(0.02) AS s, $i AS i")->fetch(PDO::FETCH_ASSOC);
    }
    return $rows;
};
