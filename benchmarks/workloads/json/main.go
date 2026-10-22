package main

import (
	"encoding/json"
	"fmt"
	"os"
	"strconv"
)

type record struct {
	ID     int      `json:"id"`
	Name   string   `json:"name"`
	Email  string   `json:"email"`
	Tags   []string `json:"tags"`
	Score  int      `json:"score"`
	Active bool     `json:"active"`
}

func main() {
	n, rounds := 20000, 20
	if len(os.Args) > 1 {
		n, _ = strconv.Atoi(os.Args[1])
	}
	if len(os.Args) > 2 {
		rounds, _ = strconv.Atoi(os.Args[2])
	}
	records := make([]record, n)
	for i := range records {
		records[i] = record{i, "user" + strconv.Itoa(i), "user" + strconv.Itoa(i) + "@example.com",
			[]string{"alpha", "beta", "gamma"}, i % 100, i%2 == 0}
	}
	length, sum := 0, 0
	for r := 0; r < rounds; r++ {
		data, _ := json.Marshal(records)
		length = len(data)
		// Decode generically, like PHP json_decode(..., true) / JSON.parse.
		var decoded []map[string]any
		_ = json.Unmarshal(data, &decoded)
		sum = 0
		for _, rec := range decoded {
			if rec["active"].(bool) {
				sum += int(rec["score"].(float64))
			}
		}
	}
	fmt.Println(length, sum)
}
