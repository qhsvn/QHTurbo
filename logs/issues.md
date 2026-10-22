# Nhật ký vấn đề

## ISSUE-001 — LTO của GCC không build được Zend VM (đã xử lý: bỏ LTO)

- **Ngày:** 2026-10-22
- **Nhánh:** 8.5 (có thể cả các nhánh khác dùng global register variables)
- **Hiện tượng:** `make prof-gen` với `CFLAGS=-flto=auto` lỗi khi link:
  ```
  ext/opcache/jit/zend_jit_vm_helpers.c:38:34: error: global register variable follows a function definition
  lto-wrapper: fatal error: make returned 2 exit status
  ```
- **Nguyên nhân:** Zend VM khai báo `register ... asm("r14")`/`asm("r15")` cho `execute_data`/`opline` (`HAVE_GCC_GLOBAL_REGS`). Khi LTO gộp nhiều file vào một đơn vị biên dịch, khai báo này đứng sau định nghĩa hàm khác, mà GCC không cho phép điều đó.
- **Lựa chọn:**
  1. `--disable-gcc-global-regs` để dùng được LTO: mất tối ưu quan trọng nhất của VM. Không chọn.
  2. Bỏ LTO, giữ PGO: **đã chọn**.
  3. Biên dịch riêng các file có global register với `-fno-lto`: cần sửa build system. Để lại cho Phase 4.
- **Trạng thái:** đóng (dùng lựa chọn 2), mục theo dõi nằm trong `docs/phases/phase-04-compiler-jit.md`.

## ISSUE-002 — Không truy cập được ổ D: từ môi trường build đám mây

- **Ngày:** 2026-10-22
- **Hiện tượng:** phiên làm việc chạy trên máy Linux đám mây, không thấy `D:\usertcd\QHTurbo` trên máy Windows của người dùng.
- **Xử lý:** dự án được đẩy lên GitHub `tanchinhdo/QHTurbo`. Trên Windows chạy:
  ```powershell
  git clone https://github.com/tanchinhdo/QHTurbo.git D:\usertcd\QHTurbo
  ```
  Build và benchmark nên chạy trong WSL trên filesystem Linux (`QHT_LAB=~/QHTurbo-lab`). Xem README.
- **Trạng thái:** đóng.

## ISSUE-003 — Môi trường đo là VM đám mây dùng chung

- **Ngày:** 2026-10-22
- **Ảnh hưởng:** độ nhiễu (CV) cao hơn máy riêng; dùng phép thử A/A (`baseline` so với `qhturbo` khi chưa có patch) để biết mức nhiễu.
- **Việc cần làm:** đo lại trên máy riêng hoặc máy chủ staging trước khi công bố số cho production.
- **Trạng thái:** mở.

## ISSUE-004 — Chế độ một tiến trình tự tắt sau `--max-requests` (đã sửa)

- **Ngày:** 2026-10-22
- **Hiện tượng:** benchmark `/io` báo 100% lỗi ở chế độ Performance. Backend giả lập (`--workers=0`) chạy được 10.089 request rồi dừng hẳn, nên mọi lệnh gọi bị `connection refused`. Bên FPM chỉ nhận warning nhưng vẫn trả 200, nên số đo của FPM cũng sai.
- **Nguyên nhân:** giới hạn tái tạo mặc định (10.000 request) vẫn áp dụng khi không có supervisor nào để khởi động lại tiến trình.
- **Sửa:** khi `--workers=0` thì tắt cơ chế tái tạo và ghi cảnh báo. Có test mới (`single-process mode keeps serving`). Front controller FPM của benchmark trả 500 khi backend lỗi, để lỗi không còn bị ẩn.

## ISSUE-005 — Rò rỉ bộ nhớ do timer đã huỷ (đã sửa)

- **Ngày:** 2026-10-22
- **Hiện tượng:** tải liên tục 30 s (~26.000 req/s), tổng PSS của 4 worker tăng tuyến tính 129 → 315 MiB.
- **Nguyên nhân:** mỗi `read()` có timeout tạo một timer (5–30 s). Timer bị huỷ chỉ được xoá khỏi bảng, còn phần tử trong `SplPriorityQueue` nằm lại tới khi đến hạn, khoảng 750.000 phần tử.
- **Sửa:** `Loop::cancel()` dựng lại hàng đợi khi số timer đã huỷ > 1024 và > số timer còn sống. Thêm `Loop::stats()` và test (50.000 timeout bị huỷ, hàng đợi ≤ 1.100). Đo lại: 54 MiB, phẳng suốt 30 s.
