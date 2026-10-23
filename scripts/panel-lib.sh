# Shared by the control-panel integrations (aapanel.sh, directadmin.sh).
# Expects: log/warn/die, PHPV, TAG, PREFIX, SOCK, SERVICE, SYSTEMD,
# QHT_ROOT and QHT_LAB to be defined by the caller.
# shellcheck shell=bash

# Install the build dependencies. A package manager error (for example a
# conflict between an old library and a newer one from a third-party repo,
# common on CentOS 7) must not stop the build: what is already installed is
# often enough, and PHP's configure names exactly what is missing.
pkg_install() { # manager packages...
  local pm="$1"; shift
  "$pm" install -y "$@" && return 0
  warn "$pm could not install every package; retrying without the conflicting ones (--skip-broken)"
  "$pm" install -y --skip-broken "$@" && return 0
  warn "some build dependencies could not be installed; continuing (configure will report what is missing)"
}

install_deps() {
  if command -v apt-get > /dev/null; then
    log "installing build dependencies (apt)"
    DEBIAN_FRONTEND=noninteractive apt-get update -q || warn "apt-get update failed; continuing"
    DEBIAN_FRONTEND=noninteractive apt-get install -y -q git build-essential autoconf bison re2c pkg-config python3 \
      libxml2-dev libssl-dev libsqlite3-dev zlib1g-dev libcurl4-openssl-dev libzip-dev libonig-dev libicu-dev \
      libsodium-dev libpng-dev libjpeg-dev libfreetype-dev libgmp-dev libreadline-dev \
      || warn "some build dependencies could not be installed; continuing (configure will report what is missing)"
    return 0
  fi
  local pm rpms=(git gcc gcc-c++ make autoconf bison re2c python3 libxml2-devel openssl-devel
    sqlite-devel zlib-devel libcurl-devel libzip-devel oniguruma-devel libicu-devel libsodium-devel
    libpng-devel libjpeg-turbo-devel freetype-devel gmp-devel readline-devel)
  if command -v dnf > /dev/null; then
    pm=dnf
    log "installing build dependencies (dnf; needs EPEL + CRB/PowerTools)"
    dnf install -y epel-release || true
    dnf config-manager --set-enabled crb 2>/dev/null || dnf config-manager --set-enabled powertools 2>/dev/null || true
    rpms+=(pkgconf)
  elif command -v yum > /dev/null; then
    # CentOS 7 / older RHEL (end of life): same packages, EPEL only.
    pm=yum
    log "installing build dependencies (yum; needs EPEL)"
    yum install -y epel-release || true
    rpms+=(pkgconfig)
  else
    die "no apt-get, dnf or yum found (PATH=$PATH): install the build dependencies listed in docs/modes/compatible.md, then run again with SKIP_DEPS=1"
  fi
  # A newer libzip may already be installed under another name (libzip5 on
  # CentOS 7 from Remi, or built by aaPanel): do not ask for the old one.
  if pkg-config --atleast-version=0.11 libzip 2>/dev/null; then
    local keep=() r
    for r in "${rpms[@]}"; do [[ "$r" == libzip-devel ]] || keep+=("$r"); done
    rpms=("${keep[@]}")
  fi
  pkg_install "$pm" "${rpms[@]}"
}

# Build PHP $PHPV (full profile) and install it into $PREFIX; PHP-FPM's
# default pool runs as <user>:<group> (the web server's user).
build_and_install() { # user group
  [[ "${SKIP_DEPS:-0}" == 1 ]] || install_deps
  local mem_mb jobs
  mem_mb="$(free -m | awk '/Mem:/{print $2}')"
  # About 1.5 GB per compiler job (intl/ICU and the Zend VM are big).
  jobs=$(( mem_mb / 1500 )); (( jobs < 1 )) && jobs=1; (( jobs > $(nproc) )) && jobs=$(nproc)
  export JOBS="${JOBS:-$jobs}" QHT_PHP="$PHPV"
  mkdir -p "$QHT_LAB"
  log "building PHP $PHPV in $QHT_LAB with $JOBS job(s); this takes 10-40 minutes"
  "$QHT_ROOT/scripts/bootstrap.sh"
  if [[ "$PHPV" == 7.* || "$PHPV" == 8.0 ]]; then "$QHT_ROOT/scripts/deps-openssl11.sh"; fi
  QHT_PROFILE=full "$QHT_ROOT/scripts/build.sh" baseline
  "$QHT_ROOT/scripts/install.sh" --user="$1" --group="$2"
  # php-fpm -t opens the error log and checks the pid directory: create them
  # first (the systemd unit only creates them when the service starts).
  mkdir -p /run/qhturbo /var/log/qhturbo
}

start_service() {
  if (( SYSTEMD )); then
    cp "$PREFIX/share/qhturbo/systemd/$SERVICE.service" /etc/systemd/system/
    systemctl daemon-reload
    systemctl enable --now "$SERVICE"
    systemctl restart "$SERVICE"
  else
    warn "systemd not running: starting php-fpm directly (it will not start on boot)"
    mkdir -p /run/qhturbo /var/log/qhturbo
    [[ -f "/run/qhturbo/php$TAG-fpm.pid" ]] && kill "$(cat "/run/qhturbo/php$TAG-fpm.pid")" 2>/dev/null && sleep 1
    "$PREFIX/sbin/php-fpm" --fpm-config "$PREFIX/etc/php-fpm.conf"
  fi
  for _ in $(seq 20); do [[ -S "$SOCK" ]] && break; sleep 0.5; done
  [[ -S "$SOCK" ]] || die "php-fpm did not create $SOCK; see /var/log/qhturbo/php$TAG-fpm.log"
  log "PHP-FPM running: $SOCK"
}

# PHP-FPM really running (a socket file alone may be left over from a crash).
fpm_alive() { # tag
  local pid
  pid="$(cat "/run/qhturbo/php$1-fpm.pid" 2>/dev/null || true)"
  [[ -S "/run/qhturbo/php$1-fpm.sock" && -n "$pid" ]] && kill -0 "$pid" 2>/dev/null
}

# Graceful reload (new pools, changed settings); true if FPM was running.
fpm_reload() { # tag
  local pid
  pid="$(cat "/run/qhturbo/php$1-fpm.pid" 2>/dev/null || true)"
  [[ -n "$pid" ]] && kill -USR2 "$pid" 2>/dev/null
}

ext_list() { # php binary -> extension names as install-ext.sh takes them
  "$1" -m 2>/dev/null | grep -v '^\[' | grep -v '^$' | tr 'A-Z' 'a-z' | grep -vE 'warning|on line [0-9]' \
    | sed -E 's/^.*ioncube.*$/ioncube/; s/^zend opcache$/opcache/' | sort -u
}

# CentOS 7 / RHEL 7 (end of life since 2024): old compiler and libraries.
warn_el7() {
  if grep -qE 'release 7\.' /etc/redhat-release 2>/dev/null; then
    warn "$(cat /etc/redhat-release): end of life. PHP 7.4-8.2 usually build here; PHP 8.3+ may need libraries newer than the system has (OpenSSL, ICU, libzip). If the build stops at 'configure', send the error."
  fi
}
