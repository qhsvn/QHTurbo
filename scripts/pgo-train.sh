#!/usr/bin/env bash
# PGO training workload. Deliberately does NOT use the measured benchmark
# scripts (benchmarks/workloads) so results are not overfitted.
#   usage: pgo-train.sh <instrumented php binary> <php source tree>
#
# Training must run with OPcache ON as well as off: the OPcache optimizer
# rewrites opcodes into type-specialised VM handlers (ZEND_ADD_DOUBLE,
# ZEND_IS_SMALLER_LONG, ...) that never execute without it. Training only
# without OPcache left those handlers cold and made the PGO build slower
# (see logs/development.md, QHT-JIT-001).
source "$(dirname "$0")/lib.sh"

php="$1"; src="$2"
train="$QHT_ROOT/benchmarks/training"

opcache=()
if [[ -f "$src/modules/opcache.so" ]]; then opcache=(-d "zend_extension=$src/modules/opcache.so"); fi

run_scripts() {
  local label="$1"; shift
  log "Training ($label): Zend/micro_bench.php + Zend/bench.php"
  "$php" -n "$@" "$src/Zend/micro_bench.php" > /dev/null
  "$php" -n "$@" "$src/Zend/bench.php" > /dev/null
  local f
  for f in "$train"/*.php; do
    log "Training ($label): $(basename "$f")"
    "$php" -n "$@" "$f" > /dev/null
  done
}

run_scripts "opcache" "${opcache[@]}" -d opcache.enable_cli=1 -d opcache.jit=disable
run_scripts "no opcache" -d opcache.enable_cli=0
if has_jit; then
  # JIT generates its own machine code; this trains the JIT compiler and the
  # C helpers it calls.
  run_scripts "jit" "${opcache[@]}" -d opcache.enable_cli=1 -d opcache.jit=tracing -d opcache.jit_buffer_size=64M
fi

# Broad language coverage from upstream PHPT tests (pass/fail does not matter
# here; the goal is to execute many code paths), with OPcache on.
log "Training: PHPT subset (OPcache on)"
TEST_PHP_EXECUTABLE="$php" "$php" -n "$src/run-tests.php" -q -j"$JOBS" \
  "${opcache[@]}" -d opcache.enable_cli=1 \
  "$src/Zend/tests" "$src/ext/standard/tests/array" "$src/ext/standard/tests/strings" \
  "$src/ext/json/tests" "$src/ext/spl/tests" > /dev/null 2>&1 || true
