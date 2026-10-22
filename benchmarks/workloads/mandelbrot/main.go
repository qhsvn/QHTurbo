package main

import (
	"fmt"
	"os"
	"strconv"
)

func main() {
	n := 1000
	if len(os.Args) > 1 {
		n, _ = strconv.Atoi(os.Args[1])
	}
	count := 0
	for y := 0; y < n; y++ {
		ci := 2.0*float64(y)/float64(n) - 1.0
		for x := 0; x < n; x++ {
			cr := 2.0*float64(x)/float64(n) - 1.5
			zr, zi, inside := 0.0, 0.0, 1
			for i := 0; i < 50; i++ {
				tr := zr*zr - zi*zi + cr
				zi = 2.0*zr*zi + ci
				zr = tr
				if zr*zr+zi*zi > 4.0 {
					inside = 0
					break
				}
			}
			count += inside
		}
	}
	fmt.Println(count)
}
