# Proposals: making PHP run better and more conveniently for users

**English** · [Tiếng Việt](proposals.vi.md)

These proposals are based on the project's measurements (`logs/benchmarks.md`, `results/web/`), ordered by **practical benefit / effort**. The "Status" column shows which items QHturbo already has.

## A. What the measurements have proven

1. **The biggest win for web PHP is removing the per-request bootstrap cost**, not speeding up the Zend VM. A long-running worker is **7–8×** faster than FPM on a simulated framework. PGO only gave ±3%, and upgrading 7.4 → 8.5 (no JIT) only +5%.
2. **I/O is the real bottleneck of real applications.** FPM handles I/O by adding processes (64 processes: 939 req/s, 104 MiB), while async I/O reaches 2,389 req/s with 56 MiB.
3. **JIT** makes pure-PHP computational code 1.5–3.9× faster, and barely helps json/strings/arrays. Only enable it after measuring with the real application.
4. PHP is already **~3× faster than Python**, on par with or faster than Node.js/Go on associative arrays and strings, but **many times slower at numeric computation**. Closing that gap requires long-term investment in the JIT/VM (Phase 2–4).

## B. High-priority proposals (within 1–3 months)

| # | Proposal | Benefit | Status |
|---|---|---|---|
| B1 | **Two modes, Compatible / Performance**, with a clear fallback path | Users choose between safety and speed per site | ✅ Done (`docs/modes/`) |
| B2 | **`qht-doctor`**: checks configuration (OPcache, Xdebug, EOL, extensions) and scans source code for risks when moving to a worker | Fixes common configuration mistakes in minutes | ✅ Done |
| B3 | **`fpm-tune`**: computes `pm.max_children` from actual RAM and RSS | Avoids swapping (the most common reason sites are slow) | ✅ Done |
| B4 | **Async MySQL/Redis drivers** for Performance mode (mysqli `MYSQLI_ASYNC` + `mysqli_poll`, RESP protocol over an async socket) | Allows `--concurrency > 1` with database-backed apps, getting the full benefit of async | ✅ Done (`runtime/src/Redis`, `runtime/src/Mysql`): 1.7× vs 64 FPM processes |
| B5 | **Connection pool + transaction checks** between requests (rule R7) | No reconnecting on every request, no leaked transactions | ✅ Done (`runtime/src/Pool`) |
| B6 | **Laravel / Symfony adapters** for Performance mode (like Laravel Octane: boot the kernel once, reset the container, auth, session after each request) | Brings the 7× benefit to the most popular frameworks | ✅ Done (`--framework=laravel\|symfony`): Laravel 13 is 6.7–14.6× faster, Symfony 8.1 is 6.3× faster |
| B7 | **aaPanel plugin**: choose the QHturbo version and Compatible/Performance mode per site, one-click rollback | Usable by people who are not comfortable with the command line | ⏳ Proposed |
| B8 | **Prebuilt packages** (`.tar.gz` already exists, next are `.deb` and an apt repository) for Ubuntu 22.04/24.04, Debian 12 | No need to build yourself (a build takes 5–15 minutes) | ◐ `scripts/package.sh` exists |

## C. Developer convenience

| # | Proposal | Status |
|---|---|---|
| C1 | A unified `qht` command: `qht install 8.5`, `qht use 8.4`, `qht doctor`, `qht serve`, `qht bench` | ⏳ (currently separate scripts in `scripts/`) |
| C2 | Dev server that auto-reloads when files change (watch, then SIGHUP) | ⏳ |
| C3 | A `/metrics` endpoint (Prometheus): RPS, latency, RAM, event loop lag, number of recycled workers | ⏳ (`Loop::stats()` already exists) |
| C4 | Automatically generate `preload.php` from OPcache's list of hot files | ⏳ |
| C5 | `multipart/form-data` uploads → `$_FILES` in Performance mode | ✅ |
| C6 | GitHub Actions template for users' projects: run tests in both modes, with `--strict-state` | ◐ (QHturbo's CI serves as the template) |

## D. Deep performance work (Phase 2–4, long-term, needs a dedicated benchmark machine)

| # | Proposal | Notes |
|---|---|---|
| D1 | Profile with `perf` on real web workloads (FPM and worker), pick hot functions to patch in the Zend Engine (`QHT-ENG-xxx`) | The current cloud machine is too noisy (±8%) |
| D2 | Third PGO attempt: train on **real web requests**, try clang | The 2 previous PGO attempts were NO-GO |
| D3 | Write the HTTP parser as a C extension for Performance mode (currently pure PHP) | First measure what percentage of time the parser takes |
| D4 | Follow the worker-mode capabilities of PHP upstream itself and FrankenPHP; avoid redoing what upstream already has | |

## E. Operations and security

1. **Upgrade off 7.4/8.0/8.1** (already EOL). QHturbo Compatible makes it possible to run a newer version side by side on the same machine, migrate site by site and keep a fallback path.
2. OpenSSL 1.1.1, used for 7.4/8.0, is also EOL: use it only temporarily.
3. Production: staging, then canary, then full cutover. Always keep the original PHP-FPM so you can roll back with a single `nginx -s reload`.
4. Performance mode: enable `--strict-state` on staging; in production set `--max-requests` and `--memory-limit` so it self-recovers from leaks.

## F. Quick selection guide

```
WordPress site / legacy code / code you can't modify  ->  Compatible (PHP-FPM)
API / newly written app, framework with heavy boot    ->  Performance, --concurrency=1
Many API/internal service calls in each request       ->  Performance, --concurrency>1 (async I/O)
Heavy computation in PHP                              ->  enable JIT (after measuring), consider writing that part in Go/Rust
Still running PHP 7.4/8.0                             ->  Compatible + an upgrade path to 8.3+
```
