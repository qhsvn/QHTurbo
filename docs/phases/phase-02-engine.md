# PHASE 02 — Zend Engine optimization (`QHT-P02`)

**English** · [Tiếng Việt](phase-02-engine.vi.md)

**Status:** `TODO`. No source patch has been accepted yet.

## Goal

Reduce the cost of opcode execution and function calls in the Zend VM through **small, measured patches**. No semantic changes, no ABI changes.

## Process for each hypothesis (`QHT-ENG-xxx`)

1. **Profile first** on a Linux machine with `perf`:
   ```bash
   perf record -g build/8.5/qhturbo/bin/php -n -d opcache.enable_cli=1 benchmarks/workloads/objects/main.php 3000000
   perf report --no-children | head -50
   ```
   Record the hottest function or opcode handler in `logs/development.md`.
2. **Write a concrete hypothesis**, for example: "handler `ZEND_INIT_METHOD_CALL` costs X% because ...; changing Y will reduce Z".
3. Make the change in `worktrees/8.5/qhturbo/`. If `Zend/zend_vm_def.h` changes, run `php Zend/zend_vm_gen.php` to regenerate `zend_vm_execute.h`.
4. `scripts/build.sh qhturbo`, then:
   - `scripts/run-tests.sh qhturbo opcache` and `... jit` (the number of failures must not increase relative to baseline),
   - `python3 scripts/bench.py php --variants baseline,qhturbo --runs 15`.
5. If there is a clear gain (the 95% CI does not contain 1.0) and no other workload slows down by more than the A/A noise, then:
   `scripts/make-patch.sh qhturbo QHT-ENG-001-<name>` and commit the patch + report.
6. If there is no gain, record `NO-GO` in `logs/development.md` together with the measurements, then reset the worktree.

## Identified candidates (not yet tried)

| ID | Idea | Risk |
|---|---|---|
| QHT-ENG-001 | Re-check the VM mode (`--with-zend-vm=HYBRID` by default) and global register variables on newer gcc | Low |
| QHT-ENG-002 | Try `-march=x86-64-v2/v3` for releases targeted by CPU generation (modern aaPanel servers) | Medium: will not run on older CPUs |
| QHT-ENG-003 | Measure the cost of `zend_call_function` for callbacks (`array_map`, `usort`) and a fast path for non-capturing closures | Medium |

## Definition of done

At least one reviewed patch, PHPT not worse, measurements that are repeatable, and side effects analyzed. If no patch helps, record `NO-GO` with evidence. No false success reports.
