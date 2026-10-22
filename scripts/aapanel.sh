#!/usr/bin/env bash
# Try QHturbo (Compatible mode) on an aaPanel server, one site at a time,
# with automatic rollback. The aaPanel PHP installations are never touched.
#
#   sudo scripts/aapanel.sh check                       inspect the server, print a plan
#   sudo scripts/aapanel.sh install [--php=8.5]         build + install /opt/qhturbo/<php>, start FPM
#   sudo scripts/aapanel.sh enable  <site> [--php=8.5] [--url=URL]
#                                                       switch ONE site to QHturbo (auto-rollback on errors)
#   sudo scripts/aapanel.sh disable <site>              switch the site back to aaPanel PHP
#   sudo scripts/aapanel.sh status                      which sites use QHturbo, service health
#   sudo scripts/aapanel.sh sync-ini [--php=8.5]        re-copy limits/disable_functions from aaPanel php.ini
#   sudo scripts/aapanel.sh uninstall [--php=8.5]       disable all sites, stop + remove the service
#
# Works with aaPanel's nginx or Apache (detected; force with --web=nginx|apache).
# <site> is the vhost file name in /www/server/panel/vhost/{nginx,apache}/
# without ".conf" (usually the domain, e.g. example.com).
set -euo pipefail

ROOT="${AAPANEL_ROOT:-}"                 # test hook: a fake filesystem root
PANEL="$ROOT/www/server/panel"
NGINX_CONF="$ROOT/www/server/nginx/conf"
NGINX_BIN="${NGINX_BIN:-$ROOT/www/server/nginx/sbin/nginx}"
APACHE_BIN="${APACHE_BIN:-$ROOT/www/server/apache/bin/httpd}"
WEB=""                                   # nginx | apache (detected, or --web=)
QHT_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
export QHT_LAB="${QHT_LAB:-/opt/qhturbo-lab}"
PHPV=8.5
URL=""
SYSTEMD=1
[[ -d /run/systemd/system ]] || SYSTEMD=0

log()  { printf '\033[1;34m[qhturbo-aapanel]\033[0m %s\n' "$*" >&2; }
warn() { printf '\033[1;33m[qhturbo-aapanel] WARNING:\033[0m %s\n' "$*" >&2; }
die()  { printf '\033[1;31m[qhturbo-aapanel] ERROR:\033[0m %s\n' "$*" >&2; exit 1; }

cmd="${1:-}"; shift || true
args=()
for a in "$@"; do
  case "$a" in
    --php=*) PHPV="${a#*=}" ;;
    --url=*) URL="${a#*=}" ;;
    --web=*) WEB="${a#*=}" ;;
    -*) die "unknown option $a" ;;
    *) args+=("$a") ;;
  esac
done
[[ -f "$QHT_ROOT/config/versions/$PHPV.env" ]] || die "unsupported --php=$PHPV (7.4, 8.0 ... 8.5)"
TAG="${PHPV/./}"
PREFIX="/opt/qhturbo/$PHPV"
SOCK="/run/qhturbo/php$TAG-fpm.sock"
SERVICE="qhturbo-php$TAG-fpm"
INCLUDE="enable-php-qhturbo$TAG.conf"

# aaPanel runs either nginx or Apache as its web server.
if [[ -z "$WEB" ]]; then
  if [[ -x "$NGINX_BIN" ]]; then WEB=nginx; elif [[ -x "$APACHE_BIN" ]]; then WEB=apache; fi
fi
case "$WEB" in
  nginx|apache|"") ;;
  *) die "unknown --web=$WEB (nginx or apache)" ;;
esac
VHOSTS="$PANEL/vhost/${WEB:-nginx}"
APACHE_ORIG_DEFAULT="proxy:unix:/tmp/php-cgi-$TAG.sock|fcgi://"

need_root() { [[ $EUID -eq 0 ]] || die "run as root (sudo)"; }
need_web() {
  [[ -n "$WEB" ]] && return 0
  if [[ -d "$ROOT/usr/local/lsws" ]]; then
    die "aaPanel uses OpenLiteSpeed here: not supported yet (nginx and Apache are)"
  fi
  die "no aaPanel web server found ($NGINX_BIN or $APACHE_BIN); install nginx or Apache in aaPanel first"
}
web_test() {
  if [[ "$WEB" == apache ]]; then "$APACHE_BIN" -t > /dev/null 2>&1; else "$NGINX_BIN" -t > /dev/null 2>&1; fi
}
web_test_output() {
  if [[ "$WEB" == apache ]]; then "$APACHE_BIN" -t 2>&1 | tail -5; else "$NGINX_BIN" -t 2>&1 | tail -5; fi
}
# reload is asynchronous: give the new workers a moment before testing.
web_reload() {
  if [[ "$WEB" == apache ]]; then "$APACHE_BIN" -k graceful 2> /dev/null; else "$NGINX_BIN" -s reload 2> /dev/null; fi
  sleep 1
}

# How a vhost hands PHP to PHP-FPM:
#   nginx:  include enable-php-84.conf;   (aaPanel's per-version include)
#   apache: SetHandler "proxy:unix:/tmp/php-cgi-84.sock|fcgi://localhost"
uses_qhturbo() { grep -qE 'enable-php-qhturbo[0-9]+\.conf|proxy:unix:/run/qhturbo/' "$1"; }
qhturbo_label() { grep -oE 'enable-php-qhturbo[0-9]+|/run/qhturbo/php[0-9]+-fpm\.sock' "$1" | head -1; }
current_php() {
  if [[ "$WEB" == apache ]]; then
    grep -oE 'proxy:unix:[^|"]+\|fcgi://' "$1" | head -1 || true
  else
    grep -oE 'include[[:space:]]+enable-php-[0-9]+\.conf;' "$1" | head -1 || true
  fi
}
switch_to_qhturbo() {
  if [[ "$WEB" == apache ]]; then
    sed -i -E "s#proxy:unix:[^|\"]+\|fcgi://#proxy:unix:$SOCK|fcgi://#g" "$1"
  else
    sed -i -E "s#include[[:space:]]+enable-php-[0-9]+\.conf;#include $INCLUDE;#" "$1"
  fi
}
switch_back() { # vhost original
  if [[ "$WEB" == apache ]]; then
    sed -i -E "s#proxy:unix:/run/qhturbo/[^|\"]+\|fcgi://#$2#g" "$1"
  else
    sed -i -E "s#include[[:space:]]+enable-php-qhturbo[0-9]+\.conf;#$2#" "$1"
  fi
}

# ------------------------------------------------------------------ check --
do_check() {
  local ok=1
  [[ -d "$PANEL" ]] && log "aaPanel found: $PANEL" || { warn "aaPanel not found at $PANEL"; ok=0; }
  case "$WEB" in
    nginx) log "web server: $("$NGINX_BIN" -v 2>&1)" ;;
    apache)
      log "web server: $("$APACHE_BIN" -v 2>&1 | head -1)"
      "$APACHE_BIN" -M 2>/dev/null | grep -q proxy_fcgi_module \
        || warn "Apache module proxy_fcgi is not loaded; aaPanel normally loads it (PHP-FPM sites need it)"
      ;;
    *) if [[ -d "$ROOT/usr/local/lsws" ]]; then warn "OpenLiteSpeed is not supported yet (nginx and Apache are)"
       else warn "no aaPanel web server found ($NGINX_BIN or $APACHE_BIN)"; fi
       ok=0 ;;
  esac
  local os; os="$(. /etc/os-release && echo "$PRETTY_NAME")"
  log "OS: $os, $(nproc) CPU, $(free -m | awk '/Mem:/{print $2}') MiB RAM, $(df -Pm / | awk 'NR==2{print $4}') MiB free on /"
  if [[ $(free -m | awk '/Mem:/{print $2+0}') -lt 1800 ]] && [[ $(free -m | awk '/Swap:/{print $2+0}') -lt 1024 ]]; then
    warn "less than 2 GB RAM and no swap: the build may run out of memory. Add 2 GB swap first (see docs/aapanel.md)"
  fi
  if [[ $(df -Pm / | awk 'NR==2{print $4}') -lt 4000 ]]; then
    warn "less than 4 GB free disk: the build needs about 3 GB in $QHT_LAB"
  fi
  id www > /dev/null 2>&1 && log "user www exists (aaPanel web user)" || warn "user 'www' not found"
  local list; list="$(ls "$ROOT"/www/server/php 2>/dev/null | tr '\n' ' ')"
  log "aaPanel PHP versions (left untouched): ${list:-none}"
  if [[ -d "$VHOSTS" ]]; then
    log "sites and the PHP they use:"
    local f
    for f in "$VHOSTS"/*.conf; do
      [[ -e "$f" ]] || continue
      printf '    %-40s %s\n' "$(basename "$f" .conf)" "$(grep -oE 'enable-php-[a-z0-9]+\.conf|php-cgi-[0-9]+\.sock|/run/qhturbo/php[0-9]+-fpm\.sock' "$f" | head -1 || echo '(no PHP)')" >&2
    done
  fi
  if [[ -x "$PREFIX/sbin/php-fpm" ]]; then
    log "QHturbo PHP $PHPV already installed in $PREFIX"
    local panel_php="$ROOT/www/server/php/$TAG/bin/php" missing
    if [[ -x "$panel_php" ]]; then
      missing="$(comm -23 <("$panel_php" -m 2>/dev/null | grep -v '^\[' | grep -v '^$' | tr 'A-Z' 'a-z' | sort -u) \
                          <("$PREFIX/bin/php" -m 2>/dev/null | grep -v '^\[' | grep -v '^$' | tr 'A-Z' 'a-z' | sort -u) | tr '\n' ' ')"
      if [[ -n "${missing// /}" ]]; then
        warn "extensions in aaPanel PHP $PHPV but not in QHturbo: $missing"
        warn "add them with: sudo scripts/install-ext.sh <name> --php=$PHPV"
      else
        log "QHturbo has every extension that aaPanel PHP $PHPV has"
      fi
    fi
  else
    log "QHturbo PHP $PHPV not installed yet: run '$0 install --php=$PHPV'"
  fi
  (( ok )) || return 1
}

# ---------------------------------------------------------------- install --
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

write_include() {
  # Copy aaPanel's own enable-php-XX.conf (keeps pathinfo/fastcgi settings)
  # and point it at the QHturbo socket.
  local src="" f
  for f in "$NGINX_CONF/enable-php-$TAG.conf" "$NGINX_CONF"/enable-php-[0-9]*.conf; do
    if [[ -f "$f" ]] && grep -q 'fastcgi_pass' "$f"; then src="$f"; break; fi
  done
  if [[ -n "$src" ]]; then
    sed -E "s#fastcgi_pass[[:space:]]+[^;]+;#fastcgi_pass unix:$SOCK;#" "$src" > "$NGINX_CONF/$INCLUDE"
    log "wrote $NGINX_CONF/$INCLUDE (based on $(basename "$src"))"
  else
    cat > "$NGINX_CONF/$INCLUDE" <<EOF
location ~ [^/]\.php(/|\$)
{
    try_files \$uri =404;
    fastcgi_pass unix:$SOCK;
    fastcgi_index index.php;
    include fastcgi.conf;
}
EOF
    log "wrote $NGINX_CONF/$INCLUDE (generic)"
  fi
}

# Carry over the settings aaPanel users change in the panel (security and
# limits), so a site behaves the same on QHturbo. Sessions are left at the
# PHP default (files) unless aaPanel also uses files.
sync_aapanel_ini() {
  local src="" v
  for v in "$TAG" $(ls "$ROOT/www/server/php" 2>/dev/null | sort -rn); do
    if [[ -f "$ROOT/www/server/php/$v/etc/php.ini" ]]; then src="$ROOT/www/server/php/$v/etc/php.ini"; break; fi
  done
  [[ -n "$src" ]] || { warn "no aaPanel php.ini found; QHturbo uses its own defaults (no disable_functions!)"; return 0; }
  local dest="$PREFIX/etc/conf.d/05-from-aapanel.ini" keys
  keys='disable_functions|upload_max_filesize|post_max_size|max_execution_time|max_input_time|memory_limit|max_input_vars|date\.timezone|short_open_tag|display_errors|error_reporting|allow_url_fopen|cgi\.fix_pathinfo|mysqli\.default_socket|pdo_mysql\.default_socket|mysqli\.default_port'
  {
    echo "; Copied by scripts/aapanel.sh from $src on $(date -u '+%Y-%m-%d %H:%M UTC')."
    echo "; Re-run '$0 sync-ini' after changing PHP settings in aaPanel."
    # "|| true": a setting missing from php.ini is normal and must not stop
    # the script (set -e + pipefail).
    grep -E "^[[:space:]]*($keys)[[:space:]]*=" "$src" | sed 's/^[[:space:]]*//' || true
    if grep -qE '^[[:space:]]*session\.save_handler[[:space:]]*=[[:space:]]*files' "$src"; then
      grep -E '^[[:space:]]*session\.save_path[[:space:]]*=' "$src" | sed 's/^[[:space:]]*//' || true
    fi
  } > "$dest"
  log "copied $(($(wc -l < "$dest") - 2)) settings from $src into $dest"
  # Performance-mode workers (loaded after conf.d/) need a few pcntl/posix
  # functions to fork and handle signals. Only those are re-allowed; every
  # other restriction (pcntl_exec included) stays.
  if [[ -d "$PREFIX/etc/performance.d" ]] && grep -q '^disable_functions' "$dest"; then
    local needed=" pcntl_fork pcntl_wait pcntl_waitpid pcntl_signal pcntl_async_signals pcntl_wifexited pcntl_wexitstatus posix_kill " list kept=() f
    list="$(grep '^disable_functions' "$dest" | tail -1 | sed -E 's/^[^=]*=[[:space:]]*//; s/^"//; s/"$//')"
    IFS=',' read -ra funcs <<< "$list"
    for f in "${funcs[@]}"; do
      f="${f//[[:space:]]/}"
      [[ -n "$f" && "$needed" != *" $f "* ]] && kept+=("$f")
    done
    {
      echo "; aaPanel disable_functions minus the pcntl/posix functions qhturbo-serve workers need."
      echo "disable_functions = $(IFS=,; echo "${kept[*]}")"
    } > "$PREFIX/etc/performance.d/05-from-aapanel.ini"
  fi
  if grep -q '^disable_functions' "$dest"; then
    log "disable_functions carried over (same security restrictions as aaPanel PHP)"
  fi
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

do_install() {
  need_root
  do_check || warn "continuing despite the warnings above"
  need_web  # before the 10-40 minute build, not after
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
  "$QHT_ROOT/scripts/install.sh" --user=www --group=www
  sync_aapanel_ini
  # php-fpm -t opens the error log and checks the pid directory: create them
  # first (the systemd unit only creates them when the service starts).
  mkdir -p /run/qhturbo /var/log/qhturbo
  "$PREFIX/sbin/php-fpm" -t
  start_service
  if [[ "$WEB" == nginx ]]; then
    write_include
    web_test || die "nginx -t failed after adding $INCLUDE (no site uses it yet); check $NGINX_CONF/$INCLUDE"
  fi
  "$PREFIX/bin/php" "$PREFIX/share/qhturbo/qht-doctor.php" || true
  cat >&2 <<EOF

QHturbo PHP $PHPV is installed and running. No site uses it yet.
Next: switch ONE test site:   sudo $0 enable <site> --php=$PHPV
EOF
}

# ------------------------------------------------------------ enable/disable --
http_code() { # site -> HTTP status of the site's home page through the local web server
  local site="$1" code redirect
  if [[ -n "$URL" ]]; then
    code="$(curl -sk -o /dev/null -w '%{http_code}' --max-time 15 -H "Host: $site" "$URL")" || true
    echo "${code:-000}"; return
  fi
  read -r code redirect < <(curl -s -o /dev/null -w '%{http_code} %{redirect_url}\n' --max-time 15 -H "Host: $site" http://127.0.0.1/ || true) || true
  # HTTP -> HTTPS redirect: the web server answers before PHP runs, so test HTTPS
  # (certificate not verified: we only want the status code).
  if [[ "$code" =~ ^30[1278]$ && "$redirect" == https://* ]]; then
    code="$(curl -sk -o /dev/null -w '%{http_code}' --max-time 15 --resolve "$site:443:127.0.0.1" "https://$site/")" || true
  fi
  echo "${code:-000}"
}

do_enable() {
  need_root
  need_web
  local site="${args[0]:-}" vhost
  [[ -n "$site" ]] || die "usage: $0 enable <site> [--php=$PHPV]"
  vhost="$VHOSTS/$site.conf"
  [[ -f "$vhost" ]] || die "vhost not found: $vhost (run '$0 check' to list sites)"
  [[ -S "$SOCK" ]] || die "QHturbo PHP $PHPV is not running ($SOCK missing); run '$0 install --php=$PHPV' first"
  [[ "$WEB" == apache || -f "$NGINX_CONF/$INCLUDE" ]] || write_include
  if uses_qhturbo "$vhost"; then
    log "$site already uses QHturbo ($(qhturbo_label "$vhost"))"; return 0
  fi
  local current
  current="$(current_php "$vhost")"
  if [[ -z "$current" ]]; then
    warn "lines mentioning php in $vhost:"
    grep -n -i 'php' "$vhost" | head -10 >&2 || true
    if [[ "$WEB" == apache ]]; then
      die "$site has no 'SetHandler \"proxy:unix:...|fcgi://localhost\"' line (static site, or PHP not set in aaPanel); edit it by hand"
    fi
    die "$site has no 'include enable-php-XX.conf;' line (static site or custom config); edit it by hand"
  fi

  local before; before="$(http_code "$site")"
  cp -p "$vhost" "$vhost.qhturbo-bak"
  echo "$current" > "$vhost.qhturbo-orig"
  switch_to_qhturbo "$vhost"
  if ! web_test; then
    local out; out="$(web_test_output)"
    cp -p "$vhost.qhturbo-bak" "$vhost"; rm -f "$vhost.qhturbo-orig"
    die "$WEB config test failed; $site restored to aaPanel PHP:
$out"
  fi
  web_reload
  local after; after="$(http_code "$site")"
  if [[ "$after" =~ ^5 || "$after" == 000 ]] && [[ ! "$before" =~ ^5 ]]; then
    cp -p "$vhost.qhturbo-bak" "$vhost"; rm -f "$vhost.qhturbo-orig"; web_reload
    die "$site answered HTTP $after with QHturbo (was $before); rolled back. Check /var/log/qhturbo/ and the site's error log"
  fi
  log "$site now runs on QHturbo PHP $PHPV ($current -> $(qhturbo_label "$vhost")); HTTP $before -> $after"
  log "watch: tail -f $ROOT/www/wwwlogs/$site*error*log /var/log/qhturbo/php$TAG-fpm.log"
  log "undo:  sudo $0 disable $site"
}

do_disable() {
  need_root
  need_web
  local site="${args[0]:-}" vhost orig
  [[ -n "$site" ]] || die "usage: $0 disable <site>"
  vhost="$VHOSTS/$site.conf"
  [[ -f "$vhost" ]] || die "vhost not found: $vhost"
  uses_qhturbo "$vhost" || { log "$site does not use QHturbo"; return 0; }
  orig="$(cat "$vhost.qhturbo-orig" 2>/dev/null || true)"
  if [[ -z "$orig" ]]; then
    if [[ "$WEB" == apache ]]; then orig="$APACHE_ORIG_DEFAULT"; else orig="include enable-php-$TAG.conf;"; fi
  fi
  # Only the PHP handler changes; other edits made since enable are kept.
  switch_back "$vhost" "$orig"
  web_test || { cp -p "$vhost.qhturbo-bak" "$vhost"; switch_back "$vhost" "$orig"; web_test || die "$WEB config test still fails; inspect $vhost"; }
  web_reload
  rm -f "$vhost.qhturbo-orig"
  log "$site is back on aaPanel PHP ($orig), HTTP $(http_code "$site"); backup kept: $vhost.qhturbo-bak"
}

do_status() {
  local f any=0
  for f in "$VHOSTS"/*.conf; do
    [[ -e "$f" ]] || continue
    if uses_qhturbo "$f"; then
      any=1; printf '    %-40s %s\n' "$(basename "$f" .conf)" "$(qhturbo_label "$f")" >&2
    fi
  done
  (( any )) && log "sites above use QHturbo" || log "no site uses QHturbo"
  local d
  for d in /opt/qhturbo/*/; do
    [[ -x "$d/sbin/php-fpm" ]] || continue
    local t; t="$(basename "$d")"; t="${t/./}"
    if [[ -S "/run/qhturbo/php$t-fpm.sock" ]]; then
      log "$("$d/bin/php" -r 'echo "PHP ", PHP_VERSION;') in $d: FPM running (/run/qhturbo/php$t-fpm.sock)"
    else
      warn "$d: FPM not running"
    fi
  done
}

do_uninstall() {
  need_root
  local f
  for f in "$VHOSTS"/*.conf; do
    [[ -e "$f" ]] || continue
    if grep -qE "enable-php-qhturbo$TAG\.conf|/run/qhturbo/php$TAG-fpm\.sock" "$f"; then
      args=("$(basename "$f" .conf)"); do_disable
    fi
  done
  if (( SYSTEMD )) && [[ -f "/etc/systemd/system/$SERVICE.service" ]]; then
    systemctl disable --now "$SERVICE" || true
    rm -f "/etc/systemd/system/$SERVICE.service"; systemctl daemon-reload
  elif [[ -f "/run/qhturbo/php$TAG-fpm.pid" ]]; then
    kill "$(cat "/run/qhturbo/php$TAG-fpm.pid")" || true
  fi
  rm -f "$NGINX_CONF/$INCLUDE"
  log "stopped and removed the service. Files remain in $PREFIX and $QHT_LAB; delete them with: rm -rf $PREFIX $QHT_LAB"
}

case "$cmd" in
  check) do_check ;;
  install) do_install ;;
  enable) do_enable ;;
  disable) do_disable ;;
  status) do_status ;;
  sync-ini) need_root; sync_aapanel_ini; [[ -f "/run/qhturbo/php$TAG-fpm.pid" ]] && kill -USR2 "$(cat "/run/qhturbo/php$TAG-fpm.pid")" && log "PHP-FPM reloaded" ;;
  uninstall) do_uninstall ;;
  *) sed -n '2,15p' "$0" | sed 's/^# \{0,1\}//'; exit 1 ;;
esac
