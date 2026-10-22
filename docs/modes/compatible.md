# QHturbo Compatible

**Mục tiêu:** chạy PHP chuẩn, PHP-FPM, Composer và ứng dụng cũ **không phải sửa code**. Mọi thứ hoạt động như một bản PHP-FPM thông thường, chỉ có cấu hình tốt hơn và đi kèm công cụ kiểm tra.

| | |
|---|---|
| Phiên bản PHP | 7.4, 8.0, 8.1, 8.2, 8.3, 8.4, 8.5 |
| Mô hình chạy | nginx/Apache → PHP-FPM, mỗi request khởi động lại ứng dụng (như PHP truyền thống) |
| Yêu cầu với ứng dụng | Không có |
| Khi nào dùng | Mặc định cho mọi site: WordPress, Laravel, code cũ, site trên aaPanel |

## Đã kiểm chứng (22/10/2026, build `full`)

| Kiểm tra | PHP 8.5.12 | PHP 7.4.33 |
|---|---|---|
| Build `full` (FPM + 51 module: openssl, curl, intl, gd, mysqli, pdo_mysql, zip, sodium, soap...) | OK | OK (OpenSSL 1.1.1w riêng) |
| `scripts/install.sh` vào `/opt/qhturbo/<nhánh>`, `php-fpm -t` | OK | OK |
| nginx → FPM qua socket riêng, chạy song song hai phiên bản | `php85-fpm.sock` | `php74-fpm.sock` |
| **Cùng một ứng dụng kiểu cũ**: `session_start`, `$_GET`, `header()`, `setcookie`, `$_FILES`, PDO SQLite, `include` | OK | OK, kết quả giống hệt |
| intl (`1.234.567 ₫`), gd, openssl, sodium, mbstring | OK | OK |
| **Composer 2.8** chạy trên PHP QHturbo | `create-project laravel/laravel` (Laravel 13.38, hơn 100 package) | `require monolog guzzle`: tự chọn phiên bản tương thích 7.4 |
| HTTPS có xác thực chứng chỉ | OK | OK (Guzzle → packagist, 200) |
| Laravel qua nginx → FPM / `php artisan test` | HTTP 200 / 2/2 pass | — (Laravel 13 cần PHP 8.3+) |
| Bộ PHPT chính thức (bản minimal) | 13.568 pass / 0 fail | 8.997 pass, 46 fail do môi trường |

## Có gì khác so với PHP-FPM gốc

Chỉ cấu hình, không đổi ngữ nghĩa PHP (`config/modes/compatible/`):

- **OPcache bật sẵn** với kích thước hợp lý cho Composer/framework: 256M bộ nhớ, 50.000 file, 32M interned strings.
- **Vẫn giữ `validate_timestamps=1`** (kiểm tra mỗi 2 giây): sửa file qua FTP, trình quản lý file của aaPanel hay WordPress tự cập nhật đều có hiệu lực như cũ. Đây là lựa chọn "tương thích trước, tốc độ sau".
- **JIT tắt** (chỉ bật khi đã đo bằng ứng dụng thật).
- `realpath_cache` 4M/600s giúp giảm `stat()` cho autoload của Composer.
- Pool FPM: `pm.max_requests=1000` (tái tạo tiến trình con định kỳ để chặn rò rỉ của code/extension cũ), có slowlog, trang status/ping.
- **Cài song song, không ghi đè:** prefix `/opt/qhturbo/<nhánh>`, socket và service riêng. `install.sh` từ chối cài vào `/usr`, `/etc`, `/www/server/php`.

## Cài đặt

```bash
export QHT_LAB=~/QHTurbo-lab QHT_PHP=8.5      # hoặc 7.4 ... 8.4
sudo apt install -y build-essential autoconf bison re2c pkg-config \
  libxml2-dev libssl-dev libsqlite3-dev zlib1g-dev libcurl4-openssl-dev libzip-dev \
  libonig-dev libicu-dev libsodium-dev libpng-dev libjpeg-dev libfreetype-dev libgmp-dev libreadline-dev
scripts/bootstrap.sh
[ "$QHT_PHP" = 7.4 ] || [ "$QHT_PHP" = 8.0 ] && scripts/deps-openssl11.sh   # chỉ 7.4/8.0
QHT_PROFILE=full scripts/build.sh baseline
sudo -E scripts/install.sh                     # -> /opt/qhturbo/8.5
sudo cp /opt/qhturbo/8.5/share/qhturbo/systemd/qhturbo-php85-fpm.service /etc/systemd/system/
sudo systemctl daemon-reload && sudo systemctl enable --now qhturbo-php85-fpm
/opt/qhturbo/8.5/bin/php /opt/qhturbo/8.5/share/qhturbo/qht-doctor.php
```

Đóng gói để mang sang máy chủ khác: `scripts/package.sh` (cùng bản distro với máy build, vì binary liên kết thư viện hệ thống như libcurl, ICU).

### Chuyển một site aaPanel sang QHturbo (có đường lui)

1. Backup vhost: `cp /www/server/panel/vhost/nginx/site.conf{,.bak}`.
2. Trong vhost, thay `include enable-php-85.conf;` bằng:
   ```nginx
   location ~ [^/]\.php(/|$) {
       try_files $uri =404;
       fastcgi_pass unix:/run/qhturbo/php85-fpm.sock;
       fastcgi_index index.php;
       include fastcgi.conf;
   }
   ```
   aaPanel chạy nginx bằng user `www`, nên khi cài dùng `--user=www --group=www`.
3. `nginx -t && nginx -s reload`, rồi theo dõi log và tỉ lệ 5xx.
4. **Rollback:** khôi phục file `.bak`, rồi `nginx -s reload`. PHP gốc của aaPanel vẫn chạy nguyên vẹn.

## PHP 7.4 / 8.0 và OpenSSL

PHP < 8.1 không biên dịch được với OpenSSL 3 (Ubuntu 22.04+). `scripts/deps-openssl11.sh` build OpenSSL 1.1.1w vào `$QHT_LAB/deps/openssl-1.1`, và PHP liên kết tới đó qua rpath. OpenSSL hệ thống không bị đụng tới. **OpenSSL 1.1.1 cũng đã hết hạn hỗ trợ**: chỉ dùng cho ứng dụng cũ trong lúc chuẩn bị nâng cấp.

## Tinh chỉnh

- `scripts/fpm-tune.py`: tính `pm.max_children` từ RAM và RSS thực tế của worker FPM.
- `qht-doctor.php`: kiểm tra OPcache, Xdebug, realpath cache, extension, phiên bản EOL.
- Khi deploy theo kiểu nguyên khối (symlink + reload FPM): có thể đặt `opcache.validate_timestamps=0` để nhanh thêm vài %. Phép đo cho thấy mức lợi này nhỏ (`results/web/`).
