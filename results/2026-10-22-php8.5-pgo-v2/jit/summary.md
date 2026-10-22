# PHP 8.5 variants — jit

- **date_utc**: 2026-10-22 07:59
- **cpu**: Intel(R) Xeon(R) Processor @ 2.80GHz (4 vCPU)
- **kernel**: 6.18.44-fc-v140
- **os**: Ubuntu 24.04.5 LTS
- **gcc**: gcc (Ubuntu 13.3.0-6ubuntu2~24.04.1) 13.3.0
- **qhturbo_rev**: 75b0737
- **php**: 8.5
- **mode**: jit
- **runs**: 10
- **warmup**: 1
- **size**: full
- **variants**: baseline sha256=baabd7289a1a, qhturbo sha256=ee4647980f1c, qhturbo-pgo sha256=f18c252c6daa

Median wall time in seconds (lower is better), peak RSS in MiB. Speed-up is relative to `baseline`; ✔/✘ = 95% bootstrap CI of the median ratio excludes 1.0 (faster/slower), no mark = not significant.

| Workload | baseline (s) | qhturbo (s) | qhturbo-pgo (s) | qhturbo vs baseline | qhturbo-pgo vs baseline | baseline RSS | qhturbo RSS | qhturbo-pgo RSS | max CV |
|---|---|---|---|---|---|---|---|---|---|
| binary_trees | 1.211 | 1.236 | 1.223 | -2.1% | -1.0% | 42 | 42 | 42 | 14.8% |
| fannkuch | 0.996 | 1.004 | 0.990 | -0.9% | +0.6% | 15 | 15 | 15 | 11.1% |
| hashmap | 1.885 | 1.778 | 1.835 | +6.0% | +2.8% | 52 | 52 | 52 | 11.2% |
| json | 1.465 | 1.546 | 1.519 | -5.2% | -3.5% | 52 | 52 | 51 | 15.4% |
| mandelbrot | 0.743 | 0.818 | 0.935 | -9.1% | -20.5% ✘ | 15 | 15 | 15 | 16.5% |
| nbody | 1.925 | 1.891 | 2.001 | +1.8% | -3.8% | 15 | 15 | 15 | 9.6% |
| objects | 1.071 | 0.994 | 1.044 | +7.7% ✔ | +2.5% | 15 | 15 | 15 | 10.6% |
| spectral_norm | 0.399 | 0.421 | 0.453 | -5.4% | -12.0% | 15 | 15 | 15 | 25.5% |
| strings | 1.293 | 1.246 | 1.177 | +3.8% | +9.9% | 59 | 59 | 59 | 11.6% |

**Geometric mean speed-up vs baseline:** qhturbo: -0.5%, qhturbo-pgo: -3.2%
