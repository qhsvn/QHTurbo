# symfony: QHturbo Compatible (PHP-FPM) vs Performance (workers)

- 2026-10-22 11:35 UTC, 4 vCPU, PHP 8.5.12, app: /srv/qht/symfony
- FPM: pm=static, 8 children; workers: 4 processes, concurrency 1; wrk -t2 -c32 10s (+3 s warm-up)

| Configuration | Requests/s | p50 | p99 | PHP memory PSS (MiB) | Errors |
|---|---|---|---|---|---|
| Compatible (FPM) `/qht/hello` | 1737 | 17.84ms | 27.15ms | 61 |  |
| Performance (workers) `/qht/hello` | 10958 | 2.66ms | 10.67ms | 77 |  |
| Compatible (FPM) `/qht/json` | 1776 | 17.72ms | 25.17ms | 61 |  |
| Performance (workers) `/qht/json` | 11160 | 2.54ms | 10.73ms | 77 |  |

- Symfony 8.1 skeleton + test controller (runtime/tests/fixtures/symfony), APP_ENV=prod.
