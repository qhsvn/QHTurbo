package main

import (
	"fmt"
	"os"
	"strconv"
	"strings"
)

func main() {
	n, rounds := 300000, 10
	if len(os.Args) > 1 {
		n, _ = strconv.Atoi(os.Args[1])
	}
	if len(os.Args) > 2 {
		rounds, _ = strconv.Atoi(os.Args[2])
	}
	length, total := 0, 0
	for r := 0; r < rounds; r++ {
		parts := make([]string, 0)
		for i := 0; i < n; i++ {
			parts = append(parts, "item-"+strconv.Itoa(i)+"-"+strconv.Itoa(i*7%1000))
		}
		s := strings.ReplaceAll(strings.Join(parts, ","), "item", "entry")
		length = len(s)
		total = 0
		for _, p := range strings.Split(s, ",") {
			if strings.Contains(p, "-7") {
				total += len(strings.ToUpper(p))
			}
		}
	}
	fmt.Println(length, total)
}
