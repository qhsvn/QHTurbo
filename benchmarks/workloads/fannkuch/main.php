<?php
// Fannkuch-redux (Benchmarks Game algorithm). Integer array permutations.
$n = (int)($argv[1] ?? 10);

function fannkuch(int $n): array {
    $perm1 = range(0, $n - 1);
    $count = array_fill(0, $n, 0);
    $perm = array_fill(0, $n, 0);
    $maxFlips = 0;
    $checksum = 0;
    $permCount = 0;
    $r = $n;
    while (true) {
        while ($r != 1) {
            $count[$r - 1] = $r;
            $r--;
        }
        for ($i = 0; $i < $n; $i++) $perm[$i] = $perm1[$i];
        $flips = 0;
        while (($k = $perm[0]) != 0) {
            $i = 0; $j = $k;
            while ($i < $j) {
                $t = $perm[$i]; $perm[$i] = $perm[$j]; $perm[$j] = $t;
                $i++; $j--;
            }
            $flips++;
        }
        if ($flips > $maxFlips) $maxFlips = $flips;
        $checksum += ($permCount % 2 == 0) ? $flips : -$flips;
        while (true) {
            if ($r == $n) return [$checksum, $maxFlips];
            $perm0 = $perm1[0];
            for ($i = 0; $i < $r; $i++) $perm1[$i] = $perm1[$i + 1];
            $perm1[$r] = $perm0;
            $count[$r]--;
            if ($count[$r] > 0) break;
            $r++;
        }
        $permCount++;
    }
}

[$checksum, $maxFlips] = fannkuch($n);
echo "$checksum\nPfannkuchen($n) = $maxFlips\n";
