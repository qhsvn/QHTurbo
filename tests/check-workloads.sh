#!/usr/bin/env bash
# Verify every workload prints the same output in PHP, Python, Node.js and Go
# (quick sizes). Usage: tests/check-workloads.sh [php binary]
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PHP="${1:-php}"
fail=0
for dir in "$ROOT"/benchmarks/workloads/*/; do
  wl="$(basename "$dir")"
  mapfile -t args < <(python3 -c "import json,sys; print('\n'.join(json.load(open('$ROOT/benchmarks/workloads.json'))['$wl']['quick']))")
  # No -n: distro builds (e.g. PHP 7.4 on Ubuntu) load json as a shared
  # extension from php.ini.
  ref="$("$PHP" "$dir/main.php" "${args[@]}")"
  for runner in "python3:py" "node:js" "go run:go"; do
    cmd="${runner%%:*}"; ext="${runner##*:}"
    command -v "${cmd%% *}" >/dev/null || continue
    got="$($cmd "$dir/main.$ext" "${args[@]}")"
    if [[ "$got" != "$ref" ]]; then
      echo "MISMATCH $wl ($cmd): php='$ref' got='$got'"; fail=1
    fi
  done
  echo "ok $wl"
done
exit $fail
