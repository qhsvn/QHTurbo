package main

import (
	"fmt"
	"os"
	"strconv"
)

type node struct{ left, right *node }

func bottomUpTree(depth int) *node {
	if depth > 0 {
		return &node{bottomUpTree(depth - 1), bottomUpTree(depth - 1)}
	}
	return &node{}
}

func (t *node) check() int {
	if t.left == nil {
		return 1
	}
	return 1 + t.left.check() + t.right.check()
}

func main() {
	maxDepth := 16
	if len(os.Args) > 1 {
		maxDepth, _ = strconv.Atoi(os.Args[1])
	}
	if maxDepth < 6 {
		maxDepth = 6
	}
	stretch := maxDepth + 1
	fmt.Printf("stretch tree of depth %d\t check: %d\n", stretch, bottomUpTree(stretch).check())
	longLived := bottomUpTree(maxDepth)
	for d := 4; d <= maxDepth; d += 2 {
		iterations := 1 << (maxDepth - d + 4)
		check := 0
		for i := 0; i < iterations; i++ {
			check += bottomUpTree(d).check()
		}
		fmt.Printf("%d\t trees of depth %d\t check: %d\n", iterations, d, check)
	}
	fmt.Printf("long lived tree of depth %d\t check: %d\n", maxDepth, longLived.check())
}
