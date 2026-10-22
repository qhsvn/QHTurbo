# Development log

**English** · [Tiếng Việt](development.vi.md)

One entry per change, following the template in `docs/original-plan.md` §6.2. Times are in UTC.

## 2026-10-22 06:30 — QHT-P01-001 Project setup

- **Goal:** set up the repo and reproducible build/test/benchmark tooling, following the original plan.
- **Environment:** Linux cloud build machine (Ubuntu 24.04.5, kernel 6.18, 4 vCPU, 15 GiB RAM, gcc 13.3.0). It cannot write directly to the user's Windows machine, so the project is pushed to GitHub `qhsvn/QHTurbo` (ISSUE-002).
- **Results:**
  - Verified the latest upstream tag of each branch via `git ls-remote` (commits pinned in `config/versions/`). `php-8.5.12` = `136e049a…`, matching the original plan.
  - The user asked for support for **PHP 7.4 and later**, so all scripts were converted to multi-version (`QHT_PHP=7.4…8.5`).
  - Baseline builds of 8.5.12 and 7.4.33 succeeded (gcc 13.3, `--disable-all` + cli/ctype/tokenizer/filter/mbstring/opcache). OPcache: static in 8.5, `opcache.so` in 7.4.
- **Decision:** KEEP.

## 2026-10-22 07:00 — QHT-JIT-001 Optimized build variant (LTO + PGO)

- **Hypothesis:** LTO + PGO make the interpreter faster without any source changes.
- **Result of attempt 1:** LTO fails at link time because of global register variables (ISSUE-001), so **LTO was dropped**, keeping only PGO.
- **Result of attempt 2 (PGO only):** a quick single run on nbody (machine busy building): PGO 2.95 s vs baseline 2.63 s, i.e. **slower**.
  - Analysis: with `-fprofile-use`, GCC optimizes for size the opcode functions/handlers that did not run during training. The Zend VM has thousands of specialized handlers, many of which run only in the measured workloads.
  - Fix: added `-fprofile-partial-training` (GCC ≥ 10).
- **Result of attempt 3 (PGO v1 + partial-training, full 10-round measurement):** interp −4.1%, jit −4.7% (geomean), while A/A was only +0.6% / −2.5%. Details in `logs/benchmarks.md`.
- **Decision:** NO-GO for v1, investigate further (v2 below).

## 2026-10-22 07:35 — QHT-JIT-001 (continued) PGO v2

- **Hypothesis:** PGO v1 is slow because training ran without OPcache, so the type-specialized handlers (emitted only when the optimizer is on) were treated as cold code.
- **Change:** `scripts/pgo-train.sh` runs the training set in 3 modes: OPcache on, OPcache off, JIT tracing; PHPT with OPcache on; added `Zend/bench.php` and `ext/spl/tests`.
- **Commands:** `QHT_PHP=8.5 scripts/build.sh qhturbo-pgo`; `taskset -c 2 python3 scripts/bench.py php --php 8.5 --variants baseline,qhturbo,qhturbo-pgo --runs 10 --name pgo-v2`
- **Benchmark:** interp +0.3%, jit −3.2% (geomean). Details in `logs/benchmarks.md`.
- **Decision:** **NO-GO.** PGO is not included in the recommended build. Next ideas (Phase 4): train with real web requests (FPM), compare against clang PGO, measure on a dedicated low-noise machine.

## 2026-10-22 08:05 — QHT-P01-002 Fix 7.4 build missing json

- **Symptom:** the `json` workload fails (exit 255) on 7.4: `--disable-all` disables json, because in 7.4 json is not yet a core extension.
- **Fix:** `config/build.env` adds `--enable-json` when `QHT_PHP=7.*`. Rebuilt the 7.4 baseline; `php -m` now lists json.
- **Decision:** KEEP.

## 2026-10-22 09:00 — QHT-WEB-001 Two modes: Compatible / Performance

- **User requirements:** (1) Compatible: standard PHP, PHP-FPM, Composer, legacy applications, minimal changes; (2) Performance: long-running workers, event loop, async I/O, accepting state rules; (3) propose improvements.
- **Design:** one and the same binary (`full` profile); the two modes differ in how they run and how they are configured. Performance is a pure-PHP runtime (`runtime/`) that needs no external extension, which keeps it easy to control. It requires PHP 8.1+ because it uses Fibers.
- **Compatible verification:** built 8.5 `full` (51 modules), `install.sh` into `/opt/qhturbo/8.5`, `php-fpm -t` OK. nginx → FPM runs a legacy app (session, cookie, header, upload, SQLite, intl, gd, openssl, sodium). Composer 2.8 on PHP QHturbo installed Laravel 13.38 (more than 100 packages). Laravel via FPM returns 200, `artisan test` 2/2.
- **Performance verification:** 16 tests (PHP 8.5.12 and 8.3.6). Web benchmark: 7–8× on the CPU tests, 2.5× on the I/O test with half the RAM. Found and fixed 2 bugs thanks to benchmarking (ISSUE-004, ISSUE-005).
- **Decision:** KEEP. Next steps per `docs/proposals.md`: async MySQL/Redis drivers, Laravel/Symfony adapters, aaPanel plugin.

## 2026-10-22 11:00 — QHT-WEB-004/005 Async Redis/MySQL and Laravel/Symfony adapters

- **Requirement:** implement item 1 (Async MySQL/Redis) and item 2 (Laravel/Symfony adapters) in `docs/proposals.md`.
- **MySQL design:** use `MYSQLI_ASYNC` + `mysqli_poll` instead of writing our own MySQL protocol implementation. mysqlnd handles authentication (including `caching_sha2_password`), TLS and charset. The trade-off is polling every 0.5 ms while a query is in flight (no polling when idle).
- **Adapter design:** pass requests through HttpFoundation. Laravel: Octane-style state cleanup on a shared application (no cloning). Symfony: relies on the built-in `kernel.reset`.
- **Verification:** 24 runtime tests and 6 framework tests pass. Load tests: Laravel 6.7–14.6×, Symfony 6.3×, async MySQL 1.7× vs FPM ×64. Laravel leak check (20,000 requests): 2 bytes/request.
- **Bugs found and fixed:** ISSUE-009 (Symfony leaks error details), ISSUE-010 (Laravel uploads), ISSUE-011 (`array` driver), ISSUE-012 (measurement method).
- **Decision:** KEEP.
