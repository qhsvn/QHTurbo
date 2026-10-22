#!/usr/bin/env bash
# Build and enable a PECL extension for an installed QHturbo PHP.
#   sudo scripts/install-ext.sh redis [--php=8.5] [--version=6.1.0]
#   sudo scripts/install-ext.sh imagick --php=7.4
#   sudo scripts/install-ext.sh ioncube --php=7.4   (ionCube Loader, binary from ioncube.com)
# Source: pecl.php.net; falls back to the extension's GitHub repository
# when PECL is unreachable. Libraries an extension needs (ImageMagick,
# libmemcached...) are installed with apt or dnf first. Writes
# <prefix>/etc/conf.d/30-<ext>.ini and reloads PHP-FPM.
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
tag="${phpv/./}"
reload_fpm() {
  if [[ -f "/run/qhturbo/php$tag-fpm.pid" ]]; then
    kill -USR2 "$(cat "/run/qhturbo/php$tag-fpm.pid")" && log "PHP-FPM $phpv reloaded"
  fi
}
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

# ionCube Loader: a closed-source binary, one file per PHP version.
if [[ "$ext" == ioncube ]]; then
  case "$(uname -m)" in
    x86_64) arch=x86-64 ;;
    aarch64) arch=aarch64 ;;
    *) die "no ionCube Loader for $(uname -m)" ;;
  esac
  url="https://downloads.ioncube.com/loader_downloads/ioncube_loaders_lin_$arch.tar.gz"
  log "downloading $url"
  curl -fsSL --max-time 120 -o "$work/ioncube.tgz" "$url" && tar -xzf "$work/ioncube.tgz" -C "$work" \
    || die "cannot download the ionCube Loader"
  so="ioncube_loader_lin_$phpv.so"   # QHturbo builds are non-thread-safe (no _ts)
  if [[ ! -f "$work/ioncube/$so" ]]; then
    avail=""
    for f in "$work"/ioncube/ioncube_loader_lin_*.so; do
      f="${f##*_lin_}"; [[ "$f" == *_ts.so ]] || avail+="${f%.so} "
    done
    die "ionCube has no loader for PHP $phpv yet (available for: $avail)"
  fi
  extdir="$("$prefix/bin/php-config" --extension-dir)"
  install -m 755 "$work/ioncube/$so" "$extdir/$so"
  # Must be the first zend_extension (before OPcache): 00- sorts first.
  echo "zend_extension=$so" > "$prefix/etc/conf.d/00-ioncube.ini"
  "$prefix/bin/php" -v 2>&1 | grep -qi 'ionCube' || die "ionCube Loader installed but not loaded; see $prefix/etc/conf.d/00-ioncube.ini"
  log "ionCube Loader enabled for PHP $phpv ($prefix/etc/conf.d/00-ioncube.ini)"
  reload_fpm
  exit 0
fi

# Libraries the common extensions build against.
declare -A APT_DEPS=(
  [imagick]=libmagickwand-dev [memcached]="libmemcached-dev zlib1g-dev" [mcrypt]=libmcrypt-dev
  [zstd]=libzstd-dev [zmq]=libzmq3-dev [mongodb]="libssl-dev" [yaml]=libyaml-dev [gnupg]=libgpgme-dev
)
declare -A DNF_DEPS=(
  [imagick]=ImageMagick-devel [memcached]="libmemcached-awesome-devel|libmemcached-devel zlib-devel" [mcrypt]=libmcrypt-devel
  [zstd]=libzstd-devel [zmq]=zeromq-devel [mongodb]="openssl-devel" [yaml]=libyaml-devel [gnupg]=gpgme-devel
)
if [[ "${SKIP_DEPS:-0}" != 1 ]]; then
  if command -v apt-get > /dev/null && [[ -n "${APT_DEPS[$ext]:-}" ]]; then
    log "installing build dependencies: ${APT_DEPS[$ext]}"
    # shellcheck disable=SC2086
    DEBIAN_FRONTEND=noninteractive apt-get install -y -q ${APT_DEPS[$ext]} > /dev/null || log "could not install ${APT_DEPS[$ext]}; trying the build anyway"
  elif command -v dnf > /dev/null && [[ -n "${DNF_DEPS[$ext]:-}" ]]; then
    for pkgs in ${DNF_DEPS[$ext]}; do
      # "a|b": the package was renamed between releases; try each name.
      ok=0
      for p in ${pkgs//|/ }; do dnf install -y -q "$p" > /dev/null 2>&1 && { ok=1; break; }; done
      (( ok )) && log "installed ${pkgs//|/ or }" || log "could not install ${pkgs//|/ or } (EPEL enabled?); trying the build anyway"
    done
  fi
fi

# GitHub mirrors of common PECL extensions (used when PECL is unreachable).
declare -A GITHUB=(
  [redis]=phpredis/phpredis [igbinary]=igbinary/igbinary [apcu]=krakjoe/apcu
  [imagick]=Imagick/imagick [msgpack]=msgpack/msgpack-php [mongodb]=mongodb/mongo-php-driver
  [swoole]=swoole/swoole-src [xdebug]=xdebug/xdebug [memcached]=php-memcached-dev/php-memcached
  [zstd]=kjdev/php-ext-zstd [mcrypt]=php/pecl-encryption-mcrypt
)

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
    tag="$(grep -xE "v?$version" <<< "$tags" | head -1 || true)"
  else
    tag="$(grep -E '^v?[0-9]+\.[0-9]+\.[0-9]+$' <<< "$tags" | sort -V | tail -1 || true)"
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

reload_fpm
[[ "$ext" == xdebug ]] && printf '\033[1;33m[qhturbo-ext] WARNING:\033[0m Xdebug slows every request; never enable it in production.\n' >&2
exit 0
