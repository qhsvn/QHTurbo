<?php
// PGO training: classes, inheritance, static/magic methods, exceptions, traits.
trait Counts { private static int $made = 0; public static function made(): int { return self::$made; } }

abstract class Node {
    use Counts;
    public function __construct() { self::$made++; }
    abstract public function eval(array $env): int;
}
final class Num extends Node { private int $v; public function __construct(int $v) { parent::__construct(); $this->v = $v; } public function eval(array $env): int { return $this->v; } }
final class Ref extends Node { private string $n; public function __construct(string $n) { parent::__construct(); $this->n = $n; }
    public function eval(array $env): int { if (!isset($env[$this->n])) throw new RuntimeException("undefined {$this->n}"); return $env[$this->n]; } }
final class Bin extends Node { private string $op; private Node $l; private Node $r;
    public function __construct(string $op, Node $l, Node $r) { parent::__construct(); $this->op = $op; $this->l = $l; $this->r = $r; }
    public function eval(array $env): int {
        $a = $this->l->eval($env); $b = $this->r->eval($env);
        switch ($this->op) { case '+': return $a + $b; case '*': return ($a * $b) % 100003; default: return $a - $b; }
    }
}
class Bag { private array $d = []; public function __get($k) { return $this->d[$k] ?? 0; } public function __set($k, $v) { $this->d[$k] = $v; } }

function build(int $depth, int $seed): Node {
    if ($depth === 0) return $seed % 3 === 0 ? new Ref('x' . ($seed % 5)) : new Num($seed % 10);
    return new Bin(['+', '*', '-'][$seed % 3], build($depth - 1, $seed * 7 + 1), build($depth - 1, $seed * 13 + 3));
}

$full = ['x0' => 1, 'x1' => 2, 'x2' => 3, 'x3' => 4, 'x4' => 5];
$bag = new Bag();
$total = 0; $errors = 0;
for ($i = 0; $i < 300; $i++) {
    $tree = build(10, $i);
    $env = $i % 10 === 0 ? array_slice($full, 0, 4) : $full; // some runs throw
    try { $total += $tree->eval($env); } catch (RuntimeException $e) { $errors++; $bag->last = strlen($e->getMessage()); }
}
echo $total, ' ', $errors, ' ', Node::made(), ' ', $bag->last, "\n";
