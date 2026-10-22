<?php
// Hash map with string keys: insert/update then lookup. Typical "count by key" code.
$n = (int)($argv[1] ?? 2000000);
$m = intdiv($n, 4);
$seed = 42;
$counts = [];
for ($i = 0; $i < $n; $i++) {
    $seed = ($seed * 48271) % 2147483647;
    $key = 'key' . ($seed % $m);
    $counts[$key] = ($counts[$key] ?? 0) + 1;
}
$max = 0;
foreach ($counts as $c) if ($c > $max) $max = $c;
$hits = 0;
for ($i = 0; $i < $n; $i++) {
    if (isset($counts['key' . $i])) $hits++;
}
echo count($counts), " $max $hits\n";
