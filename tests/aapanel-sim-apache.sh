#!/usr/bin/env bash
# End-to-end test of scripts/aapanel.sh on a simulated aaPanel that uses
# Apache (vhosts in /www/server/panel/vhost/apache, PHP through
# SetHandler "proxy:unix:/tmp/php-cgi-XX.sock|fcgi://localhost").
# Needs root, Apache 2.4 (Debian apache2 binary + modules), curl and an
# installed QHturbo PHP (scripts/install.sh --user=www --group=www).
#   sudo tests/aapanel-sim-apache.sh [--php=8.5] [--panel-fpm=/opt/qhturbo/7.4/sbin/php-fpm]
set -euo pipefail

PHPV=8.5
PANEL_FPM=""
for a in "$@"; do
  case "$a" in
    --php=*) PHPV="${a#*=}" ;;
    --panel-fpm=*) PANEL_FPM="${a#*=}" ;;
  esac
done
QHT_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SIM=/srv/qhturbo-aapanel-sim-apache
PORT=18091
TAG="${PHPV/./}"
PANEL_TAG=84
MODS=/usr/lib/apache2/modules
PANEL_FPM="${PANEL_FPM:-$(command -v php-fpm8.3 || command -v php-fpm || echo /opt/qhturbo/7.4/sbin/php-fpm)}"
QSOCK="/run/qhturbo/php$TAG-fpm.sock"
[[ $EUID -eq 0 ]] || { echo "run as root"; exit 1; }
[[ -x /usr/sbin/apache2 && -f $MODS/mod_proxy_fcgi.so ]] || { echo "need Apache 2.4 (apt install apache2)"; exit 1; }
[[ -S "$QSOCK" ]] || { echo "QHturbo PHP $PHPV FPM is not running ($QSOCK)"; exit 1; }
[[ -x "$PANEL_FPM" ]] || { echo "need a php-fpm to play the aaPanel PHP (--panel-fpm=...)"; exit 1; }
id www > /dev/null 2>&1 || useradd -r -s /usr/sbin/nologin www

pass=0 fail=0
ok()   { pass=$((pass + 1)); printf '  ok    %s\n' "$*"; }
bad()  { fail=$((fail + 1)); printf '  FAIL  %s\n' "$*"; }
site() { sleep 0.5; curl -s --max-time 5 -H 'Host: test.local' "http://127.0.0.1:$PORT/" || true; }

stop_all() {
  [[ -f $SIM/httpd.pid ]] && kill "$(cat "$SIM/httpd.pid")" 2> /dev/null || true
  [[ -f $SIM/panel-fpm.pid ]] && kill "$(cat "$SIM/panel-fpm.pid")" 2> /dev/null || true
  sleep 1
}
trap stop_all EXIT
stop_all
rm -rf "${SIM:?}"
mkdir -p "$SIM"/www/server/{panel/vhost/apache,apache/bin,php/$PANEL_TAG/etc} "$SIM"/www/{wwwroot/test.local,wwwlogs} "$SIM/run"

cat > "$SIM/httpd.conf" <<CONF
LoadModule mpm_event_module $MODS/mod_mpm_event.so
LoadModule authz_core_module $MODS/mod_authz_core.so
LoadModule dir_module $MODS/mod_dir.so
LoadModule mime_module $MODS/mod_mime.so
LoadModule proxy_module $MODS/mod_proxy.so
LoadModule proxy_fcgi_module $MODS/mod_proxy_fcgi.so
ServerName localhost
Listen $PORT
User www
Group www
PidFile $SIM/httpd.pid
ErrorLog $SIM/apache-error.log
DefaultRuntimeDir $SIM/run
Mutex file:$SIM/run default
TypesConfig /etc/mime.types
IncludeOptional $SIM/www/server/panel/vhost/apache/*.conf
CONF
printf '#!/bin/sh\nexec /usr/sbin/apache2 -f %s "$@"\n' "$SIM/httpd.conf" > "$SIM/www/server/apache/bin/httpd"
chmod +x "$SIM/www/server/apache/bin/httpd"

# The vhost as aaPanel writes it (HTTP + HTTPS blocks share the PHP handler line).
cat > "$SIM/www/server/panel/vhost/apache/test.local.conf" <<CONF
<VirtualHost *:$PORT>
    ServerAdmin webmaster@example.com
    DocumentRoot "$SIM/www/wwwroot/test.local"
    ServerName test.local
    ErrorLog "$SIM/www/wwwlogs/test.local-error_log"

    #PHP
    <FilesMatch \.php$>
            SetHandler "proxy:unix:/tmp/qht-sim-php-cgi-$PANEL_TAG.sock|fcgi://localhost"
    </FilesMatch>

    #PATH
    <Directory "$SIM/www/wwwroot/test.local">
        SetOutputFilter DEFLATE
        Options FollowSymLinks
        AllowOverride All
        Require all granted
        DirectoryIndex index.php index.html default.php index.htm default.html
    </Directory>
</VirtualHost>
CONF
sed -i '/SetOutputFilter/d' "$SIM/www/server/panel/vhost/apache/test.local.conf"   # mod_deflate not loaded here
cat > "$SIM/www/server/php/$PANEL_TAG/etc/php.ini" <<'INI'
memory_limit = 256M
upload_max_filesize = 50M
disable_functions = exec,system,shell_exec,passthru,pcntl_exec,pcntl_fork,pcntl_signal
INI
cat > "$SIM/www/server/php/$PANEL_TAG/etc/php-fpm.conf" <<CONF
[global]
pid = $SIM/panel-fpm.pid
error_log = $SIM/panel-fpm.log
[www]
user = www
group = www
listen = /tmp/qht-sim-php-cgi-$PANEL_TAG.sock
listen.owner = www
listen.group = www
pm = static
pm.max_children = 2
CONF
cat > "$SIM/www/wwwroot/test.local/index.php" <<'PHP'
<?php
echo PHP_VERSION, '|', PHP_SAPI, '|', ini_get('upload_max_filesize');
PHP
chown -R www:www "$SIM/www/wwwroot"

"$PANEL_FPM" -n --fpm-config "$SIM/www/server/php/$PANEL_TAG/etc/php-fpm.conf"
"$SIM/www/server/apache/bin/httpd" -k start
panel_version="$(site | cut -d'|' -f1)"
qht_version="$("/opt/qhturbo/$PHPV/bin/php" -r 'echo PHP_VERSION;')"
echo "aaPanel PHP (simulated, Apache): $panel_version, QHturbo: $qht_version"
[[ -n "$panel_version" ]] && ok "site serves through Apache + the simulated aaPanel PHP" || bad "simulated site does not answer: $(tail -3 "$SIM/apache-error.log")"

export AAPANEL_ROOT="$SIM"
A="$QHT_ROOT/scripts/aapanel.sh"
url="--url=http://127.0.0.1:$PORT/"
vhost="$SIM/www/server/panel/vhost/apache/test.local.conf"
cp "$vhost" "$SIM/original.conf"

out="$("$A" check --php="$PHPV" 2>&1)" || true
grep -q 'web server: Server version: Apache' <<< "$out" && ok "check detects Apache" || bad "check: $out"
grep -q 'php-cgi-84.sock' <<< "$out" && ok "check lists the site's PHP socket" || bad "check site list: $out"

"$A" enable test.local --php="$PHPV" "$url" > "$SIM/enable.log" 2>&1 && ok "enable" || bad "enable: $(tail -5 "$SIM/enable.log")"
out="$(site)"
[[ "$out" == "$qht_version|fpm-fcgi|"* ]] && ok "site runs on QHturbo through Apache ($out)" || bad "after enable: $out"
grep -q "proxy:unix:$QSOCK|fcgi://localhost" "$vhost" && ok "vhost points at $QSOCK" || bad "vhost: $(grep SetHandler "$vhost")"
out="$("$A" status 2>&1)" || true
grep -q 'test.local' <<< "$out" && ok "status lists the site" || bad "status: $out"
out="$("$A" enable test.local --php="$PHPV" "$url" 2>&1)" || true
grep -q 'already uses QHturbo' <<< "$out" && ok "enable twice is a no-op" || bad "second enable: $out"

"$A" disable test.local --php="$PHPV" "$url" > /dev/null 2>&1 && ok "disable" || bad "disable"
[[ "$(site | cut -d'|' -f1)" == "$panel_version" ]] && ok "site back on aaPanel PHP" || bad "after disable: $(site)"
cmp -s "$vhost" "$SIM/original.conf" && ok "vhost identical to the original" || bad "vhost differs: $(diff "$SIM/original.conf" "$vhost")"

# QHturbo unreachable for Apache (socket not accessible): enable must roll back.
owner="$(stat -c '%U:%G' "$QSOCK")"; mode="$(stat -c '%a' "$QSOCK")"
chown root:root "$QSOCK"; chmod 600 "$QSOCK"
if "$A" enable test.local --php="$PHPV" "$url" > /dev/null 2>&1; then bad "enable should fail when Apache cannot reach QHturbo"; else ok "enable refused (HTTP 503)"; fi
chown "$owner" "$QSOCK"; chmod "$mode" "$QSOCK"
[[ "$(site | cut -d'|' -f1)" == "$panel_version" ]] && ok "automatic rollback kept the site on aaPanel PHP" || bad "rollback: $(site)"
cmp -s "$vhost" "$SIM/original.conf" && ok "vhost restored after rollback" || bad "vhost not restored"

# Code that fails only on QHturbo's PHP: enable rolls back and says why.
idx="$SIM/www/wwwroot/test.local/index.php"
cp -p "$idx" "$idx.orig"
printf '%s\n' '<?php' 'if (PHP_VERSION_ID >= 80000) { qht_function_missing_on_new_php(); }' 'echo PHP_VERSION;' > "$idx"
if out="$("$A" enable test.local --php="$PHPV" "$url" 2>&1)"; then bad "enable should fail when the site errors on QHturbo"; else ok "enable refused (HTTP 500)"; fi
grep -q 'qht_function_missing_on_new_php' <<< "$out" && ok "rollback shows the PHP error" || bad "no PHP error shown: $out"
grep -q 'the site ran on PHP 8.4' <<< "$out" && ok "rollback suggests the site's PHP version" || bad "no version hint: $out"
mv "$idx.orig" "$idx"
cmp -s "$vhost" "$SIM/original.conf" && ok "vhost restored" || bad "vhost not restored"

# The aaPanel plugin backend, as the panel calls it.
jobs="$SIM/plugin-jobs"; mkdir -p "$jobs"
if out="$(AAPANEL_ROOT="$SIM" QHT_SRC="$QHT_ROOT" QHT_JOB_DIR="$jobs" QHT_URL="http://127.0.0.1:$PORT/" \
          timeout 240 python3 -u "$QHT_ROOT/tests/aapanel-plugin.py" "$QHT_ROOT/plugins/aapanel/qhturbo" 2>&1)"; then
  ok "aaPanel plugin backend ($(grep -c '^  ok' <<< "$out") checks)"
else
  bad "aaPanel plugin backend:
$out"
fi
cmp -s "$vhost" "$SIM/original.conf" && ok "vhost identical after the plugin tests" || bad "vhost differs after the plugin tests"

echo
echo "$pass passed, $fail failed"
exit $(( fail > 0 ))
