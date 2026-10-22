# PHASE 02 — Tối ưu Zend Engine (`QHT-P02`)

**Trạng thái:** `TODO`. Chưa có bản vá mã nguồn nào được chấp nhận.

## Mục tiêu

Giảm chi phí thực thi opcode và gọi hàm trong Zend VM bằng **những bản vá nhỏ, có số đo**. Không đổi ngữ nghĩa, không đổi ABI.

## Quy trình cho mỗi giả thuyết (`QHT-ENG-xxx`)

1. **Profile trước** trên máy Linux có `perf`:
   ```bash
   perf record -g build/8.5/qhturbo/bin/php -n -d opcache.enable_cli=1 benchmarks/workloads/objects/main.php 3000000
   perf report --no-children | head -50
   ```
   Ghi lại hàm hoặc handler opcode nóng nhất vào `logs/development.md`.
2. **Viết giả thuyết** cụ thể, ví dụ: "handler `ZEND_INIT_METHOD_CALL` tốn X% vì ...; đổi Y sẽ giảm Z".
3. Sửa trong `worktrees/8.5/qhturbo/`. Nếu đổi `Zend/zend_vm_def.h` thì chạy `php Zend/zend_vm_gen.php` để sinh lại `zend_vm_execute.h`.
4. `scripts/build.sh qhturbo`, sau đó:
   - `scripts/run-tests.sh qhturbo opcache` và `... jit` (số fail không được tăng so với baseline),
   - `python3 scripts/bench.py php --variants baseline,qhturbo --runs 15`.
5. Có lợi rõ ràng (CI 95% không chứa 1.0) và không làm chậm workload khác quá mức nhiễu A/A thì:
   `scripts/make-patch.sh qhturbo QHT-ENG-001-<tên>` rồi commit patch + báo cáo.
6. Không có lợi thì ghi `NO-GO` vào `logs/development.md` kèm số đo, rồi reset worktree.

## Ứng viên đã xác định (chưa thử)

| ID | Ý tưởng | Rủi ro |
|---|---|---|
| QHT-ENG-001 | Kiểm tra lại chế độ VM (`--with-zend-vm=HYBRID` mặc định) và global register variables trên gcc mới | Thấp |
| QHT-ENG-002 | Thử `-march=x86-64-v2/v3` cho bản phát hành theo đời CPU (máy chủ aaPanel hiện đại) | Trung bình: không chạy được trên CPU cũ |
| QHT-ENG-003 | Đo chi phí `zend_call_function` cho callback (`array_map`, `usort`) và fast path cho closure không capture | Trung bình |

## Điều kiện hoàn thành

Có ít nhất một bản vá đã review, PHPT không xấu đi, số đo lặp lại được và đã phân tích tác dụng phụ. Nếu không có bản vá nào có lợi thì ghi `NO-GO` kèm bằng chứng. Không báo thành công giả.
