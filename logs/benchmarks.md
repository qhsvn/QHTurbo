# Nhật ký benchmark

Tóm tắt các lần đo. Bảng đầy đủ nằm trong `results/<thư mục>/<chế độ>/summary.md`.
Máy đo (mọi lần, trừ khi ghi khác): VM đám mây Firecracker, 4 vCPU, 15 GiB RAM, Ubuntu 24.04.5, kernel 6.18, gcc 13.3.0. Benchmark ghim vào 1 nhân (`taskset -c 2`), 1 lần warm-up + 10 lần đo xen kẽ.

## 2026-10-22 — PHP 8.5.12: baseline / qhturbo (A/A) / qhturbo-pgo v1

Báo cáo: `results/2026-10-22-php8.5-pgo-v1/{interp,jit}/summary.md`

| Chế độ | qhturbo (A/A, cùng mã nguồn) | qhturbo-pgo v1 |
|---|---|---|
| interp (geomean) | +0.6% | **−4.1%** |
| jit (geomean) | −2.5% | **−4.7%** |

- **Độ nhiễu:** phép thử A/A dao động từ −7.7% đến +3.3% theo từng workload, và CV mỗi workload 4–17%. Ở chế độ JIT, A/A còn bị đánh dấu "có ý nghĩa" ở `json` (−6.3% ✘). Nghĩa là trên máy này, dấu ✔/✘ của một workload đơn lẻ **chưa đáng tin**: nhiễu không độc lập giữa các lần chạy (máy ảo dùng chung). Chỉ tin hiệu ứng lớn hơn rõ ràng mức A/A, và ưu tiên geomean.
- **PGO v1 chậm hơn** (spectral_norm −11.7%, fannkuch −4.7% ở interp; mandelbrot −24.6% ở JIT).
- **Nguyên nhân (giả thuyết, có cơ sở):** tập huấn luyện v1 chạy **không bật OPcache**. Khi bật OPcache, bộ tối ưu sinh ra các handler chuyên biệt theo kiểu dữ liệu (`ZEND_ADD_DOUBLE`, `ZEND_IS_SMALLER_LONG`...). Các handler này không chạy lúc huấn luyện nên bị coi là mã lạnh. Lúc huấn luyện cũng không bật JIT.
- **Quyết định:** PGO v1 → **NO-GO**. Sửa tập huấn luyện (v2: chạy với OPcache on/off và JIT), rồi đo lại.

## 2026-10-22 — PHP 8.5.12: qhturbo-pgo v2 (huấn luyện có OPcache + JIT)

Báo cáo: `results/2026-10-22-php8.5-pgo-v2/{interp,jit}/summary.md`

| Chế độ | qhturbo (A/A) | qhturbo-pgo v2 |
|---|---|---|
| interp (geomean) | +0.2% | +0.3% |
| jit (geomean) | −0.5% | **−3.2%** |

- interp: mandelbrot +16.0% ✔ và strings +5.7%, nhưng spectral_norm −18.9% ✘. Tổng thể bằng 0.
- jit: mandelbrot −20.5% ✘ (lặp lại đúng như v1), spectral_norm −12.0%.
- A/A lần này có `objects` +7.7% ✔ dù hai binary cùng mã nguồn. Đây là thêm bằng chứng rằng ngưỡng nhiễu trên máy này khoảng ±8–9% cho từng workload.
- **Quyết định: PGO → NO-GO.** Không có lợi ích ổn định; có thoái lui lặp lại ở mandelbrot (JIT) và spectral_norm. Giữ biến thể `qhturbo-pgo` để thử nghiệm tiếp (tập huấn luyện khác, gcc/clang mới hơn, máy đo riêng), **không** dùng làm bản phát hành.

## So sánh JIT với interpreter (baseline 8.5.12, cùng lần đo)

| Workload | interp (s) | JIT tracing (s) | JIT nhanh hơn |
|---|---|---|---|
| fannkuch | 3.968 | 1.062 | 3.7× |
| spectral_norm | 0.998 | 0.326 | 3.1× |
| mandelbrot | 1.282 | 0.733 | 1.7× |
| nbody | 2.570 | 1.678 | 1.5× |
| objects | 1.424 | 0.932 | 1.5× |
| binary_trees | 1.664 | 1.136 | 1.5× |
| json | 1.516 | 1.380 | 1.1× |
| strings | 1.227 | 1.078 | 1.1× |
| hashmap | 1.769 | 1.633 | 1.1× |

Kết luận: JIT có lợi rất lớn cho code tính toán thuần PHP (1.5–3.7×), và gần như không có lợi khi thời gian nằm trong hàm C có sẵn (json, chuỗi, hash). Đó cũng là đặc điểm của đa số request web.

## 2026-10-22 — PHP 7.4.33 so với PHP 8.5.12 (bản gốc, cùng máy)

Báo cáo: `results/2026-10-22-cross-php7.4-8.5-php/summary.md` (7 vòng đo)

| Workload | 7.4 interp (s) | 8.5 interp (s) | 8.5 JIT (s) | 8.5 interp / 7.4 | 8.5 JIT / 7.4 |
|---|---|---|---|---|---|
| binary_trees | 2.267 | 1.730 | 1.151 | 1.31× | 1.97× |
| fannkuch | 3.611 | 3.881 | 1.018 | **0.93×** | 3.55× |
| hashmap | 1.848 | 1.889 | 1.827 | 0.98× | 1.01× |
| json | 1.630 | 1.484 | 1.395 | 1.10× | 1.17× |
| mandelbrot | 1.068 | 1.312 | 0.748 | **0.81×** | 1.43× |
| nbody | 2.400 | 2.576 | 1.882 | 0.93× | 1.28× |
| objects | 1.579 | 1.506 | 0.962 | 1.05× | 1.64× |
| spectral_norm | 1.276 | 1.026 | 0.339 | 1.24× | 3.76× |
| strings | 1.514 | 1.236 | 1.228 | 1.22× | 1.23× |
| **geomean** | | | | **1.05×** | **1.69×** |

- Chỉ nâng 7.4 lên 8.5 mà vẫn dùng interpreter thì nhanh hơn trung bình 5%. Vòng lặp số thực thuần (mandelbrot, fannkuch, nbody) còn **chậm hơn** 7–19% trên máy này. Cần đo lại trên máy riêng trước khi kết luận chắc.
- 8.5 dùng ít RAM hơn ở workload cấp phát nhiều (binary_trees 58 → 41 MiB, strings 66 → 58 MiB).
- **Lợi ích lớn đến từ JIT (chỉ có từ 8.0)**: 1.69× so với 7.4.

## 2026-10-22 — PHP 8.5.12 so với Python, Node.js, Go

Báo cáo: `results/2026-10-22-cross-php8.5-python-node-go/summary.md` (5 vòng đo). Python 3.13.16, Node.js 22.22.0, Go 1.24.7 (binary biên dịch sẵn).

Hệ số tốc độ so với PHP 8.5 interpreter (lớn hơn 1 là nhanh hơn PHP interp):

| Workload | PHP 8.5 JIT | Python 3.13 | Node.js 22 | Go 1.24 |
|---|---|---|---|---|
| binary_trees | 1.43× | 0.75× | 3.05× | 1.47× |
| fannkuch | 3.90× | 0.40× | 6.74× | 15.97× |
| hashmap | 1.09× | 0.41× | **0.48×** | **0.83×** |
| json | 0.99× | 0.53× | 1.22× | **0.41×** |
| mandelbrot | 1.47× | 0.10× | 7.88× | 9.92× |
| nbody | 1.36× | 0.43× | 12.04× | 25.82× |
| objects | 1.37× | 0.43× | 11.61× | 7.25× |
| spectral_norm | 3.24× | 0.20× | 11.07× | 26.46× |
| strings | 1.03× | 0.47× | **0.37×** | **0.95×** |
| **geomean** | **1.56×** | **0.36×** | **3.36×** | **4.16×** |

Đọc kết quả:
- **PHP nhanh hơn Python ở cả 9 bài** (PHP interp nhanh hơn khoảng 2.8×, PHP JIT khoảng 4.3×).
- **PHP thắng Node.js và Go** ở các bài mà thời gian nằm trong thư viện C của PHP: `hashmap` (mảng kết hợp), `strings`, và `json` (thắng Go; `encoding/json` dùng reflection nên chậm).
- **PHP thua xa Node.js và Go ở tính toán số thuần và OOP** (Node.js/Go nhanh hơn PHP interp 6–26×, và vẫn nhanh hơn PHP JIT 1.7–19×). Đây là giới hạn của kiến trúc (kiểu động, zval, JIT kém V8 hoặc Go biên dịch AOT). Tinh chỉnh cấu hình hay PGO không lấp được khoảng cách này.
- RAM: PHP ngang Python và Go (15–60 MiB); Node.js dùng 50–335 MiB.
