# QHturbo architecture

**English** · [Tiếng Việt](architecture.vi.md)

## 1. What QHturbo is

QHturbo is **not a fork that rewrites PHP**. It consists of:

1. **The official PHP source** (`php-src`), pinned to a tag for each branch 7.4 → 8.5.
2. **A patch series** (`patches/<branch>/series`), applied on top of that tag in order. Each patch is small, carries a task ID (`QHT-ENG-001-...`) and comes with measurements.
3. **An optimized build process**: same source code, but compiled with **PGO** (Profile-Guided Optimization). LTO is not used because GCC's LTO conflicts with the Zend VM's global register variables (see `logs/issues.md`, ISSUE-001).
4. **Runtime configuration** (OPcache, JIT, preload, FPM) that has been measured.
5. **A benchmark and test suite** to prove every change.

This repo contains only parts 2–5. `php-src`, the worktrees and the binaries are recreated from scripts, so they are not committed.

## 2. Build flow

```text
config/versions/8.5.env ──► bootstrap.sh ──► php-src (shallow, verified tag)
                                                │
                    ┌───────────────────────────┼─────────────────────────────┐
                    ▼                           ▼                             ▼
     worktrees/8.5/baseline        worktrees/8.5/qhturbo          worktrees/8.5/qhturbo-pgo
     original tag, -O2             tag + patches/8.5, -O2         tag + patches/8.5, -O2 + PGO
                    │                           │                             │
                    ▼                           ▼                             ▼
     build/8.5/baseline/bin/php    build/8.5/qhturbo/bin/php      build/8.5/qhturbo-pgo/bin/php
```

- `baseline` vs `qhturbo`: isolates the effect of the **source patches**.
- `qhturbo` vs `qhturbo-pgo`: isolates the effect of the **compiler** (PGO).
- Each binary has a `BUILD-INFO.txt`: tag, commit, patch list, configure flags, sha256, gcc version.

### How PGO works

1. `make prof-gen`: build an instrumented binary (`-fprofile-generate`).
2. `scripts/pgo-train.sh`: run the training set (`benchmarks/training/`, `Zend/micro_bench.php`, the Zend/array/string/json PHPTs). The compiler records which branches are taken often and which functions are hot.
3. `make prof-use`: rebuild, using that data to lay out hot code contiguously, inline in the right places and predict branches better.

PGO does not change program semantics, only the layout of the machine code. The training set is kept separate from the benchmark set to avoid misleading results.

## 3. Optimization layers and scope

| Layer | How QHturbo does it | Status |
|---|---|---|
| C compiler | PGO (every branch 7.4 → 8.5) | Done, measured on 8.5 and 7.4 |
| OPcache / JIT | Configuration in `config/ini/` + JIT on/off measurements | Configuration and CLI measurements done |
| Zend VM / opcode | C patches in `patches/<branch>/` | No accepted patch yet (Phase 2) |
| Memory / HashTable | C patches | Not started (Phase 3) |
| PHP-FPM / web | FPM build, pool configuration, wrk/k6 | Not started (Phase 5) |

## 4. Operations (Phase 5 goal)

`Client → Nginx → PHP-FPM (QHturbo, separate socket) + OPcache → Redis (cache) / MySQL (primary data source)`

QHturbo installs into `/opt/qhturbo/<branch>/<version>/`, runs its own FPM service and **does not overwrite** aaPanel's `/www/server/php/`. To go back to the original PHP, just point `fastcgi_pass` back to the old socket and reload Nginx.

## 5. Directory layout

```text
QHTurbo/
├── README.md  ROADMAP.md  PROJECT_STATUS.md  CHANGELOG.md
├── config/
│   ├── versions/<branch>.env  # pinned upstream tag + commit
│   ├── build.env              # shared configure/CFLAGS flags
│   └── ini/                   # OPcache / JIT / preload profiles for production
├── patches/<branch>/series    # per-branch source patch series
├── scripts/                   # bootstrap, build, run-tests, bench.py, make-patch, pgo-train
├── benchmarks/
│   ├── workloads/<name>/main.{php,py,js,go}
│   ├── workloads.json         # input sizes
│   └── training/              # PGO training set (not used for measurement)
├── tests/                     # QHturbo's own checks
├── results/                   # benchmark + PHPT reports (summary.md)
├── logs/                      # development, issue and benchmark logs
├── docs/                      # architecture, policies, methodology, phases
│
│   (generated at run time, not committed — lives in QHT_LAB)
├── php-src/  worktrees/<branch>/<variant>/  build/<branch>/<variant>/
```
