# PHASE 05 — Web runtime / aaPanel (`QHT-P05`)

**Trạng thái:** `IN_PROGRESS`. Hai chế độ chạy đã có và đã đo: [Compatible](../modes/compatible.md) (PHP-FPM, 7.4 → 8.5) và [Performance](../modes/performance.md) (worker chạy lâu dài, PHP 8.1+). Kết quả: `results/web/`.

## Việc cần làm

- [x] Thêm biến thể build FPM: `--enable-fpm --with-fpm-user=www --with-fpm-group=www` cùng các extension aaPanel thường dùng (mysqli, pdo_mysql, curl, openssl, zip, gd, intl, redis qua PECL).
- [x] Đóng gói cài vào `/opt/qhturbo/<nhánh>/`, service systemd `qhturbo-php<nhánh>-fpm`, socket riêng (`scripts/install.sh`, `scripts/package.sh`).
- [x] Đo RSS của mỗi worker để tính `pm.max_children = (RAM dành cho PHP) / RSS_p95` (`scripts/fpm-tune.py`).
- [x] Benchmark HTTP bằng `wrk`: plaintext, JSON, I/O backend trên framework giả lập (`benchmarks/web/run.sh`).
- [ ] Benchmark PDO+MySQL, Redis hit/miss, trang Laravel/WordPress thật.
- [ ] Theo dõi RPS, p50/p95/p99, CPU, RSS, lỗi HTTP, slow query.
- [ ] Canary/staging, diễn tập rollback (xem `docs/security-and-release.md`).
- [ ] Thanh toán: idempotency, transaction, không cache trạng thái giao dịch.

## Điều kiện hoàn thành

Có bản staging chạy ổn, integration test pass, có báo cáo dung lượng (capacity report) và đã diễn tập rollback.
