<?php
// PGO training: array functions, closures, sorting, generators, references.
function gen(int $n): Generator { for ($i = 0; $i < $n; $i++) yield $i => ($i * 2654435761) % 1000003; }

$data = iterator_to_array(gen(200000));
$sum = 0;
for ($r = 0; $r < 3; $r++) {
    $copy = $data;
    usort($copy, function ($a, $b) { return $a <=> $b; });
    sort($data);
    $even = array_filter($copy, function ($v) { return $v % 2 === 0; });
    $sq = array_map(function ($v) { return $v * $v; }, array_slice($even, 0, 50000));
    $sum += array_sum($sq) % 1000003;
    $groups = [];
    foreach ($copy as $v) $groups[$v % 97][] = $v;
    ksort($groups);
    $flat = array_merge(...array_values($groups));
    $uniq = array_unique(array_slice($flat, 0, 30000));
    $keys = array_flip($uniq);
    $sum += count($keys) + (int)in_array(42, $uniq, true);
    array_walk($uniq, function (&$v, $k) { $v = $v + $k; });
    $sum += array_reduce($uniq, function ($c, $v) { return ($c + $v) % 1000003; }, 0);
}
echo $sum, "\n";
