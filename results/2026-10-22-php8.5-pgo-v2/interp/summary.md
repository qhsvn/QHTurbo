# PHP 8.5 variants — interp

- **date_utc**: 2026-10-22 07:49
- **cpu**: Intel(R) Xeon(R) Processor @ 2.80GHz (4 vCPU)
- **kernel**: 6.18.44-fc-v140
- **os**: Ubuntu 24.04.5 LTS
- **gcc**: gcc (Ubuntu 13.3.0-6ubuntu2~24.04.1) 13.3.0
- **qhturbo_rev**: 75b0737
- **php**: 8.5
- **mode**: interp
- **runs**: 10
- **warmup**: 1
- **size**: full
- **variants**: baseline sha256=baabd7289a1a, qhturbo sha256=ee4647980f1c, qhturbo-pgo sha256=f18c252c6daa

Median wall time in seconds (lower is better), peak RSS in MiB. Speed-up is relative to `baseline`; ✔/✘ = 95% bootstrap CI of the median ratio excludes 1.0 (faster/slower), no mark = not significant.

| Workload | baseline (s) | qhturbo (s) | qhturbo-pgo (s) | qhturbo vs baseline | qhturbo-pgo vs baseline | baseline RSS | qhturbo RSS | qhturbo-pgo RSS | max CV |
|---|---|---|---|---|---|---|---|---|---|
| binary_trees | 1.731 | 1.829 | 1.768 | -5.4% | -2.1% | 41 | 41 | 41 | 8.9% |
| fannkuch | 4.043 | 3.934 | 4.075 | +2.8% | -0.8% | 15 | 15 | 15 | 10.1% |
| hashmap | 1.910 | 1.965 | 1.909 | -2.8% | +0.1% | 50 | 50 | 50 | 6.4% |
| json | 1.546 | 1.499 | 1.537 | +3.2% | +0.6% | 50 | 50 | 50 | 16.1% |
| mandelbrot | 1.275 | 1.308 | 1.099 | -2.5% | +16.0% ✔ | 15 | 15 | 15 | 12.5% |
| nbody | 2.702 | 2.514 | 2.660 | +7.5% | +1.6% | 15 | 15 | 15 | 13.2% |
| objects | 1.523 | 1.418 | 1.463 | +7.4% | +4.1% | 15 | 15 | 15 | 9.3% |
| spectral_norm | 1.043 | 1.104 | 1.286 | -5.5% | -18.9% ✘ | 15 | 15 | 15 | 28.3% |
| strings | 1.271 | 1.292 | 1.202 | -1.6% | +5.7% | 58 | 58 | 57 | 14.8% |

**Geometric mean speed-up vs baseline:** qhturbo: +0.2%, qhturbo-pgo: +0.3%
