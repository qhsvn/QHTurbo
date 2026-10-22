# PHP 8.5 variants — interp

- **date_utc**: 2026-10-22 07:23
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
- **variants**: baseline sha256=baabd7289a1a, qhturbo sha256=ee4647980f1c, qhturbo-pgo sha256=f41efe4f3f08

Median wall time in seconds (lower is better), peak RSS in MiB. Speed-up is relative to `baseline`; ✔/✘ = 95% bootstrap CI of the median ratio excludes 1.0 (faster/slower), no mark = not significant.

| Workload | baseline (s) | qhturbo (s) | qhturbo-pgo (s) | qhturbo vs baseline | qhturbo-pgo vs baseline | baseline RSS | qhturbo RSS | qhturbo-pgo RSS | max CV |
|---|---|---|---|---|---|---|---|---|---|
| binary_trees | 1.664 | 1.680 | 1.791 | -0.9% | -7.1% | 41 | 41 | 41 | 16.6% |
| fannkuch | 3.968 | 4.000 | 4.165 | -0.8% | -4.7% ✘ | 15 | 15 | 15 | 7.2% |
| hashmap | 1.769 | 1.729 | 1.773 | +2.3% | -0.2% | 50 | 50 | 50 | 8.1% |
| json | 1.516 | 1.490 | 1.602 | +1.7% | -5.4% | 50 | 50 | 50 | 8.9% |
| mandelbrot | 1.282 | 1.297 | 1.301 | -1.2% | -1.4% | 15 | 15 | 15 | 7.2% |
| nbody | 2.570 | 2.520 | 2.705 | +2.0% | -5.0% | 15 | 15 | 15 | 13.1% |
| objects | 1.424 | 1.425 | 1.430 | -0.1% | -0.5% | 15 | 15 | 15 | 6.4% |
| spectral_norm | 0.998 | 1.004 | 1.129 | -0.6% | -11.7% ✘ | 15 | 15 | 15 | 10.9% |
| strings | 1.227 | 1.187 | 1.232 | +3.3% | -0.4% | 58 | 58 | 58 | 7.4% |

**Geometric mean speed-up vs baseline:** qhturbo: +0.6%, qhturbo-pgo: -4.1%
