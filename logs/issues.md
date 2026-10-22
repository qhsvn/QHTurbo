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
- **Xử lý:** dự án được đẩy lên GitHub `qhsvn/QHTurbo`. Trên Windows chạy:
  ```powershell
  git clone https://github.com/qhsvn/QHTurbo.git
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

## ISSUE-009 — Symfony trong worker lộ chi tiết exception ở prod (đã sửa)

- **Ngày:** 2026-10-22
- **Phát hiện:** test `symfony: ... safe 500`. Response 500 chứa dump `RuntimeException {#125 #message: "secret detail" ...}` dù `APP_ENV=prod`, `APP_DEBUG=0`.
- **Nguyên nhân:** Symfony chọn bộ hiển thị lỗi theo `kernel.runtime_mode`; khi không có `APP_RUNTIME_MODE`, nó suy ra từ `PHP_SAPI` (`cli`), nên dùng `CliErrorRenderer`.
- **Sửa:** adapter đặt `APP_RUNTIME_MODE=web=1&worker=1` trước khi boot kernel (giống symfony/runtime và FrankenPHP).

## ISSUE-010 — Upload trong Laravel có `isValid() = false` (đã sửa)

- **Nguyên nhân:** Laravel chuyển `UploadedFile` của Symfony sang class của nó bằng `createFromBase()` mà không giữ chế độ test, nên `is_uploaded_file()` từ chối file tạm của worker.
- **Sửa:** adapter tạo luôn `Illuminate\Http\UploadedFile` ở chế độ test.

## ISSUE-011 — "Rò rỉ" bộ nhớ Laravel với driver `array` (xử lý bằng cảnh báo)

- **Phát hiện:** `StateGuard` báo RAM tăng dần (khoảng 700 byte/request). Duyệt toàn bộ đồ thị object thì thấy `ArraySessionHandler->storage` giữ mọi session trong RAM của worker.
- **Kết luận:** đúng bản chất của driver `array` (dành cho test), không phải lỗi adapter. Với `file`/`redis`: tăng khoảng 2 byte/request qua 20.000 request.
- **Xử lý:** adapter cảnh báo khi khởi động nếu `session.driver` hoặc `cache.default` là `array`.

## ISSUE-012 — Benchmark Laravel sai do thư mục session phình to (đã xử lý trong cách đo)

- **Hiện tượng:** lần đo đầu, cả FPM và worker chỉ đạt khoảng 100–300 req/s, worker còn chậm hơn FPM.
- **Nguyên nhân:** 43.942 file session (175 MB) do benchmark tạo ra; Laravel định kỳ quét cả thư mục để dọn session cũ, nên lần chạy sau chậm hơn lần chạy trước.
- **Xử lý:** dọn thư mục, dùng Redis cho session/cache ở cả hai chế độ. Số đo cũ bị loại.

## ISSUE-013 — `aapanel.sh sync-ini` dừng giữa chừng trên php.ini thật (đã sửa)

- **Ngày:** 2026-10-22
- **Phát hiện:** rà soát code trước khi chạy thử. php.ini mặc định của aaPanel có `session.save_handler = files` nhưng `session.save_path` bị comment. Với `set -euo pipefail`, `grep` không tìm thấy dòng nào sẽ trả mã 1 và script thoát ngay, không báo lỗi gì, trước khi ghi xong file cấu hình.
- **Sửa:** thêm `|| true` cho các `grep` "không khớp cũng được". `tests/aapanel-sim.sh` giờ dùng php.ini có `;session.save_path`; script cũ thất bại ở bước `sync-ini`, script mới qua 13/13.

## ISSUE-014 — Pool mất kết nối khi timeout trùng lúc trả kết nối (đã sửa)

- **Nguyên nhân:** `acquire()` dùng `timeout()` bọc future chờ. Khi timer hết hạn, future bọc ngoài thất bại nhưng future chờ trong hàng đợi vẫn "chưa xong" cho tới khi fiber chạy lại. Nếu `release()` chạy trong khoảng đó (cùng tick), kết nối được giao cho waiter đã bỏ cuộc và mất luôn: `size` vẫn tính nó, nên pool dần cạn.
- **Sửa:** timer làm thất bại chính future chờ và gỡ nó khỏi hàng đợi trong cùng một bước. Test `pool: a waiter that timed out never receives a connection`.

## ISSUE-015 — Reload và tín hiệu trong supervisor (đã sửa)

- **Reload không nạp code mới khi `opcache.validate_timestamps=0`** (cấu hình Performance mặc định): `opcache_reset()` trong tiến trình CLI chỉ đặt cờ "chờ reset", việc reset xảy ra ở lần khởi động request tiếp theo, mà tiến trình master không bao giờ có. Trong lúc chờ, OPcache ngừng cache. Giờ master vô hiệu hoá từng script đã cache (`opcache_invalidate`), có tác dụng ngay trong bộ nhớ dùng chung.
- **Deploy lỗi làm sập dịch vụ:** reload sinh worker mới rồi giết worker cũ ngay, kể cả khi worker mới chết vì lỗi cú pháp. Giờ worker mới báo "sẵn sàng" qua một socket pair; chỉ khi đó worker cũ mới bị dừng (tối đa 60 giây để xử lý nốt request).
- **Worker con kế thừa handler SIGTERM của master:** SIGTERM đến khi worker đang khởi động sẽ chạy `shutdown()` của master *trong worker*, rồi worker vẫn chạy tiếp và master chờ mãi. Giờ worker đặt lại SIGTERM/SIGINT về mặc định ngay sau `fork()`.
- Test: `server: reload loads new code; a broken deploy keeps the old workers`, `server: SIGTERM while workers are still booting stops promptly` (code cũ treo ở test này).

## ISSUE-016 — Laravel: khoá config thêm trong request không bị xoá (đã sửa)

- **Nguyên nhân:** khôi phục bằng `config()->set($snapshot)` chỉ ghi đè các khoá có trong snapshot; khoá mới (ví dụ `config(['tenant' => ...])`) vẫn còn, sang request của người dùng khác. Vì mảng không bao giờ bằng snapshot nữa, adapter còn ghi log "config changed" ở mọi request sau.
- **Sửa:** thay nguyên mảng `items` của repository. Khi config không đổi, phép so sánh chỉ là so con trỏ (mảng dùng chung, copy-on-write). Test trong `frameworks.php`.
