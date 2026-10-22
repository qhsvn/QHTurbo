# Nhật ký benchmark

[English](benchmarks.md) · **Tiếng Việt**

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

## 2026-10-22 — Web: QHturbo Compatible (FPM) so với Performance (worker)

Báo cáo: `results/web/2026-10-22-php8.5-modes.md` (`benchmarks/web/run.sh`). PHP 8.5.12 build `full`, framework giả lập (300 class, container, 40 config, 203 route), wrk -t2 10 s sau 3 s warm-up, nginx + PHP + wrk trên cùng 4 vCPU. RAM đo bằng PSS (bộ nhớ dùng chung chỉ tính một lần).

| Bài | Compatible FPM ×4 | +perf-ini FPM ×4 | Performance ×4 |
|---|---|---|---|
| `/hello` | 2.991 req/s, p99 16,1 ms, 33 MiB | 3.174 | **23.598 req/s, p99 6,8 ms, 43 MiB (7,9×)** |
| `/api/users/42` | 3.008 req/s, p99 15,3 ms, 30 MiB | 2.865 | **21.266 req/s, p99 7,2 ms, 43 MiB (7,1×)** |

| `/io` (3 × 20 ms backend) | req/s | p99 | RAM |
|---|---|---|---|
| FPM ×4 (I/O chặn) | 49 (491 timeout) | — | 30 MiB |
| FPM ×64 (I/O chặn) | 939 | 162 ms | 104 MiB |
| Performance ×4, concurrency 64 (async) | **2.389** | **80 ms** | **56 MiB** |

- Cấu hình Performance cho FPM (`validate_timestamps=0` + JIT) chỉ thay đổi −5% đến +6%, tức là cấu hình không phải yếu tố chính.
- Lần chạy đầu bị loại vì ISSUE-004. Phép đo RAM theo RSS đếm trùng OPcache dùng chung, nên đã chuyển sang PSS.
- Kiểm tra rò rỉ: 30 s tải liên tục (775.525 request), PSS 54 MiB không đổi (sau khi sửa ISSUE-005).

## 2026-10-22 — Laravel 13 / Symfony 8.1 thật: FPM so với worker

Báo cáo: `results/web/2026-10-22-laravel13-fpm-vs-workers.md`, `results/web/2026-10-22-symfony81-fpm-vs-workers.md` (`benchmarks/web/framework.sh`). 4 vCPU, PHP 8.5.12, FPM `pm=static` 8 tiến trình, worker 4 tiến trình concurrency 1, wrk -c32 10 s.

| Route | FPM | Worker | × |
|---|---|---|---|
| Laravel `/up` | 353 | 5.150 | 14,6 |
| Laravel `/` (Blade, session Redis) | 288 | 1.924 | 6,7 |
| Laravel route JSON (web, session Redis) | 319 | 2.295 | 7,2 |
| Symfony `/qht/hello` | 1.737 | 10.958 | 6,3 |
| Symfony `/qht/json` | 1.776 | 11.160 | 6,3 |

Cấu hình Laravel: production, `artisan optimize`, session và cache bằng Redis (phpredis). Lần đo đầu (session file) bị loại, xem ISSUE-012. RAM: Laravel 98–101 MiB (FPM) so với 123–151 MiB (worker).

## 2026-10-22 — MySQL I/O: PDO chặn so với client async

Báo cáo: `results/web/2026-10-22-mysql-io-async.md` (`benchmarks/web/db/run.sh`). Mỗi request 3 × `SELECT SLEEP(0.02)`, MariaDB 10.11 trên cùng máy, wrk -c128.

| Cấu hình | req/s | p99 |
|---|---|---|
| FPM ×4 + PDO | 51 (457 timeout) | 2 s |
| FPM ×64 + PDO | 1.011 | 163 ms |
| Worker ×4 + PDO, concurrency 1 | 51 (300 timeout) | 2 s |
| Worker ×4 + MySQL async, concurrency 64 | **1.724** | **115 ms** |
