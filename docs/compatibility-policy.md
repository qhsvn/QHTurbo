# Compatibility policy

**English** · [Tiếng Việt](compatibility-policy.vi.md)

## 1. Supported PHP versions

QHturbo supports **PHP 7.4 and later**. Each PHP branch is built from that branch's exact official tag, with no code mixed between branches. So an application running on PHP 7.4 still runs on QHturbo 7.4 with the same behavior.

| Branch | Pinned upstream tag | Upstream support (10/2026) | JIT | OPcache | Notes |
|---|---|---|---|---|---|
| 7.4 | `php-7.4.33` | **Ended (EOL 11/2022)** | No | shared (`opcache.so`) | Legacy applications only; needs an upgrade plan |
| 8.0 | `php-8.0.30` | **Ended (EOL 11/2023)** | Yes | shared | Same as above |
| 8.1 | `php-8.1.34` | **Ended (EOL 12/2025)** | Yes | shared | Same as above |
| 8.2 | `php-8.2.35` | Security fixes only (until 12/2026) | Yes | shared | |
| 8.3 | `php-8.3.36` | Security fixes only (until 12/2027) | Yes | shared | |
| 8.4 | `php-8.4.27` | Active full support | Yes | shared | |
| 8.5 | `php-8.5.12` | Active full support | Yes | static (always available) | Main branch for optimization work |

Tags and commits are pinned in `config/versions/<branch>.env`. `scripts/bootstrap.sh` checks that the tag points to the pinned commit and stops if it does not.

### Warning about EOL branches (7.4, 8.0, 8.1)

The official PHP team **no longer releases security fixes** for these branches. QHturbo can build them in order to:

- speed up legacy applications while an upgrade is being prepared,
- compare performance across PHP generations on the same machine.

QHturbo **does not apply its own security fixes** to EOL branches. If you put them on a real server:

- use them only after staging, and do not expose them directly to the Internet when there is an alternative,
- track CVEs for PHP and the extensions in use,
- have a migration path to 8.3+ (see the table above).

The scripts print a warning whenever an EOL branch is bootstrapped or built.

## 2. Compatibility principles for QHturbo patches

1. **Do not change PHP syntax or semantics.** Code that runs correctly on the original PHP of the same branch must give the same result on QHturbo, including error messages, warnings and array element order.
2. **Do not break the extension ABI** (`ZEND_MODULE_API_NO`, the public `zval`, `zend_object`, `HashTable` structures). Any patch that needs an ABI change must say so clearly in `patches/<branch>/` and the CHANGELOG, and must not go into a `stable` release until it has been reviewed.
3. **Each patch applies only to the branch it was tested on.** An optimization for 8.5 is not automatically applied to 7.4. To port it to another branch, create a separate patch in `patches/<branch>/` and rerun that branch's PHPTs and benchmarks.
4. **Compiler-level optimization (PGO) applies to every branch**, because it does not change the source code. The PHPTs must still be run on the optimized binary to catch compiler bugs.
5. **Safe defaults:** JIT and aggressive settings are left off in the sample configuration until the application's own benchmarks prove they help.

## 3. Compatibility gates

| Level | Check | Required before |
|---|---|---|
| 1 | `php -l` on all benchmarks on 7.4 → 8.5 (CI `workloads`) | every commit |
| 2 | Benchmark output identical between baseline and QHturbo (`bench.py` marks `INVALID` if it differs) | every measurement |
| 3 | PHPT: QHturbo's number of failing tests is **not greater than** the baseline's for the same branch and mode (interp/opcache/jit) | every patch |
| 4 | Composer + frameworks (Laravel/WordPress) running their own test suites | Phase 5 |
| 5 | ABI/extension matrix (popular PECL: redis, imagick, swoole...) | release |

Tests that already fail on baseline (due to environment, network, locale...) are recorded in `results/tests/` for comparison. They are not counted as QHturbo bugs, but QHturbo must not increase that number either.
