#!/usr/bin/env bash
# Build and enable a PECL extension for an installed QHturbo PHP.
#   sudo scripts/install-ext.sh redis [--php=8.5] [--version=6.1.0]
#   sudo scripts/install-ext.sh imagick      (needs libmagickwand-dev first)
# Source: pecl.php.net; falls back to the extension's GitHub repository
# when PECL is unreachable. Writes <prefix>/etc/conf.d/30-<ext>.ini and
# reloads PHP-FPM.
set -euo pipefail

log() { printf '\033[1;34m[qhturbo-ext]\033[0m %s\n' "$*" >&2; }
die() { printf '\033[1;31m[qhturbo-ext] ERROR:\033[0m %s\n' "$*" >&2; exit 1; }

ext="${1:-}"; shift || true
[[ "$ext" =~ ^[a-z0-9_]+$ ]] || die "usage: $0 <extension> [--php=8.5] [--version=X.Y.Z]"
phpv=8.5 version=""
for a in "$@"; do
  case "$a" in
    --php=*) phpv="${a#*=}" ;;
    --version=*) version="${a#*=}" ;;
    *) die "unknown option $a" ;;
  esac
done
prefix="/opt/qhturbo/$phpv"
[[ -x "$prefix/bin/phpize" ]] || die "QHturbo PHP $phpv not installed in $prefix"

# GitHub mirrors of common PECL extensions (used when PECL is unreachable).
declare -A GITHUB=(
  [redis]=phpredis/phpredis [igbinary]=igbinary/igbinary [apcu]=krakjoe/apcu
  [imagick]=Imagick/imagick [msgpack]=msgpack/msgpack-php [mongodb]=mongodb/mongo-php-driver
  [swoole]=swoole/swoole-src [xdebug]=xdebug/xdebug [memcached]=php-memcached-dev/php-memcached
)

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
src=""
url="https://pecl.php.net/get/$ext${version:+-$version}"
log "downloading $url"
if curl -fsSL --max-time 60 -o "$work/ext.tgz" "$url" && tar -xzf "$work/ext.tgz" -C "$work"; then
  src="$(find "$work" -mindepth 1 -maxdepth 1 -type d -name "$ext-*" | head -1)"
elif [[ -n "${GITHUB[$ext]:-}" ]]; then
  repo="https://github.com/${GITHUB[$ext]}.git"
  # Latest stable release tag (no alpha/beta/RC), or the requested version.
  tags="$(git ls-remote --tags --refs "$repo" | awk -F/ '{print $3}')"
  if [[ -n "$version" ]]; then
    tag="$(grep -xE "v?$version" <<< "$tags" | head -1)"
  else
    tag="$(grep -E '^v?[0-9]+\.[0-9]+\.[0-9]+$' <<< "$tags" | sort -V | tail -1)"
  fi
  [[ -n "$tag" ]] || die "no matching release tag in $repo"
  log "PECL unreachable; cloning $repo at $tag"
  git -c advice.detachedHead=false clone -q --depth 1 --branch "$tag" --recurse-submodules "$repo" "$work/src" \
    || die "cannot download $ext"
  src="$work/src"
else
  die "cannot download $ext from PECL and no GitHub mirror is known"
fi
[[ -n "$src" && -f "$src/config.m4" ]] || die "no config.m4 in the $ext sources"

log "building $ext for PHP $("$prefix/bin/php" -r 'echo PHP_VERSION;')"
(cd "$src" && "$prefix/bin/phpize" > "$work/build.log" 2>&1 \
  && ./configure --with-php-config="$prefix/bin/php-config" >> "$work/build.log" 2>&1 \
  && make -j"$(nproc)" >> "$work/build.log" 2>&1 \
  && make install >> "$work/build.log" 2>&1) \
  || { tail -30 "$work/build.log" >&2; die "build of $ext failed (missing -dev library?)"; }

ini="$prefix/etc/conf.d/30-$ext.ini"
case "$ext" in
  xdebug|opcache) echo "zend_extension=$ext" > "$ini" ;;
  *) echo "extension=$ext" > "$ini" ;;
esac
"$prefix/bin/php" -m | grep -qix "$ext" || die "$ext built but does not load; see $ini"
log "$ext enabled ($ini)"

tag="${phpv/./}"
if [[ -f "/run/qhturbo/php$tag-fpm.pid" ]]; then
  kill -USR2 "$(cat "/run/qhturbo/php$tag-fpm.pid")" && log "PHP-FPM $phpv reloaded"
fi
[[ "$ext" == xdebug ]] && printf '\033[1;33m[qhturbo-ext] WARNING:\033[0m Xdebug slows every request; never enable it in production.\n' >&2
exit 0
