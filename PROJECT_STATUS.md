# PHP QHturbo — Project Status

Cập nhật: 2026-10-22 08:40 UTC (15:40 +07:00)
Giai đoạn hiện tại: PHASE 01 xong → chuẩn bị PHASE 02/04/05
Tổng quan: **Phase 01 DONE · Phase 04 IN_PROGRESS · còn lại TODO**

Máy build/đo: VM đám mây (Intel Xeon 2.8 GHz, 4 vCPU, 15 GiB, Ubuntu 24.04.5, gcc 13.3.0). Chưa chạy trên máy Windows/WSL của người dùng.

## Bảng công việc

| Task | Trạng thái | Commit / bằng chứng | Ghi chú |
|---|---|---|---|
| QHT-P01-001 Khởi tạo repo, cấu trúc thư mục, tài liệu | DONE | repo `tanchinhdo/QHTurbo` | Ổ D: không truy cập được từ máy build → GitHub (ISSUE-002) |
| QHT-P01-002 Hỗ trợ đa phiên bản PHP 7.4 → 8.5 | DONE | `config/versions/*.env`, `scripts/lib.sh` | Yêu cầu bổ sung của người dùng |
| QHT-P01-003 Build baseline 8.5.12 và 7.4.33 | DONE | `BUILD-INFO.txt` (sha256) | 7.4 cần `--enable-json` (đã sửa) |
| QHT-P01-004 PHPT baseline | DONE | `results/tests/` | 8.5: 13568 pass / 0 fail. 7.4: 8997 pass / 46 fail, tất cả do môi trường (xem dưới) |
| QHT-P01-005 Benchmark baseline + A/A | DONE | `results/2026-10-22-php8.5-pgo-v*/` | Nhiễu A/A khoảng ±8% mỗi workload, cỡ 1% ở geomean |
| QHT-P01-006 CI | DONE | GitHub Actions run 39749752272: xanh | Lint + so output 4 ngôn ngữ trên 7.4 → 8.5; build + PHPT Zend + benchmark smoke cho 7.4 và 8.5 trên máy GitHub |
| QHT-P01-007 So sánh 7.4 ↔ 8.5 và với Python/Node/Go | DONE | `results/2026-10-22-cross-*` | Xem README |
| QHT-JIT-001 PGO (v1, v2) | **NO-GO** | `results/2026-10-22-php8.5-pgo-v1`, `-v2` | v2: interp +0.3%, jit −3.2%; PHPT vẫn 0 fail |
| QHT-JIT-001a LTO | **NO-GO** | ISSUE-001 | Xung đột global register variables |
| QHT-P02 Bản vá Zend Engine | TODO | — | Cần `perf` trên máy riêng |
| QHT-P03 Bộ nhớ | TODO | — | |
| QHT-P05 FPM / aaPanel | TODO | — | |
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

1. **Trên máy người dùng:** `git clone` về `D:\usertcd\QHTurbo`, chạy trong WSL theo README (`QHT_LAB=~/QHTurbo-lab`) để có baseline của máy thật.
2. Tạo tag baseline trên máy người dùng (máy build không được phép push tag): `git tag -a qhturbo-baseline-20261022 094b04d -m "baseline" && git push origin qhturbo-baseline-20261022`.
3. Build + PHPT + benchmark cho 8.0 → 8.4 (`QHT_PHP=8.x`).
4. Phase 5: thêm biến thể FPM + extension aaPanel, benchmark HTTP bằng ứng dụng thật. Đây là nơi người dùng thấy lợi ích rõ nhất (OPcache, preload, `pm.*`, Redis/MySQL).
5. Phase 2: trên máy riêng có `perf`, profile `objects`/`nbody`/`spectral_norm` để chọn giả thuyết `QHT-ENG-001`.

## Vướng mắc

- Máy đo đám mây nhiễu (ISSUE-003): một workload đơn lẻ cần chênh hơn ±8% mới đáng tin.
- Ổ D: chỉ đồng bộ được qua Git (ISSUE-002).
