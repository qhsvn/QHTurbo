package main

import (
	"fmt"
	"os"
	"strconv"
)

type shape interface{ area() int }

type rect struct{ w, h int }

func (r *rect) area() int { return r.w * r.h }

type square struct{ s int }

func (s *square) area() int { return s.s * s.s }

type point struct{ x, y int }

func (p *point) add(o *point) *point { return &point{p.x + o.x, p.y + o.y} }

func main() {
	n := 3000000
	if len(os.Args) > 1 {
		n, _ = strconv.Atoi(os.Args[1])
	}
	p := &point{0, 0}
	area := 0
	for i := 0; i < n; i++ {
		p = p.add(&point{i % 10, 1})
		var s shape
		if i&1 == 1 {
			s = &rect{i % 7, 3}
		} else {
			s = &square{i % 5}
		}
		area += s.area()
	}
	fmt.Println(p.x, p.y, area)
}
