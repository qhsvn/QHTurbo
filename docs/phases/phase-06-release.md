# PHASE 06 — Compatibility, security, release (`QHT-P06`)

**English** · [Tiếng Việt](phase-06-release.vi.md)

**Status:** `TODO`.

## To do

- [ ] Full PHPT suite in 3 modes for every released branch, recording pass/fail/skip relative to baseline.
- [ ] Composer + framework tests (Laravel, Symfony, WordPress) on each branch 7.4 → 8.5 that the application uses.
- [ ] ABI/extension matrix (PECL: redis, imagick, swoole, xdebug) × OS × CPU generation.
- [ ] ASan/UBSan, fuzzing, security review, syncing upstream fixes.
- [ ] Reproducible builds, SBOM, version string (`PHP_EXTRA_VERSION=-qhturbo1`), release notes.
- [ ] Linux packages for staging + documentation for building from source, upgrading and rolling back.

## Definition of done

The RC passes the compatibility, security and benchmark gates, and going back to official PHP has been tested. Until then, no `stable` release.
