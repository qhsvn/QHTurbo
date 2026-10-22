# Benchmark log

**English** · [Tiếng Việt](benchmarks.vi.md)

Summary of each measurement run. Full tables are in `results/<directory>/<mode>/summary.md`.
Test machine (every run, unless noted otherwise): Firecracker cloud VM, 4 vCPU, 15 GiB RAM, Ubuntu 24.04.5, kernel 6.18, gcc 13.3.0. Benchmarks are pinned to 1 core (`taskset -c 2`), 1 warm-up run + 10 interleaved measured runs.

## 2026-10-22 — PHP 8.5.12: baseline / qhturbo (A/A) / qhturbo-pgo v1

Report: `results/2026-10-22-php8.5-pgo-v1/{interp,jit}/summary.md`

| Mode | qhturbo (A/A, same source) | qhturbo-pgo v1 |
|---|---|---|
| interp (geomean) | +0.6% | **−4.1%** |
| jit (geomean) | −2.5% | **−4.7%** |

- **Noise:** the A/A test ranges from −7.7% to +3.3% per workload, and the per-workload CV is 4–17%. In JIT mode, A/A was even flagged as "significant" on `json` (−6.3% ✘). This means that on this machine the ✔/✘ mark of a single workload **is not yet trustworthy**: the noise is not independent between runs (shared virtual machine). Only trust effects clearly larger than the A/A level, and prefer the geomean.
- **PGO v1 is slower** (spectral_norm −11.7%, fannkuch −4.7% in interp; mandelbrot −24.6% in JIT).
- **Cause (hypothesis, well-founded):** the v1 training set ran **without OPcache**. With OPcache enabled, the optimizer emits type-specialized handlers (`ZEND_ADD_DOUBLE`, `ZEND_IS_SMALLER_LONG`...). These handlers did not run during training, so they were treated as cold code. JIT was not enabled during training either.
- **Decision:** PGO v1 → **NO-GO**. Fix the training set (v2: run with OPcache on/off and JIT), then measure again.

## 2026-10-22 — PHP 8.5.12: qhturbo-pgo v2 (training with OPcache + JIT)

Report: `results/2026-10-22-php8.5-pgo-v2/{interp,jit}/summary.md`

| Mode | qhturbo (A/A) | qhturbo-pgo v2 |
|---|---|---|
| interp (geomean) | +0.2% | +0.3% |
| jit (geomean) | −0.5% | **−3.2%** |

- interp: mandelbrot +16.0% ✔ and strings +5.7%, but spectral_norm −18.9% ✘. Zero overall.
- jit: mandelbrot −20.5% ✘ (repeating exactly what happened in v1), spectral_norm −12.0%.
- This time A/A shows `objects` +7.7% ✔ even though both binaries come from the same source. This is further evidence that the noise threshold on this machine is about ±8–9% per workload.
- **Decision: PGO → NO-GO.** No stable benefit; repeated regressions on mandelbrot (JIT) and spectral_norm. Keep the `qhturbo-pgo` variant for further experiments (different training set, newer gcc/clang, dedicated test machine), **not** for release builds.

## JIT vs interpreter (baseline 8.5.12, same run)

| Workload | interp (s) | JIT tracing (s) | JIT faster by |
|---|---|---|---|
| fannkuch | 3.968 | 1.062 | 3.7× |
| spectral_norm | 0.998 | 0.326 | 3.1× |
| mandelbrot | 1.282 | 0.733 | 1.7× |
| nbody | 2.570 | 1.678 | 1.5× |
| objects | 1.424 | 0.932 | 1.5× |
| binary_trees | 1.664 | 1.136 | 1.5× |
| json | 1.516 | 1.380 | 1.1× |
| strings | 1.227 | 1.078 | 1.1× |
| hashmap | 1.769 | 1.633 | 1.1× |

Conclusion: JIT gives a very large benefit to pure-PHP computational code (1.5–3.7×), and almost none when time is spent inside built-in C functions (json, strings, hashing). That is also the profile of most web requests.

## 2026-10-22 — PHP 7.4.33 vs PHP 8.5.12 (stock builds, same machine)

Report: `results/2026-10-22-cross-php7.4-8.5-php/summary.md` (7 measurement rounds)

| Workload | 7.4 interp (s) | 8.5 interp (s) | 8.5 JIT (s) | 8.5 interp / 7.4 | 8.5 JIT / 7.4 |
|---|---|---|---|---|---|
| binary_trees | 2.267 | 1.730 | 1.151 | 1.31× | 1.97× |
| fannkuch | 3.611 | 3.881 | 1.018 | **0.93×** | 3.55× |
| hashmap | 1.848 | 1.889 | 1.827 | 0.98× | 1.01× |
| json | 1.630 | 1.484 | 1.395 | 1.10× | 1.17× |
| mandelbrot | 1.068 | 1.312 | 0.748 | **0.81×** | 1.43× |
| nbody | 2.400 | 2.576 | 1.882 | 0.93× | 1.28× |
| objects | 1.579 | 1.506 | 0.962 | 1.05× | 1.64× |
| spectral_norm | 1.276 | 1.026 | 0.339 | 1.24× | 3.76× |
| strings | 1.514 | 1.236 | 1.228 | 1.22× | 1.23× |
| **geomean** | | | | **1.05×** | **1.69×** |

- Merely upgrading from 7.4 to 8.5 while staying on the interpreter is 5% faster on average. Pure floating-point loops (mandelbrot, fannkuch, nbody) are even **slower** by 7–19% on this machine. This needs to be re-measured on a dedicated machine before drawing firm conclusions.
- 8.5 uses less RAM on allocation-heavy workloads (binary_trees 58 → 41 MiB, strings 66 → 58 MiB).
- **The big gain comes from JIT (available only since 8.0)**: 1.69× over 7.4.

## 2026-10-22 — PHP 8.5.12 vs Python, Node.js, Go

Report: `results/2026-10-22-cross-php8.5-python-node-go/summary.md` (5 measurement rounds). Python 3.13.16, Node.js 22.22.0, Go 1.24.7 (precompiled binary).

Speed factor relative to the PHP 8.5 interpreter (greater than 1 means faster than PHP interp):

| Workload | PHP 8.5 JIT | Python 3.13 | Node.js 22 | Go 1.24 |
|---|---|---|---|---|
| binary_trees | 1.43× | 0.75× | 3.05× | 1.47× |
| fannkuch | 3.90× | 0.40× | 6.74× | 15.97× |
| hashmap | 1.09× | 0.41× | **0.48×** | **0.83×** |
| json | 0.99× | 0.53× | 1.22× | **0.41×** |
| mandelbrot | 1.47× | 0.10× | 7.88× | 9.92× |
| nbody | 1.36× | 0.43× | 12.04× | 25.82× |
| objects | 1.37× | 0.43× | 11.61× | 7.25× |
| spectral_norm | 3.24× | 0.20× | 11.07× | 26.46× |
| strings | 1.03× | 0.47× | **0.37×** | **0.95×** |
| **geomean** | **1.56×** | **0.36×** | **3.36×** | **4.16×** |

Reading the results:
- **PHP is faster than Python on all 9 benchmarks** (PHP interp is about 2.8× faster, PHP JIT about 4.3×).
- **PHP beats Node.js and Go** on the benchmarks where time is spent in PHP's C library: `hashmap` (associative arrays), `strings`, and `json` (beats Go; `encoding/json` uses reflection and is therefore slow).
- **PHP falls far behind Node.js and Go on pure numeric computation and OOP** (Node.js/Go are 6–26× faster than PHP interp, and still 1.7–19× faster than PHP JIT). This is an architectural limit (dynamic typing, zvals, a JIT weaker than V8 or AOT-compiled Go). Configuration tuning or PGO cannot close this gap.
- RAM: PHP is on par with Python and Go (15–60 MiB); Node.js uses 50–335 MiB.

## 2026-10-22 — Web: QHturbo Compatible (FPM) vs Performance (worker)

Report: `results/web/2026-10-22-php8.5-modes.md` (`benchmarks/web/run.sh`). PHP 8.5.12 `full` build, simulated framework (300 classes, container, 40 configs, 203 routes), wrk -t2 for 10 s after a 3 s warm-up, nginx + PHP + wrk on the same 4 vCPU. RAM measured as PSS (shared memory counted only once).

| Test | Compatible FPM ×4 | +perf-ini FPM ×4 | Performance ×4 |
|---|---|---|---|
| `/hello` | 2,991 req/s, p99 16.1 ms, 33 MiB | 3,174 | **23,598 req/s, p99 6.8 ms, 43 MiB (7.9×)** |
| `/api/users/42` | 3,008 req/s, p99 15.3 ms, 30 MiB | 2,865 | **21,266 req/s, p99 7.2 ms, 43 MiB (7.1×)** |

| `/io` (3 × 20 ms backend) | req/s | p99 | RAM |
|---|---|---|---|
| FPM ×4 (blocking I/O) | 49 (491 timeouts) | — | 30 MiB |
| FPM ×64 (blocking I/O) | 939 | 162 ms | 104 MiB |
| Performance ×4, concurrency 64 (async) | **2,389** | **80 ms** | **56 MiB** |

- The Performance configuration for FPM (`validate_timestamps=0` + JIT) only changes results by −5% to +6%, so configuration is not the main factor.
- The first run was discarded because of ISSUE-004. RSS-based RAM measurement double-counts the shared OPcache, so we switched to PSS.
- Leak check: 30 s of sustained load (775,525 requests), PSS steady at 54 MiB (after fixing ISSUE-005).

## 2026-10-22 — Real Laravel 13 / Symfony 8.1: FPM vs worker

Reports: `results/web/2026-10-22-laravel13-fpm-vs-workers.md`, `results/web/2026-10-22-symfony81-fpm-vs-workers.md` (`benchmarks/web/framework.sh`). 4 vCPU, PHP 8.5.12, FPM `pm=static` with 8 processes, worker with 4 processes at concurrency 1, wrk -c32 for 10 s.

| Route | FPM | Worker | × |
|---|---|---|---|
| Laravel `/up` | 353 | 5,150 | 14.6 |
| Laravel `/` (Blade, Redis session) | 288 | 1,924 | 6.7 |
| Laravel JSON route (web, Redis session) | 319 | 2,295 | 7.2 |
| Symfony `/qht/hello` | 1,737 | 10,958 | 6.3 |
| Symfony `/qht/json` | 1,776 | 11,160 | 6.3 |

Laravel configuration: production, `artisan optimize`, session and cache on Redis (phpredis). The first measurement (file sessions) was discarded, see ISSUE-012. RAM: Laravel 98–101 MiB (FPM) vs 123–151 MiB (worker).

## 2026-10-22 — MySQL I/O: blocking PDO vs async client

Report: `results/web/2026-10-22-mysql-io-async.md` (`benchmarks/web/db/run.sh`). Each request runs 3 × `SELECT SLEEP(0.02)`, MariaDB 10.11 on the same machine, wrk -c128.

| Configuration | req/s | p99 |
|---|---|---|
| FPM ×4 + PDO | 51 (457 timeouts) | 2 s |
| FPM ×64 + PDO | 1,011 | 163 ms |
| Worker ×4 + PDO, concurrency 1 | 51 (300 timeouts) | 2 s |
| Worker ×4 + async MySQL, concurrency 64 | **1,724** | **115 ms** |
