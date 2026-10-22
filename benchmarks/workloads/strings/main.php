<?php
// String building, replace, split, case conversion and search.
$n = (int)($argv[1] ?? 300000);
$rounds = (int)($argv[2] ?? 10);
$len = 0; $total = 0;
for ($r = 0; $r < $rounds; $r++) {
    $parts = [];
    for ($i = 0; $i < $n; $i++) $parts[] = 'item-' . $i . '-' . ($i * 7 % 1000);
    $s = str_replace('item', 'entry', implode(',', $parts));
    $len = strlen($s);
    $total = 0;
    foreach (explode(',', $s) as $p) {
        if (strpos($p, '-7') !== false) $total += strlen(strtoupper($p));
    }
}
echo "$len $total\n";
