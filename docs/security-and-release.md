# Security and release

**English** · [Tiếng Việt](security-and-release.vi.md)

## 1. Tracking upstream fixes

- Official sources: https://www.php.net/ChangeLog-8.php, https://www.php.net/ChangeLog-7.php and the GitHub Security Advisories of `php/php-src`.
- When upstream releases a new version of a branch (for example `php-8.5.13`):
  1. Get the tag's commit: `git ls-remote --tags https://github.com/php/php-src.git 'php-8.5.13^{}'`.
  2. Update `config/versions/8.5.env` (both `PHP_TAG` and `PHP_COMMIT`) in **a separate commit**.
  3. `QHT_PHP=8.5 scripts/bootstrap.sh` (verifies the commit), then rebuild all 3 variants.
  4. Reapply `patches/8.5/series`. If a patch does not apply, fix the patch; do not edit the worktree by hand.
  5. Run the PHPTs (interp/opcache/jit) and the benchmarks. Record the results in `logs/development.md`.
- EOL branches (7.4, 8.0, 8.1) no longer receive upstream fixes. See the warning in `docs/compatibility-policy.md`.

## 2. Memory-safety checks for C patches

Every Zend Engine patch must go through:

- The full PHPT suite in 3 modes: `scripts/run-tests.sh qhturbo interp|opcache|jit`.
- A debug + ASan/UBSan build (`CFLAGS="-O1 -g -fsanitize=address,undefined"`, `--enable-debug`), running the relevant PHPTs.
- `USE_ZEND_ALLOC=0 valgrind` for small tests when needed.
- Fuzzing (php-src ships `sapi/fuzzer`) for changes to the parser, unserialize, json.

## 3. Release process

| Stage | Conditions |
|---|---|
| `alpha` | Builds, PHPT no worse than baseline, has measurements |
| `rc` | Plus: framework/Composer tests, clean ASan, extension matrix, staging run ≥ 7 days |
| `stable` | Plus: production canary, error rate/p99 not worse, rollback rehearsed |

Each release records:

- the QHturbo version, the upstream PHP branch and tag, the commit,
- the patch list and build flags (taken from `BUILD-INFO.txt`),
- the binary sha256, an SBOM (gcc and glibc versions, linked libraries: `ldd`),
- incompatibilities (if any) and how to go back to the original PHP.

**Reproducible builds:** the same `config/versions/*.env`, `config/build.env`, `patches/` and the same gcc version produce the same machine code (except for the `built:` timestamp in `php -v`). PGO additionally depends on the training set, so that set is committed in the repo.

## 4. Deploying on aaPanel

1. Do not overwrite `/www/server/php/<ver>`. Install into `/opt/qhturbo/<branch>/<version>/`.
2. A separate FPM service, a separate socket, for example `/tmp/qhturbo-85.sock`, and a separate pool.
3. Backup: PHP configuration, Nginx vhost, DB, Redis before switching.
4. Checks before switching: `php -v`, `php -m`, `php --ini`, `php-fpm -t`, the important endpoints.
5. Canary: one vhost or part of the traffic goes first; monitor 5xx, p99, RSS, CPU, slow queries.
6. Rollback: point `fastcgi_pass` back to the original PHP socket, `nginx -t && nginx -s reload`. This step must be rehearsed before running in production.
7. Payments and transactions: the DB is the source of truth, do not cache transaction state, keep idempotency.
