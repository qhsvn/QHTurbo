# Benchmark methodology

**English** · [Tiếng Việt](benchmark-methodology.vi.md)

Goal: every speed figure is **reproducible** and **fairly compared**.

## 1. Workloads

`benchmarks/workloads/<name>/main.{php,py,js,go}`: the same algorithm written in 4 languages, printing the same result. The PHP code uses 7.4 syntax so that every branch 7.4 → 8.5 runs the same file.

| Workload | What it measures | Type |
|---|---|---|
| `nbody` | floating point, nested arrays, references | CPU |
| `spectral_norm` | many calls to small functions, floating point | CPU |
| `fannkuch` | integer arrays, permutations | CPU |
| `mandelbrot` | pure floating-point loops, no allocation | CPU |
| `binary_trees` | heavy allocation/deallocation, recursion, GC | Memory |
| `hashmap` | associative arrays with string keys (counting by key) | Memory/hash |
| `json` | `json_encode`/`json_decode` of API-style data | Library |
| `strings` | string concatenation, `str_replace`, `explode`, `strtoupper` | Library |
| `objects` | object creation, method calls, interfaces | OOP |

Input sizes are in `benchmarks/workloads.json` (`full` ≈ 1–3 seconds in interpreter mode, `quick` for CI). `tests/check-workloads.sh` checks that the 4 languages produce the same result.

The **PGO training** set (`benchmarks/training/` + `Zend/micro_bench.php` + part of the PHPTs) is **separate** from the benchmark set. This way the PGO results do not "memorize" the benchmarks.

## 2. How measurements are taken (`scripts/bench.py`)

- Each run is a new process: `php -n` (does not read the machine's php.ini), `memory_limit=-1`.
- `interp` mode: OPcache on, JIT off, i.e. it measures the Zend VM. `jit` mode: OPcache + `opcache.jit=tracing`, 128M buffer (PHP ≥ 8.0 only).
- 1 warm-up run, then the contenders are **interleaved** (A B C A B C …), 7 rounds by default. This way machine noise (temperature, other processes) affects all of them equally.
- Recorded: the wall time of each run, the process's **peak RSS** (via `wait4`), stdout.
- Reported: median, min, max, standard deviation, **CV** (coefficient of variation), and the **95% bootstrap confidence interval** of the median ratio.
  - ✔ = statistically significantly faster, ✘ = significantly slower, no mark = not yet distinguishable from noise.
- If a contender's stdout differs from the baseline, the result is marked `INVALID` and **is not used**.
- Summary: the geometric mean of the speedup across all workloads.

Each report `results/<date>-.../summary.md` records everything: CPU, vCPU count, RAM, OS, kernel, gcc, QHturbo commit, upstream tag, binary sha256, number of rounds and warm-ups. Every individual measurement (time, RSS) is in `raw/results.json`; this file is committed so it can be re-checked.

## 3. A/A check (noise level)

When `patches/<branch>/series` is empty, `qhturbo` has **the same source code** as `baseline`. Comparing the two is an A/A test: the measured difference is the machine's noise level. A patch whose effect is smaller than the A/A noise is considered **not proven**.

## 4. Comparison with other languages (`bench.py cross`)

- Same algorithm, same data, same output. Go is compiled ahead of time (compilation time not counted); for the other languages, runtime start-up time is included.
- No special native libraries (numpy, SIMD...) in any language.
- This is a **single-threaded CPU benchmark**. Do not infer web/I/O performance from it. Web/API is measured separately in Phase 5 (wrk/k6 with PHP-FPM).

## 5. Interpretation rules

1. Do not publish "X% faster" if the 95% CI contains 1.0.
2. Report workloads that got slower too, not just the flattering ones.
3. Official benchmarks run on a Linux filesystem (WSL: `~/QHTurbo-lab`, not a Windows drive under `/mnt/`).
4. Shared virtual machines (cloud) are noisy. If CV > 5%, increase `--runs` or re-measure on a dedicated machine.
