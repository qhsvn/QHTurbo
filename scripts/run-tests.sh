#!/usr/bin/env bash
# Run the upstream PHPT suite against a built variant and save a summary.
#   QHT_PHP=8.5 scripts/run-tests.sh <variant> [interp|opcache|jit] [test dirs...]
# Modes: interp = no OPcache, opcache = OPcache on, jit = OPcache + tracing JIT.
source "$(dirname "$0")/lib.sh"

variant="${1:-}"; mode="${2:-opcache}"; shift 2 || true
is_variant "$variant" || die "Usage: $0 <${VARIANTS[*]// /|}> [interp|opcache|jit] [dirs...]"
wt="$WORKTREES/$variant"
php="$(php_bin "$variant")"
[[ -x "$php" ]] || die "$php not built"

ini=()
case "$mode" in
  interp)  ini=(-d opcache.enable_cli=0) ;;
  opcache) ini=(-d opcache.enable_cli=1) ;;
  jit)     has_jit || die "PHP $QHT_PHP has no JIT"
           ini=(-d opcache.enable_cli=1 -d opcache.jit=tracing -d opcache.jit_buffer_size=64M) ;;
  *) die "unknown mode $mode" ;;
esac
opcache_args "$variant"
ini=("${OPCACHE_ARGS[@]}" "${ini[@]}")

out="$RESULTS_DIR/tests/php$QHT_PHP-$variant-$mode"
mkdir -p "$out/raw"
log "PHPT: PHP $QHT_PHP $variant ($mode), output in $out"
# run-tests exits non-zero when any test fails; we record and report instead.
(cd "$wt" && TEST_PHP_EXECUTABLE="$php" NO_INTERACTION=1 "$php" -n run-tests.php \
  -j"$JOBS" -q "${ini[@]}" "$@" > "$out/raw/run-tests.log" 2>&1) || true

{
  echo "# PHPT results — PHP $QHT_PHP $variant ($mode)"
  echo
  machine_info
  echo "- Binary sha256: $(sha256sum "$php" | cut -d' ' -f1)"
  echo "- Test dirs: ${*:-full suite of built extensions}"
  echo
  echo '```'
  sed -n '/^TEST RESULT SUMMARY/,/^=====*$/p' "$out/raw/run-tests.log" | sed -n '1,40p'
  echo '```'
  echo
  echo "## Failed tests"
  echo
  echo '```'
  sed -n '/^FAILED TEST SUMMARY/,/^=====*$/p' "$out/raw/run-tests.log" | grep -v '^=*$' | grep -v 'FAILED TEST SUMMARY' | grep -v '^-*$' || echo "(none)"
  echo '```'
} > "$out/summary.md"
grep -E '^Tests (passed|failed|skipped)|^Expected fail|^Warned|^Leaked' "$out/raw/run-tests.log" >&2 || tail -20 "$out/raw/run-tests.log" >&2
