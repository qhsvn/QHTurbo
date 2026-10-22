# Chính sách tương thích

## 1. Các phiên bản PHP được hỗ trợ

QHturbo hỗ trợ **PHP 7.4 trở lên**. Mỗi nhánh PHP được build từ đúng tag chính thức của nhánh đó, không trộn mã giữa các nhánh. Vì vậy ứng dụng đang chạy PHP 7.4 vẫn chạy trên QHturbo 7.4 với cùng hành vi.

| Nhánh | Tag upstream đang ghim | Hỗ trợ upstream (10/2026) | JIT | OPcache | Ghi chú |
|---|---|---|---|---|---|
| 7.4 | `php-7.4.33` | **Hết hạn (EOL 11/2022)** | Không | shared (`opcache.so`) | Chỉ cho ứng dụng cũ, cần kế hoạch nâng cấp |
| 8.0 | `php-8.0.30` | **Hết hạn (EOL 11/2023)** | Có | shared | Như trên |
| 8.1 | `php-8.1.34` | **Hết hạn (EOL 12/2025)** | Có | shared | Như trên |
| 8.2 | `php-8.2.35` | Chỉ vá bảo mật (đến 12/2026) | Có | shared | |
| 8.3 | `php-8.3.36` | Chỉ vá bảo mật (đến 12/2027) | Có | shared | |
| 8.4 | `php-8.4.27` | Đang hỗ trợ đầy đủ | Có | shared | |
| 8.5 | `php-8.5.12` | Đang hỗ trợ đầy đủ | Có | tĩnh (luôn có sẵn) | Nhánh chính để phát triển tối ưu |

Tag và commit được ghim trong `config/versions/<nhánh>.env`. `scripts/bootstrap.sh` kiểm tra tag phải trỏ đúng commit đã ghim, sai thì dừng.

### Cảnh báo về các nhánh EOL (7.4, 8.0, 8.1)

Nhóm PHP chính thức **không còn phát hành bản vá bảo mật** cho các nhánh này. QHturbo build được chúng để:

- tăng tốc ứng dụng cũ trong lúc chuẩn bị nâng cấp,
- so sánh hiệu năng giữa các thế hệ PHP trên cùng máy.

QHturbo **không tự vá bảo mật** cho nhánh EOL. Nếu đưa lên máy chủ thật:

- chỉ dùng sau staging, không mở trực tiếp ra Internet khi có lựa chọn khác,
- theo dõi CVE của PHP và các extension đang dùng,
- có lộ trình chuyển lên 8.3+ (xem bảng trên).

Các script in cảnh báo mỗi khi bootstrap hoặc build một nhánh EOL.

## 2. Nguyên tắc tương thích cho bản vá QHturbo

1. **Không đổi cú pháp và ngữ nghĩa PHP.** Mã chạy đúng trên PHP gốc cùng nhánh phải cho cùng kết quả trên QHturbo, kể cả thông báo lỗi, warning và thứ tự phần tử mảng.
2. **Không phá ABI extension** (`ZEND_MODULE_API_NO`, cấu trúc `zval`, `zend_object`, `HashTable` công khai). Bản vá nào cần đổi ABI phải ghi rõ trong `patches/<nhánh>/` và CHANGELOG, và không được vào bản `stable` nếu chưa duyệt.
3. **Mỗi bản vá chỉ áp dụng cho nhánh đã kiểm thử.** Một tối ưu cho 8.5 không tự động áp sang 7.4. Muốn port sang nhánh khác thì tạo patch riêng trong `patches/<nhánh>/`, chạy lại PHPT và benchmark của nhánh đó.
4. **Tối ưu ở mức trình biên dịch (PGO) áp dụng cho mọi nhánh**, vì không đổi mã nguồn. Vẫn phải chạy PHPT trên binary đã tối ưu để phát hiện lỗi compiler.
5. **Mặc định an toàn:** JIT và các cấu hình mạnh tay để tắt trong cấu hình mẫu cho đến khi benchmark của chính ứng dụng chứng minh có lợi.

## 3. Cổng kiểm tra tương thích

| Mức | Kiểm tra | Bắt buộc trước |
|---|---|---|
| 1 | `php -l` toàn bộ benchmark trên 7.4 → 8.5 (CI `workloads`) | mỗi commit |
| 2 | Kết quả benchmark giống hệt giữa baseline và QHturbo (`bench.py` đánh dấu `INVALID` nếu khác) | mỗi lần đo |
| 3 | PHPT: số test fail của QHturbo **không lớn hơn** baseline cùng nhánh, cùng chế độ (interp/opcache/jit) | mỗi bản vá |
| 4 | Composer + framework (Laravel/WordPress) chạy test suite của chính chúng | Phase 5 |
| 5 | Ma trận ABI/extension (PECL phổ biến: redis, imagick, swoole...) | release |

Test fail sẵn có ở baseline (do môi trường, mạng, locale...) được ghi lại trong `results/tests/` để so sánh. Không coi đó là lỗi của QHturbo, nhưng QHturbo cũng không được làm tăng số đó.
