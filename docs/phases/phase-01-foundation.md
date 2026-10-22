# PHASE 01 — Foundation and baseline (`QHT-P01`)

**English** · [Tiếng Việt](phase-01-foundation.vi.md)

**Status:** `DONE` on the Linux build machine (cloud). On the user's Windows/WSL machine: the README steps need to be rerun to get that machine's own baseline.

## Checklist

- [x] Check the target directory: the GitHub repo `qhsvn/QHTurbo` was initially empty, so no data was overwritten. Synced via GitHub (ISSUE-002).
- [x] `README.md`, `ROADMAP.md`, `PROJECT_STATUS.md`, `CHANGELOG.md`.
- [x] Toolchain and machine info: recorded automatically in every report (`machine_info` / `bench.py`).
- [x] Clone upstream PHP at a verified tag. Instead of forking a `qhturbo/main` branch in `php-src`, use a **per-branch patch series** (`patches/<branch>/series`), which is leaner and can be reapplied on new tags.
- [x] **Support for PHP 7.4 → 8.5** (an additional user requirement): each branch has its own pinned tag/commit.
- [x] Build baseline and QHturbo with the same flags; store the binary sha256 (`BUILD-INFO.txt`).
- [x] Run the PHPTs and store the reports: `results/tests/`.
- [x] Benchmark with 10 measured runs after warm-up, recording median, min/max, CV, 95% CI, RSS.
- [x] CI: shellcheck, PHP lint 7.4 → 8.5, output comparison across languages, build smoke + PHPT + benchmark smoke.

## How to reproduce

```bash
export QHT_LAB=~/QHTurbo-lab          # build on the Linux filesystem when using WSL
QHT_PHP=8.5 scripts/bootstrap.sh
QHT_PHP=8.5 scripts/build.sh baseline
QHT_PHP=8.5 scripts/build.sh qhturbo
QHT_PHP=8.5 scripts/build.sh qhturbo-pgo
QHT_PHP=8.5 scripts/run-tests.sh baseline opcache
QHT_PHP=8.5 scripts/run-tests.sh qhturbo-pgo opcache
taskset -c 2 python3 scripts/bench.py php --php 8.5 --variants baseline,qhturbo,qhturbo-pgo --runs 10
```

Change to `QHT_PHP=7.4` (or 8.0 … 8.4) for other branches.

## Deliverables

- Measured baselines: `results/2026-10-22-php8.5-pgo-v1/`, `results/2026-10-22-php8.5-pgo-v2/` (baseline + A/A), `results/2026-10-22-cross-php7.4-8.5-php/`, `results/2026-10-22-cross-php8.5-python-node-go/`
- PHPT: `results/tests/`
- Logs: `logs/development.md`, `logs/benchmarks.md`, `logs/issues.md`

## Rollback

Everything is in Git and can be recreated from scripts. To drop a variant: delete `build/<branch>/<variant>` and run `git -C php-src worktree remove worktrees/<branch>/<variant>` (only when `git status` in the worktree is clean).
