# qhturbo/runtime — QHturbo Performance mode

Worker chạy lâu dài, event loop, async I/O bằng Fiber và quy tắc quản lý trạng thái giữa các request, cho **PHP ≥ 8.1**. Không phụ thuộc thư viện nào; `pcntl`/`posix` chỉ cần cho chế độ nhiều worker.

```bash
php bin/qhturbo-serve examples/hello.php --port=8080 --workers=4
php tests/run.php            # 15 test: loop, async, HTTP, tái tạo worker, reload, quy tắc trạng thái
```

| Thành phần | File |
|---|---|
| Event loop (`stream_select`, timer, watcher) | `src/EventLoop/Loop.php` |
| `async()`, `await()`, `all()`, `delay()`, `timeout()`, `read()`, `write()`, `connect()` | `src/Async/functions.php`, `Future.php` |
| HTTP/1.1 server không chặn, keep-alive, giới hạn concurrency | `src/Http/Server.php`, `Request.php`, `Response.php` |
| HTTP client async (http://) | `src/Http/Client.php` |
| Worker (khởi động ứng dụng một lần), supervisor (fork, tái tạo, reload SIGHUP, dừng êm) | `src/Worker/` |
| Quy tắc trạng thái: reset hook, dọn global/ini/output buffer, phát hiện static/RAM tăng dần | `src/State/` |

Tài liệu đầy đủ, số đo và quy tắc: [`docs/modes/performance.md`](../docs/modes/performance.md).
