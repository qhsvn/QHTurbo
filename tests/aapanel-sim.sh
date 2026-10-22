#!/usr/bin/env bash
# End-to-end test of scripts/aapanel.sh against a simulated aaPanel layout
# (/www/server/... under a temporary root, nginx, an "aaPanel" PHP-FPM).
# Needs root, nginx, curl and an installed QHturbo PHP (scripts/install.sh).
#   sudo tests/aapanel-sim.sh [--php=8.5] [--panel-fpm=/opt/qhturbo/7.4/sbin/php-fpm]
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
SIM=/srv/qhturbo-aapanel-sim
PORT=18090
TAG="${PHPV/./}"
PANEL_FPM="${PANEL_FPM:-$(command -v php-fpm8.3 || command -v php-fpm || echo /opt/qhturbo/7.4/sbin/php-fpm)}"
[[ $EUID -eq 0 ]] || { echo "run as root"; exit 1; }
[[ -x "/opt/qhturbo/$PHPV/sbin/php-fpm" ]] || { echo "install QHturbo PHP $PHPV first"; exit 1; }
[[ -x "$PANEL_FPM" ]] || { echo "need a php-fpm to play the aaPanel PHP (--panel-fpm=...)"; exit 1; }
id www > /dev/null 2>&1 || useradd -r -s /usr/sbin/nologin www

pass=0 fail=0
ok()   { pass=$((pass + 1)); printf '  ok    %s\n' "$*"; }
bad()  { fail=$((fail + 1)); printf '  FAIL  %s\n' "$*"; }
site() { sleep 0.5; curl -s --max-time 5 -H 'Host: test.local' "http://127.0.0.1:$PORT/" || true; }

stop_all() {
  [[ -f $SIM/nginx.pid ]] && kill "$(cat "$SIM/nginx.pid")" 2> /dev/null || true
  [[ -f $SIM/panel-fpm.pid ]] && kill "$(cat "$SIM/panel-fpm.pid")" 2> /dev/null || true
  sleep 1
}
trap stop_all EXIT
stop_all
rm -rf "${SIM:?}"
mkdir -p "$SIM"/www/server/{panel/vhost/nginx,nginx/conf,nginx/sbin,php/$TAG/etc} "$SIM"/www/{wwwroot/test.local,wwwlogs}

cp /etc/nginx/fastcgi.conf /etc/nginx/mime.types "$SIM/www/server/nginx/conf/"
echo 'set $real_script_name $fastcgi_script_name;' > "$SIM/www/server/nginx/conf/pathinfo.conf"
cat > "$SIM/www/server/nginx/conf/enable-php-$TAG.conf" <<EOF
    location ~ [^/]\.php(/|\$)
    {
        try_files \$uri =404;
        fastcgi_pass  unix:/tmp/qht-sim-php-cgi-$TAG.sock;
        fastcgi_index index.php;
        include fastcgi.conf;
        include pathinfo.conf;
    }
EOF
cat > "$SIM/www/server/panel/vhost/nginx/test.local.conf" <<EOF
server
{
    listen $PORT;
    server_name test.local;
    index index.php index.html;
    root $SIM/www/wwwroot/test.local;
    #PHP-INFO-START
    include enable-php-$TAG.conf;
    #PHP-INFO-END
    error_log $SIM/www/wwwlogs/test.local.error.log;
}
EOF
cat > "$SIM/www/server/nginx/conf/nginx.conf" <<EOF
user www;
worker_processes 1;
pid $SIM/nginx.pid;
error_log $SIM/nginx-error.log;
events { worker_connections 256; }
http {
  include mime.types;
  access_log off;
  client_body_temp_path $SIM/tmp-body; fastcgi_temp_path $SIM/tmp-fcgi;
  include $SIM/www/server/panel/vhost/nginx/*.conf;
}
EOF
printf '#!/bin/sh\nexec /usr/sbin/nginx -c %s "$@"\n' "$SIM/www/server/nginx/conf/nginx.conf" > "$SIM/www/server/nginx/sbin/nginx"
chmod +x "$SIM/www/server/nginx/sbin/nginx"
cat > "$SIM/www/server/php/$TAG/etc/php.ini" <<'EOF'
memory_limit = 256M
upload_max_filesize = 50M
disable_functions = exec,system,shell_exec,passthru,pcntl_exec,pcntl_fork,pcntl_signal
session.save_handler = files
;session.save_path = "/tmp"
EOF
cat > "$SIM/www/server/php/$TAG/etc/php-fpm.conf" <<EOF
[global]
pid = $SIM/panel-fpm.pid
error_log = $SIM/panel-fpm.log
[www]
user = www
group = www
listen = /tmp/qht-sim-php-cgi-$TAG.sock
listen.owner = www
listen.group = www
pm = static
pm.max_children = 2
EOF
cat > "$SIM/www/wwwroot/test.local/index.php" <<'EOF'
<?php
echo PHP_VERSION, '|', ini_get('upload_max_filesize'), '|', function_exists('exec') && !in_array('exec', explode(',', ini_get('disable_functions'))) ? 'exec-allowed' : 'exec-blocked';
EOF
chown -R www:www "$SIM/www/wwwroot"

"$PANEL_FPM" -n --fpm-config "$SIM/www/server/php/$TAG/etc/php-fpm.conf"
"$SIM/www/server/nginx/sbin/nginx"
panel_version="$(site | cut -d'|' -f1)"
qht_version="$("/opt/qhturbo/$PHPV/bin/php" -r 'echo PHP_VERSION;')"
echo "aaPanel PHP (simulated): $panel_version, QHturbo: $qht_version"
[[ -n "$panel_version" ]] && ok "site serves through the simulated aaPanel PHP" || bad "simulated site does not answer"

export AAPANEL_ROOT="$SIM"
A="$QHT_ROOT/scripts/aapanel.sh"
url="--url=http://127.0.0.1:$PORT/"
vhost="$SIM/www/server/panel/vhost/nginx/test.local.conf"
cp "$vhost" "$SIM/original.conf"

"$A" check --php="$PHPV" > /dev/null 2>&1 && ok "check" || bad "check"
"$A" sync-ini --php="$PHPV" > /dev/null 2>&1 && ok "sync-ini (session.save_path commented out)" || bad "sync-ini"
perf_ini="/opt/qhturbo/$PHPV/etc/performance.d/05-from-aapanel.ini"
if [[ -d "/opt/qhturbo/$PHPV/etc/performance.d" ]]; then
  grep -qx 'disable_functions = exec,system,shell_exec,passthru,pcntl_exec' "$perf_ini" \
    && ok "Performance ini re-allows only the pcntl functions workers need" || bad "$perf_ini: $(cat "$perf_ini" 2>&1)"
fi
[[ -S "/run/qhturbo/php$TAG-fpm.sock" ]] || "/opt/qhturbo/$PHPV/sbin/php-fpm" --fpm-config "/opt/qhturbo/$PHPV/etc/php-fpm.conf"
kill -USR2 "$(cat "/run/qhturbo/php$TAG-fpm.pid")"; sleep 1

"$A" enable test.local --php="$PHPV" "$url" > /dev/null 2>&1 && ok "enable" || bad "enable"
out="$(site)"
[[ "$out" == "$qht_version|50M|exec-blocked" ]] && ok "site runs on QHturbo with aaPanel limits + disable_functions ($out)" || bad "after enable: $out"
status_out="$("$A" status 2>&1)"; grep -q 'test.local' <<< "$status_out" && ok "status lists the site" || bad "status"

"$A" disable test.local --php="$PHPV" "$url" > /dev/null 2>&1 && ok "disable" || bad "disable"
[[ "$(site | cut -d'|' -f1)" == "$panel_version" ]] && ok "site back on aaPanel PHP" || bad "after disable: $(site)"
cmp -s "$vhost" "$SIM/original.conf" && ok "vhost identical to the original" || bad "vhost differs from the original"

inc="$SIM/www/server/nginx/conf/enable-php-qhturbo$TAG.conf"
cp "$inc" "$inc.good"
sed -i "s#php$TAG-fpm.sock#missing.sock#" "$inc"
if "$A" enable test.local --php="$PHPV" "$url" > /dev/null 2>&1; then bad "enable should fail with a broken QHturbo"; else ok "enable refused (HTTP 502)"; fi
[[ "$(site | cut -d'|' -f1)" == "$panel_version" ]] && ok "automatic rollback kept the site on aaPanel PHP" || bad "rollback: $(site)"
cmp -s "$vhost" "$SIM/original.conf" && ok "vhost restored after rollback" || bad "vhost not restored"
mv "$inc.good" "$inc"

echo
echo "$pass passed, $fail failed"
exit $(( fail > 0 ))
