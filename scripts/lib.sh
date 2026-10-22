# shellcheck shell=bash disable=SC2034
# Shared helpers for QHturbo scripts. Source this file; do not execute it.
set -euo pipefail

QHT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# Heavy artefacts (php-src clone, worktrees, binaries) live in QHT_LAB.
# On Windows/WSL point it at the Linux filesystem, e.g. QHT_LAB=~/QHTurbo-lab,
# because builds and benchmarks on /mnt/d are slow and noisy.
QHT_LAB="${QHT_LAB:-$QHT_ROOT}"
# PHP branch to work on: 7.4, 8.0 ... 8.5 (see config/versions/).
QHT_PHP="${QHT_PHP:-8.5}"
SUPPORTED_PHP=(7.4 8.0 8.1 8.2 8.3 8.4 8.5)
[[ -f "$QHT_ROOT/config/versions/$QHT_PHP.env" ]] \
  || { echo "[qht] ERROR: unsupported QHT_PHP=$QHT_PHP (supported: ${SUPPORTED_PHP[*]})" >&2; exit 1; }

PHP_SRC="$QHT_LAB/php-src"
WORKTREES="$QHT_LAB/worktrees/$QHT_PHP"
BUILD_DIR="$QHT_LAB/build/$QHT_PHP"
PATCH_DIR="$QHT_ROOT/patches/$QHT_PHP"
RESULTS_DIR="$QHT_ROOT/results"
JOBS="${JOBS:-$(nproc)}"

# shellcheck source=../config/versions/8.5.env
source "$QHT_ROOT/config/versions/$QHT_PHP.env"
# shellcheck source=../config/build.env
source "$QHT_ROOT/config/build.env"

VARIANTS=(baseline qhturbo qhturbo-pgo)

log()  { printf '\033[1;34m[qht]\033[0m %s\n' "$*" >&2; }
die()  { printf '\033[1;31m[qht] ERROR:\033[0m %s\n' "$*" >&2; exit 1; }

is_variant() {
  local v
  for v in "${VARIANTS[@]}"; do [[ "$v" == "$1" ]] && return 0; done
  return 1
}

# Variants whose name starts with "qhturbo" get the patch series applied.
uses_patches() { [[ "$1" == qhturbo* ]]; }

# Ordered list of patch files (patches/series, falling back to sorted *.patch).
patch_list() {
  local dir="$PATCH_DIR"
  [[ -d "$dir" ]] || return 0
  if [[ -f "$dir/series" ]]; then
    grep -v '^\s*\(#\|$\)' "$dir/series" | sed "s|^|$dir/|"
  else
    find "$dir" -maxdepth 1 -name '*.patch' | sort
  fi
}

# Hash identifying the exact source of a variant (upstream commit + patches).
source_stamp() {
  local variant="$1"
  {
    echo "$PHP_COMMIT"
    if uses_patches "$variant"; then
      local p
      while read -r p; do [[ -n "$p" ]] && sha256sum "$p" | cut -d' ' -f1; done < <(patch_list)
    fi
  } | sha256sum | cut -d' ' -f1
}

php_bin() { echo "$BUILD_DIR/$1/bin/php"; }

# Fills the OPCACHE_ARGS array with the "-d" arguments that load OPcache for
# a built variant. PHP < 8.5 builds OPcache as a shared zend_extension; 8.5+
# links it statically.
opcache_args() {
  local so="$BUILD_DIR/$1/lib/opcache.so"
  OPCACHE_ARGS=()
  if [[ -f "$so" ]]; then OPCACHE_ARGS=(-d "zend_extension=$so"); fi
}

# JIT exists since PHP 8.0.
has_jit() { [[ "$QHT_PHP" != 7.* ]]; }

warn_if_eol() {
  if [[ "$PHP_SUPPORT" == eol ]]; then
    printf '\033[1;33m[qht] WARNING:\033[0m PHP %s is end-of-life upstream (no security fixes). Use it only for legacy apps, behind staging and with a migration plan.\n' "$QHT_PHP" >&2
  fi
}

machine_info() {
  echo "- Date: $(date -u '+%Y-%m-%d %H:%M UTC')"
  echo "- CPU: $(grep -m1 'model name' /proc/cpuinfo | cut -d: -f2 | sed 's/^ *//') ($(nproc) vCPU)"
  echo "- RAM: $(free -g | awk '/Mem:/{print $2}') GiB"
  echo "- OS: $(. /etc/os-release && echo "$PRETTY_NAME"), kernel $(uname -r)"
  echo "- Compiler: $(${CC:-gcc} --version | head -1)"
  echo "- Upstream: $PHP_TAG ($PHP_COMMIT, support: $PHP_SUPPORT)"
  echo "- QHturbo repo: $(git -C "$QHT_ROOT" rev-parse --short HEAD 2>/dev/null || echo uncommitted)"
}
