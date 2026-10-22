# CHANGELOG

[English](CHANGELOG.md) · **Tiếng Việt**

Định dạng theo [Keep a Changelog](https://keepachangelog.com/). Số đo nào cũng kèm đường dẫn tới báo cáo trong `results/`.

## [Unreleased]

### Added — DirectAdmin (2026-10-22)
- `scripts/directadmin.sh`: QHturbo trên DirectAdmin (Apache / nginx_apache), từng domain một, tự rollback. User của domain có một pool PHP-FPM QHturbo chạy bằng chính user đó, chép từ pool của DirectAdmin (cùng open_basedir và giới hạn); việc chuyển là một khối có đánh dấu trong file httpd tuỳ chỉnh của domain (`.cust_httpd`) dùng `SetHandler`, đã thí nghiệm xác nhận thắng `AddHandler` của DirectAdmin dù đặt trước hay sau; cấu hình tuỳ chỉnh khác được giữ nguyên.
- Plugin DirectAdmin (`plugins/directadmin/qhturbo.tar.gz`) và `scripts/panel-agent.sh`: trang plugin chạy bằng user của admin nên chỉ xếp yêu cầu vào hàng đợi; tác tử root (systemd path unit) chạy yêu cầu sau khi xác nhận người gửi là admin DirectAdmin và kiểm tra từng giá trị. Test: `tests/directadmin-sim.sh`, 32/32 trên DirectAdmin mô phỏng với Apache thật.
- `scripts/panel-lib.sh`: các hàm build, service, FPM dùng chung cho `aapanel.sh` và `directadmin.sh`.

### Added — plugin aaPanel (2026-10-22)
- `plugins/aapanel/qhturbo`: plugin aaPanel (file zip để Import trong App Store, hoặc chép bằng dòng lệnh). Thẻ Phiên bản PHP (cài, chép extension, kiểm tra, build lại, gỡ), thẻ Site (chuyển sang QHturbo hoặc quay lại, từng site), nhật ký tác vụ trực tiếp; tiếng Anh và tiếng Việt. Plugin chạy đúng các script dòng lệnh; tác vụ dài chạy nền, tách khỏi panel. Đã test trên aaPanel mô phỏng (`tests/aapanel-plugin.py`, 13 kiểm tra). Plugin DirectAdmin và cPanel đang lên kế hoạch.
- `aapanel.sh versions|sites`: danh sách phân cách bằng tab cho plugin; FPM chỉ được tính là đang chạy khi tiến trình còn sống (không chỉ dựa vào file socket còn sót).

### Added / Fixed — lần chạy đầu trên server aaPanel thật (2026-10-22)
- `aapanel.sh` hỗ trợ aaPanel dùng **Apache** (tự nhận biết, hoặc `--web=nginx|apache`): `enable` trỏ dòng `SetHandler "proxy:unix:...|fcgi://localhost"` của site sang QHturbo, vẫn có backup, kiểm tra cấu hình và tự rollback như với nginx. Test mới `tests/aapanel-sim-apache.sh` (14/14).
- Site không kết nối được MySQL sau khi chuyển (ISSUE-017): unit PHP-FPM dùng `PrivateTmp`, che mất `/tmp/mysql.sock` của aaPanel. Đã bỏ; `sync-ini` chép thêm cấu hình socket MySQL.
- Extension tự đi theo PHP đang có trên server: `scripts/sync-ext.sh --from=<php>` dùng lại file `.so` của PHP đó khi cùng phiên bản (chỉ những file nạp không lỗi; đã thử với PHP 8.3 của Ubuntu: dùng lại redis+igbinary, pdo_pgsql, xsl, ffi, sysvmsg, còn pgsql phải build lại vì liên kết PCRE2 của hệ thống) và build phần còn lại. `aapanel.sh` tự chạy sau `install` và trước `enable` (`aapanel.sh sync-ext` để chạy tay). `install-ext.sh` build được cả extension đi kèm PHP (pgsql, xsl, ffi, bz2, ldap…) từ mã nguồn PHP. Test: `tests/sync-ext.sh` (7/7).
- `install-ext.sh ioncube` cài ionCube Loader; `install-ext.sh` tự cài thư viện hệ thống mà extension cần (apt/dnf). `ftp` và `gettext` (và `xmlrpc` với 7.4) có sẵn khi build. Khi rollback, script nêu các extension mà PHP gốc của site có nhưng QHturbo thiếu, và phiên bản PHP site đang chạy.
- `aapanel.sh install` dừng **trước** khi build nếu không tìm thấy web server được hỗ trợ (trước đây chỉ cảnh báo, build 10–40 phút rồi mới lỗi khi ghi file include của nginx).
- `aapanel.sh install` lỗi trên server mới với `failed to open error_log (/var/log/qhturbo/...)`: bước kiểm tra cấu hình PHP-FPM chạy trước khi có thư mục log. `install.sh` giờ cũng tạo `/run/qhturbo` và `/var/log/qhturbo`.
- Trang web (`website/`, GitHub Pages) với hướng dẫn cài đặt và ví dụ; mọi tài liệu có bản tiếng Anh và tiếng Việt.

### Fixed — rà soát lỗi trước khi chạy thử (2026-10-22)
Rà soát toàn bộ code (43 phát hiện), sửa các lỗi thật và thêm test hồi quy cho từng lỗi quan trọng (ISSUE-013 đến ISSUE-016).
- **aaPanel:** `aapanel.sh sync-ini` dừng giữa chừng khi php.ini thiếu một khoá (ví dụ `session.save_path` bị comment), do `grep` không khớp dưới `set -o pipefail` (ISSUE-013). `performance.d` giờ chỉ mở lại đúng các hàm `pcntl_*`/`posix_kill` mà worker cần, `pcntl_exec` vẫn bị chặn. Kiểm tra rollback đi theo chuyển hướng HTTP→HTTPS.
- **Script:** `install-ext.sh` thoát im lặng khi không tìm thấy phiên bản; `run-tests.sh` không `shift` khi chỉ có một tham số và bỏ qua `QHT_PROFILE`; `make-patch.sh` bỏ qua `QHT_PROFILE`; `build.sh` thiếu dấu cách giữa các cờ LDFLAGS; `qht-doctor` bỏ qua cả dự án nằm trong thư mục tên `cache`/`tests`; `package.sh` ghi đè cấu hình của server khi giải nén và dùng số CPU của máy build (giờ cấu hình nằm ở `share/qhturbo/etc.dist`, chạy `setup.sh` sau khi giải nén).
- **Pool:** request hết thời gian chờ kết nối đúng lúc một kết nối được trả về làm mất hẳn kết nối đó (ISSUE-014).
- **Supervisor:** reload không nạp code mới khi `opcache.validate_timestamps=0` (`opcache_reset()` không có tác dụng trong tiến trình CLI và còn làm OPcache ngừng cache); reload giết worker cũ cả khi code mới không khởi động được, giờ worker cũ được giữ lại; chờ worker cũ không giới hạn thời gian; worker con chạy handler SIGTERM của master khi nhận tín hiệu lúc đang khởi động, làm master không bao giờ dừng (ISSUE-015); sinh worker mới sau khi đã nhận SIGTERM.
- **HTTP server:** `Content-Length` âm/không hợp lệ/trùng nhau được chấp nhận (nguy cơ request smuggling); header lặp lại bị ghi đè thay vì nối; không hỗ trợ `Expect: 100-continue` (curl chờ 1 giây trước mỗi upload lớn); không giới hạn số kết nối (vượt `FD_SETSIZE` làm worker quay vòng 100% CPU), thêm `--max-connections` và `LimitNOFILE`; slot xử lý bị giữ trong lúc gửi response cho client chậm; header đóng khung (`Content-Length`, `Transfer-Encoding`) do ứng dụng đặt với chữ hoa/thường khác bị gửi trùng; CR/LF trong giá trị header; HEAD/204/304; địa chỉ IPv6 (`REMOTE_ADDR`, `--host=::`, header Host); file upload tạm bị bỏ lại khi ghi lỗi.
- **State:** hook reset không chạy khi `--concurrency > 1` dưới tải liên tục; `$_SERVER` bị khôi phục về trạng thái trước bootstrap (mất các giá trị `.env`); vòng dọn output buffer lặp vô hạn khi buffer không xoá được; đo static property chậm với framework lớn (giờ chỉ reflect class mới).
- **Laravel/Symfony:** khoá config mới thêm trong request không bị xoá và bị log ở mọi request sau (ISSUE-016); `StreamedResponse` gọi `ob_flush()` làm dữ liệu đi ra STDOUT của worker thay vì vào response.
- **MySQL/Redis:** tự thử lại sau lỗi "gone away" có thể chạy một câu INSERT/UPDATE hai lần (giờ chỉ thử lại khi câu lệnh chưa được gửi đi); kết nối nhàn rỗi trên 30 giây được kiểm tra trước khi dùng; thêm mã lỗi 4031, 2014, 1053; timer poll 0,5 ms không dừng sau timeout; `--` trong `5--?` bị coi là comment; `COMMIT WORK`/`ROLLBACK WORK` không được ghi nhận; mật khẩu chứa `+` trong URI bị đổi thành dấu cách; hỗ trợ `unix:///path/redis.sock`; HTTP client thiếu cổng trong header Host.
- **CLI:** `--memory-limit=256MB` bị hiểu là 256 byte (worker bị tái tạo sau mỗi request); `--framework` không có giá trị, số worker âm, cổng ngoài phạm vi giờ báo lỗi rõ ràng.
- Test: 34 test runtime (thêm 10), test framework kiểm tra thêm config, `StreamedResponse`, HEAD; `tests/aapanel-sim.sh` thêm trường hợp php.ini thiếu `session.save_path` (13/13). Các test mới đều thất bại trên code cũ.

### Added — Redis/MySQL async và adapter framework (2026-10-22)
- `runtime/src/Pool`: pool kết nối có giới hạn, dùng chung cho các request của một worker (hàng đợi khi pool hết chỗ, loại kết nối hỏng, timeout khi lấy kết nối).
- `runtime/src/Redis`: client Redis không chặn (RESP2, pipeline, AUTH/SELECT, mọi lệnh qua `__call`).
- `runtime/src/Mysql`: client MySQL/MariaDB không chặn (mysqlnd `MYSQLI_ASYNC` + `mysqli_poll`); tham số `?` được escape, kể cả `IN (?)`; `transaction()`; rollback transaction còn mở khi trả kết nối (R7); timeout query; tự thử lại khi gặp "gone away".
- Runtime: upload `multipart/form-data` (`$_FILES`, file tạm tự xoá), header nhiều giá trị (`Set-Cookie`), `--max-body`.
- Adapter `--framework=laravel|symfony` (`runtime/src/Bridge`). Laravel: khởi động một lần, dọn trạng thái kiểu Octane, cảnh báo khi dùng driver `array`. Symfony: reset service `kernel.reset`, đặt `APP_RUNTIME_MODE`.
- Test: 24 test runtime (thêm 8 test Redis/MySQL với server thật), 6 test framework trên Laravel 13 và Symfony 8.1 (`runtime/tests/frameworks.php`). CI có job Redis 7 + MySQL 8.4 và job framework.
- Benchmark: `benchmarks/web/framework.sh` (Laravel/Symfony thật), `benchmarks/web/db/run.sh` (MySQL I/O).

### Fixed — trong quá trình làm adapter
- Symfony chạy trong worker CLI in chi tiết exception vào response 500 ở prod (bộ hiển thị lỗi CLI) → adapter đặt `APP_RUNTIME_MODE=web=1&worker=1` (ISSUE-009).
- File upload trong Laravel bị `isValid() = false` → tạo `Illuminate\Http\UploadedFile` ở chế độ test (ISSUE-010).
- Lỗi trong hook reset không còn làm mất response.
- Hàm async được bảo vệ khỏi khai báo trùng khi runtime được nạp từ hai đường dẫn.

### Added — aaPanel và tài liệu (2026-10-22)
- `scripts/aapanel.sh`: `check`, `install`, `enable <site>` (từng site, tự rollback khi `nginx -t` lỗi hoặc site trả 5xx), `disable`, `status`, `sync-ini` (chép `disable_functions` và giới hạn từ php.ini của aaPanel; worker giữ lại `pcntl_*`), `uninstall`. Lệnh `check` liệt kê extension còn thiếu so với PHP aaPanel.
- `scripts/install-ext.sh`: cài extension PECL; nếu PECL không truy cập được thì dùng bản phát hành ổn định trên GitHub (đã thử redis 6.3.0 trên 8.5, apcu 5.1.28 trên 7.4).
- `tests/aapanel-sim.sh`: kiểm thử end-to-end trên aaPanel mô phỏng (12/12).
- `docs/aapanel.md`: hướng dẫn cài thử trên aaPanel.
- README tiếng Anh làm mặc định (`README.md`), bản tiếng Việt ở `README.vi.md`, kèm mục tính năng chính.

### Fixed
- Race khi reload: SIGTERM đến giữa lúc accept làm mất phản hồi của kết nối mới (ISSUE-007).
- Worker crash khi `disable_functions` chặn `pcntl_signal` (mặc định của aaPanel); báo lỗi rõ ràng (ISSUE-008).

### Changed
- Bỏ các đường dẫn Windows cụ thể (ổ D:) khỏi tài liệu; hướng dẫn dùng `git clone` vào thư mục bất kỳ.

### Added — hai chế độ chạy (2026-10-22)
- **QHturbo Compatible**: build profile `full` (`QHT_PROFILE=full`) gồm PHP-FPM và các extension thường dùng (openssl, curl, intl, gd, mysqli, pdo_mysql, zip, sodium, bcmath, soap, pcntl...). `scripts/install.sh` cài vào `/opt/qhturbo/<nhánh>` song song với PHP có sẵn, kèm cấu hình OPcache an toàn, pool FPM và systemd unit. `scripts/package.sh` đóng gói `.tar.gz` (đã strip, 15 MB) kèm danh sách thư viện phụ thuộc.
- PHP 7.4/8.0 build `full` dùng OpenSSL 1.1.1w riêng (`scripts/deps-openssl11.sh`), không đụng OpenSSL 3 của hệ thống.
- **QHturbo Performance** (`runtime/`, PHP ≥ 8.1): event loop, async I/O bằng Fiber (`async/await/all/delay/timeout`, socket, HTTP client), HTTP/1.1 server không chặn, supervisor nhiều worker (tái tạo theo số request/RAM, reload SIGHUP lần lượt từng worker, dừng êm), `StateGuard` (reset hook, dọn global/ini/output buffer, phát hiện static/RAM tăng dần, chế độ `--strict-state`, `--superglobals` cho code cũ). 16 test.
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
