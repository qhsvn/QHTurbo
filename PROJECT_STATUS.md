# PHP QHturbo — Project Status

Cập nhật: 2026-10-22 09:40 UTC (16:40 +07:00)
Giai đoạn hiện tại: PHASE 05: hai chế độ Compatible / Performance
Tổng quan: **Phase 01 DONE · Phase 04, 05 IN_PROGRESS · còn lại TODO**

Máy build/đo: VM đám mây (Intel Xeon 2.8 GHz, 4 vCPU, 15 GiB, Ubuntu 24.04.5, gcc 13.3.0). Chưa chạy trên máy Windows/WSL của người dùng.

## Bảng công việc

| Task | Trạng thái | Commit / bằng chứng | Ghi chú |
|---|---|---|---|
| QHT-P01-001 Khởi tạo repo, cấu trúc thư mục, tài liệu | DONE | repo `tanchinhdo/QHTurbo` | Đồng bộ qua GitHub (ISSUE-002) |
| QHT-P01-002 Hỗ trợ đa phiên bản PHP 7.4 → 8.5 | DONE | `config/versions/*.env`, `scripts/lib.sh` | Yêu cầu bổ sung của người dùng |
| QHT-P01-003 Build baseline 8.5.12 và 7.4.33 | DONE | `BUILD-INFO.txt` (sha256) | 7.4 cần `--enable-json` (đã sửa) |
| QHT-P01-004 PHPT baseline | DONE | `results/tests/` | 8.5: 13568 pass / 0 fail. 7.4: 8997 pass / 46 fail, tất cả do môi trường (xem dưới) |
| QHT-P01-005 Benchmark baseline + A/A | DONE | `results/2026-10-22-php8.5-pgo-v*/` | Nhiễu A/A khoảng ±8% mỗi workload, cỡ 1% ở geomean |
| QHT-P01-006 CI | DONE | GitHub Actions run 39749752272: xanh | Lint + so output 4 ngôn ngữ trên 7.4 → 8.5; build + PHPT Zend + benchmark smoke cho 7.4 và 8.5 trên máy GitHub |
| QHT-P01-007 So sánh 7.4 ↔ 8.5 và với Python/Node/Go | DONE | `results/2026-10-22-cross-*` | Xem README |
| QHT-JIT-001 PGO (v1, v2) | **NO-GO** | `results/2026-10-22-php8.5-pgo-v1`, `-v2` | v2: interp +0.3%, jit −3.2%; PHPT vẫn 0 fail |
| QHT-JIT-001a LTO | **NO-GO** | ISSUE-001 | Xung đột global register variables |
| QHT-WEB-001 **QHturbo Compatible**: build `full` + FPM, install/package, cấu hình | DONE (8.5, 7.4) | `docs/modes/compatible.md` | App cũ chạy giống hệt trên 7.4 và 8.5 qua nginx → FPM; Composer + Laravel 13 (test pass) trên 8.5; Composer + Guzzle HTTPS trên 7.4 |
| QHT-WEB-002 **QHturbo Performance**: runtime worker/event loop/async/quy tắc trạng thái | DONE (bản đầu) | `runtime/`, 16 test; CI xanh trên 8.1 → 8.5 (3 lần liên tiếp) | 7–8× FPM ở bài CPU; 2,5× ở bài I/O với một nửa RAM (`results/web/`) |
| QHT-WEB-003 `qht-doctor`, `fpm-tune` | DONE | `scripts/` | doctor chạy được từ 7.4 trở lên |
| QHT-WEB-004 Driver MySQL/Redis async, pool kết nối | DONE | `runtime/src/{Pool,Redis,Mysql}`, 8 test với Redis 7.0/MariaDB 10.11 (CI: Redis 7, MySQL 8.4) | MySQL I/O: 1.724 req/s so với 1.011 của FPM ×64 |
| QHT-WEB-006 Tích hợp aaPanel (`scripts/aapanel.sh`, `install-ext.sh`) | DONE (mô phỏng) | `tests/aapanel-sim.sh` 12/12, `docs/aapanel.md` | Chưa chạy trên aaPanel thật |
| QHT-WEB-005 Adapter Laravel/Symfony | DONE | `runtime/src/Bridge`, `runtime/tests/frameworks.php` 6/6 | Laravel 13: 6,7–14,6×; Symfony 8.1: 6,3× so với FPM |
| QHT-WEB-007 Plugin giao diện aaPanel, gói .deb | TODO | B7–B8 | |
| QHT-P02 Bản vá Zend Engine | TODO | — | Cần `perf` trên máy riêng |
| QHT-P03 Bộ nhớ | TODO | — | |
| QHT-P06 Phát hành | TODO | — | |
| Build + đo 8.0, 8.1, 8.2, 8.3, 8.4 | TODO | — | Script đã hỗ trợ, chưa chạy |

## PHPT — chi tiết

| Build | Pass | Fail | XFAIL | Skip | Ghi chú |
|---|---|---|---|---|---|
| 8.5 baseline (opcache) | 13568 | 0 | 6 | 8706 | Skip = extension không build (`--disable-all`) |
| 8.5 qhturbo-pgo (opcache) | 13568 | 0 | 6 | 8706 | Binary PGO không làm hỏng test nào |
| 7.4 baseline (opcache) | 8997 | 46 | 30 | 6719 | 35 preload: chạy bằng root nên cần `opcache.preload_user`; 6 không có TTY/stdin; 4 mạng (DNS bị chặn, không có wrapper https vì không build openssl); 1 timeout copy file 4 GB |

Không có test fail nào liên quan đến QHturbo (chưa có patch mã nguồn).

## Bước kế tiếp

1. **Trên máy người dùng:** `git clone` repo, chạy trong Linux/WSL theo README (`QHT_LAB=~/QHTurbo-lab`) để có baseline của máy thật; thử aaPanel theo `docs/aapanel.md`.
2. Tạo tag baseline trên máy người dùng (máy build không được phép push tag): `git tag -a qhturbo-baseline-20261022 094b04d -m "baseline" && git push origin qhturbo-baseline-20261022`.
3. Build + PHPT + benchmark cho 8.0 → 8.4 (`QHT_PHP=8.x`).
4. Phase 5: thêm biến thể FPM + extension aaPanel, benchmark HTTP bằng ứng dụng thật. Đây là nơi người dùng thấy lợi ích rõ nhất (OPcache, preload, `pm.*`, Redis/MySQL).
5. Phase 2: trên máy riêng có `perf`, profile `objects`/`nbody`/`spectral_norm` để chọn giả thuyết `QHT-ENG-001`.

## Lỗi phát hiện và đã sửa trong phiên này

- ISSUE-004: chế độ một tiến trình tự tắt sau `--max-requests`.
- ISSUE-005: rò rỉ RAM do timer đã huỷ (129 → 315 MiB trong 30 s, nay ổn định 54 MiB).
- ISSUE-006: race khi worker dừng làm mất phản hồi của kết nối vừa accept (CI phát hiện trên 8.3–8.5).
- ISSUE-007: race khi reload (tín hiệu đến giữa lúc accept), CI phát hiện nhờ annotation.
- ISSUE-008: worker crash khi aaPanel chặn `pcntl_signal`.

## Vướng mắc

- Máy đo đám mây nhiễu (ISSUE-003): một workload đơn lẻ cần chênh hơn ±8% mới đáng tin.
- Máy người dùng chỉ đồng bộ được qua Git (ISSUE-002).
