# PHP QHturbo

[English](README.md) · **Tiếng Việt**

**Trang web:** hướng dẫn cài đặt và ví dụ cho Nginx, Apache và aaPanel: <https://qhsvn.github.io/QHTurbo/#vi> (mã nguồn trong [`website/`](website/)).

QHturbo giúp ứng dụng PHP chạy nhanh hơn, **có số đo kiểm chứng và tái lập được**, cho **PHP 7.4 đến 8.5**. Dự án build từ mã nguồn PHP chính thức (`php-src`), cài **song song** với PHP đang có (không ghi đè), và có hai cách chạy:

- **QHturbo Compatible:** PHP-FPM chuẩn. Chạy WordPress, Laravel, Composer và code cũ **không cần sửa code**.
- **QHturbo Performance:** worker chạy lâu dài, event loop, async I/O, Redis/MySQL không chặn. Chạy **ứng dụng Laravel và Symfony không cần sửa code**, đạt **6–15× request/giây** so với PHP-FPM trong benchmark.

> **Trạng thái (22/10/2026):** đã xong nền tảng, hai chế độ chạy, tích hợp aaPanel và bộ công cụ, đều đã kiểm thử. Bản thân binary PHP chưa nhanh hơn bản gốc: PGO đã thử 2 lần (NO-GO) và chưa có bản vá Zend Engine. Tốc độ hiện tại đến từ **cách chạy PHP** (worker, async I/O) và cấu hình. Số đo lấy trên VM đám mây dùng chung (nhiễu khoảng ±8%). Xem [PROJECT_STATUS.vi.md](PROJECT_STATUS.vi.md).

## Tính năng chính

### Build và phiên bản
- **PHP 7.4, 8.0, 8.1, 8.2, 8.3, 8.4, 8.5**, build từ tag chính thức đã ghim. Tag được đối chiếu với mã commit trước khi build.
- Hai profile build: `minimal` (đo hiệu năng engine) và `full` (PHP-FPM + 51 module: openssl, curl, intl, gd, mysqli, pdo_mysql, zip, sodium, bcmath, soap, pcntl…).
- Biến thể `baseline` / `qhturbo` (có chuỗi patch) / `qhturbo-pgo` (PGO). Mỗi binary kèm `BUILD-INFO.txt` ghi sha256, cờ build và danh sách patch.
- PHP 7.4/8.0 dùng OpenSSL 1.1.1 riêng trên các distro mới (OpenSSL 3), không đụng tới hệ thống.
- Cài song song vào `/opt/qhturbo/<phiên bản>`; đóng gói `.tar.gz` (khoảng 15 MB) kèm danh sách thư viện phụ thuộc.

### QHturbo Compatible (PHP-FPM)
- PHP-FPM có socket và systemd unit riêng. OPcache an toàn (256 MB, 50.000 file, vẫn kiểm tra file thay đổi), JIT tắt mặc định, tái tạo tiến trình định kỳ, slowlog, trang status/ping.
- Đã kiểm chứng: app kiểu cũ (session, cookie, `header()`, upload, SQLite) chạy giống hệt trên 7.4 và 8.5. Composer cài được Laravel 13 và bộ test pass. Composer + Guzzle HTTPS chạy được trên 7.4.

### QHturbo Performance (PHP 8.1+, viết bằng PHP thuần, không cần extension)
- **Worker chạy lâu dài:** ứng dụng khởi động một lần cho mỗi tiến trình, không phải mỗi request.
- **Event loop + async I/O bằng Fiber:** `async()`, `await()`, `all()`, `delay()`, `timeout()`, TCP không chặn và HTTP client.
- **HTTP/1.1 server:** keep-alive, giới hạn số request đồng thời mỗi worker, giới hạn kích thước request. Chạy sau nginx.
- **Supervisor:** nhiều tiến trình; tái tạo worker sau N request hoặc khi vượt RAM; **reload lần lượt không rơi request** (`SIGHUP`); dừng êm; tự giãn nhịp khi worker crash liên tục.
- **Bảo vệ trạng thái** giữa các request: hook reset (`ResetInterface`); xoá biến global bị rò; khôi phục ini và output buffer; phát hiện static property và RAM tăng dần; chế độ `--strict-state` cho staging; `--superglobals` cho code cũ.
- **Client Redis async** (RESP2, pipeline, AUTH/SELECT) và **client MySQL/MariaDB async** (query async của mysqlnd, tham số `?` kể cả danh sách `IN (?)`, transaction, timeout). Cả hai dùng **pool kết nối có giới hạn** cho mỗi worker, và không bao giờ trả lại kết nối còn transaction đang mở.
- **Adapter Laravel và Symfony** (đã test trên Laravel 13 và Symfony 8.1; viết cho Laravel 9+ / Symfony 5.4+): `qhturbo-serve /đường/dẫn/app --framework=laravel`. Framework khởi động một lần. Sau mỗi request, adapter dọn user đăng nhập, session, cookie, scoped instance, transaction DB, config, locale… (Laravel), hoặc reset các service `kernel.reset` (Symfony).
- Upload `multipart/form-data` (thành `$_FILES` / `UploadedFile`), header nhiều giá trị như nhiều `Set-Cookie`.

### Tích hợp aaPanel
- `scripts/aapanel.sh`: `check` → `install` → `enable <site>` → `disable <site>` / `status` / `sync-ini` / `uninstall`.
- Chuyển **từng site một**, **tự rollback** nếu `nginx -t` lỗi hoặc site bắt đầu trả lỗi 5xx.
- Chép `disable_functions` và các giới hạn từ php.ini của aaPanel, nên site giữ nguyên mức bảo mật.
- `scripts/install-ext.sh`: cài extension PECL (redis, imagick, apcu…). Nếu PECL không truy cập được thì tải bản phát hành trên GitHub.

### Công cụ
- `qht-doctor`: kiểm tra cài đặt PHP (kích thước OPcache, Xdebug trên production, phiên bản hết hạn, extension thiếu), và **quét mã nguồn tìm rủi ro khi chạy worker** (global, static, `exit()`, `header()`, session…).
- `fpm-tune`: tính `pm.max_children` theo RAM và RAM thực tế của mỗi worker.
- `bench.py`: benchmark chạy xen kẽ, có warm-up, kiểm tra A/A, đo peak RSS, khoảng tin cậy 95% và kiểm tra output. So sánh được các bản build, các phiên bản PHP, và PHP với Python/Node.js/Go.
- Benchmark web (`benchmarks/web/run.sh`): so Compatible với Performance trên cùng ứng dụng.
- Chạy bộ test chính thức PHPT; CI GitHub Actions cho PHP 7.4 → 8.5.

## Hai chế độ chạy

| | **Compatible** | **Performance** |
|---|---|---|
| Dành cho | Mọi site: WordPress, Laravel, code cũ, aaPanel | API và ứng dụng mới cần tốc độ cao |
| Cách chạy | nginx → PHP-FPM | nginx → `qhturbo-serve` (worker chạy lâu dài, event loop, async I/O) |
| PHP | 7.4 → 8.5 | 8.1 → 8.5 (Fiber) |
| Sửa code | **Không** | Tuân thủ [quy tắc trạng thái](docs/modes/performance.vi.md) (có công cụ quét và chế độ kiểm tra nghiêm) |
| Laravel 13 (4 vCPU, cấu hình production) | 288–353 req/s | **1.924–5.150 req/s (6,7–14,6×)** |
| Symfony 8.1 | 1.737 req/s | **11.160 req/s (6,3×)** |
| 3 query MySQL × 20 ms mỗi request | 1.011 req/s (64 tiến trình FPM) | **1.724 req/s** (MySQL async) |

## Kết quả đã đo được

Toàn bộ số liệu và cách đo: [logs/benchmarks.vi.md](logs/benchmarks.vi.md), [`results/`](results/).

**Những gì thực sự làm PHP nhanh hơn** (PHP 8.5.12):

| Thay đổi | Tốc độ | Ghi chú |
|---|---|---|
| Worker chạy lâu dài thay cho PHP-FPM, ứng dụng Laravel 13 thật | **6,7–14,6×** | Bỏ chi phí khởi động framework ở mỗi request |
| Tương tự với Symfony 8.1 / framework giả lập | **6,3× / 7–8×** | |
| MySQL async thay cho PDO chặn ở 64 tiến trình FPM | **1,7×** | Khi request phải chờ database |
| Gọi HTTP async thay cho FPM chặn | **2,5×**, dùng một nửa RAM | Khi request phải chờ dịch vụ khác |
| Bật JIT trên 8.5 (bài CPU) | 1,56× | Lớn với code tính toán, gần 0 với JSON/chuỗi/mảng |
| Nâng 7.4 → 8.5 (chỉ interpreter) | 1,05× | Ít hơn nhiều người nghĩ; có JIT thì 1,69× |
| Binary tối ưu bằng PGO | +0,3% / −3,2% | **NO-GO**: không có lợi ổn định |

**So với ngôn ngữ phổ biến** (tốc độ so với PHP 8.5 interpreter; >1 là nhanh hơn PHP):

| | PHP 8.5 JIT | Python 3.13 | Node.js 22 | Go 1.24 |
|---|---|---|---|---|
| Trung bình nhân (9 bài CPU) | 1,56× | **0,36×** | 3,36× | 4,16× |
| Mảng kết hợp | 1,09× | 0,41× | **0,48×** | **0,83×** |
| Xử lý chuỗi | 1,03× | 0,47× | **0,37×** | 0,95× |
| JSON | 0,99× | 0,53× | 1,22× | **0,41×** |
| Tính toán số (`nbody`) | 1,36× | 0,43× | 12,0× | 25,8× |

PHP nhanh hơn Python ở mọi bài. PHP nhanh hơn Node.js và ngang hoặc nhanh hơn Go ở mảng kết hợp và chuỗi, nhanh hơn Go ở JSON. PHP chậm hơn nhiều ở tính toán số thuần; muốn thu hẹp khoảng cách này phải sửa Zend VM/JIT, không thể làm bằng cấu hình.

## Phiên bản hỗ trợ

| Nhánh | Tag upstream | Hỗ trợ upstream | JIT | Chế độ Performance |
|---|---|---|---|---|
| 7.4 | php-7.4.33 | ⚠️ hết hạn | không | không |
| 8.0 | php-8.0.30 | ⚠️ hết hạn | có | không |
| 8.1 | php-8.1.34 | ⚠️ hết hạn | có | có |
| 8.2 | php-8.2.35 | chỉ vá bảo mật | có | có |
| 8.3 | php-8.3.36 | chỉ vá bảo mật | có | có |
| 8.4 | php-8.4.27 | đầy đủ | có | có |
| 8.5 | php-8.5.12 | đầy đủ | có | có |

Profile `full` đã được build và kiểm thử trên 7.4 và 8.5; các nhánh khác dùng cùng script. CI kiểm tra mọi nhánh. Xem [chính sách tương thích](docs/compatibility-policy.vi.md).

## Bắt đầu nhanh

### Trên máy chủ aaPanel (chế độ Compatible, từng site một)

```bash
git clone https://github.com/qhsvn/QHTurbo.git /opt/qhturbo-src && cd /opt/qhturbo-src
sudo scripts/aapanel.sh check   --php=8.5                    # chỉ kiểm tra, không thay đổi gì
sudo scripts/aapanel.sh install --php=8.5                    # build + cài + chạy PHP-FPM (10–40 phút)
sudo scripts/aapanel.sh enable  test.example.com --php=8.5   # chuyển 1 site; lỗi thì tự quay lại
sudo scripts/aapanel.sh disable test.example.com             # quay về PHP của aaPanel
```

Hướng dẫn đầy đủ: [docs/aapanel.vi.md](docs/aapanel.vi.md). Muốn dùng giao diện? Cài [plugin aaPanel](plugins/README.vi.md).

### Máy chủ Linux bất kỳ (hoặc Windows qua WSL2)

```bash
sudo apt install -y git build-essential autoconf bison re2c pkg-config python3 \
  libxml2-dev libssl-dev libsqlite3-dev zlib1g-dev libcurl4-openssl-dev libzip-dev \
  libonig-dev libicu-dev libsodium-dev libpng-dev libjpeg-dev libfreetype-dev libgmp-dev libreadline-dev
git clone https://github.com/qhsvn/QHTurbo.git && cd QHTurbo
export QHT_LAB=~/QHTurbo-lab QHT_PHP=8.5          # QHT_PHP: 7.4 … 8.5
scripts/bootstrap.sh                               # tải + xác minh tag upstream
QHT_PROFILE=full scripts/build.sh baseline         # PHP + PHP-FPM
sudo -E scripts/install.sh                         # -> /opt/qhturbo/8.5, song song PHP hệ thống
/opt/qhturbo/8.5/bin/php /opt/qhturbo/8.5/share/qhturbo/qht-doctor.php
```

Trên Windows: cài WSL2 (`wsl --install -d Ubuntu`) và build trong filesystem Linux (`~/…`), không build trên ổ đĩa Windows.

### Chế độ Performance

```php
<?php // app.php: phần này chạy một lần cho mỗi worker
use QHTurbo\Runtime\Http\{Request, Response};

$config = require __DIR__ . '/config.php';           // khởi động một lần, dùng lại

return function (Request $request) use ($config): Response|array {
    return ['path' => $request->path(), 'env' => $config['env']];  // chạy cho mỗi request
};
```

```bash
php runtime/bin/qhturbo-serve app.php --port=8080 --workers=4 --strict-state
# dự án Laravel hoặc Symfony có sẵn, không cần sửa:
php runtime/bin/qhturbo-serve /var/www/laravel --framework=laravel --workers=4
```

Chi tiết, tuỳ chọn và quy tắc trạng thái: [docs/modes/performance.vi.md](docs/modes/performance.vi.md).

### Nghiên cứu và đo đạc

```bash
scripts/run-tests.sh baseline opcache                               # bộ test PHPT chính thức
python3 scripts/bench.py php --variants baseline,qhturbo --runs 10  # so bản build (kiểm tra nhiễu A/A)
python3 scripts/bench.py cross --php 7.4,8.5 --langs php            # so phiên bản PHP
python3 scripts/bench.py cross --php 8.5 --langs python,node,go     # so ngôn ngữ
sudo benchmarks/web/run.sh                                          # Compatible so với Performance qua HTTP
```

## Áp dụng cho máy chủ ngay hôm nay

1. **Nâng lên PHP 8.3+** nếu ứng dụng cho phép (bảo mật, JIT, ít RAM hơn).
2. **OPcache cho production:** đủ `memory_consumption` và `max_accelerated_files`; `validate_timestamps=0` nếu deploy xong có reload FPM.
3. **JIT:** bật cho ứng dụng tính toán nặng. Với web thông thường, đo trước rồi mới bật.
4. **Preload** cho framework lớn.
5. Phần lớn độ trễ web nằm ở **MySQL/Redis/I/O**. Hãy tối ưu query, index, cache, và cân nhắc chế độ Performance + async I/O.

## Cấu trúc thư mục

```text
config/versions/   tag + commit upstream được ghim cho từng nhánh
config/build.env   cờ configure (profile minimal / full)
config/modes/      cấu hình mẫu cho Compatible (FPM) và Performance (worker)
config/ini/        profile OPcache / JIT / preload
patches/<nhánh>/   chuỗi bản vá mã nguồn (hiện trống: chưa có bản vá engine)
runtime/           QHturbo Performance: event loop, async, HTTP server, worker, bảo vệ trạng thái, Redis/MySQL, Laravel/Symfony
scripts/           bootstrap, build, install, package, aapanel, install-ext, run-tests, bench.py, qht-doctor, fpm-tune
benchmarks/        bài đo CPU bằng PHP/Python/JS/Go, benchmark web, tập huấn luyện PGO
tests/             kiểm tra output giữa các ngôn ngữ, mô phỏng aaPanel end-to-end
results/           báo cáo benchmark và PHPT
logs/              nhật ký phát triển, vấn đề, benchmark
docs/              kiến trúc, các chế độ, hướng dẫn aaPanel, đề xuất, chính sách, các phase
```

## Tài liệu

- [docs/aapanel.vi.md](docs/aapanel.vi.md): cài trên aaPanel từng bước
- [docs/modes/compatible.vi.md](docs/modes/compatible.vi.md), [docs/modes/performance.vi.md](docs/modes/performance.vi.md): hai chế độ chạy
- [docs/proposals.vi.md](docs/proposals.vi.md): đề xuất cải tiến theo thứ tự ưu tiên
- [docs/architecture.vi.md](docs/architecture.vi.md), [docs/benchmark-methodology.vi.md](docs/benchmark-methodology.vi.md), [docs/compatibility-policy.vi.md](docs/compatibility-policy.vi.md), [docs/security-and-release.vi.md](docs/security-and-release.vi.md)
- [ROADMAP.vi.md](ROADMAP.vi.md), [PROJECT_STATUS.vi.md](PROJECT_STATUS.vi.md), [CHANGELOG.vi.md](CHANGELOG.vi.md), kế hoạch gốc: [docs/original-plan.vi.md](docs/original-plan.vi.md)

## Nguyên tắc

Không công bố nhanh hơn nếu không có số đo tái lập được. Không phá tương thích PHP. Không sửa thẳng production: build, rồi staging, rồi canary, luôn có rollback. Mỗi thay đổi có mã công việc, test, số đo và ghi chép. Thử mà không có lợi thì ghi NO-GO, không giấu đi.
