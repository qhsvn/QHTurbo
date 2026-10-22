# Plugin cho control panel

[English](README.md) · **Tiếng Việt**

Plugin là giao diện cho các script của QHturbo: mọi thao tác trong plugin đều chạy đúng các công cụ dòng lệnh đã được kiểm thử (`scripts/aapanel.sh`, `scripts/sync-ext.sh`, `scripts/install-ext.sh`).

| Panel | Trạng thái |
|---|---|
| **aaPanel** (nginx hoặc Apache) | Dùng thử được. Đã test trên aaPanel mô phỏng: backend của plugin chuyển site sang QHturbo và quay lại, chặn dữ liệu nhập sai, chạy tác vụ dài ở chế độ nền. Chưa test bên trong một aaPanel thật. |
| DirectAdmin | Đang lên kế hoạch: cần một pool PHP-FPM cho mỗi tài khoản hosting và cách cấu hình theo domain riêng của DirectAdmin. |
| cPanel / WHM | Đang lên kế hoạch: plugin WHM với pool riêng cho mỗi tài khoản, chạy song song với PHP của EasyApache. |

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

Sửa các file này xong thì chạy `plugins/build.sh` và commit file `qhturbo-aapanel.zip` mới (CI kiểm tra file zip khớp với mã nguồn). Test: `tests/aapanel-sim-apache.sh` chạy backend trên aaPanel mô phỏng.
