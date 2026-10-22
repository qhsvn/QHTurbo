# Bảo mật và phát hành

## 1. Theo dõi bản vá upstream

- Nguồn chính thức: https://www.php.net/ChangeLog-8.php, https://www.php.net/ChangeLog-7.php và GitHub Security Advisories của `php/php-src`.
- Khi upstream ra bản mới của một nhánh (ví dụ `php-8.5.13`):
  1. Lấy commit của tag: `git ls-remote --tags https://github.com/php/php-src.git 'php-8.5.13^{}'`.
  2. Cập nhật `config/versions/8.5.env` (cả `PHP_TAG` và `PHP_COMMIT`) trong **một commit riêng**.
  3. `QHT_PHP=8.5 scripts/bootstrap.sh` (xác minh commit), rồi build lại cả 3 biến thể.
  4. Áp lại `patches/8.5/series`. Nếu có patch không áp được thì sửa patch, không sửa tay trong worktree.
  5. Chạy PHPT (interp/opcache/jit) và benchmark. Ghi kết quả vào `logs/development.md`.
- Nhánh EOL (7.4, 8.0, 8.1) không còn bản vá upstream. Xem cảnh báo trong `docs/compatibility-policy.md`.

## 2. Kiểm tra an toàn bộ nhớ cho bản vá C

Bản vá Zend Engine nào cũng phải qua:

- PHPT đầy đủ ở 3 chế độ: `scripts/run-tests.sh qhturbo interp|opcache|jit`.
- Build debug + ASan/UBSan (`CFLAGS="-O1 -g -fsanitize=address,undefined"`, `--enable-debug`) và chạy PHPT liên quan.
- `USE_ZEND_ALLOC=0 valgrind` cho test nhỏ khi cần.
- Fuzzing (php-src có sẵn `sapi/fuzzer`) cho thay đổi ở parser, unserialize, json.

## 3. Quy trình phát hành

| Giai đoạn | Điều kiện |
|---|---|
| `alpha` | Build được, PHPT không tệ hơn baseline, có số đo |
| `rc` | Thêm: framework/Composer test, ASan sạch, ma trận extension, chạy staging ≥ 7 ngày |
| `stable` | Thêm: canary production, tỉ lệ lỗi/p99 không xấu đi, đã diễn tập rollback |

Mỗi bản phát hành ghi rõ:

- phiên bản QHturbo, nhánh và tag PHP upstream, commit,
- danh sách bản vá và cờ build (lấy từ `BUILD-INFO.txt`),
- sha256 của binary, SBOM (phiên bản gcc, glibc, thư viện liên kết: `ldd`),
- điểm không tương thích (nếu có) và cách quay lại PHP gốc.

**Build tái lập:** cùng `config/versions/*.env`, `config/build.env`, `patches/` và cùng phiên bản gcc thì ra cùng mã máy (ngoại trừ dấu thời gian `built:` trong `php -v`). PGO phụ thuộc thêm vào tập huấn luyện, nên tập này được commit trong repo.

## 4. Triển khai lên aaPanel

1. Không ghi đè `/www/server/php/<ver>`. Cài vào `/opt/qhturbo/<nhánh>/<phiên bản>/`.
2. Service FPM riêng, socket riêng, ví dụ `/tmp/qhturbo-85.sock`, và pool riêng.
3. Backup: cấu hình PHP, Nginx vhost, DB, Redis trước khi chuyển.
4. Kiểm tra trước khi chuyển: `php -v`, `php -m`, `php --ini`, `php-fpm -t`, các endpoint quan trọng.
5. Canary: một vhost hoặc một phần lưu lượng chạy trước, theo dõi 5xx, p99, RSS, CPU, slow query.
6. Rollback: đổi `fastcgi_pass` về socket PHP gốc, `nginx -t && nginx -s reload`. Phải diễn tập bước này trước khi chạy production.
7. Thanh toán và giao dịch: DB là nguồn dữ liệu đúng, không cache trạng thái giao dịch, giữ idempotency.
