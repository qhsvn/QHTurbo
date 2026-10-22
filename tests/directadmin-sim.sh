#!/usr/bin/env bash
# End-to-end test of scripts/directadmin.sh against a simulated DirectAdmin
# server: DirectAdmin's data layout, two hosting users each with their own
# PHP 7.4 FPM pool (running as the user), vhosts that use AddHandler like
# DirectAdmin's templates, a fake "directadmin taskq" that rewrites a user's
# httpd.conf including the domains' .cust_httpd files, and a real Apache.
# Needs root, Apache 2.4 (Debian apache2 + modules), curl, and installed
# QHturbo PHP 7.4 (plays DirectAdmin's PHP) and 8.5.
#   sudo tests/directadmin-sim.sh
set -euo pipefail

QHT_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SIM=/srv/qhturbo-da-sim
PORT=18095
MODS=/usr/lib/apache2/modules
WEBU=www
[[ $EUID -eq 0 ]] || { echo "run as root"; exit 1; }
for v in 7.4 8.5; do [[ -x /opt/qhturbo/$v/sbin/php-fpm ]] || { echo "install QHturbo PHP $v first"; exit 1; }; done
[[ -x /usr/sbin/apache2 && -f $MODS/mod_proxy_fcgi.so ]] || { echo "need Apache 2.4 (apt install apache2)"; exit 1; }
id "$WEBU" > /dev/null 2>&1 || useradd -r -s /usr/sbin/nologin "$WEBU"
for u in qhtu1 qhtu2; do id "$u" > /dev/null 2>&1 || useradd -r -M -s /usr/sbin/nologin "$u"; done

pass=0 fail=0
ok()  { pass=$((pass + 1)); printf '  ok    %s\n' "$*"; }
bad() { fail=$((fail + 1)); printf '  FAIL  %s\n' "$*"; }
page() { sleep 0.3; curl -s --max-time 5 -H "Host: $1" "http://127.0.0.1:$PORT/" || true; }

stop_all() {
  [[ -f $SIM/httpd.pid ]] && kill "$(cat "$SIM/httpd.pid")" 2> /dev/null || true
  [[ -f $SIM/da74.pid ]] && kill "$(cat "$SIM/da74.pid")" 2> /dev/null || true
  [[ -f /run/qhturbo/php85-fpm.pid ]] && kill "$(cat /run/qhturbo/php85-fpm.pid)" 2> /dev/null || true
  rm -f /opt/qhturbo/8.5/etc/php-fpm.d/da-qhtu*.conf
  sleep 1
}
trap stop_all EXIT
stop_all
rm -rf "${SIM:?}"
DA="$SIM/usr/local/directadmin"
mkdir -p "$DA/custombuild" "$DA/data/users" "$DA/data/admin" "$SIM/usr/sbin" "$SIM/run" \
  "$SIM/usr/local/php74/bin" "$SIM/usr/local/php74/etc/php-fpm.d" "$SIM/usr/local/php74/sockets"
chmod 755 "$SIM" "$SIM/usr" "$SIM/usr/local" "$SIM/usr/local/php74" "$SIM/usr/local/php74/sockets"

# CustomBuild options and DirectAdmin's PHP 7.4 (QHturbo's 7.4 build plays it).
printf 'webserver=apache\nphp1_release=7.4\nphp1_mode=php-fpm\nphp2_release=no\n' > "$DA/custombuild/options.conf"
ln -s /opt/qhturbo/7.4/bin/php "$SIM/usr/local/php74/bin/php"
echo admin > "$DA/data/admin/admin.list"

# Users, domains, DirectAdmin's per-user FPM pools.
cat > "$SIM/da74-fpm.conf" <<EOF
[global]
pid = $SIM/da74.pid
error_log = $SIM/da74.log
include = $SIM/usr/local/php74/etc/php-fpm.d/*.conf
EOF
n=0
for u in qhtu1 qhtu2; do
  n=$((n + 1)); d="site$n.test"
  mkdir -p "$DA/data/users/$u/domains" "$SIM/home/$u/domains/$d/public_html"
  echo "$d" > "$DA/data/users/$u/domains.list"
  printf 'ip=127.0.0.1\nphp1_select=1\n' > "$DA/data/users/$u/domains/$d.conf"
  cat > "$SIM/home/$u/domains/$d/public_html/index.php" <<'PHP'
<?php
echo PHP_VERSION, '|', posix_getpwuid(posix_geteuid())['name'], '|', ini_get('open_basedir');
PHP
  chown -R "$u:$u" "$SIM/home/$u"; chmod -R a+rX "$SIM/home"
  cat > "$SIM/usr/local/php74/etc/php-fpm.d/$u.conf" <<EOF
[$u]
user = $u
group = $u
listen = $SIM/usr/local/php74/sockets/$u.sock
listen.owner = $u
listen.group = $WEBU
listen.mode = 660
pm = ondemand
pm.max_children = 5
php_admin_value[open_basedir] = $SIM/home/$u/:/tmp/
EOF
done
/opt/qhturbo/7.4/sbin/php-fpm -n --fpm-config "$SIM/da74-fpm.conf"

# Fake "directadmin taskq --run 'action=rewrite&value=httpd&user=U'": like
# DirectAdmin, the custom httpd code goes into the CUSTOM token, here BEFORE
# the AddHandler block (the harder order for an override).
cat > "$DA/directadmin" <<EOF
#!/bin/bash
[[ "\$1" == taskq && "\$2" == --run ]] || exit 1
u="\$(sed -E 's/.*user=([a-z0-9]+).*/\1/' <<< "\$3")"
out="$DA/data/users/\$u/httpd.conf"
: > "\$out"
while read -r d; do
  cust=""; [[ -f "$DA/data/users/\$u/domains/\$d.cust_httpd" ]] && cust="\$(cat "$DA/data/users/\$u/domains/\$d.cust_httpd")"
  cat >> "\$out" <<VH
<VirtualHost *:$PORT>
  ServerName \$d
  DocumentRoot $SIM/home/\$u/domains/\$d/public_html
\$cust
  <FilesMatch "\\.(inc|php|php3|php4|php5|php7|php8|phtml)\$">
    AddHandler "proxy:unix:$SIM/usr/local/php74/sockets/\$u.sock|fcgi://localhost" .inc .php .php3 .php4 .php5 .php7 .php8 .phtml
  </FilesMatch>
  <Directory $SIM/home/\$u/domains/\$d/public_html>
    DirectoryIndex index.php
    Require all granted
  </Directory>
</VirtualHost>
VH
done < "$DA/data/users/\$u/domains.list"
EOF
chmod +x "$DA/directadmin"
cat > "$SIM/httpd.conf" <<EOF
LoadModule mpm_event_module $MODS/mod_mpm_event.so
LoadModule authz_core_module $MODS/mod_authz_core.so
LoadModule dir_module $MODS/mod_dir.so
LoadModule mime_module $MODS/mod_mime.so
LoadModule proxy_module $MODS/mod_proxy.so
LoadModule proxy_fcgi_module $MODS/mod_proxy_fcgi.so
ServerName localhost
Listen $PORT
User $WEBU
Group $WEBU
PidFile $SIM/httpd.pid
ErrorLog $SIM/httpd-error.log
DefaultRuntimeDir $SIM/run
Mutex file:$SIM/run default
TypesConfig /etc/mime.types
IncludeOptional $DA/data/users/*/httpd.conf
EOF
printf '#!/bin/sh\nexec /usr/sbin/apache2 -f %s "$@"\n' "$SIM/httpd.conf" > "$SIM/usr/sbin/httpd"
chmod +x "$SIM/usr/sbin/httpd"
for u in qhtu1 qhtu2; do "$DA/directadmin" taskq --run "action=rewrite&value=httpd&user=$u"; done
# An admin's own custom httpd code must survive enable/disable.
echo "# admin's own custom code" > "$DA/data/users/qhtu1/domains/site1.test.cust_httpd"
cp "$DA/data/users/qhtu1/domains/site1.test.cust_httpd" "$SIM/site1.cust.orig"
"$DA/directadmin" taskq --run "action=rewrite&value=httpd&user=qhtu1"
"$SIM/usr/sbin/httpd" -k start
# QHturbo 8.5 FPM (the da-<user> pools are added to it).
rm -f /run/qhturbo/php85-fpm.sock /run/qhturbo/php85-fpm.pid
/opt/qhturbo/8.5/sbin/php-fpm --fpm-config /opt/qhturbo/8.5/etc/php-fpm.conf

export DA_ROOT="$SIM" DA_WEB_USER="$WEBU" HTTPD_RELOAD="$SIM/usr/sbin/httpd -k graceful" QHT_URL="http://127.0.0.1:$PORT/"
A="$QHT_ROOT/scripts/directadmin.sh"

out="$(page site1.test)"
[[ "$out" == "7.4.33|qhtu1|$SIM/home/qhtu1/:/tmp/" ]] && ok "site1 runs on DirectAdmin PHP 7.4 as its user ($out)" || bad "site1 before: $out"
out="$("$A" check --php=8.5 2>&1)" || true
grep -q 'web server: apache' <<< "$out" && grep -q 'php1=7.4' <<< "$out" && ok "check reads CustomBuild options" || bad "check: $out"
out="$("$A" sites)"
grep -qP '^site1\.test\tqhtu1\t7\.4\t-$' <<< "$out" && ok "sites lists domain, user, DirectAdmin PHP" || bad "sites: $out"

out="$("$A" enable site1.test --php=8.5 2>&1)" && ok "enable" || bad "enable: $out"
out="$(page site1.test)"
[[ "$out" == "8.5.12|qhtu1|$SIM/home/qhtu1/:/tmp/" ]] && ok "site1 on QHturbo 8.5, same user and open_basedir ($out)" || bad "site1 after enable: $out"
[[ "$(page site2.test)" == 7.4.33* ]] && ok "site2 (other user) still on DirectAdmin PHP" || bad "site2: $(page site2.test)"
grep -q "^# admin's own custom code" "$DA/data/users/qhtu1/domains/site1.test.cust_httpd" && ok "admin's custom httpd code kept" || bad "custom code lost"
[[ -f /opt/qhturbo/8.5/etc/php-fpm.d/da-qhtu1.conf ]] && ok "pool da-qhtu1 created from DirectAdmin's pool" || bad "no pool file"
grep -qP '^site1\.test\tqhtu1\t7\.4\t8\.5$' <<< "$("$A" sites)" && ok "sites shows site1 on QHturbo 8.5" || bad "sites after enable: $("$A" sites)"
grep -q 'already uses QHturbo' <<< "$("$A" enable site1.test --php=8.5 2>&1)" && ok "enable twice is a no-op" || bad "second enable"

out="$("$A" disable site1.test 2>&1)" && ok "disable" || bad "disable: $out"
[[ "$(page site1.test)" == 7.4.33* ]] && ok "site1 back on DirectAdmin PHP" || bad "after disable: $(page site1.test)"
cmp -s "$DA/data/users/qhtu1/domains/site1.test.cust_httpd" "$SIM/site1.cust.orig" && ok "custom httpd file identical to the original" || bad "cust_httpd differs"
[[ ! -f /opt/qhturbo/8.5/etc/php-fpm.d/da-qhtu1.conf ]] && ok "unused pool removed" || bad "pool left behind"

# Code that fails only on QHturbo's PHP 8.5: rolled back, file restored.
idx="$SIM/home/qhtu2/domains/site2.test/public_html/index.php"
cp -p "$idx" "$SIM/idx.orig"
printf '%s\n' '<?php' 'if (PHP_VERSION_ID >= 80000) { qht_missing_on_new_php(); }' 'echo PHP_VERSION;' > "$idx"
if out="$("$A" enable site2.test --php=8.5 2>&1)"; then bad "enable should fail when the site errors on QHturbo"; else ok "enable refused (HTTP 500)"; fi
grep -q 'switched back' <<< "$out" && grep -q 'the domain ran on PHP 7.4' <<< "$out" && ok "rollback explained" || bad "rollback output: $out"
[[ "$(page site2.test)" == 7.4.33 ]] && ok "site2 still on DirectAdmin PHP after rollback" || bad "site2 after rollback: $(page site2.test)"
[[ ! -f "$DA/data/users/qhtu2/domains/site2.test.cust_httpd" && ! -f /opt/qhturbo/8.5/etc/php-fpm.d/da-qhtu2.conf ]] \
  && ok "no custom file or pool left after rollback" || bad "leftovers after rollback"
mv "$SIM/idx.orig" "$idx"

out="$("$A" enable nosuch.test --php=8.5 2>&1)" && bad "unknown domain accepted" || ok "unknown domain refused"
out="$("$A" versions)"
grep -qP '^8\.5\t1\t1\t8\.5\.12\t0$' <<< "$out" && grep -qP '^7\.4\t1\t\d\t7\.4\.33\t1$' <<< "$out" && ok "versions" || bad "versions: $out"

# ---- The plugin page (runs as a DirectAdmin admin's Linux user) and the root agent.
id qhtadmin > /dev/null 2>&1 || useradd -r -M -s /usr/sbin/nologin qhtadmin
echo qhtadmin >> "$DA/data/admin/admin.list"
export QHT_SPOOL="$SIM/spool" QHT_STATE="$SIM/state" QHT_JOBLOG="$SIM/jobs"
install -d -m 1733 "$QHT_SPOOL"; install -d -m 755 "$QHT_STATE" "$QHT_JOBLOG"
echo directadmin > "$QHT_STATE/panel"
PLUG="$QHT_ROOT/plugins/directadmin/qhturbo"
TOKEN="$(head -c 16 /dev/urandom | od -An -tx1 | tr -d ' \n')"
echo "$TOKEN" > "$SIM/token"; chmod 644 "$SIM/token"
chmod 755 "$SIM"
AGENT="$QHT_ROOT/scripts/panel-agent.sh"
"$AGENT" refresh
# DirectAdmin's PHP may be old: run the page with PHP 7.4.
page_as() { # user query
  runuser -u "$1" -- env QUERY_STRING="$2" QHT_SPOOL="$QHT_SPOOL" QHT_STATE="$QHT_STATE" QHT_JOBLOG="$QHT_JOBLOG" \
    QHT_TOKEN_FILE="$SIM/token" /opt/qhturbo/7.4/bin/php -n "$PLUG/admin/index.html" 2>&1
}
out="$(page_as qhtadmin '')"
grep -q '"domain":"site1.test","user":"qhtu1","php":"7.4","qhturbo":""' <<< "$out" && ok "plugin page lists the domains" || bad "plugin page: $(head -c 600 <<< "$out")"
grep -q '"branch":"8.5","installed":true,"running":true' <<< "$out" && ok "plugin page lists QHturbo 8.5 as running" || bad "versions on page"
out="$(page_as qhtadmin "action=enable&domain=site1.test&php=8.5&token=wrong")"
ls "$QHT_SPOOL"/*.req > /dev/null 2>&1 && bad "a request was queued with a wrong token" || ok "wrong token refused"
out="$(page_as qhtadmin "action=enable&domain=site1.test%3Brm&php=8.5&token=$TOKEN")"
ls "$QHT_SPOOL"/*.req > /dev/null 2>&1 && bad "a bad domain was queued" || ok "bad domain refused by the page"
out="$(page_as qhtadmin "action=enable&domain=site1.test&php=8.5&token=$TOKEN")"
req="$(ls "$QHT_SPOOL"/*.req 2> /dev/null | head -1)"
[[ -n "$req" && "$(stat -c %U "$req")" == qhtadmin ]] && ok "page queued the request as the admin user" || bad "no request queued: $(grep -o '"flash":[^]]*]' <<< "$out")"
# Requests the agent must refuse: from a hosting user, and with a forged domain.
runuser -u qhtu2 -- sh -c "printf 'action=disable\ndomain=site2.test\n' > '$QHT_SPOOL/x-$RANDOM.req'"
runuser -u qhtadmin -- sh -c "printf 'action=enable\ndomain=a;touch /tmp/qht-agent-pwned\nphp=8.5\n' > '$QHT_SPOOL/y-$RANDOM.req'"
DA_ROOT="$SIM" "$AGENT" run 2> "$SIM/agent.err"
grep -q 'not a panel admin' "$SIM/agent.err" && ok "agent ignored a request from a hosting user" || bad "agent: $(cat "$SIM/agent.err")"
grep -q 'bad domain' "$SIM/agent.err" && [[ ! -e /tmp/qht-agent-pwned ]] && ok "agent refused a forged domain" || bad "forged domain: $(cat "$SIM/agent.err")"
[[ -z "$(ls -A "$QHT_SPOOL")" ]] && ok "spool emptied" || bad "spool: $(ls -A "$QHT_SPOOL")"
[[ "$(page site1.test)" == 8.5.12* ]] && ok "agent switched site1 to QHturbo 8.5" || bad "site1 after agent: $(page site1.test)"
out="$(page_as qhtadmin '')"
grep -q '"domain":"site1.test","user":"qhtu1","php":"7.4","qhturbo":"8.5"' <<< "$out" && ok "page shows site1 on QHturbo 8.5" || bad "page after agent"
grep -q 'now runs on QHturbo PHP 8.5' <<< "$out" && grep -q '\[exit 0\]' <<< "$out" && ok "page shows the task log" || bad "task log on page"
page_as qhtadmin '' > "$SIM/page.html"
page_as qhtadmin "action=disable&domain=site1.test&token=$TOKEN" > /dev/null
DA_ROOT="$SIM" "$AGENT" run 2>> "$SIM/agent.err"
[[ "$(page site1.test)" == 7.4.33* ]] && ok "switch back through the plugin" || bad "site1 after plugin disable: $(page site1.test)"

echo
echo "$pass passed, $fail failed"
exit $(( fail > 0 ))
