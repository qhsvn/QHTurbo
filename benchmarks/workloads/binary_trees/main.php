<?php
// Binary trees (Benchmarks Game algorithm). Allocation + GC + recursion.
$maxDepth = max(6, (int)($argv[1] ?? 16));

function bottom_up_tree(int $depth): array {
    return $depth > 0
        ? [bottom_up_tree($depth - 1), bottom_up_tree($depth - 1)]
        : [null, null];
}

function item_check(array $t): int {
    return $t[0] === null ? 1 : 1 + item_check($t[0]) + item_check($t[1]);
}

$stretch = $maxDepth + 1;
echo "stretch tree of depth $stretch\t check: ", item_check(bottom_up_tree($stretch)), "\n";
$longLived = bottom_up_tree($maxDepth);
for ($d = 4; $d <= $maxDepth; $d += 2) {
    $iterations = 1 << ($maxDepth - $d + 4);
    $check = 0;
    for ($i = 0; $i < $iterations; $i++) $check += item_check(bottom_up_tree($d));
    echo "$iterations\t trees of depth $d\t check: $check\n";
}
echo "long lived tree of depth $maxDepth\t check: ", item_check($longLived), "\n";
