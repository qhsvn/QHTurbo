#!/usr/bin/env bash
# Benchmark a real Laravel or Symfony application: QHturbo Compatible
# (nginx -> PHP-FPM, framework boots on every request) versus QHturbo
# Performance (nginx -> qhturbo-serve, framework boots once per worker).
# Needs root, nginx, wrk and an installed QHT_PROFILE=full build.
#
#   sudo PREFIX=/opt/qhturbo/8.5 benchmarks/web/framework.sh laravel /var/www/app / /api/ping
#   sudo benchmarks/web/framework.sh symfony /var/www/sf /
#
# Use the same production settings for both (APP_ENV=production/prod, debug
# off, config/route caches built, a real session/cache driver).
set -euo pipefail

FRAMEWORK="${1:?laravel|symfony}"; APP="${2:?project dir}"; shift 2
PATHS=("${@:-/}")
PREFIX="${PREFIX:-/opt/qhturbo/8.5}"
PROCS="${PROCS:-4}"
CONNS="${CONNS:-32}"
DURATION="${DURATION:-10s}"
USER_NAME="${USER_NAME:-www-data}"
RUNTIME="$(cd "$(dirname "$0")/../../runtime" && pwd)"
WORK=/tmp/qht-fwbench

cleanup() {
  for f in "$WORK"/*.pid; do [[ -f $f ]] && kill "$(cat "$f")" 2> /dev/null || true; done
  sleep 1
}
trap cleanup EXIT
cleanup
rm -rf "${WORK:?}" && mkdir -p "$WORK/tmp" && chown "$USER_NAME" "$WORK"

cat > "$WORK/fpm.conf" <<EOF
[global]
pid = $WORK/fpm.pid
error_log = $WORK/fpm.log
[bench]
user = $USER_NAME
group = $USER_NAME
listen = $WORK/fpm.sock
listen.owner = $USER_NAME
pm = static
pm.max_children = $((PROCS * 2))
clear_env = no
EOF
cat > "$WORK/nginx.conf" <<EOF
user $USER_NAME;
worker_processes 2;
pid $WORK/nginx.pid;
error_log $WORK/nginx-error.log;
events { worker_connections 8192; }
http {
  access_log off;
  client_body_temp_path $WORK/tmp/body; fastcgi_temp_path $WORK/tmp/fcgi; proxy_temp_path $WORK/tmp/proxy;
  upstream workers { server 127.0.0.1:8090; keepalive 128; }
  server {
    listen 8084;
    root $APP/public;
    location / { try_files \$uri /index.php\$is_args\$args; }
    location ~ \.php\$ {
      include /etc/nginx/fastcgi_params;
      fastcgi_param SCRIPT_FILENAME \$document_root/index.php;
      fastcgi_pass unix:$WORK/fpm.sock;
    }
  }
  server {
    listen 8085;
    location / { proxy_http_version 1.1; proxy_set_header Connection ""; proxy_set_header Host \$host; proxy_pass http://workers; }
  }
}
EOF

mem() { # PSS of the PHP processes
  local pid total=0
  for pid in $(pgrep -f "php-fpm: pool bench|qhturbo-serve $APP"); do
    total=$((total + $(awk '/^Pss:/ {print $2}' "/proc/$pid/smaps_rollup" 2> /dev/null || echo 0)))
  done
  echo $((total / 1024))
}
bench() { # label url
  wrk -t2 -c"$CONNS" -d3s "$2" > /dev/null 2>&1 || true
  local out; out="$(wrk -t2 -c"$CONNS" -d"$DURATION" --latency "$2")"
  printf '| %s | %s | %s | %s | %s | %s |\n' "$1" \
    "$(awk '/Requests\/sec/ {printf "%.0f", $2}' <<< "$out")" "$(awk '$1=="50%" {print $2}' <<< "$out")" \
    "$(awk '$1=="99%" {print $2}' <<< "$out")" "$(mem)" "$(grep -E 'Non-2xx|Socket errors' <<< "$out" | tr '\n' ' ' || true)"
}

nginx -c "$WORK/nginx.conf"
echo "# $FRAMEWORK: QHturbo Compatible (PHP-FPM) vs Performance (workers)"
echo
echo "- $(date -u '+%Y-%m-%d %H:%M UTC'), $(nproc) vCPU, $("$PREFIX/bin/php" -r 'echo "PHP ", PHP_VERSION;'), app: $APP"
echo "- FPM: pm=static, $((PROCS * 2)) children; workers: $PROCS processes, concurrency 1; wrk -t2 -c$CONNS $DURATION (+3 s warm-up)"
echo
echo "| Configuration | Requests/s | p50 | p99 | PHP memory PSS (MiB) | Errors |"
echo "|---|---|---|---|---|---|"
for path in "${PATHS[@]}"; do
  "$PREFIX/sbin/php-fpm" --fpm-config "$WORK/fpm.conf"; sleep 1
  bench "Compatible (FPM) \`$path\`" "http://127.0.0.1:8084$path"
  kill "$(cat "$WORK/fpm.pid")"; rm -f "$WORK/fpm.pid"; sleep 1
  runuser -u "$USER_NAME" -- env PHP_INI_SCAN_DIR="$PREFIX/etc/conf.d:$PREFIX/etc/performance.d" \
    "$PREFIX/bin/php" "$RUNTIME/bin/qhturbo-serve" "$APP" --framework="$FRAMEWORK" --port=8090 \
    --workers="$PROCS" --max-requests=0 > "$WORK/serve.log" 2>&1 &
  echo $! > "$WORK/serve.pid"
  for _ in $(seq 100); do curl -s -o /dev/null "http://127.0.0.1:8090$path" && break; sleep 0.1; done
  bench "Performance (workers) \`$path\`" "http://127.0.0.1:8085$path"
  kill "$(cat "$WORK/serve.pid")"; rm -f "$WORK/serve.pid"; sleep 2
done
