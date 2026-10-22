package main

import (
	"fmt"
	"math"
	"os"
	"strconv"
)

const (
	solarMass   = 4 * math.Pi * math.Pi
	daysPerYear = 365.24
)

func offsetMomentum(b [][7]float64) {
	var px, py, pz float64
	for _, body := range b {
		px += body[3] * body[6]
		py += body[4] * body[6]
		pz += body[5] * body[6]
	}
	b[0][3] = -px / solarMass
	b[0][4] = -py / solarMass
	b[0][5] = -pz / solarMass
}

func energy(b [][7]float64) float64 {
	e := 0.0
	for i := range b {
		bi := &b[i]
		e += 0.5 * bi[6] * (bi[3]*bi[3] + bi[4]*bi[4] + bi[5]*bi[5])
		for j := i + 1; j < len(b); j++ {
			bj := &b[j]
			dx, dy, dz := bi[0]-bj[0], bi[1]-bj[1], bi[2]-bj[2]
			e -= (bi[6] * bj[6]) / math.Sqrt(dx*dx+dy*dy+dz*dz)
		}
	}
	return e
}

func advance(b [][7]float64, dt float64, steps int) {
	for s := 0; s < steps; s++ {
		for i := range b {
			bi := &b[i]
			for j := i + 1; j < len(b); j++ {
				bj := &b[j]
				dx, dy, dz := bi[0]-bj[0], bi[1]-bj[1], bi[2]-bj[2]
				d2 := dx*dx + dy*dy + dz*dz
				mag := dt / (d2 * math.Sqrt(d2))
				mi, mj := bi[6]*mag, bj[6]*mag
				bi[3] -= dx * mj
				bi[4] -= dy * mj
				bi[5] -= dz * mj
				bj[3] += dx * mi
				bj[4] += dy * mi
				bj[5] += dz * mi
			}
			bi[0] += dt * bi[3]
			bi[1] += dt * bi[4]
			bi[2] += dt * bi[5]
		}
	}
}

func main() {
	n := 1000000
	if len(os.Args) > 1 {
		n, _ = strconv.Atoi(os.Args[1])
	}
	b := [][7]float64{
		{0, 0, 0, 0, 0, 0, solarMass},
		{4.84143144246472090e+00, -1.16032004402742839e+00, -1.03622044471123109e-01,
			1.66007664274403694e-03 * daysPerYear, 7.69901118419740425e-03 * daysPerYear,
			-6.90460016972063023e-05 * daysPerYear, 9.54791938424326609e-04 * solarMass},
		{8.34336671824457987e+00, 4.12479856412430479e+00, -4.03523417114321381e-01,
			-2.76742510726862411e-03 * daysPerYear, 4.99852801234917238e-03 * daysPerYear,
			2.30417297573763929e-05 * daysPerYear, 2.85885980666130812e-04 * solarMass},
		{1.28943695621391310e+01, -1.51111514016986312e+01, -2.23307578892655734e-01,
			2.96460137564761618e-03 * daysPerYear, 2.37847173959480950e-03 * daysPerYear,
			-2.96589568540237556e-05 * daysPerYear, 4.36624404335156298e-05 * solarMass},
		{1.53796971148509165e+01, -2.59193146099879641e+01, 1.79258772950371181e-01,
			2.68067772490389322e-03 * daysPerYear, 1.62824170038242295e-03 * daysPerYear,
			-9.51592254519715870e-05 * daysPerYear, 5.15138902046611451e-05 * solarMass},
	}
	offsetMomentum(b)
	fmt.Printf("%.9f\n", energy(b))
	advance(b, 0.01, n)
	fmt.Printf("%.9f\n", energy(b))
}
