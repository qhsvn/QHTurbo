<?php
// Object allocation, constructors, method and interface calls.
// Written in PHP 7.4 syntax so every supported branch runs the same code.
$n = (int)($argv[1] ?? 3000000);

interface Shape { public function area(): int; }

final class Rect implements Shape {
    private int $w;
    private int $h;
    public function __construct(int $w, int $h) { $this->w = $w; $this->h = $h; }
    public function area(): int { return $this->w * $this->h; }
}

final class Square implements Shape {
    private int $s;
    public function __construct(int $s) { $this->s = $s; }
    public function area(): int { return $this->s * $this->s; }
}

final class Point {
    public int $x;
    public int $y;
    public function __construct(int $x, int $y) { $this->x = $x; $this->y = $y; }
    public function add(Point $o): Point { return new Point($this->x + $o->x, $this->y + $o->y); }
}

$p = new Point(0, 0);
$area = 0;
for ($i = 0; $i < $n; $i++) {
    $p = $p->add(new Point($i % 10, 1));
    $shape = ($i & 1) ? new Rect($i % 7, 3) : new Square($i % 5);
    $area += $shape->area();
}
echo "{$p->x} {$p->y} $area\n";
