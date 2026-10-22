# PHP QHturbo

**English** · [Tiếng Việt](README.vi.md)

**Website:** install guides and examples for Nginx, Apache and aaPanel: <https://qhsvn.github.io/QHTurbo/> (source in [`website/`](website/)).

QHturbo makes PHP applications run faster **with measured, reproducible evidence**, for **PHP 7.4 to 8.5**. It is built from the official PHP source (`php-src`), installs **beside** your existing PHP (never over it), and offers two ways to run your code:

- **QHturbo Compatible**: standard PHP-FPM. Runs WordPress, Laravel, Composer and legacy code **without code changes**.
- **QHturbo Performance**: long-lived workers, an event loop, async I/O and non-blocking Redis/MySQL. Runs **Laravel and Symfony apps unchanged** at **6–15× the requests/s of PHP-FPM** in our benchmarks.

> **Status (2026-10-22):** foundation, both run modes, aaPanel integration and tooling are done and tested. The PHP binary itself is not faster than upstream yet: PGO was tried twice (NO-GO) and there are no Zend Engine patches yet. Today's speed-ups come from **how PHP runs** (workers, async I/O) and from configuration. Numbers were measured on a shared cloud VM (±8% noise). See [PROJECT_STATUS.md](PROJECT_STATUS.md).

## Key features

### Build and versions
- **PHP 7.4, 8.0, 8.1, 8.2, 8.3, 8.4, 8.5** built from pinned upstream tags. Every tag is verified against its commit hash before building.
- Two build profiles: `minimal` (engine benchmarks) and `full` (PHP-FPM + 51 modules: openssl, curl, intl, gd, mysqli, pdo_mysql, zip, sodium, bcmath, soap, pcntl…).
- Build variants `baseline` / `qhturbo` (with patch series) / `qhturbo-pgo` (profile-guided). Each binary ships a `BUILD-INFO.txt` with its sha256, flags and patch list.
- PHP 7.4/8.0 build against a private OpenSSL 1.1.1 on modern distros (OpenSSL 3), leaving the system untouched.
- Install side by side in `/opt/qhturbo/<version>`. Package as a stripped `.tar.gz` (≈15 MB) with a dependency list.

### QHturbo Compatible (PHP-FPM)
- Drop-in PHP-FPM with its own socket and systemd unit. Safe OPcache defaults (256 MB, 50k files, file-change detection kept on), JIT off by default, worker recycling, slowlog, status/ping pages.
- Verified: legacy app (sessions, cookies, `header()`, uploads, SQLite) identical on 7.4 and 8.5. Composer installs Laravel 13 and its test suite passes. Composer + Guzzle HTTPS work on 7.4.

### QHturbo Performance (PHP 8.1+, pure PHP, no extension needed)
- **Long-lived workers:** the application boots once per process, not once per request.
- **Event loop + Fiber async I/O:** `async()`, `await()`, `all()`, `delay()`, `timeout()`, non-blocking TCP and an HTTP client.
- **HTTP/1.1 server:** keep-alive, per-worker concurrency limit, request size limits. Runs behind nginx.
- **Supervisor:** multi-process workers, recycling after N requests or a memory limit, **zero-downtime rolling reload** (`SIGHUP`), graceful shutdown, crash-loop back-off.
- **State guard** that enforces per-request isolation: reset hooks (`ResetInterface`), removal of leaked globals, restore of changed ini settings and open output buffers, detection of growing static properties and memory, a `--strict-state` mode for staging, and `--superglobals` for legacy code.
- **Async Redis client** (RESP2, pipelining, AUTH/SELECT) and **async MySQL/MariaDB client** (mysqlnd async queries, `?` placeholders incl. `IN (?)` lists, transactions, timeouts). Both share a **bounded connection pool** per worker and never hand back a connection with an open transaction.
- **Laravel and Symfony adapters** (tested on Laravel 13 and Symfony 8.1; written for Laravel 9+ / Symfony 5.4+): `qhturbo-serve /path/to/app --framework=laravel`. The framework boots once; auth guards, sessions, queued cookies, scoped instances, DB transactions, config, locale… are reset after every request (Laravel), or `kernel.reset` services are reset (Symfony).
- `multipart/form-data` uploads (as `$_FILES` / `UploadedFile`), multi-value headers such as several `Set-Cookie`.

### aaPanel integration
- `scripts/aapanel.sh`: `check` → `install` → `enable <site>` → `disable <site>` / `status` / `sync-ini` / `uninstall`.
- Switches **one site at a time**, with **automatic rollback** if nginx fails its config test or the site starts returning 5xx.
- Copies `disable_functions` and limits from the aaPanel php.ini, so the site keeps the same security restrictions.
- `scripts/install-ext.sh`: installs PECL extensions (redis, imagick, apcu…), falling back to the GitHub release when PECL is unreachable.

### Tooling
- `qht-doctor`: checks a PHP installation (OPcache sizing, Xdebug in production, EOL versions, missing extensions) and **scans code for worker-mode risks** (globals, statics, `exit()`, `header()`, sessions…).
- `fpm-tune`: computes `pm.max_children` from RAM and the measured worker memory.
- `bench.py`: interleaved, warmed-up benchmarks with A/A control, peak RSS, bootstrap 95% CI and output checks. Compares builds, PHP versions, and PHP against Python, Node.js and Go.
- Web benchmark (`benchmarks/web/run.sh`): Compatible vs Performance on the same app.
- PHPT runner (official PHP test suite), CI on GitHub Actions for PHP 7.4 → 8.5.

## Two run modes at a glance

| | **Compatible** | **Performance** |
|---|---|---|
| For | Any site: WordPress, Laravel, legacy code, aaPanel | APIs and new apps that need high throughput |
| How it runs | nginx → PHP-FPM | nginx → `qhturbo-serve` (long-lived workers, event loop, async I/O) |
| PHP | 7.4 → 8.5 | 8.1 → 8.5 (Fibers) |
| Code changes | **None** | Follow the [state rules](docs/modes/performance.md) (scanner + strict mode help) |
| Laravel 13 (4 vCPU, production config) | 288–353 req/s | **1,924–5,150 req/s (6.7–14.6×)** |
| Symfony 8.1 | 1,737 req/s | **11,160 req/s (6.3×)** |
| 3 × 20 ms MySQL queries per request | 1,011 req/s (64 FPM children) | **1,724 req/s** (async MySQL) |

## Measured results

Full data and method: [logs/benchmarks.md](logs/benchmarks.md), [`results/`](results/).

**What actually makes PHP faster** (PHP 8.5.12):

| Change | Speed-up | Notes |
|---|---|---|
| Long-lived workers instead of PHP-FPM, real Laravel 13 app | **6.7–14.6×** | Removes the per-request framework bootstrap |
| Same for Symfony 8.1 / a synthetic framework | **6.3× / 7–8×** | |
| Async MySQL instead of blocking PDO in 64 FPM children | **1.7×** | When requests wait on the database |
| Async HTTP calls instead of blocking FPM children | **2.5×** with half the RAM | When requests wait on other services |
| Enable JIT on 8.5 (CPU benchmarks) | 1.56× | Large for numeric PHP code, ~0 for JSON/string/array work |
| Upgrade 7.4 → 8.5 (interpreter only) | 1.05× | Smaller than most expect; 1.69× with JIT |
| PGO-optimised binary | +0.3% / −3.2% | **NO-GO**: no stable gain |

**PHP vs popular languages** (speed relative to the PHP 8.5 interpreter; >1 means faster than PHP):

| | PHP 8.5 JIT | Python 3.13 | Node.js 22 | Go 1.24 |
|---|---|---|---|---|
| Geometric mean (9 CPU workloads) | 1.56× | **0.36×** | 3.36× | 4.16× |
| Hash maps (associative arrays) | 1.09× | 0.41× | **0.48×** | **0.83×** |
| String processing | 1.03× | 0.47× | **0.37×** | 0.95× |
| JSON | 0.99× | 0.53× | 1.22× | **0.41×** |
| Numeric (`nbody`) | 1.36× | 0.43× | 12.0× | 25.8× |

PHP beats Python everywhere. It beats Node.js and matches or beats Go on hash maps and strings, and beats Go on JSON. It is far behind both on pure numeric code; closing that gap needs Zend VM/JIT work, not configuration.

## Supported versions

| Branch | Upstream tag | Upstream support | JIT | Performance mode |
|---|---|---|---|---|
| 7.4 | php-7.4.33 | ⚠️ end of life | no | no |
| 8.0 | php-8.0.30 | ⚠️ end of life | yes | no |
| 8.1 | php-8.1.34 | ⚠️ end of life | yes | yes |
| 8.2 | php-8.2.35 | security fixes only | yes | yes |
| 8.3 | php-8.3.36 | security fixes only | yes | yes |
| 8.4 | php-8.4.27 | active | yes | yes |
| 8.5 | php-8.5.12 | active | yes | yes |

The `full` profile was built and tested on 7.4 and 8.5; the other branches use the same scripts. CI checks every branch. See the [compatibility policy](docs/compatibility-policy.md).

## Quick start

### On an aaPanel server (Compatible mode, one site at a time)

```bash
git clone https://github.com/qhsvn/QHTurbo.git /opt/qhturbo-src && cd /opt/qhturbo-src
sudo scripts/aapanel.sh check   --php=8.5                    # inspect only, changes nothing
sudo scripts/aapanel.sh install --php=8.5                    # build + install + start PHP-FPM (10–40 min)
sudo scripts/aapanel.sh enable  test.example.com --php=8.5   # switch one site; auto-rollback on errors
sudo scripts/aapanel.sh disable test.example.com             # back to aaPanel's PHP
```

Full guide: [docs/aapanel.md](docs/aapanel.md). Prefer a UI? Install the [aaPanel plugin](plugins/README.md).

### Any Linux server (or Windows via WSL2)

```bash
sudo apt install -y git build-essential autoconf bison re2c pkg-config python3 \
  libxml2-dev libssl-dev libsqlite3-dev zlib1g-dev libcurl4-openssl-dev libzip-dev \
  libonig-dev libicu-dev libsodium-dev libpng-dev libjpeg-dev libfreetype-dev libgmp-dev libreadline-dev
git clone https://github.com/qhsvn/QHTurbo.git && cd QHTurbo
export QHT_LAB=~/QHTurbo-lab QHT_PHP=8.5          # QHT_PHP: 7.4 … 8.5
scripts/bootstrap.sh                               # fetch + verify the upstream tag
QHT_PROFILE=full scripts/build.sh baseline         # PHP + PHP-FPM
sudo -E scripts/install.sh                         # -> /opt/qhturbo/8.5, beside the system PHP
/opt/qhturbo/8.5/bin/php /opt/qhturbo/8.5/share/qhturbo/qht-doctor.php
```

On Windows, install WSL2 (`wsl --install -d Ubuntu`) and build inside the Linux filesystem (`~/…`), not on a Windows drive.

### Performance mode

```php
<?php // app.php: runs once per worker
use QHTurbo\Runtime\Http\{Request, Response};

$config = require __DIR__ . '/config.php';           // booted once, reused

return function (Request $request) use ($config): Response|array {
    return ['path' => $request->path(), 'env' => $config['env']];  // per request
};
```

```bash
php runtime/bin/qhturbo-serve app.php --port=8080 --workers=4 --strict-state
# an existing Laravel or Symfony project, unchanged:
php runtime/bin/qhturbo-serve /var/www/laravel --framework=laravel --workers=4
```

Details, options and state rules: [docs/modes/performance.md](docs/modes/performance.md).

### Research and benchmarks

```bash
scripts/run-tests.sh baseline opcache                               # official PHPT suite
python3 scripts/bench.py php --variants baseline,qhturbo --runs 10  # compare builds (A/A noise check)
python3 scripts/bench.py cross --php 7.4,8.5 --langs php            # compare PHP versions
python3 scripts/bench.py cross --php 8.5 --langs python,node,go     # compare languages
sudo benchmarks/web/run.sh                                          # Compatible vs Performance over HTTP
```

## Apply to your servers today

1. **Upgrade to PHP 8.3+** if your application allows it (security, JIT, less RAM).
2. **Production OPcache:** enough `memory_consumption` and `max_accelerated_files`; `validate_timestamps=0` if every deploy reloads FPM.
3. **JIT:** enable it for compute-heavy applications. For typical web apps, measure first, then enable.
4. **Preload** for large frameworks.
5. Most web latency is in **MySQL/Redis/I/O**. Optimise queries, indexes and caching, and consider Performance mode + async I/O.

## Repository layout

```text
config/versions/   pinned upstream tag + commit per PHP branch
config/build.env   configure flags (minimal / full profiles)
config/modes/      Compatible (FPM) and Performance (worker) configuration templates
config/ini/        OPcache / JIT / preload profiles
patches/<branch>/  source patch series (empty: no engine patches yet)
runtime/           QHturbo Performance: event loop, async, HTTP server, workers, state guard, Redis/MySQL, Laravel/Symfony
scripts/           bootstrap, build, install, package, aapanel, install-ext, run-tests, bench.py, qht-doctor, fpm-tune
benchmarks/        CPU workloads in PHP/Python/JS/Go, web benchmark, PGO training set
tests/             cross-language output check, aaPanel end-to-end simulation
results/           benchmark and PHPT reports
logs/              development log, issues, benchmark log
docs/              architecture, modes, aaPanel guide, proposals, policies, phases
```

## Documentation

Every document is available in English and Vietnamese (`*.vi.md`):

- [docs/aapanel.md](docs/aapanel.md): install on aaPanel step by step
- [docs/modes/compatible.md](docs/modes/compatible.md), [docs/modes/performance.md](docs/modes/performance.md): the two run modes
- [docs/proposals.md](docs/proposals.md): prioritised improvement proposals
- [docs/architecture.md](docs/architecture.md), [docs/benchmark-methodology.md](docs/benchmark-methodology.md), [docs/compatibility-policy.md](docs/compatibility-policy.md), [docs/security-and-release.md](docs/security-and-release.md)
- [ROADMAP.md](ROADMAP.md), [PROJECT_STATUS.md](PROJECT_STATUS.md), [CHANGELOG.md](CHANGELOG.md), original plan: [docs/original-plan.md](docs/original-plan.md)

## Principles

No speed claim without reproducible measurements. No PHP compatibility breaks. No changes straight to production: build, then staging, then canary, always with a rollback. Every change has a task ID, tests, measurements and a log entry. Experiments that do not help are recorded as NO-GO, not hidden.
