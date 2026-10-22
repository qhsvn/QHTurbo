# Cài thử QHturbo trên aaPanel

[English](aapanel.md) · **Tiếng Việt**

Hướng dẫn chạy thử **QHturbo Compatible** trên máy chủ aaPanel, **từng site một** và luôn có đường lui. PHP do aaPanel cài (`/www/server/php/...`) **không bị động tới**.

**Tóm tắt (5 lệnh):**

```bash
git clone https://github.com/qhsvn/QHTurbo.git /opt/qhturbo-src && cd /opt/qhturbo-src
sudo scripts/aapanel.sh check   --php=8.5                 # kiểm tra máy chủ, không thay đổi gì
sudo scripts/aapanel.sh install --php=8.5                 # build + cài + chạy PHP-FPM riêng (10-40 phút)
sudo scripts/aapanel.sh enable  test.example.com --php=8.5   # chuyển 1 site; lỗi thì tự quay lại
sudo scripts/aapanel.sh disable test.example.com          # quay lại PHP của aaPanel
```

---

## 0. Chuẩn bị

| Yêu cầu | Ghi chú |
|---|---|
| aaPanel dùng **Nginx hoặc Apache** | Tự nhận biết (`--web=nginx|apache` để chỉ định). Chưa hỗ trợ OpenLiteSpeed: script dừng trước khi build |
| Ubuntu 20.04 / 22.04 / 24.04, Debian 11 / 12, AlmaLinux / Rocky | Đã test trên Ubuntu 24.04; với họ RHEL, nhánh `dnf` đã build được PHP 8.5 trên server của người dùng, phần còn lại chưa test |
| Quyền root qua SSH | |
| RAM ≥ 2 GB, hoặc thêm swap | Biên dịch PHP (intl, Zend VM) tốn RAM |
| ≥ 4 GB ổ cứng trống | Mã nguồn và bản build nằm trong `/opt/qhturbo-lab` |

**Nên làm trước:**

1. **Snapshot/backup VPS** (tại nhà cung cấp VPS hoặc bằng aaPanel Backup).
2. Thử trên **site phụ** trước: tạo một site mới trong aaPanel (ví dụ `test.example.com`), hoặc nhân bản site thật sang subdomain.
3. VPS ít RAM thì thêm swap 2 GB:
   ```bash
   sudo fallocate -l 2G /swapfile && sudo chmod 600 /swapfile && sudo mkswap /swapfile && sudo swapon /swapfile
   ```

**Chọn phiên bản:** dùng **cùng phiên bản** với site đang chạy trong aaPanel (site dùng PHP 8.2 thì `--php=8.2`) để so sánh công bằng và ít rủi ro nhất. Các phiên bản hỗ trợ: 7.4, 8.0, 8.1, 8.2, 8.3, 8.4, 8.5. Dự án đã kiểm thử đầy đủ bản `full` cho **7.4 và 8.5**; các phiên bản khác dùng cùng script nhưng chưa chạy thử.

## 1. Tải QHturbo và kiểm tra máy chủ

```bash
apt install -y git          # aaPanel thường đã có
git clone https://github.com/qhsvn/QHTurbo.git /opt/qhturbo-src
cd /opt/qhturbo-src
sudo scripts/aapanel.sh check --php=8.5
```

`check` **không thay đổi gì**. Lệnh này in ra:
- aaPanel và nginx có được tìm thấy không, cùng RAM và ổ cứng (có cảnh báo nếu không đủ để build).
- Các phiên bản PHP của aaPanel và **site nào đang dùng PHP nào** (dùng tên này cho bước `enable`).
- Sau khi cài: **extension nào có trong PHP aaPanel mà QHturbo chưa có** (redis, imagick…).

## 2. Cài đặt (10–40 phút)

Chạy trong `screen` để mất kết nối SSH cũng không dừng build:

```bash
screen -S qhturbo
sudo scripts/aapanel.sh install --php=8.5
# Ctrl+A rồi D để thoát; quay lại bằng: screen -r qhturbo
```

Script sẽ:
1. Cài các gói cần để build (apt/dnf).
2. Tải đúng tag PHP chính thức và kiểm tra commit. Với 7.4/8.0 thì build kèm OpenSSL 1.1.1 riêng.
3. Build PHP + PHP-FPM với các extension thường dùng, rồi cài vào `/opt/qhturbo/8.5`.
4. **Chép thiết lập từ php.ini của aaPanel** sang (`disable_functions`, `memory_limit`, `upload_max_filesize`, `post_max_size`, `max_execution_time`, `date.timezone`…), để site có cùng giới hạn và cùng mức bảo mật.
5. Chạy PHP-FPM riêng: service `qhturbo-php85-fpm`, socket `/run/qhturbo/php85-fpm.sock`, user `www` (giống aaPanel).
6. Tạo file `/www/server/nginx/conf/enable-php-qhturbo85.conf`, chép từ `enable-php-85.conf` của aaPanel và chỉ đổi socket.
7. Chạy `qht-doctor` để kiểm tra cấu hình.

Sau bước này **chưa có site nào đổi gì**.

## 3. Bổ sung extension còn thiếu

```bash
sudo scripts/aapanel.sh check --php=8.5        # xem dòng "extensions in aaPanel PHP but not in QHturbo"
sudo scripts/install-ext.sh redis --php=8.5
sudo scripts/install-ext.sh imagick --php=8.5  # cần trước: apt install -y libmagickwand-dev
```

`install-ext.sh` tải từ PECL; nếu PECL không truy cập được thì tải từ GitHub (bản ổn định mới nhất), rồi build, bật và reload FPM. Đã thử: redis 6.3.0 trên 8.5, apcu 5.1.28 trên 7.4.

## 4. Chuyển một site sang QHturbo

```bash
sudo scripts/aapanel.sh enable test.example.com --php=8.5
```

Script làm như sau:
1. Đo mã HTTP của site **trước** khi chuyển.
2. Backup vhost (`/www/server/panel/vhost/nginx/test.example.com.conf.qhturbo-bak`, hoặc `vhost/apache/` nếu dùng Apache).
3. Chỉ đổi phần xử lý PHP:
   - nginx: `include enable-php-84.conf;` thành `include enable-php-qhturbo85.conf;`
   - Apache: `SetHandler "proxy:unix:/tmp/php-cgi-84.sock|fcgi://localhost"` thành `SetHandler "proxy:unix:/run/qhturbo/php85-fpm.sock|fcgi://localhost"` (trong mọi `<VirtualHost>` của file, cả HTTP và HTTPS)
4. `nginx -t` / `httpd -t`: lỗi thì khôi phục ngay.
5. Reload web server, đo lại mã HTTP. **Nếu site trả 5xx (mà trước đó không bị) thì tự quay về PHP aaPanel.**

Site có HTTPS hoặc không trả lời ở `http://127.0.0.1/` thì chỉ định URL để kiểm tra: `--url=https://test.example.com/`.

**Kiểm tra site đang chạy PHP nào:** tạo file tạm rồi xoá ngay sau khi xem:

```bash
echo '<?php echo PHP_VERSION, " ", PHP_SAPI, " ", php_ini_loaded_file();' > /www/wwwroot/test.example.com/qht-check.php
curl -s https://test.example.com/qht-check.php      # 8.5.12 fpm-fcgi /opt/qhturbo/8.5/etc/php.ini
rm /www/wwwroot/test.example.com/qht-check.php
```

Rồi tự kiểm tra các chức năng chính: đăng nhập, trang quản trị, upload, gửi form, thanh toán (môi trường test), cron.

**Theo dõi:** `tail -f /www/wwwlogs/test.example.com.error.log /var/log/qhturbo/php85-fpm.log /var/log/qhturbo/php85-slow.log`

Tình trạng chung: `sudo scripts/aapanel.sh status`

## 5. So sánh tốc độ

Đo **cùng một trang**, trước và sau khi chuyển:

```bash
# Thời gian tới byte đầu tiên (TTFB), 20 lần
for i in $(seq 20); do curl -s -o /dev/null -w '%{time_starttransfer}\n' https://test.example.com/; done | sort -n | awk '{a[NR]=$1} END {print "median", a[int(NR/2)+1], "s"}'
# Tải đồng thời (apt install apache2-utils)
ab -n 500 -c 20 https://test.example.com/
```

**Kỳ vọng thực tế:** chế độ Compatible là PHP-FPM chuẩn với cấu hình OPcache tốt.
- PHP aaPanel của bạn **chưa bật OPcache** hoặc OPcache quá nhỏ: thường nhanh hơn rõ rệt.
- PHP aaPanel đã có OPcache cấu hình tốt: tốc độ **gần như tương đương**.
- Nâng phiên bản (7.4 → 8.x): nhanh hơn ở mức vừa phải, chủ yếu do code PHP chạy nhanh hơn.
- Mức nhanh hơn 7–8× chỉ đạt được ở chế độ **Performance** (mục 8), vốn không dành cho WordPress.

Kết quả đo trên site thật rất quý, vì đó là phép đo mà dự án còn thiếu (ISSUE-003). Hãy gửi lại kết quả để cập nhật `logs/benchmarks.md`.

## 6. Quay lại

```bash
sudo scripts/aapanel.sh disable test.example.com     # 1 site về PHP aaPanel (ngay lập tức)
sudo scripts/aapanel.sh uninstall --php=8.5          # mọi site về PHP aaPanel, dừng và gỡ service
sudo rm -rf /opt/qhturbo/8.5 /opt/qhturbo-lab        # (tuỳ chọn) xoá hẳn file
```

Nếu script không chạy được, cứu bằng tay: `cp /www/server/panel/vhost/nginx/<site>.conf.qhturbo-bak /www/server/panel/vhost/nginx/<site>.conf && /www/server/nginx/sbin/nginx -s reload` (Apache: `vhost/apache/` và `/www/server/apache/bin/httpd -k graceful`).

## 7. Lưu ý khi dùng cùng aaPanel

| Tình huống | Điều xảy ra / cách xử lý |
|---|---|
| Đổi "PHP version" của site trong giao diện aaPanel | aaPanel ghi lại dòng `include`, nên site **quay về PHP aaPanel** (hỏng theo hướng an toàn). Muốn dùng lại thì chạy `enable` lần nữa |
| Sửa php.ini / giới hạn upload trong giao diện aaPanel | Không áp dụng cho QHturbo. Chạy `sudo scripts/aapanel.sh sync-ini --php=8.5`, hoặc sửa `/opt/qhturbo/8.5/etc/conf.d/` |
| Cài extension trong giao diện aaPanel | Chỉ vào PHP aaPanel. Với QHturbo dùng `scripts/install-ext.sh` |
| `open_basedir` (file `.user.ini` của site) | Vẫn có hiệu lực, như trên PHP aaPanel |
| Session / đăng nhập | Cùng thư mục `/tmp` nên người dùng **không bị đăng xuất** khi chuyển (đã thấy trong test) |
| Cron của aaPanel gọi `/www/server/php/85/bin/php` | Vẫn dùng PHP aaPanel. Muốn đổi thì sửa thành `/opt/qhturbo/8.5/bin/php` |
| `disable_functions` | Được chép từ aaPanel. Chế độ Performance tự bỏ riêng các hàm `pcntl_*` (worker cần) |
| Số tiến trình FPM | Mặc định 5 × số CPU. Tính theo RAM thật: `python3 /opt/qhturbo/8.5/share/qhturbo/fpm-tune.py` |
| PHP 7.4 / 8.0 | Đã hết hạn hỗ trợ bảo mật, OpenSSL 1.1.1 cũng vậy. Chỉ dùng tạm trong lúc nâng cấp |

## 8. (Nâng cao) Chế độ Performance trên aaPanel

Chỉ dành cho ứng dụng viết theo [quy tắc trạng thái](modes/performance.vi.md) (không dành cho WordPress):

1. `sudo cp /opt/qhturbo/8.5/share/qhturbo/worker.env.example /etc/qhturbo/worker-myapp.env`, rồi sửa `QHT_APP`, `QHT_PORT`.
2. `sudo cp /opt/qhturbo/8.5/share/qhturbo/systemd/qhturbo-php85-worker@.service /etc/systemd/system/ && sudo systemctl daemon-reload && sudo systemctl enable --now qhturbo-php85-worker@myapp`
3. Trong aaPanel: **Website → site → Reverse proxy** trỏ tới `http://127.0.0.1:8080`.
4. Deploy code mới: `sudo systemctl reload qhturbo-php85-worker@myapp` (thay worker lần lượt, không rơi request).

## Đã kiểm thử những gì

`tests/aapanel-sim.sh` dựng một **aaPanel mô phỏng**: cấu trúc `/www/server/...`, user `www`, `enable-php-XX.conf` và vhost theo kiểu aaPanel, nginx thật, một PHP-FPM đóng vai PHP của aaPanel. Kết quả **12/12**: check, sync-ini, enable (site chạy QHturbo với đúng `upload_max_filesize` và `disable_functions` của aaPanel), status, disable (vhost khôi phục giống hệt bản gốc), và tự rollback khi QHturbo trả 502.

**Chưa thử trên aaPanel thật.** Nếu gặp lỗi, hãy gửi lại kết quả của `sudo scripts/aapanel.sh check` và đoạn log lỗi.
