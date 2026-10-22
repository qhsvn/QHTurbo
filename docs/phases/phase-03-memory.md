# PHASE 03 — Memory and data structures (`QHT-P03`)

**English** · [Tiếng Việt](phase-03-memory.vi.md)

**Status:** `TODO`.

## Goal

Reduce allocations, copies and peak RSS on real workloads without changing PHP behavior.

## Existing tools

- `bench.py` records the **peak RSS** of every run (the `RSS` column in reports).
- `binary_trees`, `hashmap`, `json`, `objects` are the memory-heavy workloads.

## To do

- [ ] Measure `memory_get_peak_usage()` alongside RSS for each workload.
- [ ] Try `USE_ZEND_ALLOC_HUGE_PAGES=1` (runtime configuration, no patch needed) and record the results.
- [ ] Analyze `zend_mm` (bin sizes, 2 MB chunks), `HashTable` packed/hash, interned strings.
- [ ] For every change, check refcounting, GC, exceptions, and the request lifecycle (FPM runs many requests on one worker).
- [ ] ASan + valgrind for every patch in the `QHT-MEM` group.

## Definition of done

A patch that reduces RAM or improves speed on the target workloads, with before/after measurements, and PHPT not worse.
