# PHP 8.5 variants — jit

- **date_utc**: 2026-10-22 07:32
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
- **variants**: baseline sha256=baabd7289a1a, qhturbo sha256=ee4647980f1c, qhturbo-pgo sha256=f41efe4f3f08

Median wall time in seconds (lower is better), peak RSS in MiB. Speed-up is relative to `baseline`; ✔/✘ = 95% bootstrap CI of the median ratio excludes 1.0 (faster/slower), no mark = not significant.

| Workload | baseline (s) | qhturbo (s) | qhturbo-pgo (s) | qhturbo vs baseline | qhturbo-pgo vs baseline | baseline RSS | qhturbo RSS | qhturbo-pgo RSS | max CV |
|---|---|---|---|---|---|---|---|---|---|
| binary_trees | 1.136 | 1.160 | 1.139 | -2.0% | -0.3% | 42 | 42 | 42 | 8.8% |
| fannkuch | 1.062 | 1.027 | 0.979 | +3.3% | +8.4% | 15 | 15 | 15 | 11.9% |
| hashmap | 1.633 | 1.645 | 1.641 | -0.8% | -0.5% | 52 | 52 | 52 | 5.7% |
| json | 1.380 | 1.472 | 1.538 | -6.3% ✘ | -10.3% ✘ | 52 | 52 | 52 | 7.1% |
| mandelbrot | 0.733 | 0.781 | 0.973 | -6.1% | -24.6% ✘ | 15 | 15 | 15 | 11.3% |
| nbody | 1.678 | 1.817 | 1.822 | -7.7% | -7.9% | 15 | 15 | 15 | 7.2% |
| objects | 0.932 | 0.957 | 0.927 | -2.7% | +0.5% | 15 | 15 | 15 | 4.8% |
| spectral_norm | 0.326 | 0.324 | 0.333 | +0.5% | -2.0% ✘ | 15 | 15 | 15 | 5.6% |
| strings | 1.078 | 1.082 | 1.096 | -0.3% | -1.6% | 59 | 59 | 59 | 3.7% |

**Geometric mean speed-up vs baseline:** qhturbo: -2.5%, qhturbo-pgo: -4.7%
