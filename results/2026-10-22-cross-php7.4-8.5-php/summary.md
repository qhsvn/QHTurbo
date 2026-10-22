# Comparison: php7.4-interp, php8.5-interp, php8.5-jit

- **date_utc**: 2026-10-22 08:10
- **cpu**: Intel(R) Xeon(R) Processor @ 2.80GHz (4 vCPU)
- **kernel**: 6.18.44-fc-v140
- **os**: Ubuntu 24.04.5 LTS
- **gcc**: gcc (Ubuntu 13.3.0-6ubuntu2~24.04.1) 13.3.0
- **qhturbo_rev**: 75b0737
- **php**: 7.4 (baseline: PHP 7.4.33 (cli) (built: Oct 22 2026 08:08:44) ( NTS )), 8.5 (baseline: PHP 8.5.12 (cli) (built: Oct 22 2026 06:55:55) (NTS))
- **runs**: 7
- **warmup**: 1
- **size**: full
- **note**: Single-threaded CPU workloads; interpreters include start-up time.

Median wall time in seconds (lower is better), peak RSS in MiB. Speed factor (>1 = faster) is relative to `php7.4-interp`; ✔/✘ = 95% bootstrap CI of the median ratio excludes 1.0 (faster/slower), no mark = not significant.

| Workload | php7.4-interp (s) | php8.5-interp (s) | php8.5-jit (s) | php8.5-interp vs php7.4-interp | php8.5-jit vs php7.4-interp | php7.4-interp RSS | php8.5-interp RSS | php8.5-jit RSS | max CV |
|---|---|---|---|---|---|---|---|---|---|
| binary_trees | 2.267 | 1.730 | 1.151 | 1.31× ✔ | 1.97× ✔ | 58 | 41 | 42 | 14.6% |
| fannkuch | 3.611 | 3.881 | 1.018 | 0.93× ✘ | 3.55× ✔ | 15 | 15 | 15 | 7.1% |
| hashmap | 1.848 | 1.889 | 1.827 | 0.98× | 1.01× | 48 | 50 | 52 | 5.7% |
| json | 1.630 | 1.484 | 1.395 | 1.10× | 1.17× ✔ | 52 | 50 | 52 | 8.2% |
| mandelbrot | 1.068 | 1.312 | 0.748 | 0.81× ✘ | 1.43× ✔ | 15 | 15 | 15 | 7.9% |
| nbody | 2.400 | 2.576 | 1.882 | 0.93× | 1.28× ✔ | 15 | 15 | 15 | 8.2% |
| objects | 1.579 | 1.506 | 0.962 | 1.05× | 1.64× ✔ | 15 | 15 | 15 | 12.8% |
| spectral_norm | 1.276 | 1.026 | 0.339 | 1.24× ✔ | 3.76× ✔ | 15 | 15 | 15 | 18.6% |
| strings | 1.514 | 1.236 | 1.228 | 1.22× ✔ | 1.23× ✔ | 66 | 58 | 59 | 6.3% |

**Geometric mean speed factor (>1 = faster) vs php7.4-interp:** php8.5-interp: 1.05×, php8.5-jit: 1.69×
