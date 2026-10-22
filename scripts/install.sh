#!/usr/bin/env bash
# Install a QHT_PROFILE=full build into its prefix (default /opt/qhturbo/<php>)
# with the configuration for both runtime modes:
#   Compatible   php.ini + conf.d + PHP-FPM pool + systemd unit
#   Performance  performance.d overrides + qhturbo-runtime + worker unit
#
#   QHT_PHP=8.5 scripts/install.sh [--variant=baseline] [--destdir=DIR]
#                                  [--user=www-data] [--group=www-data]
#
# --destdir stages the tree under DIR (for packaging, see scripts/package.sh).
# Existing configuration files are never overwritten: new versions are
# written next to them as *.qhturbo-new.
export QHT_PROFILE=full
source "$(dirname "$0")/lib.sh"

variant=baseline destdir="" user=www-data group=www-data
for arg in "$@"; do
  case "$arg" in
    --variant=*) variant="${arg#*=}" ;;
    --destdir=*) destdir="${arg#*=}" ;;
    --user=*)    user="${arg#*=}" ;;
    --group=*)   group="${arg#*=}" ;;
    *) die "unknown argument $arg" ;;
  esac
done
is_variant "$variant" || die "unknown variant $variant"
wt="$WORKTREES/$variant$PROFILE_TAG"
[[ -x "$wt/sapi/fpm/php-fpm" ]] || die "No full build in $wt; run QHT_PHP=$QHT_PHP QHT_PROFILE=full scripts/build.sh $variant"

case "$QHT_PREFIX" in
  /usr|/usr/*|/www/server/php*|/etc|/etc/*) die "Refusing prefix $QHT_PREFIX: QHturbo installs beside the system/aaPanel PHP, never over it" ;;
esac
root="$destdir$QHT_PREFIX"
phptag="${QHT_PHP/./}"
warn_if_eol

log "make install -> $root"
make -C "$wt" install INSTALL_ROOT="$destdir" > "$WORKTREES/.meta-$variant$PROFILE_TAG/install.log" 2>&1 \
  || die "make install failed, see $WORKTREES/.meta-$variant$PROFILE_TAG/install.log"

# Values that differ per PHP branch.
if [[ "$QHT_PHP" == 8.5 ]]; then opcache_ext="; OPcache is built in (PHP >= 8.5)"; else opcache_ext="zend_extension=opcache"; fi
if has_jit; then
  jit_off="opcache.jit=disable"
  jit_on=$'opcache.jit=tracing\nopcache.jit_buffer_size=128M'
else
  jit_off="; PHP $QHT_PHP has no JIT"
  jit_on="; PHP $QHT_PHP has no JIT"
fi
cpus="$(nproc)"

# render <template> <destination> [force]
# Configuration in etc/ is never overwritten (the admin may have edited it);
# "force" is for reference copies in share/ that must follow the template.
render() {
  local tpl="$1" dest="$2" force="${3:-}" content
  content="$(cat "$tpl")"
  content="${content//@PREFIX@/$QHT_PREFIX}"
  content="${content//@PHPVER@/$QHT_PHP}"
  content="${content//@PHPTAG@/$phptag}"
  content="${content//@USER@/$user}"
  content="${content//@GROUP@/$group}"
  content="${content//@OPCACHE_EXTENSION@/$opcache_ext}"
  content="${content//@JIT_OFF@/$jit_off}"
  content="${content//@JIT_ON@/$jit_on}"
  content="${content//@MAX_CHILDREN@/$((cpus * 5))}"
  content="${content//@START_SERVERS@/$cpus}"
  content="${content//@MIN_SPARE@/$cpus}"
  content="${content//@MAX_SPARE@/$((cpus * 2))}"
  mkdir -p "$(dirname "$dest")"
  if [[ -z "$force" && -e "$dest" ]] && [[ "$(cat "$dest")" != "$content" ]]; then
    printf '%s\n' "$content" > "$dest.qhturbo-new"
    log "kept existing $dest (new version: $dest.qhturbo-new)"
  else
    printf '%s\n' "$content" > "$dest"
  fi
}

modes="$QHT_ROOT/config/modes"
# Base php.ini = upstream production defaults; QHturbo settings go to conf.d.
if [[ ! -e "$root/etc/php.ini" ]]; then
  cp "$wt/php.ini-production" "$root/etc/php.ini"
fi
render "$modes/compatible/conf.d/10-qhturbo-compatible.ini" "$root/etc/conf.d/10-qhturbo-compatible.ini"
render "$modes/compatible/php-fpm.conf" "$root/etc/php-fpm.conf"
render "$modes/compatible/php-fpm.d/www.conf" "$root/etc/php-fpm.d/www.conf"
render "$modes/compatible/systemd/qhturbo-php-fpm.service" "$root/share/qhturbo/systemd/qhturbo-php$phptag-fpm.service" force
performance=1
[[ "$QHT_PHP" == 7.* || "$QHT_PHP" == 8.0 ]] && performance=0
if (( performance )); then
  render "$modes/performance/performance.d/20-qhturbo-performance.ini" "$root/etc/performance.d/20-qhturbo-performance.ini"
  render "$modes/performance/systemd/qhturbo-worker@.service" "$root/share/qhturbo/systemd/qhturbo-php$phptag-worker@.service" force
  cp "$modes/performance/worker.env.example" "$root/share/qhturbo/worker.env.example"
fi
cp "$QHT_ROOT/scripts/qht-doctor.php" "$QHT_ROOT/scripts/fpm-tune.py" "$root/share/qhturbo/"
# The default php-fpm.conf.default/www.conf.default from make install stay
# as references; FPM only reads php-fpm.conf.

if (( ! performance )); then
  log "Performance mode skipped: needs PHP >= 8.1 (Fibers); Compatible mode only"
else
  rm -rf "$root/share/qhturbo-runtime"
  mkdir -p "$root/share/qhturbo-runtime"
  cp -r "$QHT_ROOT/runtime/bin" "$QHT_ROOT/runtime/src" "$QHT_ROOT/runtime/composer.json" "$root/share/qhturbo-runtime/"
fi
{
  echo "qhturbo_rev=$(git -C "$QHT_ROOT" rev-parse --short HEAD 2>/dev/null || echo unknown)"
  cat "$BUILD_DIR/$variant$PROFILE_TAG/BUILD-INFO.txt" 2>/dev/null
} > "$root/share/qhturbo/BUILD-INFO.txt"

# Directories php-fpm needs for its pid file, socket and error log (the
# systemd unit creates them too, but "php-fpm -t" needs them first).
if [[ -z "$destdir" && $EUID -eq 0 ]]; then
  mkdir -p /run/qhturbo /var/log/qhturbo
fi

log "Installed PHP $QHT_PHP into $root"
cat >&2 <<MSG

Compatible mode (PHP-FPM), beside the existing PHP:
  sudo cp $QHT_PREFIX/share/qhturbo/systemd/qhturbo-php$phptag-fpm.service /etc/systemd/system/
  sudo systemctl daemon-reload && sudo systemctl enable --now qhturbo-php$phptag-fpm
  nginx vhost:  fastcgi_pass unix:/run/qhturbo/php$phptag-fpm.sock;
MSG
if (( performance )); then
  cat >&2 <<MSG
Performance mode (long-lived workers):
  sudo mkdir -p /etc/qhturbo && sudo cp $QHT_PREFIX/share/qhturbo/worker.env.example /etc/qhturbo/worker-myapp.env
  sudo cp $QHT_PREFIX/share/qhturbo/systemd/qhturbo-php$phptag-worker@.service /etc/systemd/system/
  sudo systemctl daemon-reload && sudo systemctl enable --now qhturbo-php$phptag-worker@myapp
  nginx vhost:  proxy_pass http://127.0.0.1:8080;
MSG
fi
cat >&2 <<MSG

Check the setup:  $QHT_PREFIX/bin/php $QHT_PREFIX/share/qhturbo/qht-doctor.php
MSG
