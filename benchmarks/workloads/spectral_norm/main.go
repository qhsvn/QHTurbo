package main

import (
	"fmt"
	"math"
	"os"
	"strconv"
)

func a(i, j int) float64 {
	return 1.0 / float64(((i+j)*(i+j+1))>>1+i+1)
}

func mulAv(n int, v []float64) []float64 {
	out := make([]float64, n)
	for i := 0; i < n; i++ {
		s := 0.0
		for j := 0; j < n; j++ {
			s += a(i, j) * v[j]
		}
		out[i] = s
	}
	return out
}

func mulAtv(n int, v []float64) []float64 {
	out := make([]float64, n)
	for i := 0; i < n; i++ {
		s := 0.0
		for j := 0; j < n; j++ {
			s += a(j, i) * v[j]
		}
		out[i] = s
	}
	return out
}

func mulAtAv(n int, v []float64) []float64 { return mulAtv(n, mulAv(n, v)) }

func main() {
	n := 500
	if len(os.Args) > 1 {
		n, _ = strconv.Atoi(os.Args[1])
	}
	u := make([]float64, n)
	for i := range u {
		u[i] = 1.0
	}
	var v []float64
	for i := 0; i < 10; i++ {
		v = mulAtAv(n, u)
		u = mulAtAv(n, v)
	}
	var vbv, vv float64
	for i := 0; i < n; i++ {
		vbv += u[i] * v[i]
		vv += v[i] * v[i]
	}
	fmt.Printf("%.9f\n", math.Sqrt(vbv/vv))
}
