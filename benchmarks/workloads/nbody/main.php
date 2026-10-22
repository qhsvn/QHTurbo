<?php
// N-body simulation (Benchmarks Game algorithm). Float-heavy, tight loops.
$n = (int)($argv[1] ?? 1000000);

const PI = 3.141592653589793;
const SOLAR_MASS = 4 * PI * PI;
const DAYS_PER_YEAR = 365.24;

// [x, y, z, vx, vy, vz, mass]
$bodies = [
    [0.0, 0.0, 0.0, 0.0, 0.0, 0.0, SOLAR_MASS],
    [4.84143144246472090e+00, -1.16032004402742839e+00, -1.03622044471123109e-01,
     1.66007664274403694e-03 * DAYS_PER_YEAR, 7.69901118419740425e-03 * DAYS_PER_YEAR,
     -6.90460016972063023e-05 * DAYS_PER_YEAR, 9.54791938424326609e-04 * SOLAR_MASS],
    [8.34336671824457987e+00, 4.12479856412430479e+00, -4.03523417114321381e-01,
     -2.76742510726862411e-03 * DAYS_PER_YEAR, 4.99852801234917238e-03 * DAYS_PER_YEAR,
     2.30417297573763929e-05 * DAYS_PER_YEAR, 2.85885980666130812e-04 * SOLAR_MASS],
    [1.28943695621391310e+01, -1.51111514016986312e+01, -2.23307578892655734e-01,
     2.96460137564761618e-03 * DAYS_PER_YEAR, 2.37847173959480950e-03 * DAYS_PER_YEAR,
     -2.96589568540237556e-05 * DAYS_PER_YEAR, 4.36624404335156298e-05 * SOLAR_MASS],
    [1.53796971148509165e+01, -2.59193146099879641e+01, 1.79258772950371181e-01,
     2.68067772490389322e-03 * DAYS_PER_YEAR, 1.62824170038242295e-03 * DAYS_PER_YEAR,
     -9.51592254519715870e-05 * DAYS_PER_YEAR, 5.15138902046611451e-05 * SOLAR_MASS],
];

function offset_momentum(array &$b): void {
    $px = $py = $pz = 0.0;
    foreach ($b as $body) {
        $px += $body[3] * $body[6];
        $py += $body[4] * $body[6];
        $pz += $body[5] * $body[6];
    }
    $b[0][3] = -$px / SOLAR_MASS;
    $b[0][4] = -$py / SOLAR_MASS;
    $b[0][5] = -$pz / SOLAR_MASS;
}

function energy(array $b): float {
    $e = 0.0;
    $nb = count($b);
    for ($i = 0; $i < $nb; $i++) {
        $bi = $b[$i];
        $e += 0.5 * $bi[6] * ($bi[3] * $bi[3] + $bi[4] * $bi[4] + $bi[5] * $bi[5]);
        for ($j = $i + 1; $j < $nb; $j++) {
            $bj = $b[$j];
            $dx = $bi[0] - $bj[0]; $dy = $bi[1] - $bj[1]; $dz = $bi[2] - $bj[2];
            $e -= ($bi[6] * $bj[6]) / sqrt($dx * $dx + $dy * $dy + $dz * $dz);
        }
    }
    return $e;
}

function advance(array &$b, float $dt, int $steps): void {
    $nb = count($b);
    for ($s = 0; $s < $steps; $s++) {
        for ($i = 0; $i < $nb; $i++) {
            $bi = &$b[$i];
            for ($j = $i + 1; $j < $nb; $j++) {
                $bj = &$b[$j];
                $dx = $bi[0] - $bj[0]; $dy = $bi[1] - $bj[1]; $dz = $bi[2] - $bj[2];
                $d2 = $dx * $dx + $dy * $dy + $dz * $dz;
                $mag = $dt / ($d2 * sqrt($d2));
                $mi = $bi[6] * $mag; $mj = $bj[6] * $mag;
                $bi[3] -= $dx * $mj; $bi[4] -= $dy * $mj; $bi[5] -= $dz * $mj;
                $bj[3] += $dx * $mi; $bj[4] += $dy * $mi; $bj[5] += $dz * $mi;
            }
            unset($bj);
            $bi[0] += $dt * $bi[3]; $bi[1] += $dt * $bi[4]; $bi[2] += $dt * $bi[5];
        }
        unset($bi);
    }
}

offset_momentum($bodies);
printf("%.9f\n", energy($bodies));
advance($bodies, 0.01, $n);
printf("%.9f\n", energy($bodies));
