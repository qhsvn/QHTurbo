# Kiến trúc QHturbo

## 1. QHturbo là gì

QHturbo **không phải bản fork viết lại PHP**. Nó gồm:

1. **Mã nguồn PHP chính thức** (`php-src`) ghim theo tag của từng nhánh 7.4 → 8.5.
2. **Chuỗi bản vá** (`patches/<nhánh>/series`), áp lên tag đó theo thứ tự. Mỗi bản vá nhỏ, có mã công việc (`QHT-ENG-001-...`) và đi kèm số đo.
3. **Quy trình build tối ưu**: cùng mã nguồn, nhưng biên dịch bằng **PGO** (Profile-Guided Optimization). Không dùng LTO vì LTO của GCC xung đột với global register variables của Zend VM (xem `logs/issues.md`, ISSUE-001).
4. **Cấu hình runtime** (OPcache, JIT, preload, FPM) đã đo đạc.
5. **Bộ đo và kiểm thử** để chứng minh mọi thay đổi.

Repo này chỉ chứa phần 2–5. `php-src`, worktree và binary được tạo lại từ script nên không commit.

## 2. Luồng build

```text
config/versions/8.5.env ──► bootstrap.sh ──► php-src (shallow, tag đã xác minh)
                                                │
                    ┌───────────────────────────┼─────────────────────────────┐
                    ▼                           ▼                             ▼
     worktrees/8.5/baseline        worktrees/8.5/qhturbo          worktrees/8.5/qhturbo-pgo
     tag gốc, -O2                  tag + patches/8.5, -O2         tag + patches/8.5, -O2 + PGO
                    │                           │                             │
                    ▼                           ▼                             ▼
     build/8.5/baseline/bin/php    build/8.5/qhturbo/bin/php      build/8.5/qhturbo-pgo/bin/php
```

- `baseline` so với `qhturbo`: tách riêng tác dụng của **bản vá mã nguồn**.
- `qhturbo` so với `qhturbo-pgo`: tách riêng tác dụng của **trình biên dịch** (PGO).
- Mỗi binary có `BUILD-INFO.txt`: tag, commit, danh sách patch, cờ configure, sha256, phiên bản gcc.

### PGO hoạt động thế nào

1. `make prof-gen`: build bản có đo đếm (`-fprofile-generate`).
2. `scripts/pgo-train.sh`: chạy tập huấn luyện (`benchmarks/training/`, `Zend/micro_bench.php`, PHPT của Zend/array/string/json). Trình biên dịch ghi lại nhánh nào hay chạy, hàm nào nóng.
3. `make prof-use`: build lại, dùng dữ liệu đó để sắp xếp mã nóng liền nhau, inline đúng chỗ và dự đoán nhánh tốt hơn.

PGO không đổi ngữ nghĩa chương trình, chỉ đổi cách bố trí mã máy. Tập huấn luyện tách khỏi tập đo để tránh kết quả ảo.

## 3. Các lớp tối ưu và phạm vi

| Lớp | Cách làm trong QHturbo | Trạng thái |
|---|---|---|
| Trình biên dịch C | PGO (mọi nhánh 7.4 → 8.5) | Đã có, đã đo trên 8.5 và 7.4 |
| OPcache / JIT | Cấu hình `config/ini/` + đo JIT on/off | Đã có cấu hình và số đo CLI |
| Zend VM / opcode | Bản vá C trong `patches/<nhánh>/` | Chưa có bản vá được chấp nhận (Phase 2) |
| Bộ nhớ / HashTable | Bản vá C | Chưa bắt đầu (Phase 3) |
| PHP-FPM / web | Build FPM, cấu hình pool, wrk/k6 | Chưa bắt đầu (Phase 5) |

## 4. Vận hành (mục tiêu Phase 5)

`Client → Nginx → PHP-FPM (QHturbo, socket riêng) + OPcache → Redis (cache) / MySQL (nguồn dữ liệu chính)`

QHturbo cài vào `/opt/qhturbo/<nhánh>/<phiên bản>/`, chạy service FPM riêng, **không ghi đè** `/www/server/php/` của aaPanel. Muốn quay lại PHP gốc chỉ cần đổi `fastcgi_pass` về socket cũ rồi reload Nginx.

## 5. Bố cục thư mục

```text
QHTurbo/
├── README.md  ROADMAP.md  PROJECT_STATUS.md  CHANGELOG.md
├── config/
│   ├── versions/<nhánh>.env   # tag + commit upstream được ghim
│   ├── build.env              # cờ configure/CFLAGS dùng chung
│   └── ini/                   # profile OPcache / JIT / preload cho production
├── patches/<nhánh>/series     # chuỗi bản vá mã nguồn theo nhánh
├── scripts/                   # bootstrap, build, run-tests, bench.py, make-patch, pgo-train
├── benchmarks/
│   ├── workloads/<tên>/main.{php,py,js,go}
│   ├── workloads.json         # kích thước đầu vào
│   └── training/              # tập huấn luyện PGO (không dùng để đo)
├── tests/                     # kiểm tra riêng của QHturbo
├── results/                   # báo cáo benchmark + PHPT (summary.md)
├── logs/                      # nhật ký phát triển, vấn đề, benchmark
├── docs/                      # kiến trúc, chính sách, phương pháp, các phase
│
│   (tạo ra khi chạy, không commit — nằm trong QHT_LAB)
├── php-src/  worktrees/<nhánh>/<biến thể>/  build/<nhánh>/<biến thể>/
```
