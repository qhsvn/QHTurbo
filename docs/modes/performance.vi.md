# QHturbo Performance

[English](performance.md) · **Tiếng Việt**

**Mục tiêu:** tốc độ cao hơn hẳn mô hình "mỗi request khởi động lại ứng dụng", nhờ:

- **Worker chạy lâu dài:** ứng dụng khởi động **một lần** cho mỗi tiến trình, sau đó phục vụ hàng nghìn request.
- **Event loop + async I/O** (Fiber, PHP ≥ 8.1): khi một request chờ I/O (MySQL, Redis, gọi API, dịch vụ nội bộ), worker phục vụ request khác.
- **Client Redis và MySQL không chặn**, có pool kết nối (mục 5).
- **Adapter Laravel và Symfony**: chạy ứng dụng có sẵn không cần sửa code (mục 6).
- Cấu hình mạnh hơn: OPcache CLI, `validate_timestamps=0`, JIT tracing.

Đổi lại, ứng dụng **phải tuân thủ quy tắc quản lý trạng thái** giữa các request (mục 3).

| | |
|---|---|
| Phiên bản PHP | **8.1 trở lên** (cần Fiber). PHP 7.4/8.0 dùng chế độ Compatible |
| Mô hình chạy | nginx (reverse proxy) → `qhturbo-serve` (master + N worker) |
| Mã nguồn | `runtime/` (gói Composer `qhturbo/runtime`, không phụ thuộc gì, cũng chạy được không cần Composer) |
| Test | `php runtime/tests/run.php`: 34 test (event loop, async, pool, HTTP, tái tạo worker, reload kể cả deploy lỗi, quy tắc trạng thái, Redis, MySQL)<br>`php runtime/tests/frameworks.php`: 6 test trên Laravel 13 và Symfony 8.1 thật |

## 1. Kết quả đo

**Framework thật** (4 vCPU, PHP 8.5.12, cấu hình production, nginx + wrk trên cùng máy; `benchmarks/web/framework.sh`):

| Ứng dụng / route | Compatible (FPM) | Performance (worker) | Nhanh hơn |
|---|---|---|---|
| Laravel 13 `/up` (health check, không session) | 353 req/s, p50 89 ms | **5.150 req/s, p50 5,8 ms** | **14,6×** |
| Laravel 13 `/` (trang chào, Blade, session Redis) | 288 req/s | **1.924 req/s** | **6,7×** |
| Laravel 13 route JSON (middleware web, session Redis) | 319 req/s | **2.295 req/s** | **7,2×** |
| Symfony 8.1 route JSON | 1.737 req/s, p50 17,7 ms | **11.160 req/s, p50 2,5 ms** | **6,3×** |
| RAM (PSS) Laravel / Symfony | 98–101 / 61 MiB | 123–151 / 77 MiB | worker giữ sẵn framework |

Báo cáo: `results/web/2026-10-22-laravel13-fpm-vs-workers.md`, `results/web/2026-10-22-symfony81-fpm-vs-workers.md`.

**MySQL I/O** (mỗi request 3 query × 20 ms; `benchmarks/web/db/run.sh`, `results/web/2026-10-22-mysql-io-async.md`):

| Cấu hình | req/s | p99 | RAM |
|---|---|---|---|
| FPM ×4, PDO | 51 (457 timeout) | 2 s | 13 MiB |
| FPM ×64, PDO | 1.011 | 163 ms | 45 MiB |
| Worker ×4, PDO, concurrency 1 | 51 (300 timeout) | 2 s | 36 MiB |
| **Worker ×4, MySQL async, concurrency 64** | **1.724** | **115 ms** | 49 MiB |

Worker mà vẫn dùng PDO chặn thì **không chịu được tải I/O**; driver async khắc phục đúng điểm này. Giới hạn còn lại là MariaDB trên cùng máy.

**Framework giả lập** — `results/web/2026-10-22-php8.5-modes.md`: cùng một "framework" giả lập (300 class, container, 40 file config, 203 route), PHP 8.5.12, 4 vCPU, nginx + wrk chạy trên cùng máy.

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

Khi reload (`SIGHUP`): OPcache của các file đã nạp bị vô hiệu hoá để worker mới đọc code mới (kể cả khi `opcache.validate_timestamps=0`). Worker cũ chỉ bị dừng **sau khi** worker mới khởi động xong; nếu code mới lỗi (ví dụ lỗi cú pháp), reload dừng lại, log ghi `reload aborted` và các worker cũ tiếp tục phục vụ code cũ. Worker cũ có tối đa 60 giây để xử lý nốt request. Sau nhiều lần reload, bộ nhớ OPcache bị chiếm bởi các bản cũ; thỉnh thoảng hãy khởi động lại hẳn (`systemctl restart`).

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
| `--framework=laravel\|symfony` | — | Chạy thư mục dự án Laravel/Symfony (mục 6). Bắt buộc `--concurrency=1` |
| `--max-body=8M` | 8M | Kích thước request tối đa (kể cả upload). Nhận `512K`, `8M`, `8MB`, `1G` |
| `--max-connections=N` | 512 | Số kết nối mở tối đa mỗi worker (tối đa 1000, giới hạn của `stream_select`). Khi đầy, worker tạm ngừng nhận kết nối mới; các worker khác hoặc hàng đợi của kernel giữ chúng |

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
| R7 | Kết nối DB/Redis: mở một lần và dùng lại. Phải xử lý khi kết nối bị mất (reconnect), và **không để transaction mở sang request sau** | Client MySQL/Redis của runtime (mục 5): pool, kiểm tra kết nối nhàn rỗi, rollback transaction còn mở |
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

- HTTP/1.1 tự viết bằng PHP: không có TLS, HTTP/2 hay request body dạng chunked (để nginx đứng trước lo). Upload `multipart/form-data` được nạp vào RAM, nên giới hạn bằng `--max-body`.
- `move_uploaded_file()` và `is_uploaded_file()` luôn trả `false` ngoài PHP-FPM (file tạm không do PHP tạo khi upload). Code cũ dùng `--superglobals` phải đổi sang `rename($_FILES[...]['tmp_name'], $dest)`; `qht-doctor --scan` chỉ ra những chỗ này. `UploadedFile` của Laravel/Symfony hoạt động bình thường.
- Response được dựng hết trong RAM trước khi gửi, kể cả `StreamedResponse` và `BinaryFileResponse` của Symfony/Laravel. File tải xuống lớn hãy để nginx phục vụ (file tĩnh hoặc `X-Accel-Redirect`).
- Với `--concurrency > 1`, hook reset và dọn trạng thái chạy khi worker không còn request nào đang chạy; nếu tải liên tục, cứ sau 64 request worker tạm dừng nhận request mới để dọn.
- Client MySQL: kết nối mới được mở theo kiểu chặn (mysqlnd không có connect async); vì có pool nên chỉ tốn một lần cho mỗi kết nối. Chưa có prepared statement phía server: tham số được escape theo charset, giống chế độ "emulated prepares" mặc định của PDO MySQL. Redis: chưa có Pub/Sub, Cluster và Sentinel.
- PDO/Eloquent/Doctrine vẫn **chặn**. Ứng dụng Laravel/Symfony chạy với `--concurrency=1` vẫn nhanh 6–15× nhờ bỏ chi phí khởi động; muốn tận dụng I/O song song thì dùng client async ở những đoạn I/O nặng.
- Adapter Laravel mô phỏng cách dọn trạng thái của Octane nhưng chưa đủ mọi trường hợp. Package bên thứ ba giữ trạng thái trong static hay singleton vẫn có thể rò; hãy chạy staging với `--strict-state`, `--state-check-every=1` và giữ `--max-requests`.
- Các giải pháp đã trưởng thành nên cân nhắc cho production lớn: FrankenPHP (worker mode), RoadRunner, Swoole/OpenSwoole, Laravel Octane. QHturbo Performance chạy được không cần extension ngoài, và dễ đọc và kiểm soát hơn.

## 5. Redis và MySQL không chặn

```php
use QHTurbo\Runtime\Mysql\Client as Mysql;
use QHTurbo\Runtime\Redis\Client as Redis;
use function QHTurbo\Runtime\Async\{all, async};

$db    = new Mysql('mysql://app:secret@127.0.0.1:3306/shop?pool=16'); // tạo 1 lần cho mỗi worker
$redis = new Redis('redis://:secret@127.0.0.1:6379/0', poolSize: 16);

return function (Request $r) use ($db, $redis): array {
    if (($cached = $redis->get('home')) !== null) {
        return json_decode($cached, true);
    }
    // 3 query chạy song song trên 3 kết nối của pool
    [$users, $orders, $stats] = all([
        async(fn () => $db->query('SELECT id, name FROM users WHERE id IN (?)', [[1, 2, 3]])->rows),
        async(fn () => $db->query('SELECT * FROM orders WHERE created_at > ?', [new DateTime('-1 day')])->rows),
        async(fn () => $db->query('SELECT COUNT(*) FROM visits')->value()),
    ]);
    $redis->set('home', json_encode($data = compact('users', 'orders', 'stats')), 'EX', 30);
    return $data;
};
```

| Tính năng | Redis | MySQL / MariaDB |
|---|---|---|
| Không chặn worker | Socket async (RESP2) | `MYSQLI_ASYNC` + `mysqli_poll` (mysqlnd lo xác thực, kể cả `caching_sha2_password`, TLS và charset) |
| Pool kết nối | Có: giới hạn số kết nối, request chờ khi pool hết chỗ, kết nối hỏng tự bị loại | Có |
| Lệnh | Mọi lệnh qua `__call`/`command()`, `pipeline()` | `query($sql, $params)` với `?`, mảng cho `IN (?)`, `transaction(fn)`, `withConnection(fn)` |
| Lỗi | Lỗi kiểu `WRONGTYPE` → `RedisException`, kết nối vẫn dùng tiếp | `QueryException` (mã MySQL, ví dụ 1062); timeout thì loại kết nối |
| An toàn trạng thái (R7) | — | Transaction còn mở khi trả kết nối thì **tự rollback** và ghi log |
| Phục hồi | Server đóng kết nối → kết nối bị loại, lần gọi sau mở kết nối mới | "Server has gone away" khi kết nối rảnh → tự thử lại một lần |

Ngoài fiber (ví dụ dùng trong FPM hoặc script CLI), mỗi lệnh chạy như lệnh chặn bình thường.

## 6. Chạy Laravel và Symfony

```bash
# Không phải sửa code ứng dụng. Đã test: Laravel 13, Symfony 8.1 (viết cho Laravel 9+ / Symfony 5.4+)
qhturbo-serve /var/www/laravel --framework=laravel --workers=4 --max-requests=5000
qhturbo-serve /var/www/symfony --framework=symfony --workers=4
```

**Laravel:** framework khởi động một lần (env, config, provider, route). Sau **mỗi** request, adapter dọn những trạng thái mà Laravel giữ trong các service sống lâu (cách làm tương tự Laravel Octane):

| Trạng thái | Cách xử lý | Đã test |
|---|---|---|
| User đã đăng nhập (guard) | `forgetGuards()` | ✔ user của request trước không lọt sang request sau |
| Session | `flush()` + `regenerate()` store dùng chung (nếu không, dữ liệu session sẽ trộn giữa hai người dùng) | ✔ hai trình duyệt có session tách biệt |
| Cookie xếp hàng | `flushQueuedCookies()` | ✔ |
| Transaction DB còn mở | rollback + ghi log (R7) | ✔ |
| Config đổi trong lúc chạy | khôi phục | ✔ |
| Locale | đặt lại `app.locale` | ✔ |
| Scoped instance, view section, log context, Str cache, query log | dọn | |
| Upload, JSON body, 404, 500 (không lộ chi tiết lỗi) | — | ✔ |

Adapter cảnh báo khi khởi động nếu `SESSION_DRIVER`/`CACHE_STORE` là `array`: driver này giữ dữ liệu trong RAM của worker, nên tăng mãi và không chia sẻ giữa các worker. Khi phát triển adapter, mình đã đo và xác định đây là nguyên nhân của một "rò rỉ" (khoảng 700 byte/request). Với session `file` hoặc `redis`: tăng khoảng 2 byte/request qua 20.000 request, tức là không rò rỉ.

**Symfony:** kernel khởi động một lần. Symfony tự reset các service có tag `kernel.reset` (`ResetInterface`) giữa các request (đã test). Adapter đặt `APP_RUNTIME_MODE=web=1&worker=1`; nếu không, vì `PHP_SAPI` là `cli`, Symfony sẽ in **chi tiết exception vào response 500** ngay cả ở môi trường prod. Đây là lỗi tìm thấy và sửa trong quá trình test.

**Cấu hình nên dùng:** session và cache bằng Redis hoặc file (không dùng `array`), `php artisan optimize` / `cache:warmup`, và tin tưởng proxy (`trustProxies`) vì request đến từ nginx.
