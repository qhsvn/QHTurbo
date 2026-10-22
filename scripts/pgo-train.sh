#!/usr/bin/env bash
# PGO training workload. Deliberately does NOT use the measured benchmark
# scripts (benchmarks/micro, Zend/bench.php) so results are not overfitted.
#   usage: pgo-train.sh <instrumented php binary> <php source tree>
source "$(dirname "$0")/lib.sh"

php="$1"; src="$2"
train="$QHT_ROOT/benchmarks/training"

log "Training: Zend/micro_bench.php"
"$php" -n "$src/Zend/micro_bench.php" > /dev/null

for f in "$train"/*.php; do
  log "Training: $(basename "$f")"
  "$php" -n "$f" > /dev/null
done

# Broad language coverage from upstream PHPT tests (pass/fail does not matter
# here; the goal is to execute many code paths).
log "Training: PHPT subset"
TEST_PHP_EXECUTABLE="$php" "$php" -n "$src/run-tests.php" -q -j"$JOBS" \
  -d opcache.enable_cli=0 \
  "$src/Zend/tests" "$src/ext/standard/tests/array" "$src/ext/standard/tests/strings" \
  "$src/ext/json/tests" > /dev/null 2>&1 || true
