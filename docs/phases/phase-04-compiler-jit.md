# PHASE 04 — Compiler / OPcache / JIT (`QHT-P04`)

**English** · [Tiếng Việt](phase-04-compiler-jit.vi.md)

**Status:** `IN_PROGRESS`. PGO and JIT on/off measurements are done; optimization of the PHP compiler passes has not started.

## Done

- `qhturbo-pgo` variant: PGO (gcc `-fprofile-generate`/`-fprofile-use`). The training set is separate from the benchmark set.
- LTO was tried and **dropped**: GCC reports the error `global register variable follows a function definition` (ISSUE-001).
- `bench.py` measures both `interp` (OPcache, no JIT) and `jit` (tracing JIT, 128M buffer) for every branch ≥ 8.0.
- Configuration profiles: `config/ini/opcache-production.ini`, `config/ini/jit.ini`.

See the measurements in `results/` and `logs/benchmarks.md`.

## Remaining work

- [ ] Retry LTO a different way: build with clang, or compile the files that use global registers (`zend_execute.c`, `ext/opcache/jit/zend_jit_vm_helpers.c`) with `-fno-lto`.
- [ ] Measure JIT `function` and `tracing` at the `opcache.jit=1254/1255` levels, compared with the interpreter.
- [ ] Run the PHPTs with JIT on the PGO binary (`scripts/run-tests.sh qhturbo-pgo jit`).
- [ ] Evaluate the optimization passes in `Zend/Optimizer/` (DFA, SCCP, inference) on real framework code.
- [ ] Check deoptimization, exceptions, integer overflow, dynamic types, and debug info with JIT enabled.
- [ ] Policy: JIT is **off by default** in the web profile, enabled only when the application's own benchmarks show a gain.

## Definition of done

Figures for each mode, no crashes, no semantic errors, and a clear on/off policy.
