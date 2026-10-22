# CHANGELOG

Định dạng theo [Keep a Changelog](https://keepachangelog.com/). Số đo nào cũng kèm đường dẫn tới báo cáo trong `results/`.

## [Unreleased]

### Added — aaPanel và tài liệu (2026-10-22)
- `scripts/aapanel.sh`: `check`, `install`, `enable <site>` (từng site, tự rollback khi `nginx -t` lỗi hoặc site trả 5xx), `disable`, `status`, `sync-ini` (chép `disable_functions` và giới hạn từ php.ini của aaPanel; worker giữ lại `pcntl_*`), `uninstall`. Lệnh `check` liệt kê extension còn thiếu so với PHP aaPanel.
- `scripts/install-ext.sh`: cài extension PECL; nếu PECL không truy cập được thì dùng bản phát hành ổn định trên GitHub (đã thử redis 6.3.0 trên 8.5, apcu 5.1.28 trên 7.4).
- `tests/aapanel-sim.sh`: kiểm thử end-to-end trên aaPanel mô phỏng (12/12).
- `docs/aapanel.md`: hướng dẫn cài thử trên aaPanel.
- README tiếng Anh làm mặc định (`README.md`), bản tiếng Việt ở `README.vi.md`, kèm mục tính năng chính.

### Changed
- Bỏ các đường dẫn Windows cụ thể (ổ D:) khỏi tài liệu; hướng dẫn dùng `git clone` vào thư mục bất kỳ.

### Added — hai chế độ chạy (2026-10-22)
- **QHturbo Compatible**: build profile `full` (`QHT_PROFILE=full`) gồm PHP-FPM và các extension thường dùng (openssl, curl, intl, gd, mysqli, pdo_mysql, zip, sodium, bcmath, soap, pcntl...). `scripts/install.sh` cài vào `/opt/qhturbo/<nhánh>` song song với PHP có sẵn, kèm cấu hình OPcache an toàn, pool FPM và systemd unit. `scripts/package.sh` đóng gói `.tar.gz` (đã strip, 15 MB) kèm danh sách thư viện phụ thuộc.
- PHP 7.4/8.0 build `full` dùng OpenSSL 1.1.1w riêng (`scripts/deps-openssl11.sh`), không đụng OpenSSL 3 của hệ thống.
- **QHturbo Performance** (`runtime/`, PHP ≥ 8.1): event loop, async I/O bằng Fiber (`async/await/all/delay/timeout`, socket, HTTP client), HTTP/1.1 server không chặn, supervisor nhiều worker (tái tạo theo số request/RAM, reload SIGHUP lần lượt từng worker, dừng êm), `StateGuard` (reset hook, dọn global/ini/output buffer, phát hiện static/RAM tăng dần, chế độ `--strict-state`, `--superglobals` cho code cũ). 15 test.
- `scripts/qht-doctor.php` (PHP 7.4+): kiểm tra cấu hình và quét mã nguồn tìm rủi ro khi chuyển sang worker. `scripts/fpm-tune.py`: tính `pm.*` từ RAM/RSS.
- Benchmark web hai chế độ (`benchmarks/web/run.sh`) và báo cáo `results/web/`.
- Tài liệu: `docs/modes/compatible.md`, `docs/modes/performance.md` (quy tắc trạng thái R1–R9), `docs/proposals.md`.

### Fixed — runtime
- Chế độ một tiến trình tự dừng hẳn sau `--max-requests` vì không có supervisor để khởi động lại: tắt cơ chế tái tạo khi `--workers=0` (ISSUE-004).
- Rò rỉ bộ nhớ dưới tải: timeout đã huỷ nằm lại trong hàng đợi timer (RAM tăng từ 129 lên 315 MiB trong 30 s). Đã thêm cơ chế dọn hàng đợi: RAM ổn định 54 MiB (ISSUE-005).
- Header quá lớn đến trong một lần đọc không bị chặn bằng mã 431.

### Added — nền tảng
- Hỗ trợ build nhiều nhánh PHP: **7.4, 8.0, 8.1, 8.2, 8.3, 8.4, 8.5**, mỗi nhánh ghim tag và commit upstream đã xác minh (`config/versions/`).
- `scripts/bootstrap.sh`: clone `php-src`, lấy tag, kiểm tra commit; cảnh báo khi dùng nhánh đã hết hạn hỗ trợ (EOL).
- `scripts/build.sh`: 3 biến thể `baseline` / `qhturbo` (có patch) / `qhturbo-pgo` (PGO). Mỗi biến thể có worktree riêng và `BUILD-INFO.txt` (sha256, cờ build, danh sách patch). Script không bao giờ xóa thay đổi chưa export trong worktree.
- `scripts/make-patch.sh`: xuất thay đổi trong worktree thành patch có mã công việc, thêm vào `patches/<nhánh>/series`.
- `scripts/run-tests.sh`: chạy bộ PHPT chính thức ở chế độ interp / opcache / jit, lưu tóm tắt pass/fail/skip.
- `scripts/bench.py`: benchmark chạy xen kẽ, warm-up, đo peak RSS, CI 95% bootstrap, kiểm tra output, tổng hợp geomean. Hai lệnh `php` (so biến thể) và `cross` (so với Python/Node.js/Go).
- 9 workload viết bằng 4 ngôn ngữ, cùng output (`tests/check-workloads.sh`). Mã PHP tương thích 7.4.
- Tập huấn luyện PGO riêng (`benchmarks/training/`), tách khỏi tập đo.
- Profile cấu hình production: `config/ini/opcache-production.ini`, `jit.ini`, `preload-example.ini`.
- CI GitHub Actions: shellcheck, lint PHP 7.4 → 8.5, so output giữa các ngôn ngữ, build + PHPT + benchmark smoke cho 7.4 và 8.5.
- Tài liệu: kiến trúc, chính sách tương thích, phương pháp benchmark, bảo mật và phát hành, 6 phase.

### Changed
- Biến thể tối ưu dùng **PGO, không dùng LTO**: LTO của GCC không build được Zend VM vì xung đột với global register variables (ISSUE-001).
- PGO dùng `-fprofile-partial-training`; tập huấn luyện chạy cả khi OPcache bật/tắt và với JIT.

### Fixed
- Build 7.4 thiếu extension json (`--enable-json` cho nhánh 7.x).

### Performance
- **Chưa có cải thiện tốc độ nào được chấp nhận.** PGO v1 (−4.1% / −4.7%) và v2 (+0.3% / −3.2%, interp / JIT) đều **NO-GO** (`results/2026-10-22-php8.5-pgo-v*/`).
- Số đo tham chiếu: JIT trên 8.5 nhanh hơn interpreter 1.56× (geomean); 8.5 so với 7.4: 1.05× (interp), 1.69× (8.5 JIT); PHP 8.5 so với Python 3.13 / Node 22 / Go 1.24: xem `logs/benchmarks.md`.

### Compatibility
- Chưa có bản vá mã nguồn nào: hành vi PHP giống hệt upstream ở mọi nhánh.
- PHPT: 8.5 baseline và 8.5 PGO đều 13568 pass / 0 fail; 7.4 baseline 8997 pass / 46 fail (tất cả do môi trường, xem `PROJECT_STATUS.md`).
