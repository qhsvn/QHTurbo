# PHP QHturbo — Implementation Plan, Engineering and Development Log

**English** · [Tiếng Việt](original-plan.vi.md)

> **Project name:** PHP QHturbo — High-Performance PHP Runtime  
> **Drafted on:** 22/10/2026  
> **Project location:** GitHub repo `qhsvn/QHTurbo` (clone into any directory)  
> **Release goal:** A verified custom PHP runtime with maximum backward compatibility; runs on Linux/aaPanel after passing testing.  
> **Actual status:** The **starter kit** `PHP-QHturbo-starter.zip` exists; **not yet** deployed, the PHP core has not been cloned/built, and there is no Zend Engine patch proving a speedup.

---

## 0. Mandatory principles

1. **No guessing about speed.** Only claim something is faster when there is a reproducible measurement log.
2. **Do not break PHP compatibility.** Every core change must run the PHPT test suite; Laravel, WordPress and Composer applications are tested in the integration phase.
3. **Never modify the production server directly.** First try on a build machine/VM and staging; back up + have rollback before deploying to aaPanel.
4. **Do not delete/overwrite existing data in the project directory.** Check the directory first; work through Git and backups.
5. **Separate language performance from application performance.** Optimizing the Zend Engine, OPcache, FPM, Redis and MySQL are different layers.
6. **Apply security updates regularly.** Follow official patches; upgrade the PHP base together with re-running tests and benchmarks.
7. **Only mark DONE when there is evidence:** commit, commands run, tool versions, logs and test results.
8. **Keep notes throughout:** every phase must have goals, progress, results, risks, and next steps.

## 1. Scope, architecture, goals

### 1.1. What is QHturbo?

QHturbo is an experimental fork of the **official PHP source code (`php-src`)**, not a renamed php.ini configuration set. It keeps PHP syntax, the Zend API and ABI where feasible; any change that may break ABI must be clearly documented. Proposed Git branch name: `qhturbo/main`.

**Starting base of the existing starter kit:** tag `php-8.5.12`. Before cloning or releasing, confirm the tag, its signature/source and the current security patches on the official PHP site. If a safer release exists, update the documentation and the tag in a controlled way.

### 1.2. Improvement layers

| Layer | Goal | How it is verified |
|---|---|---|
| Zend VM/opcode | Reduce the cost of executing opcodes and function calls | Microbenchmarks and PHPT |
| Memory/HashTable | Reduce allocations, copying, memory usage | Peak RSS, allocations, microbenchmarks |
| Compiler/OPcache/JIT | Optimize hot code, avoid repeated compilation | CLI+web benchmarks, JIT on/off |
| PHP-FPM | Optimize workers, tail latency, throughput | HTTP load tests, p95/p99, errors |
| Database and Redis | Reduce application latency, avoid repeated queries | Query plans, cache hits, consistency testing |
| Compatibility | Composer + frameworks + extensions | PHPT, integration, regression |

**We cannot promise to be faster than Rust, Go, Java or Node.js in every workload.** The goal is to be competitive in clearly defined real-world scenarios. The speedup ranges below are only experimental targets, not achieved results:

- Zend VM/microbenchmarks: aim for a 5–20% gain on selected tasks.
- API throughput: aim for a 10–30% gain under the same conditions.
- Memory peak: research a 5–15% reduction on specific workloads.
- Production: must not increase the error rate, seriously regress p99, or break the API/ABI outside an approved policy.

### 1.3. Operational architecture

`Client → Nginx → PHP-FPM/QHturbo + OPcache → Redis (cache) / MySQL (primary data source)`.

Only after QHturbo meets the compatibility standard will a separate production PHP-FPM be packaged for aaPanel. Do not overwrite `/www/server/php/...` before there is a side-by-side deployment and rollback plan.

---

## 2. Project structure

```text
QHTurbo/
├── README.md
├── ROADMAP.md
├── PROJECT_STATUS.md
├── CHANGELOG.md
├── docs\
│   ├── architecture.md
│   ├── compatibility-policy.md
│   ├── benchmark-methodology.md
│   ├── security-and-release.md
│   └── phases\
│       ├── phase-01-foundation.md
│       ├── phase-02-engine.md
│       ├── phase-03-memory.md
│       ├── phase-04-compiler-jit.md
│       ├── phase-05-runtime-aaPanel.md
│       └── phase-06-release.md
├── logs\
│   ├── development.md
│   ├── issues.md
│   └── benchmarks.md
├── scripts\                 # bootstrap.sh/build.sh/benchmark.sh/compare.py
├── benchmarks\              # independent, fixed workloads
├── tests\                   # additional QHturbo tests
├── config\                  # benchmark INI and starting profiles
├── patches\                 # annotated experimental patches
├── php-src\                 # PHP upstream clone, branch qhturbo/main
├── worktrees\               # separate baseline and qhturbo
├── build\                   # built CLI binaries, FPM later
└── results\                 # benchmark results and logs
```

**Note:** The structure above matches the existing `PHP-QHturbo-starter.zip`, whose scripts use the `php-src`, `worktrees`, `build`, `results` and `config` directories. Do not rename these directories before adjusting the corresponding scripts. Files/documents that do not exist yet must be created in Phase 1.

### 2.1. Git and branching strategy

- `upstream`: the remote for the official PHP source code.
- `qhturbo/main`: QHturbo's integration development branch.
- `feature/QHT-ENG-001-...`: one small optimization with measurements.
- `release/qhturbo-x.y`: release packaging branch.
- Create reproducible tags for the baseline, e.g. `qhturbo-baseline-YYYYMMDD` in the project's own repo (do not overwrite upstream tags).
- Every optimization must have its own commit so it is easy to revert; do not mix functional, security and optimization changes in one commit.

---

## 3. Granting access and setting up on a Windows machine

### 3.1. Access limits

ChatGPT in an ordinary chat browser **does not by itself have direct access to manipulate the `D:` drive**. If you use ChatGPT Work/an app that can act on the machine and supports local folders, grant access right in the interface/access-request dialog shown by the product. **There is no single Windows permission button that applies to every way of using ChatGPT.** Do not grant administrator rights over the whole machine or share passwords/RDP just to carry out the project. If there is no local file access mode, run the commands below in PowerShell/WSL or transfer results via an archive file.

### 3.2. Safe preparation from PowerShell

Open PowerShell with normal Windows account privileges:

```powershell
$Project = "$env:USERPROFILE\QHTurbo"
if (Test-Path $Project) {
    Write-Host "Already exists: $Project — check the data before writing"
    Get-ChildItem -Force $Project | Select-Object Name, Length, LastWriteTime
} else {
    New-Item -ItemType Directory -Path $Project | Out-Null
}
```

If the directory contains data, create a separate backup **before** extracting or moving source code. Copy the `PHP-QHturbo-starter.zip` package to the machine and, after making sure there are no duplicate files that need to be kept, extract it to a temporary location:

```powershell
$Zip = "$env:USERPROFILE\Downloads\PHP-QHturbo-starter.zip"
$Temp = "$env:USERPROFILE\QHTurbo-starter-extracted"
if (!(Test-Path $Zip)) { throw "Not found: $Zip" }
if (Test-Path $Temp) { throw "Temporary directory already exists: $Temp" }
Expand-Archive -LiteralPath $Zip -DestinationPath $Temp
# Inspect <temporary directory>\PHP-QHturbo before copying.
# Only copy files that do not exist yet, or resolve duplicates with Git/diff.
```

Do not use `robocopy /MIR` or recursive delete commands on the project path.

### 3.3. Install WSL2 Ubuntu for Linux builds

Use PowerShell with Administrator rights **only for the WSL installation step, if needed**:

```powershell
wsl --install -d Ubuntu
wsl --status
wsl -l -v
```

You may need to restart Windows. Then open Ubuntu. Windows drives are mounted by WSL under `/mnt/<drive>`. For official benchmarks, **it is recommended to build and run tests on the Linux filesystem**, e.g. `~/QHTurbo-lab`, and sync documents/patches and reports via Git to avoid WSL filesystem distortions.

In Ubuntu:

```bash
sudo apt update
sudo apt install -y git build-essential autoconf bison re2c pkg-config \
  libxml2-dev libssl-dev zlib1g-dev libsqlite3-dev python3
```

### 3.4. Bootstrap with the starter kit

After placing the starter contents in the project, from Ubuntu:

```bash
cd ~/QHTurbo
bash scripts/bootstrap.sh
bash scripts/build.sh baseline
bash scripts/build.sh qhturbo
bash scripts/benchmark.sh
```

**Prerequisites before running:** Internet access to GitHub, a valid PHP tag, complete dependencies and enough build resources. The starter's `build.sh` produces a minimal CLI binary with OPcache, **not yet** a PHP-FPM build that can be swapped into aaPanel. The benchmark script runs only a small number of trials by default: increase the number of runs, warm-up and statistics to reach serious conclusions.

### 3.5. Rebuild after modifying the Zend Engine

The starter script creates a detached Git worktree once; it **does not automatically update the QHturbo worktree** after the branch moves to a new commit. Before rebuilding, check that the code is committed and update the worktree deliberately:

```bash
cd ~/QHTurbo
# Make sure all changes are committed/backed up before removing the worktree.
git -C php-src status --short
git -C worktrees/qhturbo status --short
# Only when the worktree is clean:
git -C php-src worktree remove ../worktrees/qhturbo
bash scripts/build.sh qhturbo
bash scripts/benchmark.sh
```

If `git status` reports uncommitted changes, **stop**; do not use `--force` unless you have backed up and intend to discard the changes.

---

## 4. Six-phase roadmap — acceptance gates

### PHASE 01 — FOUNDATION & BASELINE

**ID:** `QHT-P01` — **Status:** `TODO` (not yet run on the user's machine).

**Work items**

- [ ] Check the target directory, back up existing data, confirm the starter version.
- [ ] Standardize `README.md`, `ROADMAP.md`, `PROJECT_STATUS.md`, `CHANGELOG.md`.
- [ ] Install WSL2/toolchain, collect CPU, RAM, OS and compiler version.
- [ ] Clone PHP upstream at the verified tag, create `qhturbo/main`.
- [ ] Build baseline and QHturbo with the same compiler flags; record binary hashes.
- [ ] Run the PHP/PHPT test suite and save the report (not just microbenchmarks).
- [ ] Run benchmarks with at least 5–10 measured runs after warm-up (depending on workload), record median, p95 and variability.
- [ ] Set up CI for syntax checks, tests, regression and benchmark smoke.

**Completion criteria:** reproducible sources and revisions, a measured baseline, clear error/test logs, a rollback path and a clean repo.

**Deliverables:** `docs/phases/phase-01-foundation.md`, `results/baseline/`, `logs/development.md`, a baseline Git tag.

### PHASE 02 — ZEND ENGINE OPTIMIZATION

**ID:** `QHT-P02` — **Status:** `TODO`.

**Work items**

- [ ] Profile with flamegraph/perf and identify hot opcodes/frequent call paths.
- [ ] Pick one small hypothesis (e.g. reducing branch/call overhead) instead of optimizing the whole VM at once.
- [ ] Create a feature branch, benchmark first, write a minimal C patch.
- [ ] Run the relevant PHPT, sanitizer/ASan where appropriate, microbenchmarks and mixed workloads.
- [ ] Compare using statistical runs; only merge if the benefit is consistent with no significant regression.

**Completion criteria:** at least one core patch that has been reviewed, committed, passes tests, has repeated measurement results and an analysis of side effects. If no patch brings a benefit, record `NO-GO` with evidence; do not fake success.

### PHASE 03 — MEMORY & DATA STRUCTURES

**ID:** `QHT-P03` — **Status:** `TODO`.

**Work items**

- [ ] Profile allocations, peak memory/RSS, copy-on-write, request lifecycle.
- [ ] Analyze `zend_mm`, `HashTable`, strings and common array cases.
- [ ] Pick improvement points backed by evidence; check refcount, GC, exceptions, lifetimes.
- [ ] Test memory safety and regression; compare speed + RAM.

**Completion criteria:** a patch proven to reduce RAM or improve speed on the target workload without changing PHP behavior beyond the approved scope.

### PHASE 04 — COMPILER / OPCACHE / JIT

**ID:** `QHT-P04` — **Status:** `TODO`.

**Work items**

- [ ] Benchmark interpreter / OPcache / JIT on-off separately.
- [ ] Evaluate optimization passes in the compiler, bytecode and hot paths.
- [ ] Research type specialization where it is semantically safe.
- [ ] Check deoptimization, exceptions, overflow, dynamic types and debug info.

**Completion criteria:** figures for each mode, no crash regressions, no semantic violations, and a policy for enabling/disabling the feature.

### PHASE 05 — WEB RUNTIME / AAPANEL INTEGRATION

**ID:** `QHT-P05` — **Status:** `TODO`.

**Work items**

- [ ] Build PHP-FPM with the required extensions; create a separate package, **do not overwrite aaPanel's PHP**.
- [ ] Configure OPcache compatibly; measure FPM worker RSS to determine `pm.max_children`.
- [ ] Benchmark plaintext, JSON, PDO+MySQL, Redis-cache hit/miss, real web frameworks.
- [ ] Track RPS, p50/p95/p99, CPU, RSS, I/O, HTTP errors, DB slow queries.
- [ ] Try canary/staging, separate ports/sockets and a backout plan.
- [ ] For payments: verify idempotency and transactions, and do not cache sensitive transaction state.

**Completion criteria:** a working staging build, passing integration tests, a capacity report and a rehearsed rollback.

### PHASE 06 — COMPATIBILITY, SECURITY & RELEASE

**ID:** `QHT-P06` — **Status:** `TODO`.

**Work items**

- [ ] Full PHPT (record pass/fail/skip), extended framework/Composer tests.
- [ ] Check ABI/extensions and the compatibility matrix (OS, CPU, framework).
- [ ] Fuzzing/sanitizers, security review, upstream patch sync.
- [ ] Reproducible builds, SBOM/dependencies, version string and release notes.
- [ ] Linux staging package and build-from-source, rollback/upgrade documentation.

**Completion criteria:** the release candidate meets the compatibility, security and benchmark gates and the procedure for reverting to official PHP; if not met, do not release `stable`.

---

## 5. Fair benchmarking methodology

### 5.1. Mandatory data for every measurement

- Git commits of upstream and QHturbo; `configure` flags, compiler, OPcache/JIT modes.
- Machine: CPU, number of vCPUs, RAM, distro, kernel, filesystem, power settings.
- Command, dataset, concurrency, run duration, warm-up, number of iterations.
- Results: median, p95/p99 if available, standard deviation/CV, peak RSS, error count.
- Regression tracking: workloads that got faster and workloads that got slower; record both clearly.

### 5.2. Benchmark groups

| Group | Examples | Tools/measurements |
|---|---|---|
| Micro | loops, arrays, strings, JSON, function calls | `php benchmarks/micro.php`, hyperfine if installed |
| Macro CLI | data parsing, templates, batch computation | elapsed, CPU, max RSS |
| HTTP without DB | plaintext, JSON API | `wrk` or k6; RPS, latency, HTTP errors |
| HTTP with DB | indexed SELECTs, transactional writes | RPS + DB slow queries + correctness |
| Cache | Redis hit/miss, invalidation | hit rate, latency, correctness |
| Sustained load | soak tests, bursts, worker recycling | memory growth, 5xx, p99 |

**Database benchmarks must use equivalent datasets**, with warm/cold cache separated and the same concurrency level. Higher RPS with the same number of errors or worse p99 latency is not automatically a success.

### 5.3. Comparing with other languages/runtimes

This requires the same problem, protocol, amount of processing, CPU/RAM environment, number of workers, DB connections and serialization rules. Benchmarking PHP vs Go/Rust/Java/Node.js is meaningful **only** when comparing equivalent workloads. Separate CPU-bound and I/O-bound; do not use a single plaintext test to draw conclusions about every case.

---

## 6. Progress tracking and log templates

### 6.1. `PROJECT_STATUS.md` — update after every working session

```markdown
# PHP QHturbo — Project Status
Updated: YYYY-MM-DD HH:mm (+07:00)
Current phase: PHASE 01 — FOUNDATION
Overview: TODO / IN_PROGRESS / TESTING / DONE / BLOCKED

| Task | Status | Commit | Evidence | Blockers |
|---|---|---|---|---|
| QHT-P01-001 Inspect project | TODO | - | - | No access to the user's machine yet |
| QHT-P01-002 Build baseline | TODO | - | - | Toolchain not installed yet |

Next steps:
1. ...
2. ...
```

### 6.2. `logs/development.md` — one entry per change

```markdown
## YYYY-MM-DD HH:mm — QHT-ENG-001
- Goal/hypothesis:
- Files changed:
- Branch/commit:
- Build/test commands:
- Test results:
- Benchmark before/after:
- Regression/risk analysis:
- Decision: KEEP / REVERT / INVESTIGATE
- Next step:
```

### 6.3. `CHANGELOG.md` — per version

```markdown
## [Unreleased]
### Added
- ...
### Changed
- ...
### Fixed
- ...
### Performance
- ... (with a link/report of the results)
### Compatibility
- ...
```

### 6.4. Task IDs

- `QHT-P01-xxx` ... `QHT-P06-xxx`: phase work items.
- `QHT-ENG-xxx`: Zend Engine changes.
- `QHT-MEM-xxx`: memory/data structures.
- `QHT-JIT-xxx`: compiler/JIT.
- `QHT-WEB-xxx`: FPM/API/aaPanel.
- `QHT-SEC-xxx`: security and compatibility.

### 6.5. Acceptance gate (Definition of Done)

- [ ] A commit/patch/artifact or document is stored in the right place.
- [ ] There are test cases; pass/fail/skip is clearly recorded.
- [ ] There is a baseline and a benchmark under the same conditions (for performance optimizations).
- [ ] There are notes on impact, risks, rollback.
- [ ] `PROJECT_STATUS.md`, `CHANGELOG.md` and the log are updated.
- [ ] There is a next step and a list of topics to handle.

---

## 7. Per-session workflow

1. Open `PROJECT_STATUS.md` to confirm the current task, Git state, blockers.
2. Do not change several core components in one task.
3. Write the hypothesis and baseline **before** making changes.
4. Create a feature branch, edit the code, build, run tests and benchmarks.
5. Review crashes/memory/regressions and compatibility.
6. Commit with an ID; update status/changelog/log.
7. If it fails: revert the branch/patch, record the results so it is not retried blindly.

Example commit:

```text
QHT-ENG-001: reduce VM dispatch overhead for selected opcode

Benchmark: results/2026-xx-xx/eng-001.md
Tests: PHPT 1234 pass, 0 fail (illustrative example, NOT an actual result)
```

---

## 8. Release process and aaPanel

- Do not replace the system PHP or a web server that is serving users before there is a staging environment.
- Use a separate install path such as `/opt/qhturbo/<version>/`, a separate FPM service/socket, a separate configuration; pick a test web vhost first.
- Back up the PHP config, Nginx, DB, Redis config, and have a procedure for switching the upstream socket back to the official PHP FPM.
- Check `php -v`, `php -m`, `php --ini`, `php-fpm -t`, extension compatibility and the important endpoints.
- Deploy as a canary, monitor 5xx, p99, memory, CPU, slow queries; roll back automatically or manually if thresholds derived from the baseline are exceeded.
- Publish the QHturbo version, commit, PHP upstream tag, the commits/patches integrated and any incompatibilities.

---

## 9. Main risks and mitigations

| Risk | Mitigation |
|---|---|
| Microbenchmarks speed up but the application slows down | Add macro/HTTP workloads before merging |
| Crashes or memory errors in the Zend Engine | PHPT + ASan/UBSan + fuzzing, feature flags, revert |
| Breaking extensions/ABI | Matrix testing, no stable release before testing |
| JIT does not help web/API | Benchmark JIT on/off, keep safe defaults |
| Benchmark distortion on a Windows mount | Build/test on the Linux filesystem for the main comparisons |
| Upstream releases a security patch | Track it and do reviewed cherry-picks/rebases, re-run tests |
| Redis/MySQL corrupting transaction state | DB as the source of truth, transactions/idempotency, cache invalidation |
| aaPanel updates overwriting the custom runtime | Separate package/service, do not replace the panel's paths |

---

## 10. Kick-off checklist — things that can be done right away

- [ ] Download `PHP-QHturbo-starter.zip` from the conversation to the machine.
- [ ] Check whether the project directory already contains data.
- [ ] Extract to a temporary directory, inspect, then move over the non-duplicate content.
- [ ] Save this document as `ROADMAP.md` or `PROJECT_PLAN.md`.
- [ ] Install WSL2 Ubuntu and the toolchain.
- [ ] Run `scripts/bootstrap.sh` (after verifying the upstream tag).
- [ ] Build baseline/QHturbo and record the binary hashes.
- [ ] Run the standard PHPT test suite + baseline benchmark.
- [ ] Fill in `PROJECT_STATUS.md` and `logs/development.md` with real data.
- [ ] Pick the first bottleneck to start `QHT-ENG-001`.

### Handover status at the time this document was drafted

| Item | Actual status |
|---|---|
| Overall plan, 6 phases, acceptance criteria | **Documented** |
| Starter kit `PHP-QHturbo-starter.zip` | **Available in the conversation** |
| This Markdown plan file | **Created in the ChatGPT environment** |
| Creating project files | **Replaced by the GitHub repo** |
| Clone php-src on the user's machine | **Not done yet** |
| Build + run PHPT | **Not done yet** |
| Zend Engine optimization patch | **None yet** |
| Benchmark proving a speedup | **None yet** |

**Closing principle:** Every working session must leave behind `PROJECT_STATUS.md` and the log so the next person can continue without having to guess what has been done.
