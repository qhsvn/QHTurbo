# CHANGELOG

Định dạng theo [Keep a Changelog](https://keepachangelog.com/). Số đo nào cũng kèm đường dẫn tới báo cáo trong `results/`.

## [Unreleased]

### Added
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
