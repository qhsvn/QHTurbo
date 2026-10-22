# PHP QHturbo

Dự án thử nghiệm để **làm PHP chạy nhanh hơn một cách có kiểm chứng**, hỗ trợ **PHP 7.4 → 8.5**. Dự án được xây dựng trên mã nguồn PHP chính thức (`php-src`) và giữ tương thích ngược.

> **Trạng thái thật (22/10/2026):** đã xong Phase 1 (nền tảng, baseline, công cụ đo/kiểm thử), cùng hai chế độ chạy **Compatible** và **Performance**. Binary PHP chưa nhanh hơn bản gốc (PGO đã thử 2 lần, đánh giá NO-GO; chưa có bản vá Zend Engine). Tốc độ hiện đến từ **mô hình chạy** (worker chạy lâu dài, async I/O) và cấu hình. Các con số đo trên VM đám mây có nhiễu. Xem [PROJECT_STATUS.md](PROJECT_STATUS.md).

## Hai chế độ chạy

| | **QHturbo Compatible** | **QHturbo Performance** |
|---|---|---|
| Dành cho | Mọi site: WordPress, Laravel, code cũ, aaPanel | API và ứng dụng mới cần tốc độ cao |
| Cách chạy | nginx → PHP-FPM (như PHP truyền thống) | nginx → `qhturbo-serve`: worker chạy lâu dài, event loop, async I/O |
| PHP | 7.4 → 8.5 | 8.1 → 8.5 (Fiber) |
| Sửa code | **Không** | Tuân thủ [quy tắc trạng thái](docs/modes/performance.md#3-quy-tắc-quản-lý-trạng-thái-bắt-buộc) (có công cụ quét và chế độ kiểm tra nghiêm) |
| Đã kiểm chứng | Composer + Laravel 13 (test pass), app cũ (session/cookie/upload/SQLite) qua nginx → FPM | 15 test runtime; tải liên tục không rò RAM |
| Tốc độ (framework giả lập, 4 vCPU) | ~3.000 req/s | **~21.000–23.000 req/s (7–8×)**; I/O: 2.389 so với 939 req/s, dùng một nửa RAM |
| Tài liệu | [docs/modes/compatible.md](docs/modes/compatible.md) | [docs/modes/performance.md](docs/modes/performance.md) |

Cài đặt cả hai: `QHT_PROFILE=full scripts/build.sh baseline && sudo -E scripts/install.sh` (vào `/opt/qhturbo/<nhánh>`, **song song** với PHP đang có, không ghi đè). Đề xuất cải tiến tiếp theo: [docs/proposals.md](docs/proposals.md).

## Kết quả đã đo được

Toàn bộ số liệu, cách đo và giới hạn: [logs/benchmarks.md](logs/benchmarks.md) và thư mục [`results/`](results/).

**1. Những gì thực sự làm PHP nhanh hơn hôm nay** (PHP 8.5.12, 9 workload CPU):

| Thay đổi | Tốc độ (geomean) | Ghi chú |
|---|---|---|
| Nâng 7.4 → 8.5, vẫn dùng interpreter | **1.05×** | Ít hơn nhiều người nghĩ; một số vòng lặp số còn chậm hơn |
| Nâng 7.4 → 8.5 **và bật JIT** | **1.69×** | fannkuch/spectral_norm nhanh hơn 3.5–3.8× |
| Bật JIT trên 8.5 (so với interpreter) | **1.56×** | Lợi lớn cho code tính toán, gần như 0 cho json/chuỗi/mảng |
| PGO (build lại PHP bằng profile) | +0.3% / −3.2% | **NO-GO**: không ổn định, có thoái lui |

**2. So với ngôn ngữ phổ biến** (hệ số tốc độ so với PHP 8.5 interpreter, >1 là nhanh hơn PHP):

| | PHP 8.5 JIT | Python 3.13 | Node.js 22 | Go 1.24 |
|---|---|---|---|---|
| Trung bình (geomean) | 1.56× | **0.36×** | 3.36× | 4.16× |
| Mảng kết hợp (`hashmap`) | 1.09× | 0.41× | **0.48×** | **0.83×** |
| Xử lý chuỗi (`strings`) | 1.03× | 0.47× | **0.37×** | 0.95× |
| JSON | 0.99× | 0.53× | 1.22× | **0.41×** |
| Tính toán số (`nbody`) | 1.36× | 0.43× | 12.0× | 25.8× |

- PHP **nhanh hơn Python** ở mọi bài (khoảng 2.8×, có JIT thì khoảng 4.3×).
- PHP **nhanh hơn Node.js và ngang hoặc nhanh hơn Go** ở mảng kết hợp và xử lý chuỗi. Với JSON, PHP nhanh hơn Go 2.4× nhưng chậm hơn Node.js 1.2×. Đây là những loại việc rất phổ biến trong ứng dụng web.
- PHP **chậm hơn nhiều** ở tính toán số thuần và OOP nặng. Đây là giới hạn kiến trúc. Muốn thu hẹp khoảng cách này thì phải sửa Zend VM/JIT (Phase 2–4), không thể làm bằng cấu hình.

## Phiên bản hỗ trợ

| Nhánh | Tag upstream | Hỗ trợ upstream | JIT |
|---|---|---|---|
| 7.4 | php-7.4.33 | ⚠️ EOL | Không |
| 8.0 | php-8.0.30 | ⚠️ EOL | Có |
| 8.1 | php-8.1.34 | ⚠️ EOL | Có |
| 8.2 | php-8.2.35 | Chỉ vá bảo mật | Có |
| 8.3 | php-8.3.36 | Chỉ vá bảo mật | Có |
| 8.4 | php-8.4.27 | Đầy đủ | Có |
| 8.5 | php-8.5.12 | Đầy đủ | Có |

7.4 và 8.5 đã được build, test và đo trong dự án. 8.0 → 8.4 dùng cùng script, nhưng chưa chạy đo đầy đủ. Các nhánh EOL không còn bản vá bảo mật: xem [chính sách tương thích](docs/compatibility-policy.md).

## Bắt đầu nhanh

### Windows: đặt dự án tại `D:\usertcd\QHTurbo`

```powershell
# PowerShell, tài khoản thường. Kiểm tra trước để không ghi đè dữ liệu cũ.
if (Test-Path D:\usertcd\QHTurbo) { Get-ChildItem -Force D:\usertcd\QHTurbo } 
git clone https://github.com/tanchinhdo/QHTurbo.git D:\usertcd\QHTurbo
```

Build PHP cần Linux. Dùng WSL2 (cài một lần, PowerShell Administrator: `wsl --install -d Ubuntu`), rồi trong Ubuntu:

```bash
sudo apt update && sudo apt install -y git build-essential autoconf bison re2c pkg-config python3
cd /mnt/d/usertcd/QHTurbo
export QHT_LAB=~/QHTurbo-lab   # php-src + build nằm trên filesystem Linux (nhanh, ít nhiễu)
```

### Build, test, đo (Linux hoặc WSL)

```bash
export QHT_PHP=8.5                     # hoặc 7.4, 8.0, 8.1, 8.2, 8.3, 8.4
scripts/bootstrap.sh                   # clone php-src, xác minh tag/commit
scripts/build.sh baseline              # PHP gốc
scripts/build.sh qhturbo               # PHP gốc + patches/$QHT_PHP/
scripts/run-tests.sh qhturbo opcache   # bộ PHPT chính thức (interp | opcache | jit)

# So sánh biến thể (A/A khi chưa có patch = đo độ nhiễu của máy)
taskset -c 2 python3 scripts/bench.py php --variants baseline,qhturbo --runs 10

# So sánh phiên bản PHP, hoặc với Python / Node.js / Go
python3 scripts/bench.py cross --php 7.4,8.5 --langs php
python3 scripts/bench.py cross --php 8.5 --langs python,node,go
```

Binary nằm ở `$QHT_LAB/build/<nhánh>/<biến thể>/bin/php`, kèm `BUILD-INFO.txt` (sha256, cờ build, danh sách patch).

## Áp dụng cho máy chủ ngay hôm nay

Chưa cần binary QHturbo vẫn làm được (xem [`config/ini/`](config/ini/)):

1. **Nâng lên PHP 8.3+** nếu ứng dụng cho phép (bảo mật + JIT + ít RAM hơn).
2. **OPcache production**: `opcache.validate_timestamps=0` (deploy xong thì reload FPM), đủ `memory_consumption` và `max_accelerated_files`.
3. **JIT** (`opcache.jit=tracing`): bật cho ứng dụng nặng tính toán. Với web thông thường, đo trước rồi mới bật.
4. **Preload** cho framework lớn (Laravel, Symfony).
5. Phần lớn độ trễ web nằm ở **MySQL/Redis/I/O**, không nằm ở Zend VM. Hãy tối ưu query, index và cache trước (Phase 5).

## Cấu trúc

```text
config/versions/   tag + commit upstream được ghim cho từng nhánh
config/build.env   cờ configure dùng chung
config/ini/        profile OPcache / JIT / preload
patches/<nhánh>/   chuỗi bản vá mã nguồn (hiện trống)
config/modes/      cấu hình chế độ Compatible (FPM) và Performance (worker)
runtime/           QHturbo Performance: event loop, async, HTTP server, worker, quy tắc trạng thái
scripts/           bootstrap, build, install, package, run-tests, bench.py, qht-doctor, fpm-tune...
benchmarks/        workload PHP/Python/JS/Go, benchmark web 2 chế độ, tập huấn luyện PGO
results/           báo cáo benchmark và PHPT
logs/              nhật ký phát triển, vấn đề, benchmark
docs/              kiến trúc, chính sách, phương pháp, 6 phase, kế hoạch gốc
```

Chi tiết: [docs/architecture.md](docs/architecture.md) · Lộ trình: [ROADMAP.md](ROADMAP.md) · Kế hoạch gốc: [docs/KE-HOACH-GOC.md](docs/KE-HOACH-GOC.md)

## Nguyên tắc

Không công bố nhanh hơn nếu không có số đo tái lập được. Không phá tương thích PHP. Không sửa thẳng production. Mỗi thay đổi có mã công việc, test, số đo và ghi chép. Thử mà không có lợi thì ghi NO-GO, không giấu đi.
