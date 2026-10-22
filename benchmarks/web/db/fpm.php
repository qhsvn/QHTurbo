<?php
// Blocking PDO: three 20 ms queries one after another (classic PHP-FPM).
$pdo = new PDO(getenv('BENCH_PDO_DSN') ?: 'mysql:host=127.0.0.1;dbname=qht', getenv('BENCH_DB_USER') ?: 'qht',
    getenv('BENCH_DB_PASS') ?: 'qhtpass', [PDO::ATTR_PERSISTENT => true, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$rows = [];
foreach ([1, 2, 3] as $i) {
    $rows[] = $pdo->query("SELECT SLEEP(0.02) AS s, $i AS i")->fetch(PDO::FETCH_ASSOC);
}
header('Content-Type: application/json');
echo json_encode($rows);
