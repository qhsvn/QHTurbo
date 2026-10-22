#!/usr/bin/env bash
# Web benchmark: QHturbo Compatible (nginx -> PHP-FPM) versus QHturbo
# Performance (nginx -> long-lived workers) on the same synthetic framework.
# Needs root (starts nginx/php-fpm on high ports), wrk, and an installed
# QHT_PROFILE=full build. Writes a Markdown report to stdout.
#
#   sudo PREFIX=/opt/qhturbo/8.5 benchmarks/web/run.sh > report.md
set -euo pipefail

PREFIX="${PREFIX:-/opt/qhturbo/8.5}"
PHP="$PREFIX/bin/php"
SRC="$(cd "$(dirname "$0")" && pwd)"
RUNTIME="$(cd "$SRC/../../runtime" && pwd)"
WORK=/tmp/qht-webbench
PROCS="${PROCS:-4}"            # PHP processes for CPU-bound tests
DURATION="${DURATION:-10s}"
USER_NAME="${USER_NAME:-www-data}"

log() { printf '[webbench] %s\n' "$*" >&2; }

cleanup() {
  [[ -f $WORK/nginx.pid ]] && kill "$(cat "$WORK/nginx.pid")" 2>/dev/null || true
  [[ -f $WORK/fpm.pid ]] && kill "$(cat "$WORK/fpm.pid")" 2>/dev/null || true
  for f in "$WORK"/serve-*.pid; do [[ -f $f ]] && kill "$(cat "$f")" 2>/dev/null || true; done
  sleep 1
}
trap cleanup EXIT

rm -rf "$WORK" && mkdir -p "$WORK/app" "$WORK/nginx-tmp"
cp -r "$SRC/app" "$SRC/public" "$SRC/worker.php" "$SRC/backend.php" "$WORK/app/"
[[ -f $WORK/app/app/generated/COUNT ]] || "$PHP" "$SRC/setup.php" 300 > /dev/null
[[ -f $WORK/app/app/generated/COUNT ]] || cp -r "$SRC/app/generated" "$WORK/app/app/"
chown -R "$USER_NAME" "$WORK"

cat > "$WORK/nginx.conf" <<EOF
user $USER_NAME;
worker_processes 2;
pid $WORK/nginx.pid;
error_log $WORK/nginx-error.log;
events { worker_connections 8192; }
http {
  access_log off;
  client_body_temp_path $WORK/nginx-tmp/body;
  fastcgi_temp_path $WORK/nginx-tmp/fcgi;
  proxy_temp_path $WORK/nginx-tmp/proxy;
  upstream workers { server 127.0.0.1:8090; keepalive 128; }
  server {                       # Compatible: PHP-FPM
    listen 8084;
    location / {
      include /etc/nginx/fastcgi_params;
      fastcgi_param SCRIPT_FILENAME $WORK/app/public/index.php;
      fastcgi_pass unix:$WORK/fpm.sock;
    }
  }
  server {                       # Performance: workers
    listen 8085;
    location / {
      proxy_http_version 1.1;
      proxy_set_header Connection "";
      proxy_pass http://workers;
    }
  }
}
EOF

start_fpm() { # children, extra ini scan dir ("" = Compatible defaults)
  local children="$1" scan="$PREFIX/etc/conf.d${2:+:$2}"
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
pm.max_children = $children
EOF
  PHP_INI_SCAN_DIR="$scan" "$PREFIX/sbin/php-fpm" --fpm-config "$WORK/fpm.conf"
  sleep 1
}
stop_fpm() { kill "$(cat "$WORK/fpm.pid")"; sleep 1; rm -f "$WORK/fpm.pid"; }

start_serve() { # name app port args...
  local name="$1" app="$2" port="$3"; shift 3
  PHP_INI_SCAN_DIR="$PREFIX/etc/conf.d:$PREFIX/etc/performance.d" \
    "$PHP" "$RUNTIME/bin/qhturbo-serve" "$app" --port="$port" "$@" > "$WORK/serve-$name.log" 2>&1 &
  echo $! > "$WORK/serve-$name.pid"
  for _ in $(seq 50); do curl -s -o /dev/null "http://127.0.0.1:$port/" && return; sleep 0.1; done
  log "serve $name failed to start"; cat "$WORK/serve-$name.log" >&2; exit 1
}
stop_serve() { kill "$(cat "$WORK/serve-$1.pid")"; sleep 1; rm -f "$WORK/serve-$1.pid"; }

php_mem_mib() { # PSS of all PHP processes: shared memory (OPcache) counted once
  local pid total=0
  for pid in $(pgrep -f "php-fpm: (pool bench|master process \($WORK)|qhturbo-serve .*worker.php"); do
    total=$(( total + $(awk '/^Pss:/ {print $2}' "/proc/$pid/smaps_rollup" 2>/dev/null || echo 0) ))
  done
  echo $(( total / 1024 ))
}

# bench <label> <url> <connections>  -> one Markdown table row
bench() {
  local label="$1" url="$2" conns="$3" out
  wrk -t2 -c"$conns" -d3s "$url" > /dev/null 2>&1 || true   # warm-up
  out="$(wrk -t2 -c"$conns" -d"$DURATION" --latency "$url")"
  local rps p50 p99 errors
  rps="$(awk '/Requests\/sec/ {printf "%.0f", $2}' <<< "$out")"
  p50="$(awk '$1=="50%" {print $2}' <<< "$out")"
  p99="$(awk '$1=="99%" {print $2}' <<< "$out")"
  errors="$(awk '/Non-2xx|Socket errors/ {print}' <<< "$out" | tr '\n' ' ')"
  printf '| %s | %s | %s | %s | %s | %s |\n' "$label" "$rps" "$p50" "$p99" "$(php_mem_mib)" "${errors:-0}"
}

header() {
  echo
  echo "### $1"
  echo
  echo "| Configuration | Requests/s | p50 | p99 | PHP memory, PSS (MiB) | Errors |"
  echo "|---|---|---|---|---|---|"
}

nginx -c "$WORK/nginx.conf"
start_serve backend "$WORK/app/backend.php" 8095 --concurrency=4096 --max-requests=0

echo "# Web benchmark: QHturbo Compatible vs Performance"
echo
echo "- Date: $(date -u '+%Y-%m-%d %H:%M UTC'); CPU: $(grep -m1 'model name' /proc/cpuinfo | cut -d: -f2 | sed 's/^ *//') ($(nproc) vCPU)"
echo "- PHP: $("$PHP" -v | head -1)"
echo "- App: synthetic framework, $(cat "$WORK/app/app/generated/COUNT") service classes, 40 config files, 203 routes"
echo "- Load: wrk -t2, $DURATION after a 3 s warm-up; nginx, PHP and wrk share the same machine"
echo "- Compatible = nginx -> PHP-FPM (pm=static) with Compatible ini; +perf-ini = same FPM with the Performance ini (validate_timestamps=0, JIT)"
echo "- Performance = nginx -> qhturbo-serve workers (framework booted once per worker)"

for endpoint in /hello /api/users/42; do
  header "GET $endpoint (CPU-bound, $PROCS PHP processes, 32 connections)"
  start_fpm "$PROCS" ""
  bench "Compatible: FPM x$PROCS" "http://127.0.0.1:8084$endpoint" 32
  stop_fpm
  start_fpm "$PROCS" "$PREFIX/etc/performance.d"
  bench "Compatible + perf-ini: FPM x$PROCS" "http://127.0.0.1:8084$endpoint" 32
  stop_fpm
  start_serve app "$WORK/app/worker.php" 8090 --workers="$PROCS" --concurrency=1 --max-requests=0
  bench "Performance: workers x$PROCS, concurrency 1" "http://127.0.0.1:8085$endpoint" 32
  stop_serve app
done

header "GET /io (3 backend calls x 20 ms each, 128 connections)"
for children in "$PROCS" 64; do
  start_fpm "$children" ""
  bench "Compatible: FPM x$children (blocking I/O)" "http://127.0.0.1:8084/io" 128
  stop_fpm
done
start_serve app "$WORK/app/worker.php" 8090 --workers="$PROCS" --concurrency=64 --max-requests=0
bench "Performance: workers x$PROCS, concurrency 64 (async I/O)" "http://127.0.0.1:8085/io" 128
stop_serve app
