import sys

max_depth = max(6, int(sys.argv[1]) if len(sys.argv) > 1 else 16)


def bottom_up_tree(depth):
    if depth > 0:
        return (bottom_up_tree(depth - 1), bottom_up_tree(depth - 1))
    return (None, None)


def item_check(t):
    return 1 if t[0] is None else 1 + item_check(t[0]) + item_check(t[1])


stretch = max_depth + 1
print("stretch tree of depth %d\t check: %d" % (stretch, item_check(bottom_up_tree(stretch))))
long_lived = bottom_up_tree(max_depth)
for d in range(4, max_depth + 1, 2):
    iterations = 1 << (max_depth - d + 4)
    check = 0
    for _ in range(iterations):
        check += item_check(bottom_up_tree(d))
    print("%d\t trees of depth %d\t check: %d" % (iterations, d, check))
print("long lived tree of depth %d\t check: %d" % (max_depth, item_check(long_lived)))
