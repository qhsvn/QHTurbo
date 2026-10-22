# Comparison: php8.5-interp, php8.5-jit, python3, node, go

- **date_utc**: 2026-10-22 08:16
- **cpu**: Intel(R) Xeon(R) Processor @ 2.80GHz (4 vCPU)
- **kernel**: 6.18.44-fc-v140
- **os**: Ubuntu 24.04.5 LTS
- **gcc**: gcc (Ubuntu 13.3.0-6ubuntu2~24.04.1) 13.3.0
- **qhturbo_rev**: 75b0737
- **php**: 8.5 (baseline: PHP 8.5.12 (cli) (built: Oct 22 2026 06:55:55) (NTS))
- **runs**: 5
- **warmup**: 1
- **size**: full
- **python**: Python 3.13.16
- **node**: v22.22.0
- **go**: go version go1.24.7 linux/amd64 (prebuilt binary, compile time excluded)
- **note**: Single-threaded CPU workloads; interpreters include start-up time.

Median wall time in seconds (lower is better), peak RSS in MiB. Speed factor (>1 = faster) is relative to `php8.5-interp`; ✔/✘ = 95% bootstrap CI of the median ratio excludes 1.0 (faster/slower), no mark = not significant.

| Workload | php8.5-interp (s) | php8.5-jit (s) | python3 (s) | node (s) | go (s) | php8.5-jit vs php8.5-interp | python3 vs php8.5-interp | node vs php8.5-interp | go vs php8.5-interp | php8.5-interp RSS | php8.5-jit RSS | python3 RSS | node RSS | go RSS | max CV |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| binary_trees | 1.756 | 1.225 | 2.348 | 0.576 | 1.193 | 1.43× ✔ | 0.75× ✘ | 3.05× ✔ | 1.47× ✔ | 41 | 42 | 17 | 98 | 19 | 17.3% |
| fannkuch | 4.166 | 1.068 | 10.374 | 0.618 | 0.261 | 3.90× ✔ | 0.40× ✘ | 6.74× ✔ | 15.97× ✔ | 15 | 15 | 15 | 48 | 15 | 14.1% |
| hashmap | 1.838 | 1.684 | 4.442 | 3.832 | 2.223 | 1.09× | 0.41× ✘ | 0.48× ✘ | 0.83× ✘ | 50 | 52 | 53 | 118 | 65 | 13.3% |
| json | 1.438 | 1.456 | 2.734 | 1.174 | 3.482 | 0.99× | 0.53× ✘ | 1.22× ✔ | 0.41× ✘ | 50 | 52 | 38 | 125 | 57 | 15.3% |
| mandelbrot | 1.252 | 0.850 | 12.624 | 0.159 | 0.126 | 1.47× ✔ | 0.10× ✘ | 7.88× ✔ | 9.92× ✔ | 15 | 15 | 15 | 50 | 15 | 14.8% |
| nbody | 2.456 | 1.811 | 5.732 | 0.204 | 0.095 | 1.36× ✔ | 0.43× ✘ | 12.04× ✔ | 25.82× ✔ | 15 | 15 | 15 | 50 | 15 | 12.2% |
| objects | 1.438 | 1.049 | 3.322 | 0.124 | 0.198 | 1.37× ✔ | 0.43× ✘ | 11.61× ✔ | 7.25× ✔ | 15 | 15 | 15 | 50 | 15 | 15.4% |
| spectral_norm | 1.094 | 0.338 | 5.566 | 0.099 | 0.041 | 3.24× ✔ | 0.20× ✘ | 11.07× ✔ | 26.46× ✔ | 15 | 15 | 15 | 50 | 15 | 17.3% |
| strings | 1.294 | 1.261 | 2.742 | 3.484 | 1.359 | 1.03× | 0.47× ✘ | 0.37× ✘ | 0.95× ✘ | 58 | 59 | 62 | 335 | 41 | 15.1% |

**Geometric mean speed factor (>1 = faster) vs php8.5-interp:** php8.5-jit: 1.56×, python3: 0.36×, node: 3.36×, go: 4.16×
