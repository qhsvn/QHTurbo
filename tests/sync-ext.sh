#!/usr/bin/env bash
# Test scripts/sync-ext.sh against a distro PHP of the same version (reuse
# of its .so files) and of another version (build from source).
# Needs root, a QHturbo PHP of that version and a distro PHP with extra
# extensions, e.g. Ubuntu 24.04: apt install php8.3-cli php8.3-redis php8.3-pgsql php8.3-xsl
#   sudo QHT_LAB=~/QHTurbo-lab tests/sync-ext.sh [--from=/usr/bin/php8.3] [--php=8.3]
set -euo pipefail
from=/usr/bin/php8.3 phpv=8.3
for a in "$@"; do
  case "$a" in
    --from=*) from="${a#*=}" ;;
    --php=*) phpv="${a#*=}" ;;
  esac
done
QHT_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
S="$QHT_ROOT/scripts/sync-ext.sh"
conf="/opt/qhturbo/$phpv/etc/conf.d"
[[ -x "$from" && -d "$conf" ]] || { echo "need $from and /opt/qhturbo/$phpv"; exit 1; }
pass=0 fail=0
ok()  { pass=$((pass + 1)); printf '  ok    %s\n' "$*"; }
bad() { fail=$((fail + 1)); printf '  FAIL  %s\n' "$*"; }
mods() { "$1" -d display_startup_errors=0 -m 2> /dev/null | grep -v '^\[' | grep -v '^$' | tr 'A-Z' 'a-z' | sort -u; }

# Start from a clean target: remove what earlier runs added (30-*.ini of extensions the target lacks).
backup="$(mktemp -d)"
cp -a "$conf/." "$backup/"
trap 'rm -rf "${conf:?}"/*; cp -a "$backup/." "$conf/"; rm -rf "$backup"' EXIT
find "$conf" -name '[03][0-9]-*.ini' ! -name '10-qhturbo-compatible.ini' ! -name '05-from-aapanel.ini' -delete

before="$(comm -23 <(mods "$from") <(mods "/opt/qhturbo/$phpv/bin/php") | tr '\n' ' ')"
echo "missing before: $before"
[[ -n "${before// /}" ]] && ok "the source has extensions QHturbo lacks" || bad "nothing to test: install more extensions in $from"

out="$("$S" --from="$from" --php="$phpv" --dry-run 2>&1)" || true
after_dry="$(comm -23 <(mods "$from") <(mods "/opt/qhturbo/$phpv/bin/php") | tr '\n' ' ')"
[[ "$after_dry" == "$before" ]] && ok "--dry-run changes nothing" || bad "--dry-run changed: $after_dry"

out="$("$S" --from="$from" --php="$phpv" 2>&1)" && ok "sync-ext succeeds" || bad "sync-ext: $out"
grep -q 'reused ' <<< "$out" && ok "same version: .so files reused ($(grep -oE 'reused: .*' <<< "$out"))" || bad "nothing reused: $out"
left="$(comm -23 <(mods "$from") <(mods "/opt/qhturbo/$phpv/bin/php") | tr '\n' ' ')"
[[ -z "${left// /}" ]] && ok "no extension missing afterwards" || bad "still missing: $left"
warn="$("/opt/qhturbo/$phpv/bin/php" -d display_startup_errors=1 -m 2>&1 | grep -i warning || true)"
[[ -z "$warn" ]] && ok "QHturbo PHP starts without warnings" || bad "startup warnings: $warn"
out="$("$S" --from="$from" --php="$phpv" 2>&1)" || true
grep -q 'already has every extension' <<< "$out" && ok "second run is a no-op" || bad "second run: $out"

echo
echo "$pass passed, $fail failed"
exit $(( fail > 0 ))
