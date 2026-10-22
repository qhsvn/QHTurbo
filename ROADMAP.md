# ROADMAP — PHP QHturbo

Kế hoạch gốc (đầy đủ nguyên tắc, rủi ro, quy trình): [`docs/KE-HOACH-GOC.md`](docs/KE-HOACH-GOC.md).
File này tóm tắt lộ trình và **trạng thái thật** của từng giai đoạn. Chi tiết từng ngày xem `PROJECT_STATUS.md`.

**Phạm vi phiên bản:** PHP **7.4, 8.0, 8.1, 8.2, 8.3, 8.4, 8.5** ([chính sách](docs/compatibility-policy.md)). Nhánh phát triển tối ưu chính là 8.5. Mọi tối ưu ở mức trình biên dịch (PGO) áp dụng cho mọi nhánh.

| Phase | Nội dung | Trạng thái | Tài liệu |
|---|---|---|---|
| 01 | Nền tảng, baseline, công cụ build/test/benchmark, CI | **DONE** (trên máy build Linux) | [phase-01](docs/phases/phase-01-foundation.md) |
| 02 | Tối ưu Zend Engine (bản vá C) | TODO | [phase-02](docs/phases/phase-02-engine.md) |
| 03 | Bộ nhớ và cấu trúc dữ liệu | TODO | [phase-03](docs/phases/phase-03-memory.md) |
| 04 | Trình biên dịch / OPcache / JIT | IN_PROGRESS (PGO + số đo JIT xong) | [phase-04](docs/phases/phase-04-compiler-jit.md) |
| 05 | PHP-FPM / web / aaPanel: chế độ **Compatible** và **Performance** | IN_PROGRESS (2 chế độ đã chạy và đo; còn thiếu plugin aaPanel, driver DB async, adapter framework) | [compatible](docs/modes/compatible.md) · [performance](docs/modes/performance.md) · [phase-05](docs/phases/phase-05-runtime-aaPanel.md) |
| 06 | Tương thích, bảo mật, phát hành | TODO | [phase-06](docs/phases/phase-06-release.md) |

## Nguyên tắc (rút gọn từ kế hoạch gốc)

1. Không tuyên bố nhanh hơn nếu không có số đo tái lập được (CI 95% không chứa 1.0).
2. Không phá tương thích PHP: PHPT không xấu đi so với baseline cùng nhánh.
3. Không sửa thẳng production: build/VM, rồi staging, rồi canary. Luôn có rollback.
4. Mỗi tối ưu là một commit/patch riêng, có mã công việc `QHT-...`.
5. Chỉ đánh dấu DONE khi có bằng chứng: commit, lệnh, log, kết quả.

## Bước tiếp theo theo thứ tự ưu tiên

Chi tiết và lý do: [docs/proposals.md](docs/proposals.md).

1. Chạy chế độ Compatible trên staging của người dùng (aaPanel), đo bằng site thật.
2. Driver MySQL/Redis async + pool kết nối cho Performance mode (B4, B5).
3. Adapter Laravel/Symfony cho Performance mode (B6).
4. Plugin aaPanel và gói `.deb` (B7, B8).
5. Build và kiểm thử bản `full` cho 8.0 → 8.4.
6. Phase 2: profile bằng `perf` trên máy riêng, chọn giả thuyết `QHT-ENG-001`.
