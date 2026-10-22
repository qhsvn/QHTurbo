# ROADMAP — PHP QHturbo

**English** · [Tiếng Việt](ROADMAP.vi.md)

Original plan (full principles, risks, process): [`docs/original-plan.md`](docs/original-plan.md).
This file summarises the roadmap and the **actual status** of each phase. For day-to-day detail see `PROJECT_STATUS.md`.

**Version scope:** PHP **7.4, 8.0, 8.1, 8.2, 8.3, 8.4, 8.5** ([policy](docs/compatibility-policy.md)). The main optimisation branch is 8.5. Every compiler-level optimisation (PGO) applies to all branches.

| Phase | Content | Status | Docs |
|---|---|---|---|
| 01 | Foundation, baseline, build/test/benchmark tooling, CI | **DONE** (on the Linux build machine) | [phase-01](docs/phases/phase-01-foundation.md) |
| 02 | Zend Engine optimisation (C patches) | TODO | [phase-02](docs/phases/phase-02-engine.md) |
| 03 | Memory and data structures | TODO | [phase-03](docs/phases/phase-03-memory.md) |
| 04 | Compiler / OPcache / JIT | IN_PROGRESS (PGO + JIT measurements done) | [phase-04](docs/phases/phase-04-compiler-jit.md) |
| 05 | PHP-FPM / web / aaPanel: **Compatible** and **Performance** modes | IN_PROGRESS (both modes running and measured; async MySQL/Redis drivers and Laravel/Symfony adapters done; still missing the aaPanel plugin and other platforms) | [compatible](docs/modes/compatible.md) · [performance](docs/modes/performance.md) · [phase-05](docs/phases/phase-05-runtime-aaPanel.md) |
| 06 | Compatibility, security, release | TODO | [phase-06](docs/phases/phase-06-release.md) |

## Principles (condensed from the original plan)

1. No claim of being faster without reproducible measurements (95% CI does not contain 1.0).
2. Do not break PHP compatibility: PHPT must not get worse than the baseline of the same branch.
3. Never change production directly: build/VM, then staging, then canary. Always have a rollback.
4. Each optimisation is its own commit/patch, with a `QHT-...` task ID.
5. Only mark DONE with evidence: commit, command, log, result.

## Next steps, in priority order

Details and rationale: [docs/proposals.md](docs/proposals.md).

1. Run Compatible mode on the user's staging (aaPanel), measured with a real site.
2. Support more platforms: plain Apache and nginx, DirectAdmin, cPanel, LiteSpeed; builds for AlmaLinux/Rocky/Debian; one PHP-FPM pool per hosting account.
3. Community website with installation guides and examples.
4. aaPanel plugin and `.deb` package (B7, B8).
5. Build and test the `full` build for 8.0 → 8.4.
6. Phase 2: profile with `perf` on a dedicated machine, pick hypothesis `QHT-ENG-001`.
