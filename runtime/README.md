# qhturbo/runtime — QHturbo Performance mode

Worker chạy lâu dài, event loop, async I/O bằng Fiber, client Redis/MySQL không chặn, adapter Laravel/Symfony và quy tắc quản lý trạng thái giữa các request, cho **PHP ≥ 8.1**. Không phụ thuộc thư viện nào; `pcntl`/`posix` chỉ cần cho chế độ nhiều worker, `mysqli` cho client MySQL.

```bash
php bin/qhturbo-serve examples/hello.php --port=8080 --workers=4
php bin/qhturbo-serve /var/www/laravel --framework=laravel --workers=4
php tests/run.php            # 24 test (Redis/MySQL thật: QHT_TEST_REDIS, QHT_TEST_MYSQL)
QHT_TEST_LARAVEL=... QHT_TEST_SYMFONY=... php tests/frameworks.php   # 6 test trên app thật
```

| Thành phần | File |
|---|---|
| Event loop (`stream_select`, timer, watcher) | `src/EventLoop/Loop.php` |
| `async()`, `await()`, `all()`, `delay()`, `timeout()`, `read()`, `write()`, `connect()` | `src/Async/functions.php`, `Future.php` |
| HTTP/1.1 server không chặn, keep-alive, giới hạn concurrency | `src/Http/Server.php`, `Request.php`, `Response.php` |
| HTTP client async (http://) | `src/Http/Client.php` |
| Worker (khởi động ứng dụng một lần), supervisor (fork, tái tạo, reload SIGHUP, dừng êm) | `src/Worker/` |
| Quy tắc trạng thái: reset hook, dọn global/ini/output buffer, phát hiện static/RAM tăng dần | `src/State/` |
| Pool kết nối, client Redis (RESP2), client MySQL (mysqlnd async) | `src/Pool/`, `src/Redis/`, `src/Mysql/` |
| Adapter Laravel / Symfony (HttpFoundation) | `src/Bridge/` |

Tài liệu đầy đủ, số đo và quy tắc: [`docs/modes/performance.md`](../docs/modes/performance.md).
