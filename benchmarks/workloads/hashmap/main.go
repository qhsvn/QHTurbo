package main

import (
	"fmt"
	"os"
	"strconv"
)

func main() {
	n := 2000000
	if len(os.Args) > 1 {
		n, _ = strconv.Atoi(os.Args[1])
	}
	m := n / 4
	seed := 42
	counts := map[string]int{}
	for i := 0; i < n; i++ {
		seed = (seed * 48271) % 2147483647
		counts["key"+strconv.Itoa(seed%m)]++
	}
	max := 0
	for _, c := range counts {
		if c > max {
			max = c
		}
	}
	hits := 0
	for i := 0; i < n; i++ {
		if _, ok := counts["key"+strconv.Itoa(i)]; ok {
			hits++
		}
	}
	fmt.Println(len(counts), max, hits)
}
