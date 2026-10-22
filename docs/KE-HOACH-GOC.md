# PHP QHturbo — Kế hoạch triển khai, kỹ thuật và nhật ký phát triển

> **Tên dự án:** PHP QHturbo — High-Performance PHP Runtime  
> **Ngày lập:** 22/10/2026  
> **Vị trí dự án:** repo GitHub `qhsvn/QHTurbo` (clone về bất kỳ thư mục nào)  
> **Mục tiêu phát hành:** Runtime PHP tùy biến có kiểm chứng, tương thích ngược tối đa; chạy Linux/aaPanel sau khi vượt qua kiểm thử.  
> **Trạng thái thực tế:** Đã có **bộ khởi tạo** `PHP-QHturbo-starter.zip`; **chưa** triển khai, chưa clone/build lõi PHP và chưa có bản vá Zend Engine chứng minh tăng tốc.

---

## 0. Các nguyên tắc bắt buộc

1. **Không phỏng đoán tốc độ.** Chỉ tuyên bố nhanh hơn khi có log đo lường có thể tái lập.
2. **Không phá tính tương thích PHP.** Mọi thay đổi lõi phải chạy bộ kiểm thử PHPT; ứng dụng Laravel, WordPress và Composer kiểm thử ở giai đoạn tích hợp.
3. **Không sửa thẳng máy chủ production.** Đầu tiên thử trên máy build/VM và staging; sao lưu + rollback trước khi triển khai aaPanel.
4. **Không xóa/ghi đè dữ liệu có sẵn ở thư mục dự án.** Kiểm tra thư mục trước; làm việc qua Git và backup.
5. **Tách hiệu năng ngôn ngữ khỏi hiệu năng ứng dụng.** Tối ưu Zend Engine, OPcache, FPM, Redis và MySQL là những lớp khác nhau.
6. **Cập nhật bảo mật định kỳ.** Theo dõi bản vá chính thức; nâng nền PHP kèm chạy lại test và benchmark.
7. **Chỉ đánh dấu DONE khi có bằng chứng:** commit, lệnh chạy, phiên bản công cụ, log và kết quả kiểm thử.
8. **Giữ ghi chú xuyên suốt:** mọi giai đoạn phải có mục tiêu, tiến trình, kết quả, rủi ro, bước tiếp theo.

## 1. Phạm vi, kiến trúc, mục tiêu

### 1.1. QHturbo là gì?

QHturbo là một nhánh thực nghiệm của **mã nguồn PHP chính thức (`php-src`)**, không phải một bộ cấu hình php.ini đổi tên. Giữ PHP syntax, Zend API và ABI khi khả thi; mọi thay đổi có khả năng phá ABI phải ghi rõ. Tên nhánh Git đề xuất: `qhturbo/main`.

**Nền khởi đầu của bộ starter hiện có:** tag `php-8.5.12`. Trước khi clone hoặc phát hành, xác nhận tag, chữ ký/nguồn và bản vá bảo mật hiện hành tại trang PHP chính thức. Nếu đã có bản an toàn hơn thì cập nhật tài liệu và tag một cách có kiểm soát.

### 1.2. Các lớp cải tiến

| Lớp | Mục tiêu | Cách kiểm chứng |
|---|---|---|
| Zend VM/opcode | Giảm chi phí thực thi opcode, gọi hàm | Microbenchmark và PHPT |
| Memory/HashTable | Giảm cấp phát, copy, sử dụng bộ nhớ | Peak RSS, allocations, microbenchmark |
| Compiler/OPcache/JIT | Tối ưu đoạn mã nóng, tránh biên dịch lặp | Benchmark CLI+web, JIT on/off |
| PHP-FPM | Tối ưu worker, tail latency, throughput | HTTP load test, p95/p99, lỗi |
| CSDL và Redis | Giảm độ trễ ứng dụng, tránh truy vấn lặp | Query plan, cache hit, kiểm thử nhất quán |
| Tương thích | Composer + framework + extensions | PHPT, integration, regression |

**Không thể cam kết nhanh hơn Rust, Go, Java hay Node.js trong mọi workload.** Mục tiêu là cạnh tranh trong các kịch bản thực tế được định nghĩa rõ. Các khoảng tăng tốc dưới đây chỉ là mục tiêu thử nghiệm, không phải kết quả đạt được:

- Zend VM/microbenchmark: thử hướng tăng 5–20% cho tác vụ chọn lọc.
- API throughput: thử hướng tăng 10–30% với cùng điều kiện.
- Memory peak: nghiên cứu giảm 5–15% ở workload cụ thể.
- Production: không được tăng tỷ lệ lỗi, regress p99 nghiêm trọng, hoặc phá API/ABI ngoài chính sách được phê duyệt.

### 1.3. Kiến trúc vận hành

`Client → Nginx → PHP-FPM/QHturbo + OPcache → Redis (cache) / MySQL (nguồn dữ liệu chính)`.

Chỉ sau khi QHturbo đạt tiêu chuẩn tương thích mới đóng gói PHP-FPM production riêng cho aaPanel. Không ghi đè `/www/server/php/...` trước khi có kế hoạch triển khai song song và rollback.

---

## 2. Cấu trúc dự án

```text
QHTurbo/
├── README.md
├── ROADMAP.md
├── PROJECT_STATUS.md
├── CHANGELOG.md
├── docs\
│   ├── architecture.md
│   ├── compatibility-policy.md
│   ├── benchmark-methodology.md
│   ├── security-and-release.md
│   └── phases\
│       ├── phase-01-foundation.md
│       ├── phase-02-engine.md
│       ├── phase-03-memory.md
│       ├── phase-04-compiler-jit.md
│       ├── phase-05-runtime-aaPanel.md
│       └── phase-06-release.md
├── logs\
│   ├── development.md
│   ├── issues.md
│   └── benchmarks.md
├── scripts\                 # bootstrap.sh/build.sh/benchmark.sh/compare.py
├── benchmarks\              # workload độc lập, cố định
├── tests\                   # test bổ sung QHturbo
├── config\                  # INI benchmark và profile khởi đầu
├── patches\                 # patch thử nghiệm có ghi chú
├── php-src\                 # clone PHP upstream, branch qhturbo/main
├── worktrees\               # baseline và qhturbo tách biệt
├── build\                   # binary build CLI, về sau có FPM
└── results\                 # kết quả benchmark và logs
```

**Lưu ý:** Cấu trúc trên phù hợp với `PHP-QHturbo-starter.zip` hiện có, trong đó script sử dụng các thư mục `php-src`, `worktrees`, `build`, `results` và `config`. Không đổi tên các thư mục này trước khi chỉnh script tương ứng. Những file/tài liệu chưa có phải tạo ở Phase 1.

### 2.1. Git và chiến lược nhánh

- `upstream`: remote mã nguồn PHP chính thức.
- `qhturbo/main`: nhánh phát triển tích hợp của QHturbo.
- `feature/QHT-ENG-001-...`: một tối ưu nhỏ có phép đo.
- `release/qhturbo-x.y`: nhánh đóng gói phát hành.
- Tạo tag có thể tái lập cho baseline, ví dụ `qhturbo-baseline-YYYYMMDD` trong repo nội bộ của dự án (không ghi đè tag upstream).
- Mọi tối ưu phải có commit riêng để dễ revert; không gộp thay đổi chức năng, bảo mật và tối ưu trong một commit.

---

## 3. Cấp quyền và triển khai ở máy Windows

### 3.1. Giới hạn truy cập

ChatGPT trong trình duyệt chat thông thường **không tự có quyền trực tiếp thao tác ổ `D:`**. Nếu dùng ChatGPT Work/ứng dụng có thao tác trên máy và hỗ trợ thư mục cục bộ, cấp quyền ngay trong giao diện/đối thoại yêu cầu truy cập do sản phẩm hiển thị. **Không có một nút cấp quyền Windows chung áp dụng cho mọi cách dùng ChatGPT.** Không cấp quyền quản trị toàn bộ máy hoặc chia sẻ mật khẩu/RDP chỉ để thực hiện dự án. Nếu không có chế độ truy cập file cục bộ, chạy các lệnh dưới đây trong PowerShell/WSL hoặc chuyển kết quả bằng file nén.

### 3.2. Chuẩn bị an toàn từ PowerShell

Mở PowerShell trong quyền tài khoản Windows bình thường:

```powershell
$Project = "$env:USERPROFILE\QHTurbo"
if (Test-Path $Project) {
    Write-Host "Đã tồn tại: $Project — kiểm tra dữ liệu trước khi ghi"
    Get-ChildItem -Force $Project | Select-Object Name, Length, LastWriteTime
} else {
    New-Item -ItemType Directory -Path $Project | Out-Null
}
```

Nếu thư mục có dữ liệu, tạo backup riêng **trước** khi giải nén hoặc di chuyển mã nguồn. Sao chép gói `PHP-QHturbo-starter.zip` về máy và, sau khi bảo đảm không có file trùng cần giữ, giải nén tại một vị trí tạm:

```powershell
$Zip = "$env:USERPROFILE\Downloads\PHP-QHturbo-starter.zip"
$Temp = "$env:USERPROFILE\QHTurbo-starter-extracted"
if (!(Test-Path $Zip)) { throw "Không tìm thấy $Zip" }
if (Test-Path $Temp) { throw "Thư mục tạm đã tồn tại: $Temp" }
Expand-Archive -LiteralPath $Zip -DestinationPath $Temp
# Kiểm tra <thư mục tạm>\PHP-QHturbo trước khi copy.
# Chỉ copy những file chưa có, hoặc xử lý trùng file bằng Git/diff.
```

Không dùng `robocopy /MIR` hoặc lệnh xóa đệ quy trên đường dẫn dự án.

### 3.3. Cài WSL2 Ubuntu để biên dịch Linux

Dùng PowerShell quyền Administrator **chỉ cho bước cài WSL nếu cần**:

```powershell
wsl --install -d Ubuntu
wsl --status
wsl -l -v
```

Có thể cần khởi động lại Windows. Sau đó mở Ubuntu. Ổ đĩa Windows được WSL mount dưới `/mnt/<ổ>`. Với benchmark chính thức, **khuyến nghị build và chạy test trong filesystem Linux**, ví dụ `~/QHTurbo-lab`, đồng bộ tài liệu/patch và báo cáo qua Git để tránh sai lệch filesystem của WSL.

Trong Ubuntu:

```bash
sudo apt update
sudo apt install -y git build-essential autoconf bison re2c pkg-config \
  libxml2-dev libssl-dev zlib1g-dev libsqlite3-dev python3
```

### 3.4. Bootstrap với bộ starter

Sau khi đặt nội dung starter vào dự án, từ Ubuntu:

```bash
cd ~/QHTurbo
bash scripts/bootstrap.sh
bash scripts/build.sh baseline
bash scripts/build.sh qhturbo
bash scripts/benchmark.sh
```

**Điều kiện trước khi chạy:** có Internet truy cập GitHub, tag PHP hợp lệ, dependencies hoàn chỉnh và đủ tài nguyên build. `build.sh` của starter tạo binary CLI tối giản với OPcache, **chưa phải** bản PHP-FPM có thể thay thế trong aaPanel. Script benchmark mặc định chỉ chạy số lần thử nhỏ: cần tăng số lần, warm-up và thống kê để có kết luận nghiêm túc.

### 3.5. Rebuild sau khi sửa Zend Engine

Script starter tạo Git worktree detached một lần; **không tự cập nhật worktree QHturbo** sau khi branch đổi commit. Trước khi build lại, phải kiểm tra code đã commit và cập nhật worktree một cách chủ động:

```bash
cd ~/QHTurbo
# Đảm bảo mọi thay đổi đã commit/backup trước khi remove worktree.
git -C php-src status --short
git -C worktrees/qhturbo status --short
# Chỉ khi worktree sạch:
git -C php-src worktree remove ../worktrees/qhturbo
bash scripts/build.sh qhturbo
bash scripts/benchmark.sh
```

Nếu `git status` báo thay đổi chưa commit thì **dừng**, không dùng `--force` trừ khi đã backup và chủ đích hủy thay đổi.

---

## 4. Lộ trình 6 giai đoạn — các cổng nghiệm thu

### PHASE 01 — FOUNDATION & BASELINE

**ID:** `QHT-P01` — **Trạng thái:** `TODO` (chưa chạy trên máy người dùng).

**Nội dung công việc**

- [ ] Kiểm tra thư mục đích, backup dữ liệu hiện hữu, xác nhận bản starter.
- [ ] Chuẩn hóa `README.md`, `ROADMAP.md`, `PROJECT_STATUS.md`, `CHANGELOG.md`.
- [ ] Cài WSL2/toolchain, thu thập CPU, RAM, OS và version compiler.
- [ ] Clone PHP upstream theo tag đã xác minh, tạo `qhturbo/main`.
- [ ] Build baseline và QHturbo cùng cờ biên dịch; lưu binary hash.
- [ ] Chạy test suite PHP/PHPT và lưu báo cáo (không chỉ chạy microbenchmark).
- [ ] Chạy benchmark ít nhất 5–10 lần đo sau warm-up (tùy workload), ghi median, p95 và độ biến động.
- [ ] Thiết lập CI kiểm tra cú pháp, test, regression và benchmark smoke.

**Điều kiện hoàn thành:** nguồn và revision có thể tái tạo, baseline có số đo, log lỗi/test rõ ràng, có hướng rollback và repo sạch.

**Sản phẩm:** `docs/phases/phase-01-foundation.md`, `results/baseline/`, `logs/development.md`, tag Git baseline.

### PHASE 02 — ZEND ENGINE OPTIMIZATION

**ID:** `QHT-P02` — **Trạng thái:** `TODO`.

**Nội dung**

- [ ] Profile flamegraph/perf và xác định opcode/frequent call path nóng.
- [ ] Chọn một hypothesis nhỏ (ví dụ giảm overhead branch/call) thay vì tối ưu toàn bộ VM cùng lúc.
- [ ] Tạo feature branch, benchmark trước, viết bản vá C tối thiểu.
- [ ] Chạy PHPT liên quan, sanitizer/ASan khi phù hợp, microbenchmark và workload hỗn hợp.
- [ ] So sánh bằng statistical runs; chỉ merge nếu lợi ích nhất quán và không regression đáng kể.

**Điều kiện hoàn thành:** có ít nhất một bản vá lõi đã review, commit, test pass, kết quả đo lặp lại và phân tích hiệu ứng phụ. Nếu không có patch mang lợi ích, ghi `NO-GO` với bằng chứng, không giả mạo thành công.

### PHASE 03 — MEMORY & DATA STRUCTURES

**ID:** `QHT-P03` — **Trạng thái:** `TODO`.

**Nội dung**

- [ ] Profile allocations, peak memory/RSS, copy-on-write, vòng đời request.
- [ ] Phân tích `zend_mm`, `HashTable`, string và các trường hợp array phổ biến.
- [ ] Chọn điểm cải tiến có bằng chứng; kiểm tra refcount, GC, exception, lifetime.
- [ ] Kiểm thử memory safety và regression; so sánh tốc độ + RAM.

**Điều kiện hoàn thành:** patch chứng minh giảm RAM hoặc cải thiện tốc độ ở workload mục tiêu mà không đổi hành vi PHP ngoài phạm vi được duyệt.

### PHASE 04 — COMPILER / OPCACHE / JIT

**ID:** `QHT-P04` — **Trạng thái:** `TODO`.

**Nội dung**

- [ ] Benchmark riêng interpreter / OPcache / JIT on-off.
- [ ] Đánh giá optimization pass trong compiler, bytecode và hot paths.
- [ ] Nghiên cứu chuyên biệt hóa theo kiểu dữ liệu khi an toàn về semantics.
- [ ] Kiểm tra deoptimization, exception, overflow, dynamic types và debug info.

**Điều kiện hoàn thành:** có số liệu theo từng chế độ, không regression crash, không vi phạm semantics và có policy bật/tắt tính năng.

### PHASE 05 — WEB RUNTIME / AAPANEL INTEGRATION

**ID:** `QHT-P05` — **Trạng thái:** `TODO`.

**Nội dung**

- [ ] Build PHP-FPM với extensions cần thiết; tạo package riêng, **không ghi đè PHP aaPanel**.
- [ ] Cấu hình OPcache tương thích; đo FPM worker RSS để xác định `pm.max_children`.
- [ ] Benchmark plaintext, JSON, PDO+MySQL, Redis-cache hit/miss, web framework thực tế.
- [ ] Theo dõi RPS, p50/p95/p99, CPU, RSS, I/O, HTTP errors, DB slow queries.
- [ ] Thử canary/staging, phân tách port/socket và backout plan.
- [ ] Đối với thanh toán: xác minh idempotency, transaction, không cache trạng thái giao dịch nhạy cảm.

**Điều kiện hoàn thành:** có bản staging hoạt động, integration tests pass, capacity report và rollback được diễn tập.

### PHASE 06 — COMPATIBILITY, SECURITY & RELEASE

**ID:** `QHT-P06` — **Trạng thái:** `TODO`.

**Nội dung**

- [ ] PHPT đầy đủ (ghi pass/fail/skip), mở rộng test framework/Composer.
- [ ] Kiểm tra ABI/extensions và compatibility matrix (OS, CPU, framework).
- [ ] Fuzzing/sanitizer, security review, upstream patch sync.
- [ ] Reproducible build, SBOM/phụ thuộc, version string và release notes.
- [ ] Gói Linux staging và tài liệu build-from-source, rollback/upgrade.

**Điều kiện hoàn thành:** release candidate đáp ứng gate tương thích, bảo mật, benchmark và thao tác quay lại bản PHP chính thức; nếu chưa đạt thì không phát hành `stable`.

---

## 5. Phương pháp benchmark công bằng

### 5.1. Dữ liệu bắt buộc của mỗi lần đo

- Git commit của upstream và QHturbo; flags `configure`, compiler, OPcache/JIT modes.
- Máy chạy: CPU, số vCPU, RAM, distro, kernel, filesystem, power settings.
- Command, dataset, concurrency, thời lượng chạy, warm-up, số lần lặp.
- Kết quả: median, p95/p99 nếu có, standard deviation/CV, peak RSS, error count.
- Theo dõi regression: workload nhanh hơn và workload chậm hơn; ghi rõ.

### 5.2. Nhóm benchmark

| Nhóm | Ví dụ | Công cụ/đo lường |
|---|---|---|
| Micro | vòng lặp, array, string, JSON, function call | `php benchmarks/micro.php`, hyperfine nếu cài |
| Macro CLI | parse dữ liệu, template, batch computation | elapsed, CPU, max RSS |
| HTTP không DB | plaintext, JSON API | `wrk` hoặc k6; RPS, latency, HTTP errors |
| HTTP có DB | SELECT theo index, ghi transaction | RPS + DB slow query + correctness |
| Cache | Redis hit/miss, invalidation | hit rate, latency, correctness |
| Tải bền vững | soak test, burst, worker recycle | memory growth, 5xx, p99 |

**Benchmark với database phải dùng bộ dữ liệu tương đương**, cache warm/cold được tách và cùng mức concurrency. Tăng RPS với cùng lượng lỗi hoặc độ trễ p99 tệ hơn không tự động là thành công.

### 5.3. So sánh ngôn ngữ/runtime khác

Cần cùng bài toán, giao thức, mức xử lý, môi trường CPU/RAM, số lượng worker, kết nối DB và quy tắc serialize. Benchmark PHP vs Go/Rust/Java/Node.js **chỉ** có ý nghĩa khi so sánh workload tương đương. Tách CPU-bound và I/O-bound; không lấy một bài plaintext để suy luận mọi trường hợp.

---

## 6. Mẫu quản lý tiến độ và nhật ký

### 6.1. `PROJECT_STATUS.md` — cập nhật sau mỗi phiên làm việc

```markdown
# PHP QHturbo — Project Status
Cập nhật: YYYY-MM-DD HH:mm (+07:00)
Giai đoạn hiện tại: PHASE 01 — FOUNDATION
Tổng quan: TODO / IN_PROGRESS / TESTING / DONE / BLOCKED

| Task | Trạng thái | Commit | Bằng chứng | Vướng mắc |
|---|---|---|---|---|
| QHT-P01-001 Kiểm tra dự án | TODO | - | - | Chưa có quyền truy cập máy người dùng |
| QHT-P01-002 Build baseline | TODO | - | - | Chưa cài toolchain |

Bước kế tiếp:
1. ...
2. ...
```

### 6.2. `logs/development.md` — một bản ghi mỗi thay đổi

```markdown
## YYYY-MM-DD HH:mm — QHT-ENG-001
- Mục tiêu/hypothesis:
- Tệp đã sửa:
- Branch/commit:
- Lệnh build/test:
- Kết quả test:
- Benchmark trước/sau:
- Regression/phân tích rủi ro:
- Quyết định: KEEP / REVERT / INVESTIGATE
- Bước tiếp theo:
```

### 6.3. `CHANGELOG.md` — theo phiên bản

```markdown
## [Unreleased]
### Added
- ...
### Changed
- ...
### Fixed
- ...
### Performance
- ... (kèm link/report kết quả)
### Compatibility
- ...
```

### 6.4. Mã công việc

- `QHT-P01-xxx` ... `QHT-P06-xxx`: công việc theo giai đoạn.
- `QHT-ENG-xxx`: thay đổi Zend Engine.
- `QHT-MEM-xxx`: memory/data structures.
- `QHT-JIT-xxx`: compiler/JIT.
- `QHT-WEB-xxx`: FPM/API/aaPanel.
- `QHT-SEC-xxx`: bảo mật và compatibility.

### 6.5. Cổng nghiệm thu (Definition of Done)

- [ ] Có commit/patch/artefact hoặc tài liệu được lưu đúng nơi.
- [ ] Có test case; ghi rõ pass/fail/skip.
- [ ] Có baseline và benchmark cùng điều kiện (nếu tối ưu hiệu năng).
- [ ] Có ghi chú tác động, rủi ro, rollback.
- [ ] Cập nhật `PROJECT_STATUS.md`, `CHANGELOG.md` và nhật ký.
- [ ] Có bước kế tiếp và chủ đề cần xử lý.

---

## 7. Quy trình làm việc từng phiên

1. Mở `PROJECT_STATUS.md` để xác nhận việc đang làm, trạng thái Git, vướng mắc.
2. Không thay đổi nhiều thành phần lõi trong một nhiệm vụ.
3. Viết giả thuyết và baseline **trước** khi sửa.
4. Tạo feature branch, chỉnh code, build, chạy test và benchmark.
5. Review crash/memory/regression và tính tương thích.
6. Commit có ID; cập nhật status/changelog/log.
7. Nếu thất bại: revert branch/patch, ghi kết quả để không thử lại mù quáng.

Ví dụ commit:

```text
QHT-ENG-001: reduce VM dispatch overhead for selected opcode

Benchmark: results/2026-xx-xx/eng-001.md
Tests: PHPT 1234 pass, 0 fail (ví dụ minh họa, KHÔNG là kết quả thực tế)
```

---

## 8. Quy trình release và aaPanel

- Không thay PHP hệ thống hoặc máy chủ web đang phục vụ người dùng khi chưa có staging.
- Dùng đường dẫn cài đặt riêng như `/opt/qhturbo/<version>/`, service FPM/socket riêng, cấu hình riêng; chọn web test vhost trước.
- Backup PHP config, Nginx, DB, Redis config, và có quy trình đổi upstream socket quay về FPM PHP chính thức.
- Kiểm tra `php -v`, `php -m`, `php --ini`, `php-fpm -t`, extension compatibility và các endpoint quan trọng.
- Triển khai canary, giám sát 5xx, p99, memory, CPU, slow query; tự động hoặc thủ công rollback nếu vượt ngưỡng xác định từ baseline.
- Công khai phiên bản QHturbo, commit, PHP upstream tag, commit/patch đã tích hợp và các điểm không tương thích.

---

## 9. Rủi ro chính và phương án xử lý

| Rủi ro | Biện pháp |
|---|---|
| Tăng tốc microbenchmark nhưng chậm ứng dụng | Bổ sung macro/HTTP workloads trước merge |
| Crash hoặc lỗi bộ nhớ trong Zend Engine | PHPT + ASan/UBSan + fuzzing, feature flags, revert |
| Phá extensions/ABI | Matrix test, không phát hành ổn định trước kiểm thử |
| JIT không giúp web/API | Benchmark JIT on/off, giữ mặc định an toàn |
| Sai lệch benchmark trên Windows mount | Build/test trên filesystem Linux để so sánh chính |
| Upstream ra bản vá bảo mật | Theo dõi và cherry-pick/rebase được review, chạy test lại |
| Redis/MySQL làm sai trạng thái giao dịch | DB làm nguồn sự thật, transaction/idempotency, cache invalidation |
| aaPanel cập nhật ghi đè runtime tùy biến | Package/service riêng, không thay đường dẫn của panel |

---

## 10. Checklist khởi động — việc có thể thực hiện ngay

- [ ] Tải `PHP-QHturbo-starter.zip` từ cuộc hội thoại về máy.
- [ ] Kiểm tra thư mục dự án có dữ liệu hay chưa.
- [ ] Giải nén vào thư mục tạm, kiểm tra rồi chuyển nội dung không trùng.
- [ ] Lưu tài liệu này thành `ROADMAP.md` hoặc `PROJECT_PLAN.md`.
- [ ] Cài WSL2 Ubuntu và toolchain.
- [ ] Chạy `scripts/bootstrap.sh` (sau khi kiểm chứng upstream tag).
- [ ] Build baseline/QHturbo và lưu hash binary.
- [ ] Chạy bộ test PHPT chuẩn + baseline benchmark.
- [ ] Điền `PROJECT_STATUS.md`, `logs/development.md` thực tế.
- [ ] Chọn điểm nghẽn đầu tiên để bắt đầu `QHT-ENG-001`.

### Trạng thái bàn giao tại thời điểm lập tài liệu

| Đầu việc | Trạng thái thật |
|---|---|
| Kế hoạch tổng thể, 6 phase, tiêu chí nghiệm thu | **Đã soạn tài liệu** |
| Bộ khởi tạo `PHP-QHturbo-starter.zip` | **Đã có trong hội thoại** |
| File kế hoạch Markdown này | **Đã tạo trong môi trường ChatGPT** |
| Tạo file dự án | **Thay bằng repo GitHub** |
| Clone php-src trên máy người dùng | **Chưa thực hiện** |
| Build + chạy PHPT | **Chưa thực hiện** |
| Patch tối ưu Zend Engine | **Chưa có** |
| Benchmark chứng minh tăng tốc | **Chưa có** |

**Nguyên tắc kết thúc:** Bất cứ lần làm việc nào cũng phải để lại `PROJECT_STATUS.md` và nhật ký cho người tiếp theo có thể tiếp tục mà không phải đoán việc đã làm.
