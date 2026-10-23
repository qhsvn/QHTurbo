# Plugin cho control panel

[English](README.md) · **Tiếng Việt**

Plugin là giao diện cho các script của QHturbo: mọi thao tác trong plugin đều chạy đúng các công cụ dòng lệnh đã được kiểm thử (`scripts/aapanel.sh`, `scripts/directadmin.sh`, `scripts/sync-ext.sh`, `scripts/install-ext.sh`).

| Panel | Trạng thái |
|---|---|
| **aaPanel** (nginx hoặc Apache) | Dùng thử được. Đã test trên aaPanel mô phỏng: backend của plugin chuyển site sang QHturbo và quay lại, chặn dữ liệu nhập sai, chạy tác vụ dài ở chế độ nền. Server thật đầu tiên (aaPanel dùng Apache, họ RHEL, 2026-10-23): plugin cài được và build được PHP QHturbo 8.5; đang thử chuyển site từ plugin. |
| **DirectAdmin** (Apache hoặc nginx_apache, PHP-FPM) | Dùng thử được. Đã test trên DirectAdmin mô phỏng với Apache thật (32 kiểm tra: pool riêng cho từng user, giữ nguyên cấu hình httpd tuỳ chỉnh, rollback, trang plugin chạy bằng PHP 7.4, tác tử root từ chối yêu cầu không phải của admin hoặc bị làm giả). Chưa test bên trong một DirectAdmin thật. Chưa hỗ trợ nginx thuần và (Open)LiteSpeed. |
| cPanel / WHM | Đang lên kế hoạch: plugin WHM với pool riêng cho mỗi tài khoản, chạy song song với PHP của EasyApache. |

## Plugin DirectAdmin

Làm được gì ở cấp admin của DirectAdmin:
- **Phiên bản PHP:** build và cài PHP QHturbo 7.4–8.5 song song với PHP DirectAdmin, chép extension từ PHP DirectAdmin cùng phiên bản, kiểm tra, build lại, gỡ.
- **Domain:** từng domain kèm user và PHP đang chạy; chuyển sang một phiên bản QHturbo, hoặc quay lại. User của domain có một pool PHP-FPM trong QHturbo **chạy bằng chính user đó**, chép từ pool của DirectAdmin cho user (giữ nguyên `open_basedir`, giới hạn, cấu hình mail). Việc chuyển là một khối có đánh dấu trong *Custom HTTPD Configuration* của domain; những gì bạn tự viết ở đó được giữ nguyên. Domain báo lỗi sau khi chuyển sẽ được tự chuyển về.
- **Tác vụ:** build và chuyển domain chạy nền; trang tự cập nhật tiến trình.

Cách hoạt động: DirectAdmin chạy trang plugin bằng user Linux của admin đang đăng nhập, không bao giờ bằng root. Vì vậy trang chỉ ghi một file yêu cầu vào `/var/spool/qhturbo`; tác tử QHturbo (`scripts/panel-agent.sh`, do một systemd path unit khởi động bằng root) chỉ chạy yêu cầu do admin DirectAdmin (có trong `admin.list`) tạo, kiểm tra từng giá trị, rồi chạy `scripts/directadmin.sh`. Nhật ký và danh sách được công bố ở `/var/log/qhturbo/jobs` và `/var/lib/qhturbo`.

### Cài đặt

Snapshot máy chủ trước.

1. Trong DirectAdmin (cấp admin) mở **Plugin Manager**, thêm plugin từ URL `https://github.com/qhsvn/QHTurbo/raw/main/plugins/directadmin/qhturbo.tar.gz` (hoặc tải file lên). Giữ nguyên tên file `qhturbo.tar.gz`: DirectAdmin đặt tên thư mục plugin theo tên file.
2. Script cài đặt của plugin tải các script QHturbo về `/opt/qhturbo-src` và cài tác tử nếu DirectAdmin chạy nó bằng root. Nếu trang plugin báo chưa cài tác tử, chạy một lần bằng root:
   ```bash
   git clone https://github.com/qhsvn/QHTurbo.git /opt/qhturbo-src
   /opt/qhturbo-src/scripts/panel-agent.sh setup directadmin
   ```
3. Mở **QHturbo PHP** trong menu admin, cài phiên bản PHP mà các domain đang dùng, rồi chuyển thử một domain phụ.

Làm tương tự không cần plugin, qua SSH:

```bash
cd /opt/qhturbo-src
sudo scripts/directadmin.sh check   --php=7.4
sudo scripts/directadmin.sh install --php=7.4          # 10-40 phút
sudo scripts/directadmin.sh enable  example.com --php=7.4
sudo scripts/directadmin.sh disable example.com
```

### Gỡ cài đặt

Chuyển các domain về trước (hoặc `scripts/directadmin.sh uninstall --php=<phiên bản>`), rồi xoá plugin trong Plugin Manager; script gỡ của plugin sẽ gỡ tác tử.

## Plugin aaPanel

Làm được gì ngay trong giao diện aaPanel:
- **Phiên bản PHP:** build và cài PHP QHturbo 7.4–8.5 song song với PHP aaPanel, chép extension từ PHP aaPanel cùng phiên bản, kiểm tra, build lại, gỡ.
- **Site:** từng site kèm PHP đang chạy; chuyển sang một phiên bản QHturbo, hoặc về PHP aaPanel. Site báo lỗi sau khi chuyển sẽ được tự chuyển về, kèm lý do.
- **Nhật ký tác vụ:** build và chuyển site chạy nền; nhật ký cập nhật trực tiếp.

### Cài đặt

Cách nào cũng nên snapshot máy chủ trước.

**Từ giao diện panel:** tải [`qhturbo-aapanel.zip`](https://github.com/qhsvn/QHTurbo/raw/main/plugins/aapanel/qhturbo-aapanel.zip), rồi trong aaPanel mở **App Store**, dùng nút **Import** (plugin bên thứ ba) và tải file zip lên. aaPanel chạy `install.sh` của plugin, script này tải các script QHturbo về `/opt/qhturbo-src`.

**Từ dòng lệnh** (kết quả như nhau):

```bash
git clone https://github.com/qhsvn/QHTurbo.git /opt/qhturbo-src 2>/dev/null || git -C /opt/qhturbo-src pull
cp -r /opt/qhturbo-src/plugins/aapanel/qhturbo /www/server/panel/plugin/
cd /www/server/panel/plugin/qhturbo && bash install.sh install
cp icon.png /www/server/panel/BTPanel/static/img/soft_ico/ico-qhturbo.png
```

Sau đó tìm **QHturbo PHP** trong App Store → Installed.

### Gỡ cài đặt

Chuyển mọi site về trước (thẻ Site), rồi gỡ plugin trong App Store. Gỡ plugin không đụng tới PHP QHturbo và các site đang dùng nó; lệnh `/opt/qhturbo-src/scripts/aapanel.sh uninstall --php=<phiên bản>` gỡ một bản PHP QHturbo.

### Các file

| File | Vai trò |
|---|---|
| `info.json` | Tên, tiêu đề, phiên bản hiển thị trong aaPanel |
| `install.sh` | aaPanel chạy với tham số `install` / `uninstall` |
| `qhturbo_main.py` | Backend; trang web gọi các hàm qua `/plugin?action=a&name=qhturbo&s=<hàm>` |
| `index.html` | Giao diện (tiếng Anh và tiếng Việt) |
| `icon.png` | Biểu tượng trong App Store |

Sửa file plugin xong thì chạy `plugins/build.sh` và commit `qhturbo-aapanel.zip` / `qhturbo.tar.gz` mới (CI kiểm tra gói khớp với mã nguồn). Test: `tests/aapanel-sim-apache.sh` (backend aaPanel) và `tests/directadmin-sim.sh` (script DirectAdmin, trang plugin và tác tử).
