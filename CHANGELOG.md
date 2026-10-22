# CHANGELOG

**English** · [Tiếng Việt](CHANGELOG.vi.md)

Format follows [Keep a Changelog](https://keepachangelog.com/). Every measurement comes with a path to its report in `results/`.

## [Unreleased]

### Added / Fixed — first run on a real aaPanel server (2026-10-22)
- `aapanel.sh` supports aaPanel with **Apache** (detected automatically, or `--web=nginx|apache`): `enable` points the site's `SetHandler "proxy:unix:...|fcgi://localhost"` at QHturbo, with the same backup, config test and automatic rollback as nginx. New test `tests/aapanel-sim-apache.sh` (14/14).
- Sites could not reach MySQL after switching (ISSUE-017): the PHP-FPM unit used `PrivateTmp`, hiding aaPanel's `/tmp/mysql.sock`. Removed; `sync-ini` also copies the MySQL socket settings.
- `aapanel.sh install` stops **before** the build when no supported web server is found (it used to warn, build for 10–40 minutes, then fail while writing the nginx include).
- `aapanel.sh install` failed on a fresh server with `failed to open error_log (/var/log/qhturbo/...)`: the PHP-FPM config test ran before the log directory existed. `install.sh` now creates `/run/qhturbo` and `/var/log/qhturbo` too.
- Website (`website/`, GitHub Pages) with install guides and examples, and English/Vietnamese versions of every document.

### Fixed — bug review before trial run (2026-10-22)
Reviewed the whole codebase (43 findings), fixed the real bugs and added a regression test for each important one (ISSUE-013 to ISSUE-016).
- **aaPanel:** `aapanel.sh sync-ini` stopped halfway when php.ini was missing a key (for example `session.save_path` commented out), because `grep` found no match under `set -o pipefail` (ISSUE-013). `performance.d` now re-enables only the exact `pcntl_*`/`posix_kill` functions the worker needs; `pcntl_exec` stays blocked. The rollback check follows HTTP→HTTPS redirects.
- **Scripts:** `install-ext.sh` exited silently when no version was found; `run-tests.sh` did not `shift` when given a single argument and ignored `QHT_PROFILE`; `make-patch.sh` ignored `QHT_PROFILE`; `build.sh` was missing a space between LDFLAGS flags; `qht-doctor` skipped entire projects located in a directory named `cache`/`tests`; `package.sh` overwrote the server's configuration when extracted and used the build machine's CPU count (configuration now lives in `share/qhturbo/etc.dist`; run `setup.sh` after extracting).
- **Pool:** a request timing out while waiting for a connection at the exact moment a connection was returned lost that connection for good (ISSUE-014).
- **Supervisor:** reload did not load new code with `opcache.validate_timestamps=0` (`opcache_reset()` has no effect in a CLI process and also stops OPcache from caching); reload killed the old workers even when the new code failed to boot, now the old workers are kept; waiting for old workers had no time limit; a child worker ran the master's SIGTERM handler when it received the signal during startup, so the master never stopped (ISSUE-015); new workers were spawned after SIGTERM had been received.
- **HTTP server:** negative/invalid/duplicate `Content-Length` was accepted (request smuggling risk); repeated headers were overwritten instead of joined; `Expect: 100-continue` was not supported (curl waited 1 second before every large upload); no limit on the number of connections (exceeding `FD_SETSIZE` made the worker spin at 100% CPU), added `--max-connections` and `LimitNOFILE`; the handler slot was held while sending the response to a slow client; framing headers (`Content-Length`, `Transfer-Encoding`) set by the application with different letter case were sent twice; CR/LF in header values; HEAD/204/304; IPv6 addresses (`REMOTE_ADDR`, `--host=::`, Host header); temporary upload files were left behind on write errors.
- **State:** reset hooks did not run with `--concurrency > 1` under sustained load; `$_SERVER` was restored to its pre-bootstrap state (losing `.env` values); the output buffer cleanup loop spun forever when a buffer could not be removed; static property measurement was slow with large frameworks (now only newly loaded classes are reflected).
- **Laravel/Symfony:** config keys newly added during a request were not removed and were logged on every following request (ISSUE-016); `StreamedResponse` calling `ob_flush()` sent data to the worker's STDOUT instead of the response.
- **MySQL/Redis:** automatic retry after a "gone away" error could run an INSERT/UPDATE twice (now it only retries when the statement has not been sent yet); connections idle for more than 30 seconds are checked before use; added error codes 4031, 2014, 1053; the 0.5 ms poll timer did not stop after a timeout; `--` in `5--?` was treated as a comment; `COMMIT WORK`/`ROLLBACK WORK` were not recognised; a password containing `+` in a URI was turned into a space; support for `unix:///path/redis.sock`; the HTTP client omitted the port in the Host header.
- **CLI:** `--memory-limit=256MB` was read as 256 bytes (the worker was respawned after every request); `--framework` without a value, a negative worker count and an out-of-range port now give clear errors.
- Tests: 34 runtime tests (10 added); the framework tests also check config, `StreamedResponse`, HEAD; `tests/aapanel-sim.sh` adds the case of a php.ini missing `session.save_path` (13/13). All new tests fail on the old code.

### Added — async Redis/MySQL and framework adapters (2026-10-22)
- `runtime/src/Pool`: bounded connection pool shared by the requests of one worker (queueing when the pool is full, dropping broken connections, timeout when acquiring a connection).
- `runtime/src/Redis`: non-blocking Redis client (RESP2, pipelining, AUTH/SELECT, every command via `__call`).
- `runtime/src/Mysql`: non-blocking MySQL/MariaDB client (mysqlnd `MYSQLI_ASYNC` + `mysqli_poll`); `?` parameters are escaped, including `IN (?)`; `transaction()`; open transactions are rolled back when a connection is returned (R7); query timeout; automatic retry on "gone away".
- Runtime: `multipart/form-data` uploads (`$_FILES`, temporary files removed automatically), multi-value headers (`Set-Cookie`), `--max-body`.
- Adapter `--framework=laravel|symfony` (`runtime/src/Bridge`). Laravel: boots once, Octane-style state cleanup, warning when `array` drivers are used. Symfony: resets `kernel.reset` services, sets `APP_RUNTIME_MODE`.
- Tests: 24 runtime tests (8 Redis/MySQL tests added, against real servers), 6 framework tests on Laravel 13 and Symfony 8.1 (`runtime/tests/frameworks.php`). CI has a Redis 7 + MySQL 8.4 job and a framework job.
- Benchmarks: `benchmarks/web/framework.sh` (real Laravel/Symfony), `benchmarks/web/db/run.sh` (MySQL I/O).

### Fixed — while building the adapters
- Symfony running in a CLI worker printed exception details into 500 responses in prod (the CLI error renderer) → the adapter sets `APP_RUNTIME_MODE=web=1&worker=1` (ISSUE-009).
- File uploads in Laravel had `isValid() = false` → `Illuminate\Http\UploadedFile` is created in test mode (ISSUE-010).
- An error in a reset hook no longer loses the response.
- Async functions are protected against duplicate declaration when the runtime is loaded from two paths.

### Added — aaPanel and documentation (2026-10-22)
- `scripts/aapanel.sh`: `check`, `install`, `enable <site>` (per site, automatic rollback when `nginx -t` fails or the site returns 5xx), `disable`, `status`, `sync-ini` (copies `disable_functions` and limits from aaPanel's php.ini; the worker keeps `pcntl_*`), `uninstall`. The `check` command lists extensions missing compared with aaPanel's PHP.
- `scripts/install-ext.sh`: installs PECL extensions; if PECL is unreachable it uses the stable GitHub release (tested redis 6.3.0 on 8.5, apcu 5.1.28 on 7.4).
- `tests/aapanel-sim.sh`: end-to-end test on a simulated aaPanel (12/12).
- `docs/aapanel.md`: guide for a trial install on aaPanel.
- English README as the default (`README.md`), Vietnamese version in `README.vi.md`, with a key features section.

### Fixed
- Race on reload: a SIGTERM arriving in the middle of accept lost the response of the new connection (ISSUE-007).
- Worker crash when `disable_functions` blocks `pcntl_signal` (aaPanel's default); a clear error is reported (ISSUE-008).

### Changed
- Removed specific Windows paths (drive D:) from the documentation; instructions now use `git clone` into any directory.

### Added — two run modes (2026-10-22)
- **QHturbo Compatible**: build profile `full` (`QHT_PROFILE=full`) with PHP-FPM and commonly used extensions (openssl, curl, intl, gd, mysqli, pdo_mysql, zip, sodium, bcmath, soap, pcntl...). `scripts/install.sh` installs into `/opt/qhturbo/<branch>` beside the existing PHP, with a safe OPcache configuration, an FPM pool and a systemd unit. `scripts/package.sh` packages a `.tar.gz` (stripped, 15 MB) with a list of library dependencies.
- PHP 7.4/8.0 `full` builds use a private OpenSSL 1.1.1w (`scripts/deps-openssl11.sh`), leaving the system's OpenSSL 3 untouched.
- **QHturbo Performance** (`runtime/`, PHP ≥ 8.1): event loop, Fiber-based async I/O (`async/await/all/delay/timeout`, sockets, HTTP client), non-blocking HTTP/1.1 server, multi-worker supervisor (respawn by request count/memory, SIGHUP reload one worker at a time, graceful shutdown), `StateGuard` (reset hooks, cleanup of globals/ini/output buffers, detection of growing statics/memory, `--strict-state` mode, `--superglobals` for legacy code). 16 tests.
- `scripts/qht-doctor.php` (PHP 7.4+): checks configuration and scans source code for risks when moving to workers. `scripts/fpm-tune.py`: computes `pm.*` from RAM/RSS.
- Web benchmark of both modes (`benchmarks/web/run.sh`) and report `results/web/`.
- Documentation: `docs/modes/compatible.md`, `docs/modes/performance.md` (state rules R1–R9), `docs/proposals.md`.

### Fixed — runtime
- Single-process mode stopped for good after `--max-requests` because there was no supervisor to restart it: recycling is disabled when `--workers=0` (ISSUE-004).
- Memory leak under load: cancelled timeouts stayed in the timer queue (memory grew from 129 to 315 MiB in 30 s). Added queue cleanup: memory stable at 54 MiB (ISSUE-005).
- Oversized headers arriving in a single read were not rejected with status 431.

### Added — foundation
- Builds for several PHP branches: **7.4, 8.0, 8.1, 8.2, 8.3, 8.4, 8.5**, each pinned to a verified upstream tag and commit (`config/versions/`).
- `scripts/bootstrap.sh`: clones `php-src`, fetches the tag, verifies the commit; warns when an end-of-life (EOL) branch is used.
- `scripts/build.sh`: 3 variants `baseline` / `qhturbo` (patched) / `qhturbo-pgo` (PGO). Each variant has its own worktree and a `BUILD-INFO.txt` (sha256, build flags, patch list). The script never deletes unexported changes in a worktree.
- `scripts/make-patch.sh`: exports the changes in a worktree as a patch with a task ID and adds it to `patches/<branch>/series`.
- `scripts/run-tests.sh`: runs the official PHPT suite in interp / opcache / jit mode and saves a pass/fail/skip summary.
- `scripts/bench.py`: interleaved benchmarks, warm-up, peak RSS measurement, bootstrap 95% CI, output checks, geomean summary. Two commands: `php` (compare variants) and `cross` (compare with Python/Node.js/Go).
- 9 workloads written in 4 languages, with identical output (`tests/check-workloads.sh`). The PHP code is 7.4-compatible.
- Separate PGO training set (`benchmarks/training/`), kept apart from the measurement set.
- Production configuration profiles: `config/ini/opcache-production.ini`, `jit.ini`, `preload-example.ini`.
- GitHub Actions CI: shellcheck, PHP lint 7.4 → 8.5, output comparison between languages, build + PHPT + benchmark smoke for 7.4 and 8.5.
- Documentation: architecture, compatibility policy, benchmark methodology, security and release, 6 phases.

### Changed
- The optimised variant uses **PGO, not LTO**: GCC's LTO cannot build the Zend VM because it conflicts with global register variables (ISSUE-001).
- PGO uses `-fprofile-partial-training`; the training set runs with OPcache both on and off, and with JIT.

### Fixed
- The 7.4 build was missing the json extension (`--enable-json` for the 7.x branch).

### Performance
- **No speed improvement has been accepted yet.** PGO v1 (−4.1% / −4.7%) and v2 (+0.3% / −3.2%, interp / JIT) are both **NO-GO** (`results/2026-10-22-php8.5-pgo-v*/`).
- Reference measurements: JIT on 8.5 is 1.56× faster than the interpreter (geomean); 8.5 versus 7.4: 1.05× (interp), 1.69× (8.5 JIT); PHP 8.5 versus Python 3.13 / Node 22 / Go 1.24: see `logs/benchmarks.md`.

### Compatibility
- No source patches yet: PHP behaves exactly like upstream on every branch.
- PHPT: 8.5 baseline and 8.5 PGO both 13568 pass / 0 fail; 7.4 baseline 8997 pass / 46 fail (all due to the environment, see `PROJECT_STATUS.md`).
