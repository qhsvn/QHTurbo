# PHASE 03 — Bộ nhớ và cấu trúc dữ liệu (`QHT-P03`)

**Trạng thái:** `TODO`.

## Mục tiêu

Giảm cấp phát, copy và peak RSS ở workload thật mà không đổi hành vi PHP.

## Công cụ đã có

- `bench.py` ghi **peak RSS** của mọi lần chạy (cột `RSS` trong báo cáo).
- `binary_trees`, `hashmap`, `json`, `objects` là các workload nặng về bộ nhớ.

## Việc cần làm

- [ ] Đo `memory_get_peak_usage()` song song với RSS cho từng workload.
- [ ] Thử `USE_ZEND_ALLOC_HUGE_PAGES=1` (cấu hình runtime, không cần vá) và ghi kết quả.
- [ ] Phân tích `zend_mm` (kích thước bin, chunk 2 MB), `HashTable` packed/hash, interned strings.
- [ ] Mỗi thay đổi kiểm tra refcount, GC, exception, vòng đời request (FPM chạy nhiều request trên một worker).
- [ ] ASan + valgrind cho mọi bản vá thuộc nhóm `QHT-MEM`.

## Điều kiện hoàn thành

Bản vá giảm RAM hoặc tăng tốc ở workload mục tiêu, có số đo trước/sau, PHPT không xấu đi.
