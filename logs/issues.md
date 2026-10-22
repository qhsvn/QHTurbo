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

## ISSUE-002 — Không truy cập được máy Windows của người dùng từ môi trường build đám mây

- **Ngày:** 2026-10-22
- **Hiện tượng:** phiên làm việc chạy trên máy Linux đám mây, không ghi trực tiếp được vào ổ đĩa trên máy Windows của người dùng.
- **Xử lý:** dự án được đẩy lên GitHub `tanchinhdo/QHTurbo`. Trên Windows chạy:
  ```powershell
  git clone https://github.com/tanchinhdo/QHTurbo.git
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

## ISSUE-006 — Race khi worker dừng: kết nối vừa accept bị đóng không phản hồi (đã sửa)

- **Ngày:** 2026-10-22
- **Phát hiện:** CI (GitHub Actions) báo lỗi bộ test runtime trên PHP 8.3/8.4/8.5. Tái hiện được ở máy build khi bật OPcache + JIT cho CLI (giống cấu hình mặc định của `setup-php`): test reload SIGHUP có 1 request bị đóng mà không có phản hồi.
- **Nguyên nhân:** (1) nếu worker bắt đầu dừng giữa lúc `accept()` và lần đọc đầu tiên, vòng lặp phục vụ thoát ngay; (2) khi dừng, worker đóng **mọi** kết nối đang chờ, kể cả kết nối mới chưa gửi request.
- **Sửa:** kết nối mới luôn được phục vụ request đầu tiên; khi dừng chỉ đóng kết nối keep-alive **đang rảnh**. Test chạy lặp 16 lần (có và không có JIT) đều pass.

## ISSUE-007 — Request lỗi chập chờn khi reload SIGHUP (đã sửa)

- **Ngày:** 2026-10-22
- **Hiện tượng:** run CI ở commit `0666733` (sau khi sửa ISSUE-006): job `workloads (8.5)`, bước runtime tests fail một lần. Không đọc được log vì storage log của GitHub bị chặn từ máy build.
- **Đã làm:** test runner giờ in lỗi thành annotation `::error` (đọc được qua API). Sau đó 3 lần chạy CI liên tiếp (1 lần mới + 2 lần re-run) đều xanh trên 7.4 → 8.5, và 16 lần chạy ở máy build (có/không JIT) cũng pass.
- **Tái diễn** ở commit `bd6179a` trên PHP 8.1; annotation chỉ ra đúng test: `SIGHUP reload ... 1 failed requests during reload`.
- **Nguyên nhân:** tín hiệu SIGTERM (async) có thể chạy **giữa** `stream_socket_accept()` và lúc kết nối được ghi vào danh sách. Khi đó worker thấy "không còn kết nối", lên lịch dừng, và kết nối vừa nhận bị đóng mà không có phản hồi. Tải thường rất khó gặp (25 lần chạy dưới tải CPU ở máy build đều không tái hiện được).
- **Sửa:** bước dừng cuối cùng được hoãn sang tick sau và **kiểm tra lại** không còn kết nối hay request nào; nếu có, việc dừng chờ kết nối đó xong.
- **Trạng thái:** đã sửa; theo dõi tiếp qua CI.

## ISSUE-008 — Worker crash khi `disable_functions` chặn `pcntl_signal` (đã sửa)

- **Ngày:** 2026-10-22
- **Phát hiện:** khi thử tích hợp aaPanel. aaPanel chặn `pcntl_*` trong php.ini; chạy `qhturbo-serve` với php.ini đó thì chế độ một tiến trình crash (`Call to undefined function pcntl_signal()`), còn chế độ nhiều worker báo sai là "thiếu extension pcntl".
- **Sửa:** kiểm tra từng hàm cần dùng. Chế độ một tiến trình vẫn chạy (kèm cảnh báo: SIGTERM dừng ngay, không chờ request xong). Chế độ nhiều worker báo rõ hàm nào bị `disable_functions` chặn và cách dùng `performance.d`. Có test mới; `scripts/aapanel.sh` tự tạo `performance.d/05-from-aapanel.ini` bỏ `pcntl_*` cho worker.
