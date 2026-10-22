#!/usr/bin/env bash
# Database I/O benchmark: three 20 ms MySQL queries per request.
#   sudo PREFIX=/opt/qhturbo/8.5 benchmarks/web/db/run.sh
# Needs MySQL/MariaDB with user qht/qhtpass on database qht (or set
# BENCH_PDO_DSN, BENCH_DB_USER, BENCH_DB_PASS, BENCH_MYSQL_URI), nginx, wrk.
set -euo pipefail
PREFIX="${PREFIX:-/opt/qhturbo/8.5}"
DIR="$(cd "$(dirname "$0")" && pwd)"
RUNTIME="$(cd "$DIR/../../../runtime" && pwd)"
WORK=/tmp/qht-dbbench
USER_NAME="${USER_NAME:-www-data}"
cleanup() { for f in "$WORK"/*.pid; do [[ -f $f ]] && kill "$(cat "$f")" 2> /dev/null || true; done; sleep 1; }
trap cleanup EXIT
cleanup
rm -rf "${WORK:?}" && mkdir -p "$WORK/tmp" && cp "$DIR"/*.php "$WORK/" && chown -R "$USER_NAME" "$WORK"
cat > "$WORK/nginx.conf" <<NGX
user $USER_NAME;
worker_processes 2;
pid $WORK/nginx.pid;
error_log $WORK/nginx-error.log;
events { worker_connections 8192; }
http {
  access_log off;
  client_body_temp_path $WORK/tmp/b; fastcgi_temp_path $WORK/tmp/f; proxy_temp_path $WORK/tmp/p;
  upstream workers { server 127.0.0.1:8090; keepalive 256; }
  server { listen 8084; location / { include /etc/nginx/fastcgi_params;
      fastcgi_param SCRIPT_FILENAME $WORK/fpm.php; fastcgi_pass unix:$WORK/fpm.sock; } }
  server { listen 8085; location / { proxy_http_version 1.1; proxy_set_header Connection ""; proxy_pass http://workers; } }
}
NGX
mem() { local t=0 p; for p in $(pgrep -f "php-fpm: pool bench|qhturbo-serve $WORK"); do
  t=$((t + $(awk '/^Pss:/{print $2}' "/proc/$p/smaps_rollup" 2> /dev/null || echo 0))); done; echo $((t / 1024)); }
bench() {
  wrk -t2 -c128 -d3s "$2" > /dev/null 2>&1 || true
  local out; out="$(wrk -t2 -c128 -d10s --latency "$2")"
  printf '| %s | %s | %s | %s | %s | %s |\n' "$1" "$(awk '/Requests\/sec/{printf "%.0f",$2}' <<< "$out")" \
    "$(awk '$1=="50%"{print $2}' <<< "$out")" "$(awk '$1=="99%"{print $2}' <<< "$out")" "$(mem)" \
    "$(grep -E 'Non-2xx|Socket errors' <<< "$out" | tr '\n' ' ' || true)"
}
serve() { # app args...
  local app="$1"; shift
  runuser -u "$USER_NAME" -- env PHP_INI_SCAN_DIR="$PREFIX/etc/conf.d:$PREFIX/etc/performance.d" \
    "$PREFIX/bin/php" "$RUNTIME/bin/qhturbo-serve" "$WORK/$app" --port=8090 --max-requests=0 "$@" > "$WORK/serve.log" 2>&1 &
  echo $! > "$WORK/serve.pid"
  for _ in $(seq 100); do curl -s -o /dev/null http://127.0.0.1:8090/ && break; sleep 0.1; done
}
nginx -c "$WORK/nginx.conf"
echo "# MySQL I/O: 3 x 20 ms queries per request"
echo
echo "- $(date -u '+%Y-%m-%d %H:%M UTC'), $(nproc) vCPU, $("$PREFIX/bin/php" -r 'echo "PHP ", PHP_VERSION;'), wrk -t2 -c128 10s"
echo
echo "| Configuration | Requests/s | p50 | p99 | PHP memory PSS (MiB) | Errors |"
echo "|---|---|---|---|---|---|"
for children in 4 64; do
  printf '[global]\npid = %s/fpm.pid\nerror_log = %s/fpm.log\n[bench]\nuser = %s\ngroup = %s\nlisten = %s/fpm.sock\nlisten.owner = %s\npm = static\npm.max_children = %s\nclear_env = no\n' \
    "$WORK" "$WORK" "$USER_NAME" "$USER_NAME" "$WORK" "$USER_NAME" "$children" > "$WORK/fpm.conf"
  "$PREFIX/sbin/php-fpm" --fpm-config "$WORK/fpm.conf"; sleep 1
  bench "Compatible: FPM x$children, PDO (blocking)" http://127.0.0.1:8084/
  kill "$(cat "$WORK/fpm.pid")"; rm -f "$WORK/fpm.pid"; sleep 1
done
serve worker-pdo.php --workers=4 --concurrency=1
bench "Performance: 4 workers, PDO, concurrency 1" http://127.0.0.1:8085/
kill "$(cat "$WORK/serve.pid")"; rm -f "$WORK/serve.pid"; sleep 2
serve worker-async.php --workers=4 --concurrency=64
bench "Performance: 4 workers, async MySQL, concurrency 64" http://127.0.0.1:8085/
