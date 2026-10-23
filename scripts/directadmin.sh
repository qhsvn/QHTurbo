#!/usr/bin/env bash
# QHturbo Compatible on a DirectAdmin server, one domain at a time, with
# automatic rollback. DirectAdmin's own PHP (/usr/local/phpXX) is never
# touched.
#
#   sudo scripts/directadmin.sh check   [--php=8.5]          inspect the server, change nothing
#   sudo scripts/directadmin.sh install [--php=8.5]          build + install /opt/qhturbo/<php>, start FPM,
#                                                            copy DirectAdmin PHP's extensions (same version)
#   sudo scripts/directadmin.sh enable  <domain> [--php=8.5] [--url=URL]
#                                                            switch ONE domain to QHturbo (auto-rollback)
#   sudo scripts/directadmin.sh disable <domain>             switch the domain back to DirectAdmin's PHP
#   sudo scripts/directadmin.sh status                       domains on QHturbo, service health
#   sudo scripts/directadmin.sh sync-ext [--php=8.5]         copy extensions from DirectAdmin's PHP of that version
#   sudo scripts/directadmin.sh uninstall [--php=8.5]        switch all domains back, stop + remove the service
#   scripts/directadmin.sh versions | sites                  machine-readable lists (tab-separated) for the plugin
#
# How a domain is switched:
#   - its user gets a PHP-FPM pool in QHturbo that runs as that user: a copy
#     of DirectAdmin's own pool for the user (same open_basedir, limits,
#     mail settings), listening on /run/qhturbo/php<ver>-<user>.sock;
#   - a marked block in the domain's custom httpd file
#     (data/users/<user>/domains/<domain>.cust_httpd, DirectAdmin's
#     "Custom HTTPD Configurations") sets the PHP handler to that socket;
#     SetHandler wins over DirectAdmin's AddHandler;
#   - DirectAdmin rewrites the user's httpd.conf, Apache reloads, the domain
#     is checked over HTTP and switched back if it now fails.
# Web servers: Apache, or nginx_apache (Apache runs PHP). Plain nginx and
# (Open)LiteSpeed are not supported yet.
set -euo pipefail
# Control panels may start us with a minimal PATH (aaPanel's panel process):
# make sure the system tools (dnf/apt-get, git, gcc, systemctl) are found.
export PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin${PATH:+:$PATH}"

ROOT="${DA_ROOT:-}"                      # test hook: a fake filesystem root
DA="$ROOT/usr/local/directadmin"
DA_BIN="${DA_BIN:-$DA/directadmin}"
USERS="$DA/data/users"
OPTIONS="$DA/custombuild/options.conf"
HTTPD_BIN="${HTTPD_BIN:-$ROOT/usr/sbin/httpd}"
WEB_USER="${DA_WEB_USER:-apache}"
QHT_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
export QHT_LAB="${QHT_LAB:-/opt/qhturbo-lab}"
PHPV=8.5
URL="${QHT_URL:-}"
SYSTEMD=1
[[ -d /run/systemd/system ]] || SYSTEMD=0
# No "/" in the markers: they are used as sed addresses.
MARK_BEGIN="# BEGIN QHturbo - managed by QHturbo directadmin.sh, do not edit"
MARK_END="# END QHturbo"

log()  { printf '\033[1;34m[qhturbo-da]\033[0m %s\n' "$*" >&2; }
warn() { printf '\033[1;33m[qhturbo-da] WARNING:\033[0m %s\n' "$*" >&2; }
die()  { printf '\033[1;31m[qhturbo-da] ERROR:\033[0m %s\n' "$*" >&2; exit 1; }

cmd="${1:-}"; shift || true
args=()
for a in "$@"; do
  case "$a" in
    --php=*) PHPV="${a#*=}" ;;
    --url=*) URL="${a#*=}" ;;
    -*) die "unknown option $a" ;;
    *) args+=("$a") ;;
  esac
done
[[ -f "$QHT_ROOT/config/versions/$PHPV.env" ]] || die "unsupported --php=$PHPV (7.4, 8.0 ... 8.5)"
TAG="${PHPV/./}"
PREFIX="/opt/qhturbo/$PHPV"
SOCK="/run/qhturbo/php$TAG-fpm.sock"
SERVICE="qhturbo-php$TAG-fpm"

# shellcheck source=scripts/panel-lib.sh
source "$QHT_ROOT/scripts/panel-lib.sh"

need_root() { [[ $EUID -eq 0 ]] || die "run as root (sudo)"; }
option() { # CustomBuild option value
  sed -n "s/^$1=//p" "$OPTIONS" 2>/dev/null | tail -1
}
webserver() { option webserver; }
need_apache() {
  [[ -x "$DA_BIN" ]] || die "DirectAdmin not found ($DA_BIN)"
  case "$(webserver)" in
    apache|nginx_apache) ;;
    "") die "cannot read the web server from $OPTIONS" ;;
    *) die "web server '$(webserver)' is not supported yet (apache and nginx_apache are)" ;;
  esac
  [[ -x "$HTTPD_BIN" ]] || die "Apache not found ($HTTPD_BIN)"
}
httpd_test() { "$HTTPD_BIN" -t > /dev/null 2>&1; }
httpd_reload() {
  if [[ -n "${HTTPD_RELOAD:-}" ]]; then eval "$HTTPD_RELOAD"
  elif (( SYSTEMD )); then systemctl reload httpd 2>/dev/null || "$HTTPD_BIN" -k graceful
  else "$HTTPD_BIN" -k graceful; fi
  sleep 1
}
# DirectAdmin regenerates the user's httpd.conf from its templates plus the
# domains' custom httpd files.
da_rewrite() { # user
  if [[ -n "${DA_REWRITE:-}" ]]; then eval "$DA_REWRITE"; return; fi
  "$DA_BIN" taskq --run "action=rewrite&value=httpd&user=$1" > /dev/null 2>&1 && return 0
  # Older DirectAdmin: queue the task and run the queue now.
  echo "action=rewrite&value=httpd&user=$1" >> "$DA/data/task.queue"
  "$DA/dataskq" d > /dev/null 2>&1 || true
}

domain_user() { # domain -> owner (from data/users/*/domains.list)
  local f
  for f in "$USERS"/*/domains.list; do
    [[ -f "$f" ]] || continue
    if grep -qxF -- "$1" "$f"; then basename "$(dirname "$f")"; return 0; fi
  done
  return 1
}
# PHP version DirectAdmin uses for a domain: php1_select=N in the domain's
# config picks CustomBuild's phpN_release (default 1).
domain_php() { # user domain -> e.g. 7.4
  local sel
  sel="$(sed -n 's/^php1_select=//p' "$USERS/$1/domains/$2.conf" 2>/dev/null | tail -1)"
  [[ "$sel" =~ ^[1-4]$ ]] || sel=1
  option "php${sel}_release"
}
domain_ip() { sed -n 's/^ip=//p' "$USERS/$1/domains/$2.conf" 2>/dev/null | tail -1; }
cust_file() { echo "$USERS/$1/domains/$2.cust_httpd"; }
# QHturbo version a domain is switched to, from the marked block ("" if none).
domain_qhturbo() { # user domain
  local f; f="$(cust_file "$1" "$2")"
  [[ -f "$f" ]] || return 0
  sed -n "/^$MARK_BEGIN\$/,/^$MARK_END\$/p" "$f" | grep -oE '/run/qhturbo/php[0-9]+-' | head -1 | grep -oE '[0-9]+' \
    | sed -E 's/^([0-9])([0-9])$/\1.\2/' || true
}
user_sock() { echo "/run/qhturbo/php$TAG-$1.sock"; }
pool_file() { echo "$PREFIX/etc/php-fpm.d/da-$1.conf"; }

# A pool for <user> in QHturbo $PHPV, copied from DirectAdmin's pool for
# that user when there is one (any PHP version), so the same limits apply.
write_pool() { # user
  local user="$1" src="" f dest sock
  dest="$(pool_file "$user")"; sock="$(user_sock "$user")"
  for f in "$ROOT"/usr/local/php*/etc/php-fpm.d/"$user".conf; do
    [[ -f "$f" ]] && { src="$f"; break; }
  done
  {
    echo "; Written by scripts/directadmin.sh for DirectAdmin user $user${src:+ (based on $src)}."
    if [[ -n "$src" ]]; then
      sed -E -e "s#^\[.*\]#[da-$user]#" -e "s#^listen[[:space:]]*=.*#listen = $sock#" "$src"
    else
      cat <<EOF
[da-$user]
user = $user
group = $user
listen = $sock
listen.owner = $user
listen.group = $WEB_USER
listen.mode = 0660
pm = ondemand
pm.max_children = 10
pm.process_idle_timeout = 20s
pm.max_requests = 500
php_admin_value[open_basedir] = /home/$user/:/tmp/:/var/tmp/:/dev/urandom
EOF
    fi
  } > "$dest.tmp"
  # The socket must be reachable by Apache whatever DirectAdmin's pool said.
  grep -q '^listen.group' "$dest.tmp" || echo "listen.group = $WEB_USER" >> "$dest.tmp"
  grep -q '^listen.mode' "$dest.tmp" || echo "listen.mode = 0660" >> "$dest.tmp"
  mv "$dest.tmp" "$dest"
}
# Remove the user's pool when none of the user's domains uses this version.
drop_pool_if_unused() { # user
  local d
  while read -r d; do
    [[ -n "$d" ]] || continue
    [[ "$(domain_qhturbo "$1" "$d")" == "$PHPV" ]] && return 0
  done < "$USERS/$1/domains.list"
  rm -f "$(pool_file "$1")"
  fpm_reload "$TAG" || true
}
wait_socket() { local _; for _ in $(seq 40); do [[ -S "$1" ]] && return 0; sleep 0.25; done; return 1; }

http_code() { # user domain -> HTTP status of the domain's home page on its own IP
  local user="$1" domain="$2" ip code redirect
  if [[ -n "$URL" ]]; then
    code="$(curl -sk -o /dev/null -w '%{http_code}' --max-time 15 -H "Host: $domain" "$URL")" || true
    echo "${code:-000}"; return
  fi
  ip="$(domain_ip "$user" "$domain")"; ip="${ip:-127.0.0.1}"
  read -r code redirect < <(curl -s -o /dev/null -w '%{http_code} %{redirect_url}\n' --max-time 15 \
    --resolve "$domain:80:$ip" "http://$domain/" || true) || true
  if [[ "$code" =~ ^30[1278]$ && "$redirect" == https://* ]]; then
    code="$(curl -sk -o /dev/null -w '%{http_code}' --max-time 15 --resolve "$domain:443:$ip" "https://$domain/")" || true
  fi
  echo "${code:-000}"
}

set_block() { # user domain sock|"" : add/replace or remove the marked block
  local f tmp; f="$(cust_file "$1" "$2")"; tmp="$f.qhturbo-tmp"
  touch "$f"
  sed "/^$MARK_BEGIN\$/,/^$MARK_END\$/d" "$f" > "$tmp"
  if [[ -n "$3" ]]; then
    cat >> "$tmp" <<EOF
$MARK_BEGIN
<FilesMatch "\\.(inc|php|php[0-9]+|phtml)\$">
    SetHandler "proxy:unix:$3|fcgi://localhost"
</FilesMatch>
$MARK_END
EOF
  fi
  # Keep DirectAdmin's ownership of the file.
  chown --reference="$f" "$tmp" 2>/dev/null || true
  chmod --reference="$f" "$tmp" 2>/dev/null || true
  mv "$tmp" "$f"
  [[ -s "$f" ]] || rm -f "$f"
}
apply() { # user : rewrite the user's vhosts, test, reload
  da_rewrite "$1"
  httpd_test || return 1
  httpd_reload
}

# ------------------------------------------------------------------ check --
do_check() {
  local ok=1 ws
  [[ -x "$DA_BIN" ]] && log "DirectAdmin: $DA" || { warn "DirectAdmin not found ($DA_BIN)"; ok=0; }
  ws="$(webserver)"
  case "$ws" in
    apache|nginx_apache) log "web server: $ws ($("$HTTPD_BIN" -v 2>/dev/null | head -1 || echo "httpd not found"))" ;;
    "") warn "cannot read the web server from $OPTIONS"; ok=0 ;;
    *) warn "web server '$ws' is not supported yet (apache and nginx_apache are)"; ok=0 ;;
  esac
  local os; os="$(. /etc/os-release && echo "$PRETTY_NAME")"
  warn_el7
  log "OS: $os, $(nproc) CPU, $(free -m | awk '/Mem:/{print $2}') MiB RAM, $(df -Pm / | awk 'NR==2{print $4}') MiB free on /"
  id "$WEB_USER" > /dev/null 2>&1 && log "web server user: $WEB_USER" || warn "user '$WEB_USER' not found (set DA_WEB_USER)"
  local n v list=""
  for n in 1 2 3 4; do
    v="$(option "php${n}_release")"
    [[ -n "$v" && "$v" != no ]] && list+="php$n=$v ($(option "php${n}_mode")) "
  done
  log "DirectAdmin PHP (left untouched): ${list:-none}"
  local d t missing
  if [[ -x "$PREFIX/sbin/php-fpm" ]]; then
    log "QHturbo PHP $PHPV installed in $PREFIX"
    for d in "$ROOT"/usr/local/php[0-9][0-9]/; do
      [[ -x "$d/bin/php" ]] || continue
      t="$(basename "$d")"; t="${t#php}"
      missing="$(comm -23 <(ext_list "$d/bin/php") <(ext_list "$PREFIX/bin/php") | tr '\n' ' ')"
      if [[ -n "${missing// /}" ]]; then warn "extensions in DirectAdmin PHP ${t:0:1}.${t:1} but not in QHturbo $PHPV: $missing"
      else log "QHturbo $PHPV has every extension of DirectAdmin PHP ${t:0:1}.${t:1}"; fi
    done
  else
    log "QHturbo PHP $PHPV not installed yet: run '$0 install --php=$PHPV'"
  fi
  (( ok )) || return 1
}

# ---------------------------------------------------------------- install --
do_sync_ext() { # [DirectAdmin PHP tag, default same version]
  local tag="${1:-$TAG}" src
  src="$ROOT/usr/local/php$tag/bin/php"
  [[ -x "$src" ]] || { log "no DirectAdmin PHP ${tag:0:1}.${tag:1} here; nothing to sync"; return 0; }
  log "syncing extensions from DirectAdmin PHP ${tag:0:1}.${tag:1} ($src)"
  QHT_LAB="$QHT_LAB" "$QHT_ROOT/scripts/sync-ext.sh" --from="$src" --php="$PHPV" \
    || warn "some extensions are still missing (see above); domains that need them may fail"
}

do_install() {
  need_root
  do_check || warn "continuing despite the warnings above"
  need_apache  # before the 10-40 minute build, not after
  build_and_install "$WEB_USER" "$WEB_USER"
  "$PREFIX/sbin/php-fpm" -t
  start_service
  [[ "${NO_SYNC_EXT:-0}" == 1 ]] || do_sync_ext
  "$PREFIX/bin/php" "$PREFIX/share/qhturbo/qht-doctor.php" || true
  cat >&2 <<EOF

QHturbo PHP $PHPV is installed and running. No domain uses it yet.
Next: switch ONE test domain:   sudo $0 enable <domain> --php=$PHPV
EOF
}

# ------------------------------------------------------------ enable/disable --
do_enable() {
  need_root
  need_apache
  local domain="${args[0]:-}" user cur da_php
  [[ -n "$domain" ]] || die "usage: $0 enable <domain> [--php=$PHPV]"
  user="$(domain_user "$domain")" || die "domain not found in DirectAdmin: $domain"
  fpm_alive "$TAG" || die "QHturbo PHP $PHPV is not running; start it (systemctl start $SERVICE) or run '$0 install --php=$PHPV'"
  cur="$(domain_qhturbo "$user" "$domain")"
  [[ -z "$cur" ]] || { log "$domain already uses QHturbo PHP $cur"; return 0; }
  da_php="$(domain_php "$user" "$domain")"

  # Same PHP version as the domain: bring over its extensions first.
  if [[ "${NO_SYNC_EXT:-0}" != 1 && "${da_php/./}" == "$TAG" ]]; then do_sync_ext "$TAG"; fi

  local before; before="$(http_code "$user" "$domain")"
  local f; f="$(cust_file "$user" "$domain")"
  [[ -f "$f" ]] && cp -p "$f" "$f.qhturbo-bak"
  write_pool "$user"
  fpm_reload "$TAG" || die "could not reload QHturbo PHP-FPM $PHPV"
  wait_socket "$(user_sock "$user")" || { rm -f "$(pool_file "$user")"; fpm_reload "$TAG" || true
    die "QHturbo PHP-FPM did not open $(user_sock "$user"); see /var/log/qhturbo/php$TAG-fpm.log"; }
  set_block "$user" "$domain" "$(user_sock "$user")"
  if ! apply "$user"; then
    set_block "$user" "$domain" ""; drop_pool_if_unused "$user"; apply "$user" || true
    die "Apache config test failed; $domain restored to DirectAdmin's PHP"
  fi
  local after; after="$(http_code "$user" "$domain")"
  if [[ "$after" =~ ^5 || "$after" == 000 ]] && [[ ! "$before" =~ ^5 ]]; then
    set_block "$user" "$domain" ""; drop_pool_if_unused "$user"; apply "$user" || true
    warn "PHP errors are in /var/log/httpd/domains/$domain.error.log and /var/log/qhturbo/php$TAG-fpm.log"
    [[ "${da_php/./}" == "$TAG" ]] || warn "the domain ran on PHP $da_php; for the fewest surprises use the same version: $0 install --php=$da_php"
    die "$domain answered HTTP $after with QHturbo (was $before); switched back to DirectAdmin's PHP $da_php"
  fi
  log "$domain (user $user) now runs on QHturbo PHP $PHPV (was DirectAdmin PHP $da_php); HTTP $before -> $after"
  log "undo: sudo $0 disable $domain"
}

do_disable() {
  need_root
  need_apache
  local domain="${args[0]:-}" user cur
  [[ -n "$domain" ]] || die "usage: $0 disable <domain>"
  user="$(domain_user "$domain")" || die "domain not found in DirectAdmin: $domain"
  cur="$(domain_qhturbo "$user" "$domain")"
  [[ -n "$cur" ]] || { log "$domain does not use QHturbo"; return 0; }
  PHPV="$cur"; TAG="${cur/./}"; PREFIX="/opt/qhturbo/$PHPV"
  set_block "$user" "$domain" ""
  apply "$user" || die "Apache config test failed after removing the QHturbo block; inspect $(cust_file "$user" "$domain")"
  drop_pool_if_unused "$user"
  log "$domain is back on DirectAdmin's PHP $(domain_php "$user" "$domain"), HTTP $(http_code "$user" "$domain")"
}

# ------------------------------------------------------------------ lists --
# Tab-separated, one line per supported PHP branch:
#   branch  installed(0|1)  fpm_running(0|1)  php_version  directadmin_php(0|1)
do_versions() {
  local f v t installed running ver da
  for f in "$QHT_ROOT"/config/versions/*.env; do
    v="$(basename "$f" .env)"; t="${v/./}"
    installed=0 running=0 ver="" da=0
    if [[ -x "/opt/qhturbo/$v/sbin/php-fpm" ]]; then
      installed=1
      ver="$("/opt/qhturbo/$v/bin/php" -n -r 'echo PHP_VERSION;' 2>/dev/null || true)"
    fi
    fpm_alive "$t" && running=1
    [[ -x "$ROOT/usr/local/php$t/bin/php" ]] && da=1
    printf '%s\t%s\t%s\t%s\t%s\n' "$v" "$installed" "$running" "${ver:--}" "$da"
  done
}

# Tab-separated, one line per domain:
#   domain  user  directadmin_php  qhturbo_version(or -)
do_sites() {
  local f user d q
  for f in "$USERS"/*/domains.list; do
    [[ -f "$f" ]] || continue
    user="$(basename "$(dirname "$f")")"
    while read -r d; do
      [[ -n "$d" ]] || continue
      q="$(domain_qhturbo "$user" "$d")"
      printf '%s\t%s\t%s\t%s\n' "$d" "$user" "$(domain_php "$user" "$d")" "${q:--}"
    done < "$f"
  done
}

do_status() {
  local d u p q any=0
  while IFS=$'\t' read -r d u p q; do
    [[ "$q" == - ]] && continue
    any=1; printf '    %-40s user %-16s QHturbo PHP %s (DirectAdmin PHP %s)\n' "$d" "$u" "$q" "$p" >&2
  done < <(do_sites)
  (( any )) && log "domains above use QHturbo" || log "no domain uses QHturbo"
  local v i r ver _
  while IFS=$'\t' read -r v i r ver _; do
    [[ "$i" == 1 ]] || continue
    if [[ "$r" == 1 ]]; then log "QHturbo PHP $ver: FPM running"; else warn "QHturbo PHP $ver: FPM not running"; fi
  done < <(do_versions)
}

do_uninstall() {
  need_root
  local d u p q
  while IFS=$'\t' read -r d u p q; do
    [[ "$q" == "$PHPV" ]] || continue
    args=("$d"); ( do_disable ) || warn "could not switch $d back"
  done < <(do_sites)
  if (( SYSTEMD )) && [[ -f "/etc/systemd/system/$SERVICE.service" ]]; then
    systemctl disable --now "$SERVICE" || true
    rm -f "/etc/systemd/system/$SERVICE.service"; systemctl daemon-reload
  elif [[ -f "/run/qhturbo/php$TAG-fpm.pid" ]]; then
    kill "$(cat "/run/qhturbo/php$TAG-fpm.pid")" 2>/dev/null || true
  fi
  rm -f "$PREFIX"/etc/php-fpm.d/da-*.conf
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
  uninstall) do_uninstall ;;
  *) sed -n '2,32p' "$0" | sed 's/^# \{0,1\}//'; exit 1 ;;
esac
