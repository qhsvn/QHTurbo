# PHASE 05 — Web runtime / aaPanel (`QHT-P05`)

**English** · [Tiếng Việt](phase-05-runtime-aaPanel.vi.md)

**Status:** `IN_PROGRESS`. Two run modes are in place and measured: [Compatible](../modes/compatible.md) (PHP-FPM, 7.4 → 8.5) and [Performance](../modes/performance.md) (long-running workers, PHP 8.1+). Results: `results/web/`.

## To do

- [x] Add an FPM build variant: `--enable-fpm --with-fpm-user=www --with-fpm-group=www` together with the extensions commonly used on aaPanel (mysqli, pdo_mysql, curl, openssl, zip, gd, intl, redis via PECL).
- [x] Package for installation into `/opt/qhturbo/<branch>/`, systemd service `qhturbo-php<branch>-fpm`, separate socket (`scripts/install.sh`, `scripts/package.sh`).
- [x] Measure the RSS of each worker to compute `pm.max_children = (RAM reserved for PHP) / RSS_p95` (`scripts/fpm-tune.py`).
- [x] HTTP benchmark with `wrk`: plaintext, JSON, backend I/O on a simulated framework (`benchmarks/web/run.sh`).
- [ ] Benchmark PDO+MySQL, Redis hit/miss, real Laravel/WordPress pages.
- [ ] Monitor RPS, p50/p95/p99, CPU, RSS, HTTP errors, slow queries.
- [ ] Canary/staging, rollback rehearsal (see `docs/security-and-release.md`).
- [ ] Payments: idempotency, transactions, no caching of transaction state.

## Definition of done

A staging deployment running stably, integration tests passing, a capacity report, and a rehearsed rollback.
