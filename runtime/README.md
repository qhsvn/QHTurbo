# qhturbo/runtime — QHturbo Performance mode

**English** · [Tiếng Việt](README.vi.md)

Long-lived workers, an event loop, Fiber-based async I/O, non-blocking Redis/MySQL clients, Laravel/Symfony adapters and rules for managing state between requests, for **PHP ≥ 8.1**. No library dependencies; `pcntl`/`posix` are only needed for multi-worker mode, `mysqli` for the MySQL client.

```bash
php bin/qhturbo-serve examples/hello.php --port=8080 --workers=4
php bin/qhturbo-serve /var/www/laravel --framework=laravel --workers=4
php tests/run.php            # 34 tests (real Redis/MySQL: QHT_TEST_REDIS, QHT_TEST_MYSQL)
QHT_TEST_LARAVEL=... QHT_TEST_SYMFONY=... php tests/frameworks.php   # 6 tests on real apps
```

| Component | File |
|---|---|
| Event loop (`stream_select`, timers, watchers) | `src/EventLoop/Loop.php` |
| `async()`, `await()`, `all()`, `delay()`, `timeout()`, `read()`, `write()`, `connect()` | `src/Async/functions.php`, `Future.php` |
| Non-blocking HTTP/1.1 server, keep-alive, concurrency limit | `src/Http/Server.php`, `Request.php`, `Response.php` |
| Async HTTP client (http://) | `src/Http/Client.php` |
| Worker (boots the application once), supervisor (fork, respawn, SIGHUP reload, graceful shutdown) | `src/Worker/` |
| State rules: reset hooks, cleanup of globals/ini/output buffers, detection of growing statics/memory | `src/State/` |
| Connection pool, Redis client (RESP2), MySQL client (mysqlnd async) | `src/Pool/`, `src/Redis/`, `src/Mysql/` |
| Laravel / Symfony (HttpFoundation) adapters | `src/Bridge/` |

Full documentation, measurements and rules: [`docs/modes/performance.md`](../docs/modes/performance.md).
