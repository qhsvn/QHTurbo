# PHASE 04 — Trình biên dịch / OPcache / JIT (`QHT-P04`)

[English](phase-04-compiler-jit.md) · **Tiếng Việt**

**Trạng thái:** `IN_PROGRESS`. PGO và số đo JIT on/off đã có; tối ưu pass của compiler PHP chưa bắt đầu.

## Đã làm

- Biến thể `qhturbo-pgo`: PGO (gcc `-fprofile-generate`/`-fprofile-use`). Tập huấn luyện tách khỏi tập đo.
- LTO đã thử và **loại bỏ**: GCC báo lỗi `global register variable follows a function definition` (ISSUE-001).
- `bench.py` đo cả `interp` (OPcache, không JIT) và `jit` (tracing JIT, buffer 128M) cho mọi nhánh ≥ 8.0.
- Profile cấu hình: `config/ini/opcache-production.ini`, `config/ini/jit.ini`.

Số đo xem trong `results/` và `logs/benchmarks.md`.

## Việc còn lại

- [ ] Thử lại LTO theo cách khác: build bằng clang, hoặc biên dịch các file dùng global register (`zend_execute.c`, `ext/opcache/jit/zend_jit_vm_helpers.c`) với `-fno-lto`.
- [ ] Đo JIT `function` và `tracing` với các mức `opcache.jit=1254/1255`, so với interpreter.
- [ ] Chạy PHPT với JIT trên binary PGO (`scripts/run-tests.sh qhturbo-pgo jit`).
- [ ] Đánh giá pass tối ưu trong `Zend/Optimizer/` (DFA, SCCP, inference) với code framework thực tế.
- [ ] Kiểm tra deoptimization, exception, tràn số nguyên, kiểu động, debug info khi bật JIT.
- [ ] Chính sách: JIT **tắt mặc định** trong profile web, chỉ bật khi benchmark của chính ứng dụng cho thấy có lợi.

## Điều kiện hoàn thành

Có số liệu cho từng chế độ, không crash, không sai ngữ nghĩa, có chính sách bật/tắt rõ ràng.
