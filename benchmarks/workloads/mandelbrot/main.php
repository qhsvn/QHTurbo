<?php
// Mandelbrot set membership count. Pure float loop, no allocation.
$n = (int)($argv[1] ?? 1000);
$count = 0;
for ($y = 0; $y < $n; $y++) {
    $ci = 2.0 * $y / $n - 1.0;
    for ($x = 0; $x < $n; $x++) {
        $cr = 2.0 * $x / $n - 1.5;
        $zr = 0.0; $zi = 0.0; $inside = 1;
        for ($i = 0; $i < 50; $i++) {
            $tr = $zr * $zr - $zi * $zi + $cr;
            $zi = 2.0 * $zr * $zi + $ci;
            $zr = $tr;
            if ($zr * $zr + $zi * $zi > 4.0) { $inside = 0; break; }
        }
        $count += $inside;
    }
}
echo "$count\n";
