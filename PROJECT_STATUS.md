# PHP QHturbo — Project Status

**English** · [Tiếng Việt](PROJECT_STATUS.vi.md)

Updated: 2026-10-22 12:45 UTC (19:45 +07:00)
Current stage: PHASE 05: the two modes, Compatible / Performance
Overview: **Phase 01 DONE · Phase 04, 05 IN_PROGRESS · the rest TODO**

Build/measurement machine: cloud VM (Intel Xeon 2.8 GHz, 4 vCPU, 15 GiB, Ubuntu 24.04.5, gcc 13.3.0). Not yet run on the user's Windows/WSL machine.

## Task board

| Task | Status | Commit / evidence | Notes |
|---|---|---|---|
| QHT-P01-001 Initialise repo, directory layout, documentation | DONE | repo `qhsvn/QHTurbo` | Synced via GitHub (ISSUE-002) |
| QHT-P01-002 Multi-version support PHP 7.4 → 8.5 | DONE | `config/versions/*.env`, `scripts/lib.sh` | Additional requirement from the user |
| QHT-P01-003 Build baseline 8.5.12 and 7.4.33 | DONE | `BUILD-INFO.txt` (sha256) | 7.4 needs `--enable-json` (fixed) |
| QHT-P01-004 PHPT baseline | DONE | `results/tests/` | 8.5: 13568 pass / 0 fail. 7.4: 8997 pass / 46 fail, all due to the environment (see below) |
| QHT-P01-005 Benchmark baseline + A/A | DONE | `results/2026-10-22-php8.5-pgo-v*/` | A/A noise about ±8% per workload, around 1% on the geomean |
| QHT-P01-006 CI | DONE | GitHub Actions run 39749752272: green | Lint + 4-language output comparison on 7.4 → 8.5; build + Zend PHPT + benchmark smoke for 7.4 and 8.5 on GitHub runners |
| QHT-P01-007 Compare 7.4 ↔ 8.5 and against Python/Node/Go | DONE | `results/2026-10-22-cross-*` | See README |
| QHT-JIT-001 PGO (v1, v2) | **NO-GO** | `results/2026-10-22-php8.5-pgo-v1`, `-v2` | v2: interp +0.3%, jit −3.2%; PHPT still 0 fail |
| QHT-JIT-001a LTO | **NO-GO** | ISSUE-001 | Conflicts with global register variables |
| QHT-WEB-001 **QHturbo Compatible**: `full` build + FPM, install/package, configuration | DONE (8.5, 7.4) | `docs/modes/compatible.md` | Legacy app runs identically on 7.4 and 8.5 via nginx → FPM; Composer + Laravel 13 (tests pass) on 8.5; Composer + Guzzle HTTPS on 7.4 |
| QHT-WEB-002 **QHturbo Performance**: runtime worker/event loop/async/state rules | DONE (first version) | `runtime/`, 34 tests; CI green on 8.1 → 8.5 (3 runs in a row) | 7–8× FPM on the CPU test; 2.5× on the I/O test with half the RAM (`results/web/`) |
| QHT-WEB-003 `qht-doctor`, `fpm-tune` | DONE | `scripts/` | doctor runs on 7.4 and later |
| QHT-WEB-004 Async MySQL/Redis drivers, connection pool | DONE | `runtime/src/{Pool,Redis,Mysql}`, 8 tests with Redis 7.0/MariaDB 10.11 (CI: Redis 7, MySQL 8.4) | MySQL I/O: 1,724 req/s versus 1,011 for FPM ×64 |
| QHT-WEB-006 aaPanel integration (`scripts/aapanel.sh`, `install-ext.sh`) | DONE (simulated) | `tests/aapanel-sim.sh` 13/13, `docs/aapanel.md` | Not yet run on a real aaPanel |
| QHT-WEB-005 Laravel/Symfony adapters | DONE | `runtime/src/Bridge`, `runtime/tests/frameworks.php` 6/6 | Laravel 13: 6.7–14.6×; Symfony 8.1: 6.3× versus FPM |
| QHT-WEB-008 Bug review before trial run | DONE | CHANGELOG "bug review", ISSUE-013 → 016 | 43 findings; real bugs fixed with regression tests (the new tests fail on the old code) |
| QHT-WEB-007 aaPanel UI plugin, .deb package | TODO | B7–B8 | |
| QHT-P02 Zend Engine patches | TODO | — | Needs `perf` on a dedicated machine |
| QHT-P03 Memory | TODO | — | |
| QHT-P06 Release | TODO | — | |
| Build + measure 8.0, 8.1, 8.2, 8.3, 8.4 | TODO | — | Scripts already support it, not run yet |

## PHPT — details

| Build | Pass | Fail | XFAIL | Skip | Notes |
|---|---|---|---|---|---|
| 8.5 baseline (opcache) | 13568 | 0 | 6 | 8706 | Skip = extensions not built (`--disable-all`) |
| 8.5 qhturbo-pgo (opcache) | 13568 | 0 | 6 | 8706 | The PGO binary breaks no tests |
| 7.4 baseline (opcache) | 8997 | 46 | 30 | 6719 | 35 preload: running as root requires `opcache.preload_user`; 6 no TTY/stdin; 4 network (DNS blocked, no https wrapper because openssl was not built); 1 timeout copying a 4 GB file |

No test failure is related to QHturbo (there are no source patches yet).

## Next steps

1. **On the user's machine:** `git clone` the repo and run it in Linux/WSL following the README (`QHT_LAB=~/QHTurbo-lab`) to get a baseline on real hardware; try aaPanel following `docs/aapanel.md`.
2. Create the baseline tag on the user's machine (the build machine is not allowed to push tags): `git tag -a qhturbo-baseline-20261022 094b04d -m "baseline" && git push origin qhturbo-baseline-20261022`.
3. Build + PHPT + benchmark for 8.0 → 8.4 (`QHT_PHP=8.x`).
4. Phase 5: support plain Apache/nginx, DirectAdmin and cPanel (per-account PHP-FPM pools, AlmaLinux/Rocky builds), then test on a real server.
5. Phase 2: on a dedicated machine with `perf`, profile `objects`/`nbody`/`spectral_norm` to pick hypothesis `QHT-ENG-001`.

## Bugs found and fixed in this session

- ISSUE-004: single-process mode shuts itself down after `--max-requests`.
- ISSUE-005: memory leak from cancelled timers (129 → 315 MiB in 30 s, now stable at 54 MiB).
- ISSUE-006: race when a worker stops loses the response of a just-accepted connection (caught by CI on 8.3–8.5).
- ISSUE-007: race on reload (signal arriving in the middle of accept), caught by CI thanks to annotations.
- ISSUE-008: worker crash when aaPanel blocks `pcntl_signal`.

## Blockers

- The cloud measurement machine is noisy (ISSUE-003): a single workload needs a difference above ±8% to be trustworthy.
- The user's machine can only sync via Git (ISSUE-002).
