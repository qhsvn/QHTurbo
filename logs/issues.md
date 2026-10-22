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
