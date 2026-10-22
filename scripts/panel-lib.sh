# Shared by the control-panel integrations (aapanel.sh, directadmin.sh).
# Expects: log/warn/die, PHPV, TAG, PREFIX, SOCK, SERVICE, SYSTEMD,
# QHT_ROOT and QHT_LAB to be defined by the caller.
# shellcheck shell=bash

install_deps() {
  if command -v apt-get > /dev/null; then
    log "installing build dependencies (apt)"
    DEBIAN_FRONTEND=noninteractive apt-get update -q
    DEBIAN_FRONTEND=noninteractive apt-get install -y -q git build-essential autoconf bison re2c pkg-config python3 \
      libxml2-dev libssl-dev libsqlite3-dev zlib1g-dev libcurl4-openssl-dev libzip-dev libonig-dev libicu-dev \
      libsodium-dev libpng-dev libjpeg-dev libfreetype-dev libgmp-dev libreadline-dev
  elif command -v dnf > /dev/null; then
    log "installing build dependencies (dnf; needs EPEL + CRB/PowerTools)"
    dnf install -y epel-release || true
    dnf config-manager --set-enabled crb 2>/dev/null || dnf config-manager --set-enabled powertools 2>/dev/null || true
    dnf install -y git gcc gcc-c++ make autoconf bison re2c pkgconf python3 libxml2-devel openssl-devel \
      sqlite-devel zlib-devel libcurl-devel libzip-devel oniguruma-devel libicu-devel libsodium-devel \
      libpng-devel libjpeg-turbo-devel freetype-devel gmp-devel readline-devel
  else
    die "unsupported package manager: install the build dependencies listed in docs/modes/compatible.md"
  fi
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
