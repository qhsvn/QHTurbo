# Đề xuất: làm PHP chạy tối ưu và tiện lợi hơn cho người dùng

Các đề xuất dựa trên số đo của dự án (`logs/benchmarks.md`, `results/web/`), sắp theo **lợi ích thực tế / công sức**. Cột "Trạng thái" cho biết mục nào QHturbo đã có.

## A. Những điều số đo đã chứng minh

1. **Lợi ích lớn nhất của web PHP là bỏ chi phí khởi động mỗi request**, không phải tăng tốc Zend VM. Worker chạy lâu dài nhanh hơn FPM **7–8×** trên framework giả lập. PGO chỉ được ±3%, còn nâng 7.4 → 8.5 (không JIT) chỉ +5%.
2. **I/O mới là nút thắt của ứng dụng thật.** FPM xử lý I/O bằng cách tăng số tiến trình (64 tiến trình: 939 req/s, 104 MiB), trong khi async I/O đạt 2.389 req/s với 56 MiB.
3. **JIT** giúp code tính toán thuần PHP nhanh hơn 1,5–3,9×, gần như không giúp json/chuỗi/mảng. Chỉ bật khi đã đo bằng ứng dụng thật.
4. PHP đã **nhanh hơn Python ~3×**, ngang hoặc nhanh hơn Node.js/Go ở mảng kết hợp và chuỗi, nhưng **chậm hơn nhiều lần ở tính toán số**. Muốn lấp khoảng cách đó phải đầu tư vào JIT/VM lâu dài (Phase 2–4).

## B. Đề xuất ưu tiên cao (làm trong 1–3 tháng)

| # | Đề xuất | Lợi ích | Trạng thái |
|---|---|---|---|
| B1 | **Hai chế độ Compatible / Performance** với đường lui rõ ràng | Người dùng chọn giữa an toàn và tốc độ cho từng site | ✅ Đã có (`docs/modes/`) |
| B2 | **`qht-doctor`**: kiểm tra cấu hình (OPcache, Xdebug, EOL, extension) và quét mã nguồn tìm rủi ro khi chuyển sang worker | Sửa được lỗi cấu hình phổ biến trong vài phút | ✅ Đã có |
| B3 | **`fpm-tune`**: tính `pm.max_children` từ RAM và RSS thực tế | Tránh swap (nguyên nhân hay gặp nhất khiến site chậm) | ✅ Đã có |
| B4 | **Driver MySQL/Redis async** cho Performance mode (mysqli `MYSQLI_ASYNC` + `mysqli_poll`, giao thức RESP qua socket async) | Cho phép `--concurrency > 1` với ứng dụng có DB, tận dụng hết lợi ích async | ✅ Đã có (`runtime/src/Redis`, `runtime/src/Mysql`): 1,7× so với 64 tiến trình FPM |
| B5 | **Pool kết nối + kiểm tra transaction** giữa các request (rule R7) | Không mở lại kết nối mỗi request, không rò transaction | ✅ Đã có (`runtime/src/Pool`) |
| B6 | **Adapter Laravel / Symfony** cho Performance mode (giống Laravel Octane: khởi động kernel một lần, reset container, auth, session sau mỗi request) | Đưa lợi ích 7× tới các framework phổ biến nhất | ✅ Đã có (`--framework=laravel\|symfony`): Laravel 13 nhanh hơn 6,7–14,6×, Symfony 8.1 nhanh hơn 6,3× |
| B7 | **Plugin aaPanel**: chọn phiên bản QHturbo và chế độ Compatible/Performance cho từng site, rollback một nút bấm | Người không rành dòng lệnh cũng dùng được | ⏳ Đề xuất |
| B8 | **Gói cài sẵn** (`.tar.gz` đã có, tiếp theo là `.deb` và kho apt) cho Ubuntu 22.04/24.04, Debian 12 | Không phải tự build (build mất 5–15 phút) | ◐ Có `scripts/package.sh` |

## C. Tiện lợi cho lập trình viên

| # | Đề xuất | Trạng thái |
|---|---|---|
| C1 | Lệnh `qht` hợp nhất: `qht install 8.5`, `qht use 8.4`, `qht doctor`, `qht serve`, `qht bench` | ⏳ (hiện là các script riêng trong `scripts/`) |
| C2 | Dev server tự reload khi file thay đổi (watch, rồi SIGHUP) | ⏳ |
| C3 | Endpoint `/metrics` (Prometheus): RPS, độ trễ, RAM, độ trễ event loop, số worker bị tái tạo | ⏳ (`Loop::stats()` đã có) |
| C4 | Sinh `preload.php` tự động từ danh sách file nóng của OPcache | ⏳ |
| C5 | Upload `multipart/form-data` → `$_FILES` trong Performance mode | ✅ |
| C6 | Mẫu GitHub Actions cho dự án người dùng: chạy test trên cả hai chế độ, kèm `--strict-state` | ◐ (CI của QHturbo làm mẫu) |

## D. Hiệu năng sâu (Phase 2–4, dài hạn, cần máy đo riêng)

| # | Đề xuất | Ghi chú |
|---|---|---|
| D1 | Profile bằng `perf` trên workload web thật (FPM và worker), chọn hàm nóng để vá Zend Engine (`QHT-ENG-xxx`) | Máy đám mây hiện tại quá nhiễu (±8%) |
| D2 | PGO lần 3: huấn luyện bằng **request web thật**, thử clang | 2 lần PGO trước là NO-GO |
| D3 | Viết parser HTTP bằng C extension cho Performance mode (hiện là PHP thuần) | Đo trước xem parser chiếm bao nhiêu % thời gian |
| D4 | Theo dõi khả năng worker mode của chính PHP upstream và FrankenPHP; tránh tự làm lại những gì upstream đã có | |

## E. Vận hành và bảo mật

1. **Nâng cấp khỏi 7.4/8.0/8.1** (đã EOL). QHturbo Compatible giúp chạy song song bản mới trên cùng máy, chuyển từng site và giữ đường lui.
2. OpenSSL 1.1.1 dùng cho 7.4/8.0 cũng đã EOL: chỉ dùng tạm thời.
3. Production: staging, rồi canary, rồi chuyển toàn bộ. Luôn giữ PHP-FPM gốc để rollback bằng một lần `nginx -s reload`.
4. Performance mode: bật `--strict-state` trên staging; production đặt `--max-requests` và `--memory-limit` để tự phục hồi khi có rò rỉ.

## F. Hướng dẫn chọn nhanh

```
Site WordPress / code cũ / không sửa được code  ->  Compatible (PHP-FPM)
API / ứng dụng viết mới, framework khởi động nặng  ->  Performance, --concurrency=1
Gọi nhiều API/dịch vụ nội bộ trong mỗi request     ->  Performance, --concurrency>1 (I/O async)
Tính toán nặng bằng PHP                          ->  bật JIT (sau khi đo), cân nhắc viết phần đó bằng Go/Rust
Còn chạy PHP 7.4/8.0                             ->  Compatible + lộ trình nâng cấp lên 8.3+
```
