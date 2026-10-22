# PHASE 05 — Web runtime / aaPanel (`QHT-P05`)

**Trạng thái:** `TODO`.

## Việc cần làm

- [ ] Thêm biến thể build FPM: `--enable-fpm --with-fpm-user=www --with-fpm-group=www` cùng các extension aaPanel thường dùng (mysqli, pdo_mysql, curl, openssl, zip, gd, intl, redis qua PECL).
- [ ] Đóng gói cài vào `/opt/qhturbo/<nhánh>/<phiên bản>/`, service systemd `qhturbo-fpm-<nhánh>`, socket riêng.
- [ ] Đo RSS của mỗi worker để tính `pm.max_children = (RAM dành cho PHP) / RSS_p95`.
- [ ] Benchmark HTTP bằng `wrk`/`k6`: plaintext, JSON, PDO+MySQL, Redis hit/miss, một trang framework thật (Laravel/WordPress).
- [ ] Theo dõi RPS, p50/p95/p99, CPU, RSS, lỗi HTTP, slow query.
- [ ] Canary/staging, diễn tập rollback (xem `docs/security-and-release.md`).
- [ ] Thanh toán: idempotency, transaction, không cache trạng thái giao dịch.

## Điều kiện hoàn thành

Có bản staging chạy ổn, integration test pass, có báo cáo dung lượng (capacity report) và đã diễn tập rollback.
