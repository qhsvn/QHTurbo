# PHASE 01 — Nền tảng và baseline (`QHT-P01`)

**Trạng thái:** `DONE` trên máy build Linux (đám mây). Trên máy Windows/WSL của người dùng: cần chạy lại các bước ở README để có baseline của chính máy đó.

## Checklist

- [x] Kiểm tra thư mục đích: repo GitHub `tanchinhdo/QHTurbo` lúc đầu trống, không có dữ liệu bị ghi đè. Đồng bộ qua GitHub (ISSUE-002).
- [x] `README.md`, `ROADMAP.md`, `PROJECT_STATUS.md`, `CHANGELOG.md`.
- [x] Toolchain và thông tin máy: được ghi tự động vào mọi báo cáo (`machine_info` / `bench.py`).
- [x] Clone PHP upstream theo tag đã xác minh. Không fork nhánh `qhturbo/main` trong `php-src` mà dùng **chuỗi patch theo nhánh** (`patches/<nhánh>/series`), gọn hơn và áp lại được trên tag mới.
- [x] **Hỗ trợ PHP 7.4 → 8.5** (yêu cầu bổ sung của người dùng): mỗi nhánh có tag/commit ghim riêng.
- [x] Build baseline và QHturbo cùng cờ; lưu sha256 binary (`BUILD-INFO.txt`).
- [x] Chạy PHPT và lưu báo cáo: `results/tests/`.
- [x] Benchmark 10 lần đo sau warm-up, ghi median, min/max, CV, CI 95%, RSS.
- [x] CI: shellcheck, lint PHP 7.4 → 8.5, so output giữa các ngôn ngữ, build smoke + PHPT + benchmark smoke.

## Cách tái lập

```bash
export QHT_LAB=~/QHTurbo-lab          # build trên filesystem Linux khi dùng WSL
QHT_PHP=8.5 scripts/bootstrap.sh
QHT_PHP=8.5 scripts/build.sh baseline
QHT_PHP=8.5 scripts/build.sh qhturbo
QHT_PHP=8.5 scripts/build.sh qhturbo-pgo
QHT_PHP=8.5 scripts/run-tests.sh baseline opcache
QHT_PHP=8.5 scripts/run-tests.sh qhturbo-pgo opcache
taskset -c 2 python3 scripts/bench.py php --php 8.5 --variants baseline,qhturbo,qhturbo-pgo --runs 10
```

Đổi `QHT_PHP=7.4` (hoặc 8.0 … 8.4) cho nhánh khác.

## Sản phẩm

- Baseline đã đo: `results/2026-10-22-php8.5-pgo-v1/`, `results/2026-10-22-php8.5-pgo-v2/` (baseline + A/A), `results/2026-10-22-cross-php7.4-8.5-php/`, `results/2026-10-22-cross-php8.5-python-node-go/`
- PHPT: `results/tests/`
- Nhật ký: `logs/development.md`, `logs/benchmarks.md`, `logs/issues.md`

## Rollback

Mọi thứ nằm trong Git và có thể tạo lại từ script. Muốn bỏ một biến thể: xóa `build/<nhánh>/<biến thể>` và `git -C php-src worktree remove worktrees/<nhánh>/<biến thể>` (chỉ khi `git status` trong worktree sạch).
