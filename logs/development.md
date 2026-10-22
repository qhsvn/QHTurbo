# Nhật ký phát triển

Mỗi thay đổi một mục, theo mẫu trong `docs/KE-HOACH-GOC.md` §6.2. Giờ theo UTC.

## 2026-10-22 06:30 — QHT-P01-001 Khởi tạo dự án

- **Mục tiêu:** dựng repo, công cụ build/test/benchmark có thể tái lập, theo kế hoạch gốc.
- **Môi trường:** máy build Linux đám mây (Ubuntu 24.04.5, kernel 6.18, 4 vCPU, 15 GiB RAM, gcc 13.3.0). Không ghi trực tiếp được vào máy Windows của người dùng, nên dự án đẩy lên GitHub `tanchinhdo/QHTurbo` (ISSUE-002).
- **Kết quả:**
  - Xác minh tag upstream mới nhất của từng nhánh qua `git ls-remote` (commit ghim trong `config/versions/`). `php-8.5.12` = `136e049a…`, khớp kế hoạch gốc.
  - Người dùng yêu cầu thêm hỗ trợ **PHP 7.4 trở lên**, nên đã chuyển toàn bộ script sang đa phiên bản (`QHT_PHP=7.4…8.5`).
  - Build baseline 8.5.12 và 7.4.33 thành công (gcc 13.3, `--disable-all` + cli/ctype/tokenizer/filter/mbstring/opcache). OPcache: tĩnh ở 8.5, `opcache.so` ở 7.4.
- **Quyết định:** KEEP.

## 2026-10-22 07:00 — QHT-JIT-001 Biến thể build tối ưu (LTO + PGO)

- **Giả thuyết:** LTO + PGO giúp interpreter nhanh hơn mà không cần sửa mã nguồn.
- **Kết quả lần 1:** LTO lỗi link vì global register variables (ISSUE-001), nên **bỏ LTO**, chỉ giữ PGO.
- **Kết quả lần 2 (chỉ PGO):** đo nhanh 1 lần trên nbody (máy đang bận build): PGO 2.95 s so với baseline 2.63 s, tức **chậm hơn**.
  - Phân tích: với `-fprofile-use`, GCC tối ưu kích thước cho các hàm/handler opcode không chạy trong lúc huấn luyện. Zend VM có hàng nghìn handler chuyên biệt hóa, nhiều cái chỉ chạy ở workload đo.
  - Sửa: thêm `-fprofile-partial-training` (GCC ≥ 10).
- **Kết quả lần 3 (PGO v1 + partial-training, đo đầy đủ 10 vòng):** interp −4.1%, jit −4.7% (geomean), trong khi A/A chỉ +0.6% / −2.5%. Chi tiết trong `logs/benchmarks.md`.
- **Quyết định:** NO-GO cho v1, điều tra tiếp (v2 bên dưới).

## 2026-10-22 07:35 — QHT-JIT-001 (tiếp) PGO v2

- **Giả thuyết:** PGO v1 chậm vì huấn luyện không bật OPcache, nên các handler chuyên biệt hóa theo kiểu (chỉ sinh ra khi bật optimizer) bị coi là mã lạnh.
- **Thay đổi:** `scripts/pgo-train.sh` chạy tập huấn luyện ở 3 chế độ: OPcache on, OPcache off, JIT tracing; PHPT với OPcache on; thêm `Zend/bench.php` và `ext/spl/tests`.
- **Lệnh:** `QHT_PHP=8.5 scripts/build.sh qhturbo-pgo`; `taskset -c 2 python3 scripts/bench.py php --php 8.5 --variants baseline,qhturbo,qhturbo-pgo --runs 10 --name pgo-v2`
- **Benchmark:** interp +0.3%, jit −3.2% (geomean). Chi tiết trong `logs/benchmarks.md`.
- **Quyết định:** **NO-GO.** Không đưa PGO vào bản khuyến nghị. Ý tưởng tiếp theo (Phase 4): huấn luyện bằng request web thật (FPM), so với clang PGO, đo trên máy riêng ít nhiễu.

## 2026-10-22 08:05 — QHT-P01-002 Sửa build 7.4 thiếu json

- **Hiện tượng:** workload `json` lỗi (exit 255) trên 7.4: `--disable-all` tắt json, vì ở 7.4 json chưa phải extension lõi.
- **Sửa:** `config/build.env` thêm `--enable-json` khi `QHT_PHP=7.*`. Build lại 7.4 baseline, `php -m` đã có json.
- **Quyết định:** KEEP.

## 2026-10-22 09:00 — QHT-WEB-001 Hai chế độ Compatible / Performance

- **Yêu cầu của người dùng:** (1) Compatible: PHP chuẩn, PHP-FPM, Composer, ứng dụng cũ, thay đổi tối thiểu; (2) Performance: worker chạy lâu dài, event loop, async I/O, chấp nhận quy tắc trạng thái; (3) đề xuất cải tiến.
- **Thiết kế:** cùng một binary (profile `full`); hai chế độ khác nhau ở cách chạy và cấu hình. Performance là runtime PHP thuần (`runtime/`), không cần extension ngoài, nên dễ kiểm soát. Yêu cầu PHP 8.1+ vì dùng Fiber.
- **Kiểm chứng Compatible:** build 8.5 `full` (51 module), `install.sh` vào `/opt/qhturbo/8.5`, `php-fpm -t` OK. nginx → FPM chạy app cũ (session, cookie, header, upload, SQLite, intl, gd, openssl, sodium). Composer 2.8 trên PHP QHturbo cài Laravel 13.38 (hơn 100 package). Laravel qua FPM trả 200, `artisan test` 2/2.
- **Kiểm chứng Performance:** 15 test (PHP 8.5.12 và 8.3.6). Benchmark web: 7–8× ở bài CPU, 2,5× ở bài I/O với một nửa RAM. Phát hiện và sửa 2 lỗi nhờ benchmark (ISSUE-004, ISSUE-005).
- **Quyết định:** KEEP. Bước tiếp theo theo `docs/proposals.md`: driver MySQL/Redis async, adapter Laravel/Symfony, plugin aaPanel.
