# Trang web QHturbo

[English](README.md) · **Tiếng Việt**

`index.html` là toàn bộ trang web: một trang tự chứa (tiếng Anh và tiếng Việt, giao diện sáng và tối), không cần bước build. Mở bằng trình duyệt để xem thử.

Trang được đăng lên <https://qhsvn.github.io/QHTurbo/> bởi `.github/workflows/pages.yml` mỗi lần push lên `main` có thay đổi trong `website/`. Cài đặt một lần trong repo GitHub: **Settings → Pages → Build and deployment → Source: GitHub Actions**.

Khi đổi một lệnh, tuỳ chọn hay phiên bản trong tài liệu, hãy sửa cùng nội dung đó trong `index.html`. Các bước cài cho từng nền tảng nằm trong khối JSON `finder-data` ở cuối file.
