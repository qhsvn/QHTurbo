# Issue log

**English** · [Tiếng Việt](issues.vi.md)

## ISSUE-001 — GCC LTO cannot build the Zend VM (resolved: LTO dropped)

- **Date:** 2026-10-22
- **Branch:** 8.5 (possibly other branches that use global register variables too)
- **Symptom:** `make prof-gen` with `CFLAGS=-flto=auto` fails at link time:
  ```
  ext/opcache/jit/zend_jit_vm_helpers.c:38:34: error: global register variable follows a function definition
  lto-wrapper: fatal error: make returned 2 exit status
  ```
- **Cause:** the Zend VM declares `register ... asm("r14")`/`asm("r15")` for `execute_data`/`opline` (`HAVE_GCC_GLOBAL_REGS`). When LTO merges many files into one compilation unit, this declaration ends up after other function definitions, which GCC does not allow.
- **Options:**
  1. `--disable-gcc-global-regs` to make LTO usable: loses the VM's most important optimization. Not chosen.
  2. Drop LTO, keep PGO: **chosen**.
  3. Compile the files with global registers separately with `-fno-lto`: requires build system changes. Deferred to Phase 4.
- **Status:** closed (using option 2); the follow-up item is in `docs/phases/phase-04-compiler-jit.md`.

## ISSUE-002 — The user's Windows machine is not reachable from the cloud build environment

- **Date:** 2026-10-22
- **Symptom:** the work session runs on a Linux cloud machine and cannot write directly to the disk of the user's Windows machine.
- **Resolution:** the project is pushed to GitHub `qhsvn/QHTurbo`. On Windows, run:
  ```powershell
  git clone https://github.com/qhsvn/QHTurbo.git
  ```
  Builds and benchmarks should run in WSL on the Linux filesystem (`QHT_LAB=~/QHTurbo-lab`). See README.
- **Status:** closed.

## ISSUE-003 — The test environment is a shared cloud VM

- **Date:** 2026-10-22
- **Impact:** noise (CV) is higher than on a dedicated machine; an A/A test (`baseline` vs `qhturbo` with no patches yet) is used to gauge the noise level.
- **To do:** re-measure on a dedicated machine or staging server before publishing numbers for production.
- **Status:** open.

## ISSUE-004 — Single-process mode shuts itself down after `--max-requests` (fixed)

- **Date:** 2026-10-22
- **Symptom:** the `/io` benchmark reports 100% errors in Performance mode. The simulated backend (`--workers=0`) served 10,089 requests and then stopped for good, so every call got `connection refused`. The FPM side only received warnings but still returned 200, so the FPM measurements were wrong too.
- **Cause:** the default recycle limit (10,000 requests) still applied even though there was no supervisor to restart the process.
- **Fix:** with `--workers=0`, recycling is disabled and a warning is logged. New test (`single-process mode keeps serving`). The benchmark's FPM front controller returns 500 when the backend fails, so errors are no longer hidden.

## ISSUE-005 — Memory leak from cancelled timers (fixed)

- **Date:** 2026-10-22
- **Symptom:** under 30 s of sustained load (~26,000 req/s), the total PSS of 4 workers grows linearly 129 → 315 MiB.
- **Cause:** every `read()` with a timeout creates a timer (5–30 s). A cancelled timer was only removed from the table, while its entry in the `SplPriorityQueue` stayed until it came due, about 750,000 entries.
- **Fix:** `Loop::cancel()` rebuilds the queue when the number of cancelled timers is > 1024 and > the number of live timers. Added `Loop::stats()` and a test (50,000 cancelled timeouts, queue ≤ 1,100). Re-measured: 54 MiB, flat for the full 30 s.

## ISSUE-006 — Race on worker shutdown: a just-accepted connection is closed without a response (fixed)

- **Date:** 2026-10-22
- **Found by:** CI (GitHub Actions) reported failures in the runtime test suite on PHP 8.3/8.4/8.5. Reproduced on the build machine with OPcache + JIT enabled for the CLI (like the default `setup-php` configuration): in the SIGHUP reload test, 1 request was closed without a response.
- **Cause:** (1) if the worker starts stopping between `accept()` and the first read, the serving loop exits immediately; (2) on shutdown, the worker closed **all** pending connections, including new connections that had not yet sent a request.
- **Fix:** a new connection always gets its first request served; on shutdown only **idle** keep-alive connections are closed. The test run 16 times in a loop (with and without JIT) passes every time.

## ISSUE-007 — Intermittent failed request during SIGHUP reload (fixed)

- **Date:** 2026-10-22
- **Symptom:** CI run at commit `0666733` (after the ISSUE-006 fix): job `workloads (8.5)`, the runtime tests step failed once. The log could not be read because GitHub's log storage is blocked from the build machine.
- **Done:** the test runner now prints failures as `::error` annotations (readable via the API). After that, 3 consecutive CI runs (1 new + 2 re-runs) were all green on 7.4 → 8.5, and 16 runs on the build machine (with/without JIT) also passed.
- **Recurred** at commit `bd6179a` on PHP 8.1; the annotation pointed to the exact test: `SIGHUP reload ... 1 failed requests during reload`.
- **Cause:** the SIGTERM signal (async) can run **between** `stream_socket_accept()` and the moment the connection is recorded in the list. The worker then sees "no connections left", schedules a stop, and the just-accepted connection is closed without a response. It is very hard to hit under normal load (25 runs under CPU load on the build machine did not reproduce it).
- **Fix:** the final stop step is deferred to the next tick and **re-checks** that no connection or request remains; if one does, the stop waits for that connection to finish.
- **Status:** fixed; continuing to monitor via CI.

## ISSUE-008 — Worker crashes when `disable_functions` blocks `pcntl_signal` (fixed)

- **Date:** 2026-10-22
- **Found by:** trying the aaPanel integration. aaPanel blocks `pcntl_*` in php.ini; running `qhturbo-serve` with that php.ini made single-process mode crash (`Call to undefined function pcntl_signal()`), while multi-worker mode wrongly reported "missing pcntl extension".
- **Fix:** each required function is checked individually. Single-process mode still runs (with a warning: SIGTERM stops immediately without waiting for requests to finish). Multi-worker mode clearly reports which function is blocked by `disable_functions` and how to use `performance.d`. New test; `scripts/aapanel.sh` automatically creates `performance.d/05-from-aapanel.ini` that unblocks `pcntl_*` for workers.

## ISSUE-009 — Symfony in a worker leaks exception details in prod (fixed)

- **Date:** 2026-10-22
- **Found by:** the test `symfony: ... safe 500`. The 500 response contained the dump `RuntimeException {#125 #message: "secret detail" ...}` despite `APP_ENV=prod`, `APP_DEBUG=0`.
- **Cause:** Symfony picks its error renderer based on `kernel.runtime_mode`; without `APP_RUNTIME_MODE`, it infers the mode from `PHP_SAPI` (`cli`) and so uses `CliErrorRenderer`.
- **Fix:** the adapter sets `APP_RUNTIME_MODE=web=1&worker=1` before booting the kernel (like symfony/runtime and FrankenPHP).

## ISSUE-010 — Uploads in Laravel have `isValid() = false` (fixed)

- **Cause:** Laravel converts Symfony's `UploadedFile` to its own class with `createFromBase()` without preserving test mode, so `is_uploaded_file()` rejects the worker's temporary file.
- **Fix:** the adapter creates an `Illuminate\Http\UploadedFile` in test mode directly.

## ISSUE-011 — Laravel memory "leak" with the `array` driver (handled with a warning)

- **Found by:** `StateGuard` reported steadily growing RAM (about 700 bytes/request). Walking the entire object graph showed that `ArraySessionHandler->storage` keeps every session in the worker's RAM.
- **Conclusion:** this is inherent to the `array` driver (meant for tests), not an adapter bug. With `file`/`redis`: growth of about 2 bytes/request over 20,000 requests.
- **Resolution:** the adapter warns at startup if `session.driver` or `cache.default` is `array`.

## ISSUE-012 — Laravel benchmark skewed by a bloated session directory (handled in the measurement method)

- **Symptom:** in the first measurement, both FPM and worker reached only about 100–300 req/s, and the worker was even slower than FPM.
- **Cause:** 43,942 session files (175 MB) created by the benchmark; Laravel periodically scans the whole directory to clean up old sessions, so each run was slower than the previous one.
- **Resolution:** cleaned the directory and used Redis for session/cache in both modes. The old measurements were discarded.

## ISSUE-013 — `aapanel.sh sync-ini` stops midway on a real php.ini (fixed)

- **Date:** 2026-10-22
- **Found by:** code review before the trial run. aaPanel's default php.ini has `session.save_handler = files` but `session.save_path` commented out. With `set -euo pipefail`, a `grep` that finds no line returns exit code 1 and the script exits immediately, without any error message, before it finishes writing the configuration file.
- **Fix:** added `|| true` to the "no match is fine" `grep` calls. `tests/aapanel-sim.sh` now uses a php.ini with `;session.save_path`; the old script fails at the `sync-ini` step, the new one passes 13/13.

## ISSUE-014 — Pool loses a connection when a timeout coincides with a connection being returned (fixed)

- **Cause:** `acquire()` wrapped the waiting future in `timeout()`. When the timer expired, the outer future failed but the waiting future in the queue remained "pending" until the fiber resumed. If `release()` ran in that window (same tick), the connection was handed to a waiter that had already given up and was lost for good: `size` still counted it, so the pool gradually ran dry.
- **Fix:** the timer fails the waiting future itself and removes it from the queue in the same step. Test `pool: a waiter that timed out never receives a connection`.

## ISSUE-015 — Reload and signals in the supervisor (fixed)

- **Reload did not load new code with `opcache.validate_timestamps=0`** (the default Performance configuration): `opcache_reset()` in a CLI process only sets a "reset pending" flag, and the reset happens at the next request startup, which the master process never has. While pending, OPcache stops caching. The master now invalidates each cached script (`opcache_invalidate`), which takes effect immediately in shared memory.
- **A broken deploy brought the service down:** reload spawned new workers and killed the old ones immediately, even when the new workers died from a syntax error. Now new workers signal "ready" over a socket pair; only then are the old workers stopped (with up to 60 seconds to finish in-flight requests).
- **Child workers inherited the master's SIGTERM handler:** a SIGTERM arriving while a worker was booting would run the master's `shutdown()` *inside the worker*, after which the worker kept running and the master waited forever. Workers now reset SIGTERM/SIGINT to the default right after `fork()`.
- Tests: `server: reload loads new code; a broken deploy keeps the old workers`, `server: SIGTERM while workers are still booting stops promptly` (the old code hung on this test).

## ISSUE-016 — Laravel: config keys added during a request are not removed (fixed)

- **Cause:** restoring with `config()->set($snapshot)` only overwrites the keys present in the snapshot; new keys (e.g. `config(['tenant' => ...])`) remained and carried over into another user's request. Because the array never equalled the snapshot again, the adapter also logged "config changed" on every subsequent request.
- **Fix:** replace the repository's whole `items` array. When the config has not changed, the comparison is just a pointer comparison (shared array, copy-on-write). Test in `frameworks.php`.

## ISSUE-017 — Sites lose their database connection after switching to QHturbo (fixed)

- **Date:** 2026-10-22
- **Found:** first real aaPanel server (Apache, RHEL family). A site switched with `aapanel.sh enable` reported that it could not connect to the database.
- **Cause:** the PHP-FPM systemd unit had `PrivateTmp=true`, so PHP saw an empty private `/tmp`. aaPanel's MySQL listens on `/tmp/mysql.sock`, which PHP uses whenever the database host is `localhost` (WordPress and most PHP apps). aaPanel's own PHP-FPM has no such setting. The simulated aaPanel tests did not run PHP-FPM under systemd, so they could not see it.
- **Fix:** `PrivateTmp` removed from the unit. `install.sh` now refreshes the reference unit files in `share/` (configuration in `etc/` is still never overwritten), and `aapanel.sh sync-ini` also copies `mysqli.default_socket`, `pdo_mysql.default_socket` and `mysqli.default_port`. Existing installs: `sudo sed -i '/^PrivateTmp=/d' /etc/systemd/system/qhturbo-php85-fpm.service && sudo systemctl daemon-reload && sudo systemctl restart qhturbo-php85-fpm`.
