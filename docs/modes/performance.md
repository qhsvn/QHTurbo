# QHturbo Performance

**Mục tiêu:** tốc độ cao hơn hẳn mô hình "mỗi request khởi động lại ứng dụng", nhờ:

- **Worker chạy lâu dài:** ứng dụng khởi động **một lần** cho mỗi tiến trình, sau đó phục vụ hàng nghìn request.
- **Event loop + async I/O** (Fiber, PHP ≥ 8.1): khi một request chờ I/O (gọi API, dịch vụ nội bộ), worker phục vụ request khác.
- Cấu hình mạnh hơn: OPcache CLI, `validate_timestamps=0`, JIT tracing.

Đổi lại, ứng dụng **phải tuân thủ quy tắc quản lý trạng thái** giữa các request (mục 3).

| | |
|---|---|
| Phiên bản PHP | **8.1 trở lên** (cần Fiber). PHP 7.4/8.0 dùng chế độ Compatible |
| Mô hình chạy | nginx (reverse proxy) → `qhturbo-serve` (master + N worker) |
| Mã nguồn | `runtime/` (gói Composer `qhturbo/runtime`, không phụ thuộc gì, cũng chạy được không cần Composer) |
| Test | `php runtime/tests/run.php`: 15 test (event loop, async, HTTP, tái tạo worker, reload, quy tắc trạng thái) |

## 1. Kết quả đo

`results/web/2026-10-22-php8.5-modes.md`: cùng một "framework" giả lập (300 class, container, 40 file config, 203 route), PHP 8.5.12, 4 vCPU, nginx + wrk chạy trên cùng máy.

| Bài | Compatible (FPM) | Performance (worker) | Kết quả |
|---|---|---|---|
| `/hello`, 4 tiến trình PHP | 2.991 req/s, p50 10,4 ms | **23.598 req/s, p50 1,1 ms** | **7,9×** |
| `/api/users/42` (JSON) | 3.008 req/s, p50 10,4 ms | **21.266 req/s, p50 1,3 ms** | **7,1×** |
| `/io`: 3 lần gọi backend × 20 ms | FPM ×4: 49 req/s, gần 500 timeout<br>FPM ×64: 939 req/s, 104 MiB | **2.389 req/s, 56 MiB** (4 worker, concurrency 64) | 2,5× so với FPM ×64, dùng một nửa RAM |
| RAM (PSS) bài CPU | 30–33 MiB | 43 MiB | +10 MiB cho 4 worker giữ sẵn ứng dụng |
| RAM sau 30 s tải liên tục (~780.000 request) | — | 54 MiB, không tăng | Không rò rỉ |

Cách đọc: lợi ích lớn nhất đến từ việc **bỏ chi phí khởi động** framework ở mỗi request. Framework thật (Laravel, Symfony) có chi phí khởi động khác với bài giả lập, nên phải đo bằng chính ứng dụng của bạn. `benchmarks/web/run.sh` là mẫu để làm việc đó. Bài `/io` bị giới hạn bởi backend giả lập (chỉ 1 tiến trình).

## 2. Cách dùng

```php
<?php // worker.php: phần này chạy MỘT LẦN cho mỗi worker
use QHTurbo\Runtime\Http\{Request, Response, Client};
use function QHTurbo\Runtime\Async\{async, all};

$db = new PDO($dsn, $user, $pass, [PDO::ATTR_PERSISTENT => false]); // dùng lại giữa các request
$config = require __DIR__ . '/config.php';

return function (Request $request) use ($db, $config): Response|array|string {
    // Phần này chạy cho MỖI request. Trạng thái của request nằm trong $request.
    if ($request->path() === '/dashboard') {
        // 3 lệnh gọi dịch vụ nội bộ chạy song song (cần --concurrency > 1 mới tận dụng hết)
        [$a, $b, $c] = all([
            async(fn () => Client::get('http://stats.internal/a')['body']),
            async(fn () => Client::get('http://stats.internal/b')['body']),
            async(fn () => Client::get('http://stats.internal/c')['body']),
        ]);
        return ['a' => $a, 'b' => $b, 'c' => $c];
    }
    return Response::text("Not Found\n", 404);
};
```

```bash
# phát triển
php runtime/bin/qhturbo-serve worker.php --port=8080 --strict-state --debug
# production: 1 worker mỗi CPU, tái tạo sau 10.000 request hoặc 256 MiB
qhturbo-serve worker.php --workers=4 --concurrency=1 --max-requests=10000 --memory-limit=256M
kill -HUP <master-pid>      # deploy: thay worker lần lượt, không rơi request
```

Systemd: `qhturbo-php85-worker@<tên>.service` và file `/etc/qhturbo/worker-<tên>.env` (do `scripts/install.sh` cài). nginx đứng trước làm reverse proxy (TLS, HTTP/2, file tĩnh, nén):

```nginx
upstream app { server 127.0.0.1:8080; keepalive 64; }
location / { proxy_http_version 1.1; proxy_set_header Connection ""; proxy_set_header Host $host;
             proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for; proxy_pass http://app; }
```

| Tuỳ chọn | Mặc định | Ý nghĩa |
|---|---|---|
| `--workers=N` | 0 | Số tiến trình (0 = một tiến trình, không cần pcntl, không tự tái tạo) |
| `--concurrency=C` | 1 | Số request xử lý xen kẽ trong một worker. **1 = an toàn cho mọi code** |
| `--max-requests=N` | 10000 | Tái tạo worker sau N request (chặn rò rỉ dần) |
| `--memory-limit=256M` | 256M | Tái tạo worker khi RAM vượt ngưỡng |
| `--superglobals` | tắt | Điền `$_GET/$_POST/$_COOKIE/$_SERVER` cho code cũ (bắt buộc `--concurrency=1`) |
| `--strict-state` | tắt | Vi phạm quy tắc trạng thái thì trả 500 và ghi log (dùng ở dev/staging) |
| `--state-check-every=N` | 100 | Kiểm tra static property/RAM mỗi N request |

## 3. Quy tắc quản lý trạng thái (bắt buộc)

Trong FPM, mọi thứ tự mất khi request kết thúc. Trong worker, **những gì còn tồn tại sau request sẽ "rò" sang request sau**, thậm chí sang người dùng khác. Quy tắc:

| # | Quy tắc | Runtime hỗ trợ |
|---|---|---|
| R1 | **Không lưu dữ liệu của request vào biến global, static property hay static variable.** Đặt nó trong `Request` hoặc object tạo mới cho mỗi request | Biến global mới bị **xoá** và ghi log; static property cứ lớn dần thì bị cảnh báo |
| R2 | Service giữ trạng thái theo request (user hiện tại, unit of work…) phải implement `ResetInterface` | `reset()` được gọi sau **mỗi** request |
| R3 | **Không `exit()`/`die()`**: sẽ giết worker. Trả `Response` | Supervisor khởi động lại worker, nhưng request đang dở bị lỗi |
| R4 | **Không `header()`, `setcookie()`, `echo` để trả kết quả**: đặt header/body trên `Response` | `qht-doctor --scan` liệt kê các chỗ cần sửa |
| R5 | Không đổi `ini_set`, `date_default_timezone_set`, `setlocale` theo request | ini đã thay đổi được **khôi phục** sau request |
| R6 | Output buffer phải đóng | Buffer còn mở được dọn và ghi log |
| R7 | Kết nối DB/Redis: mở một lần và dùng lại. Phải xử lý khi kết nối bị mất (reconnect), và **không để transaction mở sang request sau** | — (Phase tiếp theo: pool + kiểm tra transaction) |
| R8 | Với `--concurrency > 1`: mọi trạng thái dùng chung giữa các request đang chạy xen kẽ phải **an toàn**. Không dùng superglobals. **Lệnh chặn (PDO, `file_get_contents` URL, `sleep`) sẽ chặn cả worker**, nên chỉ dùng I/O async | `--superglobals` bị từ chối khi concurrency > 1 |
| R9 | Code đăng ký handler theo request (`set_error_handler`, `register_shutdown_function`) sẽ bị chồng lên nhau | `qht-doctor --scan` cảnh báo |

**Lộ trình chuyển một ứng dụng sang Performance:**

1. `php qht-doctor.php --scan=/var/www/app`: liệt kê các chỗ vi phạm R1–R9 (đã bỏ qua `vendor/`).
2. Chạy staging với `--concurrency=1 --superglobals --strict-state`: code cũ ít phải sửa, và vi phạm hiện ra ngay dưới dạng lỗi 500 kèm log.
3. Sửa hết vi phạm, tắt `--superglobals` và chuyển sang dùng `Request`.
4. Chỉ tăng `--concurrency` khi I/O đã là async (R8).
5. Production: bắt đầu với một phần lưu lượng (canary), giữ FPM (Compatible) làm đường lui.

**Không phù hợp với Performance mode (cứ dùng Compatible):** WordPress và plugin của nó (dùng global rất nhiều), code dùng `exit()` để điều khiển luồng, ứng dụng mà bạn không kiểm soát được mã nguồn.

## 4. Giới hạn hiện tại

- HTTP/1.1 tự viết bằng PHP: không có TLS, HTTP/2 hay request body dạng chunked (dùng nginx đứng trước). Chưa hỗ trợ upload `multipart/form-data` thành `$_FILES`.
- Async I/O mới có TCP và HTTP client (không TLS). **Chưa có driver MySQL/Redis async**: với `--concurrency=1`, PDO/phpredis vẫn chạy bình thường (chặn, như FPM).
- Chưa có adapter cho Laravel/Symfony (kiểu Octane). Hiện ứng dụng viết theo dạng handler `Request → Response`.
- Các giải pháp đã trưởng thành nên cân nhắc cho production lớn: FrankenPHP (worker mode), RoadRunner, Swoole/OpenSwoole, Laravel Octane. QHturbo Performance chạy được không cần extension ngoài, và dễ đọc và kiểm soát hơn.
