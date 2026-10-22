# MySQL I/O: 3 x 20 ms queries per request

- 2026-10-22 11:37 UTC, 4 vCPU, PHP 8.5.12, wrk -t2 -c128 10s

| Configuration | Requests/s | p50 | p99 | PHP memory PSS (MiB) | Errors |
|---|---|---|---|---|---|
| Compatible: FPM x4, PDO (blocking) | 51 | 2.00s | 2.00s | 13 |   Socket errors: connect 0, read 0, write 0, timeout 457  |
| Compatible: FPM x64, PDO (blocking) | 1011 | 124.18ms | 162.64ms | 45 |  |
| Performance: 4 workers, PDO, concurrency 1 | 51 | 1.99s | 2.00s | 36 |   Socket errors: connect 0, read 0, write 0, timeout 300  |
| Performance: 4 workers, async MySQL, concurrency 64 | 1724 | 85.49ms | 114.74ms | 49 |  |

- MariaDB 10.11 (max_connections 151) on the same machine. Async client pool: 32 connections per worker.
