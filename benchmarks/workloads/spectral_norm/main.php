<?php
// Spectral norm (Benchmarks Game algorithm). Float math + function calls.
$n = (int)($argv[1] ?? 500);

function a(int $i, int $j): float {
    return 1.0 / ((($i + $j) * ($i + $j + 1) >> 1) + $i + 1);
}

function mul_av(int $n, array $v): array {
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        $s = 0.0;
        for ($j = 0; $j < $n; $j++) $s += a($i, $j) * $v[$j];
        $out[$i] = $s;
    }
    return $out;
}

function mul_atv(int $n, array $v): array {
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        $s = 0.0;
        for ($j = 0; $j < $n; $j++) $s += a($j, $i) * $v[$j];
        $out[$i] = $s;
    }
    return $out;
}

function mul_atav(int $n, array $v): array {
    return mul_atv($n, mul_av($n, $v));
}

$u = array_fill(0, $n, 1.0);
for ($i = 0; $i < 10; $i++) {
    $v = mul_atav($n, $u);
    $u = mul_atav($n, $v);
}
$vbv = $vv = 0.0;
for ($i = 0; $i < $n; $i++) {
    $vbv += $u[$i] * $v[$i];
    $vv += $v[$i] * $v[$i];
}
printf("%.9f\n", sqrt($vbv / $vv));
