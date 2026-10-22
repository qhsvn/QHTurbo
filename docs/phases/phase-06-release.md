# PHASE 06 — Tương thích, bảo mật, phát hành (`QHT-P06`)

**Trạng thái:** `TODO`.

## Việc cần làm

- [ ] PHPT đầy đủ ở 3 chế độ cho mọi nhánh phát hành, ghi pass/fail/skip so với baseline.
- [ ] Test Composer + framework (Laravel, Symfony, WordPress) trên từng nhánh 7.4 → 8.5 mà ứng dụng dùng.
- [ ] Ma trận ABI/extension (PECL: redis, imagick, swoole, xdebug) × OS × đời CPU.
- [ ] ASan/UBSan, fuzzing, review bảo mật, đồng bộ bản vá upstream.
- [ ] Build tái lập, SBOM, chuỗi phiên bản (`PHP_EXTRA_VERSION=-qhturbo1`), release notes.
- [ ] Gói Linux cho staging + tài liệu build từ nguồn, nâng cấp và rollback.

## Điều kiện hoàn thành

Bản RC đạt các cổng tương thích, bảo mật, benchmark, và đã kiểm tra quay lại PHP chính thức. Chưa đạt thì không phát hành `stable`.
