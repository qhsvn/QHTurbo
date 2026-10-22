# laravel: QHturbo Compatible (PHP-FPM) vs Performance (workers)

- 2026-10-22 11:33 UTC, 4 vCPU, PHP 8.5.12, app: /srv/qht/laravel
- FPM: pm=static, 8 children; workers: 4 processes, concurrency 1; wrk -t2 -c32 10s (+3 s warm-up)

| Configuration | Requests/s | p50 | p99 | PHP memory PSS (MiB) | Errors |
|---|---|---|---|---|---|
| Compatible (FPM) `/up` | 353 | 89.23ms | 122.76ms | 98 |  |
| Performance (workers) `/up` | 5150 | 5.82ms | 19.23ms | 123 |  |
| Compatible (FPM) `/` | 288 | 108.77ms | 152.82ms | 101 |  |
| Performance (workers) `/` | 1924 | 16.31ms | 30.06ms | 148 |  |
| Compatible (FPM) `/qht/json` | 319 | 99.29ms | 126.94ms | 101 |  |
| Performance (workers) `/qht/json` | 2295 | 13.69ms | 25.67ms | 151 |  |

- Laravel 13.38: APP_ENV=production, APP_DEBUG=false, `php artisan optimize`, SESSION_DRIVER=redis, CACHE_STORE=redis (phpredis), SQLite database.
