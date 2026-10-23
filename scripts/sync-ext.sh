#!/usr/bin/env bash
# Give a QHturbo PHP the extensions another PHP on the same server has
# (aaPanel, DirectAdmin, cPanel, distro packages...), automatically.
#
#   sudo scripts/sync-ext.sh --from=/www/server/php/74/bin/php [--php=7.4] [--dry-run] [--no-build]
#
# For every extension the source PHP loads and QHturbo lacks:
#   1. same PHP version (same extension API): reuse the source's own .so
#      file (copied into QHturbo's extension directory) if it loads cleanly;
#   2. otherwise build it with scripts/install-ext.sh (PECL, the PHP source,
#      or the ionCube Loader).
# Extensions compiled into the source PHP (no .so) are built the same way.
# Nothing is changed in the source PHP. PHP-FPM is reloaded at the end.
set -euo pipefail
# Control panels may start us with a minimal PATH (aaPanel's panel process):
# make sure the system tools (dnf/apt-get, git, gcc, systemctl) are found.
export PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin${PATH:+:$PATH}"

log()  { printf '\033[1;34m[qhturbo-sync-ext]\033[0m %s\n' "$*" >&2; }
warn() { printf '\033[1;33m[qhturbo-sync-ext] WARNING:\033[0m %s\n' "$*" >&2; }
die()  { printf '\033[1;31m[qhturbo-sync-ext] ERROR:\033[0m %s\n' "$*" >&2; exit 1; }

QHT_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
from="" phpv="" dry=0 build=1
for a in "$@"; do
  case "$a" in
    --from=*) from="${a#*=}" ;;
    --php=*) phpv="${a#*=}" ;;
    --dry-run) dry=1 ;;
    --no-build) build=0 ;;
    *) die "unknown option $a (usage: $0 --from=/path/to/php [--php=X.Y] [--dry-run] [--no-build])" ;;
  esac
done
[[ -x "$from" ]] || die "source PHP not found: --from=$from"

q() { "$@" -d display_startup_errors=0 -d display_errors=0; }  # PHP without startup noise
info() { q "$1" -r 'echo PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION, " ", PHP_ZTS, PHP_DEBUG;' 2> /dev/null; }
read -r src_ver src_abi <<< "$(info "$from")"
[[ -n "${src_ver:-}" ]] || die "$from does not run"
phpv="${phpv:-$src_ver}"
prefix="/opt/qhturbo/$phpv"
php="$prefix/bin/php"
[[ -x "$php" ]] || die "QHturbo PHP $phpv is not installed ($prefix); install it first or pass --php="
read -r tgt_ver tgt_abi <<< "$(info "$php")"
same_abi=0
[[ "$src_ver" == "$tgt_ver" && "$src_abi" == "$tgt_abi" ]] && same_abi=1
extdir="$(q "$php" -r 'echo ini_get("extension_dir");')"

# Module names as install-ext.sh takes them ("ionCube Loader" -> ioncube).
modules() {
  local out; out="$(q "$@" -m 2> /dev/null || true)"
  grep -v '^\[' <<< "$out" | grep -v '^$' | tr 'A-Z' 'a-z' | grep -vE 'warning|on line [0-9]' \
    | sed -E 's/^.*ioncube.*$/ioncube/; s/^zend opcache$/opcache/' | sort -u
}
# "extension|zend_extension <absolute .so path>" for every extension line in
# the source's php.ini files.
src_files() {
  q "$from" -r '
    $dir = ini_get("extension_dir");
    $files = array_filter(array_map("trim", array_merge([(string) php_ini_loaded_file()], explode(",", (string) php_ini_scanned_files()))));
    foreach ($files as $f) {
        foreach (@file($f) ?: [] as $line) {
            if (preg_match("/^\s*(zend_extension|extension)\s*=\s*\"?([^\";\s]+)\"?/i", $line, $m)) {
                $p = $m[2];
                if ($p[0] !== "/") { $p = "$dir/$p"; if (!preg_match("/\.so$/", $p)) { $p .= ".so"; } }
                echo strtolower($m[1]), " ", $p, "\n";
            }
        }
    }' 2> /dev/null || true
}
# Guess the module name from a file name: redis.so -> redis,
# ioncube_loader_lin_7.4.so -> ioncube, php_xdebug-3.1.so -> xdebug.
so_name() {
  local b; b="$(basename "$1" .so)"; b="${b#php_}"; b="${b%%-[0-9]*}"
  [[ "$b" == ioncube* ]] && b=ioncube
  echo "$b" | tr 'A-Z' 'a-z'
}

mapfile -t missing < <(comm -23 <(modules "$from") <(modules "$php"))
if (( ${#missing[@]} == 0 )); then
  log "QHturbo PHP $phpv already has every extension of $from (PHP $src_ver)"
  exit 0
fi
log "source: $from (PHP $src_ver); target: $php (PHP $tgt_ver)"
log "missing in QHturbo: ${missing[*]}"
(( same_abi )) || log "different PHP version or build type: the source's .so files cannot be reused, extensions will be built"

declare -A so_of=() type_of=()
while read -r type path; do
  [[ -f "$path" ]] || continue
  so_of[$(so_name "$path")]="$path"; type_of[$(so_name "$path")]="$type"
done < <(src_files)

reused=() built=() failed=() pending=()
for ext in "${missing[@]}"; do
  if (( same_abi )) && [[ -n "${so_of[$ext]:-}" ]]; then pending+=("$ext"); fi
done

# 1. Reuse the source's .so files. Several passes: an extension that needs
# another one (redis -> igbinary) loads once its dependency is enabled; each
# pass gets a later ini prefix so the load order is kept.
if (( dry )); then
  for ext in "${pending[@]}"; do log "would try to reuse ${so_of[$ext]}"; done
else
  # Startup warnings the target already prints (they are not the new .so's fault).
  base="$(q "$php" -d display_startup_errors=1 -m 2>&1 || true)"
  for pass in 0 1 2; do
    next=()
    for ext in "${pending[@]}"; do
      src_so="${so_of[$ext]}" type="${type_of[$ext]}"
      file="$(basename "$src_so")"
      if [[ "$type" == zend_extension ]]; then ini="$prefix/etc/conf.d/0$pass-$ext.ini"; else ini="$prefix/etc/conf.d/3$pass-$ext.ini"; fi
      out="$(q "$php" -d "display_startup_errors=1" -d "$type=$src_so" -m 2>&1 || true)"
      new="$(grep -vxFf <(printf '%s\n' "$base") <<< "$out" || true)"
      if grep -qiE 'warning|error|cannot|undefined symbol' <<< "$new" || ! grep -qi -- "$ext" <<< "$new"; then
        next+=("$ext"); continue
      fi
      install -m 755 "$src_so" "$extdir/$file"
      echo "; Reused from $src_so by scripts/sync-ext.sh" > "$ini"
      echo "$type=$file" >> "$ini"
      reused+=("$ext")
      log "reused $ext ($src_so)"
    done
    (( ${#next[@]} == ${#pending[@]} )) && break
    pending=("${next[@]}")
    (( ${#pending[@]} )) || break
  done
fi

# 2. Build everything else.
for ext in "${missing[@]}"; do
  [[ " ${reused[*]} " == *" $ext "* ]] && continue
  if (( dry )); then log "would build $ext"; continue; fi
  if (( ! build )); then failed+=("$ext"); continue; fi
  case "$ext" in
    opcache|mysqlnd|core|standard) continue ;;  # always present or compiled in
  esac
  log "building $ext"
  if QHT_LAB="${QHT_LAB:-/opt/qhturbo-lab}" "$QHT_ROOT/scripts/install-ext.sh" "$ext" --php="$phpv"; then
    built+=("$ext")
  else
    failed+=("$ext")
  fi
done

(( dry )) && exit 0
if [[ -f "/run/qhturbo/php${phpv/./}-fpm.pid" ]] && kill -USR2 "$(cat "/run/qhturbo/php${phpv/./}-fpm.pid")" 2> /dev/null; then
  log "PHP-FPM $phpv reloaded"
fi
log "reused: ${reused[*]:-none}"
log "built:  ${built[*]:-none}"
still="$(comm -23 <(modules "$from") <(modules "$php") | tr '\n' ' ')"
if [[ -n "${still// /}" ]]; then
  warn "still missing: $still"
  warn "build them one by one to see the error: sudo $QHT_ROOT/scripts/install-ext.sh <name> --php=$phpv"
  exit 1
fi
log "QHturbo PHP $phpv now has every extension of $from"
