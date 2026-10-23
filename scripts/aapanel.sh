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
#   sudo scripts/aapanel.sh sync-ext [--php=8.5]        give QHturbo the extensions of aaPanel's PHP of that
#                                                       version (reuses its .so files, builds the rest);
#                                                       also runs after install and before enable
#                                                       (NO_SYNC_EXT=1 to skip)
#   sudo scripts/aapanel.sh uninstall [--php=8.5]       disable all sites, stop + remove the service
#   scripts/aapanel.sh versions | sites                 machine-readable lists (tab-separated) for the panel plugin
#
# Works with aaPanel's nginx or Apache (detected; force with --web=nginx|apache).
# <site> is the vhost file name in /www/server/panel/vhost/{nginx,apache}/
# without ".conf" (usually the domain, e.g. example.com).
set -euo pipefail
# Control panels may start us with a minimal PATH (aaPanel's panel process):
# make sure the system tools (dnf/apt-get, git, gcc, systemctl) are found.
export PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin${PATH:+:$PATH}"

ROOT="${AAPANEL_ROOT:-}"                 # test hook: a fake filesystem root
PANEL="$ROOT/www/server/panel"
NGINX_CONF="$ROOT/www/server/nginx/conf"
NGINX_BIN="${NGINX_BIN:-$ROOT/www/server/nginx/sbin/nginx}"
APACHE_BIN="${APACHE_BIN:-$ROOT/www/server/apache/bin/httpd}"
WEB=""                                   # nginx | apache (detected, or --web=)
QHT_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
export QHT_LAB="${QHT_LAB:-/opt/qhturbo-lab}"
PHPV=8.5
URL="${QHT_URL:-}"                    # --url=; QHT_URL for the panel plugin tests
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

# shellcheck source=scripts/panel-lib.sh
source "$QHT_ROOT/scripts/panel-lib.sh"

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

missing_exts() { # aaPanel PHP tag (e.g. 84) -> extensions it has that QHturbo lacks
  local panel_php="$ROOT/www/server/php/$1/bin/php"
  [[ -x "$panel_php" && -x "$PREFIX/bin/php" ]] || return 0
  comm -23 <(ext_list "$panel_php") <(ext_list "$PREFIX/bin/php") | tr '\n' ' '
}
# Error logs PHP may write to while a site is tested.
site_logs() { printf '%s\n' "/var/log/qhturbo/php$TAG-fpm.log" "$ROOT"/www/wwwlogs/"$1"*error*log "$ROOT"/www/wwwlogs/"$1".log; }
log_sizes() { local f; while read -r f; do if [[ -f "$f" ]]; then echo "$f $(stat -c %s "$f")"; fi; done < <(site_logs "$1"); }
# After a failed switch: show what PHP logged since $2 (log_sizes output) and
# what the site's original PHP has that QHturbo lacks.
explain_failure() { # site sizes current
  local site="$1" sizes="$2" current="$3" f size new tag missing
  new="$(while read -r f size; do
           [[ -f "$f" ]] && tail -c +"$((size + 1))" "$f"
         done <<< "$sizes" | grep -aE 'PHP (Fatal|Parse|Warning|Deprecated)|Uncaught|Got error|FastCGI sent' | tail -n 8 || true)"
  if [[ -n "$new" ]]; then
    warn "PHP errors while the site ran on QHturbo $PHPV:"
    printf '    %s\n' "${new//$'\n'/$'\n'    }" >&2
  else
    warn "no PHP error was logged; look at the site's own logs (e.g. storage/logs, wp-content/debug.log)"
  fi
  tag="$(grep -oE '(php-cgi-|enable-php-)[0-9]+' <<< "$current" | grep -oE '[0-9]+$' || true)"
  if [[ -n "$tag" ]]; then
    missing="$(missing_exts "$tag")"
    [[ -n "${missing// /}" ]] && warn "the site's PHP ($tag) has extensions QHturbo $PHPV lacks: $missing (add: sudo $QHT_ROOT/scripts/install-ext.sh <name> --php=$PHPV)"
    [[ "$tag" != "$TAG" ]] && warn "the site ran on PHP ${tag:0:1}.${tag:1}; for the fewest surprises use the same version: $0 install --php=${tag:0:1}.${tag:1}"
  fi
  return 0
}

# Extensions: copy/build what aaPanel's PHP <tag> has into QHturbo $PHPV.
do_sync_ext() { # [aaPanel tag, default: same version]
  local tag="${1:-$TAG}" src
  src="$ROOT/www/server/php/$tag/bin/php"
  [[ -x "$src" ]] || { log "no aaPanel PHP $tag here; nothing to sync"; return 0; }
  log "syncing extensions from aaPanel PHP $tag ($src)"
  QHT_LAB="$QHT_LAB" "$QHT_ROOT/scripts/sync-ext.sh" --from="$src" --php="$PHPV" \
    || warn "some extensions are still missing (see above); sites that need them may fail"
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
      grep -q proxy_fcgi_module <<< "$("$APACHE_BIN" -M 2>/dev/null || true)" \
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
    local d t missing
    for d in "$ROOT"/www/server/php/*/; do
      t="$(basename "$d")"
      [[ -x "$d/bin/php" ]] || continue
      missing="$(missing_exts "$t")"
      if [[ -n "${missing// /}" ]]; then
        warn "extensions in aaPanel PHP $t but not in QHturbo $PHPV: $missing"
      else
        log "QHturbo $PHPV has every extension that aaPanel PHP $t has"
      fi
    done
    log "add a missing extension with: sudo scripts/install-ext.sh <name> --php=$PHPV"
  else
    log "QHturbo PHP $PHPV not installed yet: run '$0 install --php=$PHPV'"
  fi
  (( ok )) || return 1
}

# ---------------------------------------------------------------- install --

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


do_install() {
  need_root
  do_check || warn "continuing despite the warnings above"
  need_web  # before the 10-40 minute build, not after
  build_and_install www www
  sync_aapanel_ini
  "$PREFIX/sbin/php-fpm" -t
  start_service
  if [[ "$WEB" == nginx ]]; then
    write_include
    web_test || die "nginx -t failed after adding $INCLUDE (no site uses it yet); check $NGINX_CONF/$INCLUDE"
  fi
  [[ "${NO_SYNC_EXT:-0}" == 1 ]] || do_sync_ext
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
  fpm_alive "$TAG" || die "QHturbo PHP $PHPV is not running; start it (systemctl start $SERVICE) or run '$0 install --php=$PHPV'"
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

  # Same PHP version as the site: bring over its extensions first.
  local site_tag missing
  site_tag="$(grep -oE '(php-cgi-|enable-php-)[0-9]+' <<< "$current" | grep -oE '[0-9]+$' || true)"
  if [[ "${NO_SYNC_EXT:-0}" != 1 && "$site_tag" == "$TAG" ]]; then
    missing="$(missing_exts "$site_tag")"
    [[ -z "${missing// /}" ]] || do_sync_ext "$site_tag"
  fi

  local before; before="$(http_code "$site")"
  local sizes; sizes="$(log_sizes "$site")"
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
    explain_failure "$site" "$sizes" "$current"
    die "$site answered HTTP $after with QHturbo (was $before); rolled back to aaPanel PHP"
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
    if fpm_alive "$t"; then
      log "$("$d/bin/php" -r 'echo "PHP ", PHP_VERSION;') in $d: FPM running (/run/qhturbo/php$t-fpm.sock)"
    else
      warn "$d: FPM not running"
    fi
  done
}


# Tab-separated, one line per supported PHP branch:
#   branch  installed(0|1)  fpm_running(0|1)  php_version  aapanel_php(0|1)
do_versions() {
  local f v t installed running ver panel
  for f in "$QHT_ROOT"/config/versions/*.env; do
    v="$(basename "$f" .env)"; t="${v/./}"
    installed=0 running=0 ver="" panel=0
    if [[ -x "/opt/qhturbo/$v/sbin/php-fpm" ]]; then
      installed=1
      ver="$("/opt/qhturbo/$v/bin/php" -n -r 'echo PHP_VERSION;' 2>/dev/null || true)"
    fi
    fpm_alive "$t" && running=1
    [[ -x "$ROOT/www/server/php/$t/bin/php" ]] && panel=1
    printf '%s\t%s\t%s\t%s\t%s\n' "$v" "$installed" "$running" "${ver:--}" "$panel"
  done
}

# Tab-separated, one line per site:
#   site  web_server  original_php_tag(or -)  qhturbo_tag(or -)
do_sites() {
  local f site cur qht
  [[ -n "$WEB" ]] || return 0
  for f in "$VHOSTS"/*.conf; do
    [[ -e "$f" ]] || continue
    site="$(basename "$f" .conf)"
    cur="$(grep -oE '(php-cgi-|enable-php-)[0-9]+' "$f" | head -1 | grep -oE '[0-9]+$' || true)"
    qht="$(grep -oE '(enable-php-qhturbo|/run/qhturbo/php)[0-9]+' "$f" | head -1 | grep -oE '[0-9]+$' || true)"
    if [[ -n "$qht" ]]; then
      cur="$(grep -oE '(php-cgi-|enable-php-)[0-9]+' "$f.qhturbo-orig" 2>/dev/null | head -1 | grep -oE '[0-9]+$' || true)"
    fi
    printf '%s\t%s\t%s\t%s\n' "$site" "$WEB" "${cur:--}" "${qht:--}"
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
  sync-ext) need_root; do_sync_ext ;;
  versions) do_versions ;;
  sites) do_sites ;;
  sync-ini)
    need_root; sync_aapanel_ini
    if [[ -f "/run/qhturbo/php$TAG-fpm.pid" ]] && kill -USR2 "$(cat "/run/qhturbo/php$TAG-fpm.pid")" 2> /dev/null; then
      log "PHP-FPM reloaded"
    else
      warn "PHP-FPM $PHPV is not running; start it with: systemctl start $SERVICE"
    fi ;;
  uninstall) do_uninstall ;;
  *) sed -n '2,15p' "$0" | sed 's/^# \{0,1\}//'; exit 1 ;;
esac
