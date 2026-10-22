<?php
// JSON encode/decode round trips of API-like records.
$n = (int)($argv[1] ?? 20000);
$rounds = (int)($argv[2] ?? 20);
$records = [];
for ($i = 0; $i < $n; $i++) {
    $records[] = [
        'id' => $i,
        'name' => "user$i",
        'email' => "user$i@example.com",
        'tags' => ['alpha', 'beta', 'gamma'],
        'score' => $i % 100,
        'active' => $i % 2 === 0,
    ];
}
$len = 0; $sum = 0;
for ($r = 0; $r < $rounds; $r++) {
    $json = json_encode($records);
    $len = strlen($json);
    $sum = 0;
    foreach (json_decode($json, true) as $rec) {
        if ($rec['active']) $sum += $rec['score'];
    }
}
echo "$len $sum\n";
