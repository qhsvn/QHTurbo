# Phương pháp benchmark

[English](benchmark-methodology.md) · **Tiếng Việt**

Mục tiêu: mọi con số về tốc độ đều **tái lập được** và **so sánh công bằng**.

## 1. Workload

`benchmarks/workloads/<tên>/main.{php,py,js,go}`: cùng một thuật toán viết bằng 4 ngôn ngữ, in ra cùng một kết quả. Mã PHP dùng cú pháp 7.4 để mọi nhánh 7.4 → 8.5 chạy chung một file.

| Workload | Đo cái gì | Loại |
|---|---|---|
| `nbody` | số thực, mảng lồng nhau, tham chiếu | CPU |
| `spectral_norm` | gọi hàm nhỏ liên tục, số thực | CPU |
| `fannkuch` | mảng số nguyên, hoán vị | CPU |
| `mandelbrot` | vòng lặp số thực thuần, không cấp phát | CPU |
| `binary_trees` | cấp phát/giải phóng nhiều, đệ quy, GC | Bộ nhớ |
| `hashmap` | mảng kết hợp khóa chuỗi (đếm theo khóa) | Bộ nhớ/hash |
| `json` | `json_encode`/`json_decode` dữ liệu kiểu API | Thư viện |
| `strings` | nối chuỗi, `str_replace`, `explode`, `strtoupper` | Thư viện |
| `objects` | tạo object, gọi method, interface | OOP |

Kích thước đầu vào nằm trong `benchmarks/workloads.json` (`full` ≈ 1–3 giây ở chế độ interpreter, `quick` cho CI). `tests/check-workloads.sh` kiểm tra 4 ngôn ngữ cho ra cùng kết quả.

Tập **huấn luyện PGO** (`benchmarks/training/` + `Zend/micro_bench.php` + một phần PHPT) **tách biệt** với tập đo. Nhờ vậy kết quả PGO không bị "học thuộc" bài đo.

## 2. Cách đo (`scripts/bench.py`)

- Mỗi lần chạy là một tiến trình mới: `php -n` (không đọc php.ini của máy), `memory_limit=-1`.
- Chế độ `interp`: OPcache bật, JIT tắt, tức là đo Zend VM. Chế độ `jit`: OPcache + `opcache.jit=tracing`, buffer 128M (chỉ PHP ≥ 8.0).
- Warm-up 1 lần, sau đó **chạy xen kẽ** các đối thủ (A B C A B C …), mặc định 7 vòng. Nhờ vậy nhiễu của máy (nhiệt độ, tiến trình khác) ảnh hưởng đều cho tất cả.
- Ghi lại: thời gian thực (wall time) của từng lần, **peak RSS** của tiến trình (qua `wait4`), stdout.
- Báo cáo: median, min, max, độ lệch chuẩn, **CV** (hệ số biến thiên), và **khoảng tin cậy 95% bootstrap** của tỉ số median.
  - ✔ = nhanh hơn có ý nghĩa thống kê, ✘ = chậm hơn có ý nghĩa, không dấu = chưa phân biệt được với nhiễu.
- Nếu stdout của một đối thủ khác baseline, kết quả bị đánh dấu `INVALID` và **không được dùng**.
- Tổng hợp: trung bình nhân (geometric mean) tốc độ trên mọi workload.

Mỗi báo cáo `results/<ngày>-.../summary.md` ghi đủ: CPU, số vCPU, RAM, OS, kernel, gcc, commit QHturbo, tag upstream, sha256 của binary, số vòng và warm-up. Từng lần đo (thời gian, RSS) nằm trong `raw/results.json`, file này được commit để kiểm tra lại được.

## 3. Kiểm tra A/A (độ nhiễu)

Khi `patches/<nhánh>/series` trống, `qhturbo` có **cùng mã nguồn** với `baseline`. So sánh hai bản này là phép thử A/A: chênh lệch đo được chính là mức nhiễu của máy. Bản vá nào có hiệu ứng nhỏ hơn mức nhiễu A/A thì coi như **chưa chứng minh**.

## 4. So sánh với ngôn ngữ khác (`bench.py cross`)

- Cùng thuật toán, cùng dữ liệu, cùng output. Go được biên dịch trước (không tính thời gian biên dịch), các ngôn ngữ còn lại tính cả thời gian khởi động runtime.
- Không dùng thư viện native đặc biệt (numpy, SIMD...) ở bất kỳ ngôn ngữ nào.
- Đây là **benchmark CPU một luồng**. Không suy ra hiệu năng web/I/O từ đây. Web/API đo riêng ở Phase 5 (wrk/k6 với PHP-FPM).

## 5. Quy tắc diễn giải

1. Không công bố "nhanh hơn X%" nếu CI 95% chứa 1.0.
2. Báo cáo cả workload chậm đi, không chỉ chọn workload đẹp.
3. Benchmark chính thức chạy trên filesystem Linux (WSL: `~/QHTurbo-lab`, không phải ổ Windows dưới `/mnt/`).
4. Máy ảo dùng chung (cloud) có nhiễu cao. CV > 5% thì tăng `--runs` hoặc đo lại trên máy riêng.
