#!/usr/bin/env bash
# Root helper for control-panel plugins that cannot run as root
# (DirectAdmin runs plugin pages as the logged-in admin's Linux user).
#
# The plugin drops a request file into $SPOOL; this agent (started by a
# systemd path unit, or by cron) checks who wrote it and what it asks,
# runs scripts/<panel>.sh as root, and publishes logs and lists the plugin
# can read.
#
#   sudo scripts/panel-agent.sh setup directadmin   create the directories, install the systemd units
#   sudo scripts/panel-agent.sh run                  process pending requests (what the units run)
#   sudo scripts/panel-agent.sh refresh              rewrite the published lists
#   sudo scripts/panel-agent.sh remove               stop and remove the units
#
# Request file ($SPOOL/<id>.req, written by the plugin; key=value lines):
#   action=install|sync-ext|uninstall|check|enable|disable|refresh
#   php=7.4 ... 8.5        domain=<domain>        (when the action needs them)
# Only files owned by root or by a user listed in the panel's admin list
# are executed; anything else is deleted unread.
set -euo pipefail
# Control panels may start us with a minimal PATH (aaPanel's panel process):
# make sure the system tools (dnf/apt-get, git, gcc, systemctl) are found.
export PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin${PATH:+:$PATH}"

QHT_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SPOOL="${QHT_SPOOL:-/var/spool/qhturbo}"
STATE="${QHT_STATE:-/var/lib/qhturbo}"
JOBS="${QHT_JOBLOG:-/var/log/qhturbo/jobs}"
DA_ADMINS="${DA_ROOT:-}/usr/local/directadmin/data/admin/admin.list"

log() { printf '[qhturbo-agent] %s\n' "$*" >&2; }
die() { printf '[qhturbo-agent] ERROR: %s\n' "$*" >&2; exit 1; }
[[ $EUID -eq 0 ]] || die "run as root"

panel() { cat "$STATE/panel" 2>/dev/null || echo directadmin; }
panel_script() { echo "$QHT_ROOT/scripts/$(panel).sh"; }

allowed() { # user -> may this user send requests?
  [[ "$1" == root ]] && return 0
  case "$(panel)" in
    directadmin) [[ -f "$DA_ADMINS" ]] && grep -qxF -- "$1" "$DA_ADMINS" ;;
    *) return 1 ;;
  esac
}

refresh() {
  local s; s="$(panel_script)"
  mkdir -p "$STATE"
  "$s" versions > "$STATE/versions.tsv.tmp" 2>/dev/null || true
  "$s" sites > "$STATE/sites.tsv.tmp" 2>/dev/null || true
  chmod 644 "$STATE"/*.tsv.tmp
  mv "$STATE/versions.tsv.tmp" "$STATE/versions.tsv"
  mv "$STATE/sites.tsv.tmp" "$STATE/sites.tsv"
  (cd "$QHT_ROOT" && git log -1 --format='%h %cd' --date=short) > "$STATE/revision" 2>/dev/null || true
  chmod 644 "$STATE/revision" 2>/dev/null || true
}

# One request: validate, then run. Prints nothing; results go to $JOBS/<id>.log.
handle() { # file
  local f="$1" id owner action="" php="" domain="" key value
  id="$(basename "$f" .req)"
  if [[ -L "$f" || ! -f "$f" ]] || ! [[ "$id" =~ ^[A-Za-z0-9-]{1,64}$ ]] || [[ $(stat -c %s "$f") -gt 1024 ]]; then
    rm -f "$f"; log "ignored malformed request $f"; return
  fi
  owner="$(stat -c %U "$f")"
  if ! allowed "$owner"; then
    rm -f "$f"; log "ignored request $id from $owner (not a panel admin)"; return
  fi
  while IFS='=' read -r key value; do
    case "$key" in
      action) action="$value" ;;
      php) php="$value" ;;
      domain) domain="$value" ;;
    esac
  done < "$f"
  rm -f "$f"
  local -a cmd
  case "$action" in
    refresh) refresh; return ;;
    install|sync-ext|uninstall|check) cmd=("$action") ;;
    enable|disable)
      [[ "$domain" =~ ^[A-Za-z0-9]([A-Za-z0-9.-]{0,251}[A-Za-z0-9])?$ ]] || { log "bad domain in $id"; return; }
      cmd=("$action" "$domain") ;;
    *) log "unknown action '$action' in $id"; return ;;
  esac
  if [[ -n "$php" ]]; then
    [[ -f "$QHT_ROOT/config/versions/$php.env" && "$php" =~ ^[0-9]\.[0-9]$ ]] || { log "bad php '$php' in $id"; return; }
    cmd+=("--php=$php")
  fi
  mkdir -p "$JOBS"
  local out="$JOBS/$id.log" code=0
  {
    echo "\$ $(panel).sh ${cmd[*]}    (requested by $owner, $(date '+%Y-%m-%d %H:%M:%S'))"
  } > "$out"
  chmod 644 "$out"
  printf '%s\t%s\t%s\n' "$id" "$owner" "${cmd[*]}" > "$STATE/current"
  chmod 644 "$STATE/current"
  "$(panel_script)" "${cmd[@]}" >> "$out" 2>&1 < /dev/null || code=$?
  echo "[exit $code]" >> "$out"
  rm -f "$STATE/current"
  echo "$id" > "$STATE/last-job"; chmod 644 "$STATE/last-job"
  refresh
  # Keep the 50 newest job logs.
  find "$JOBS" -name '*.log' -printf '%T@ %p\n' | sort -rn | tail -n +51 | cut -d' ' -f2- | xargs -r rm -f
}

do_run() {
  mkdir -p "$STATE"
  exec 9> "$STATE/agent.lock"
  flock -n 9 || { log "another agent is running"; return 0; }
  local f
  while :; do
    f="$(find "$SPOOL" -maxdepth 1 -name '*.req' -printf '%T@ %p\n' 2>/dev/null | sort -n | head -1 | cut -d' ' -f2-)"
    [[ -n "$f" ]] || break
    handle "$f"
  done
}

do_setup() {
  local p="${1:-directadmin}"
  [[ -x "$QHT_ROOT/scripts/$p.sh" ]] || die "unknown panel '$p'"
  # Anyone may create a request (sticky, not listable): the owner check
  # above decides what runs.
  install -d -m 1733 -o root -g root "$SPOOL"
  install -d -m 755 "$STATE" "$JOBS"
  echo "$p" > "$STATE/panel"; chmod 644 "$STATE/panel"
  if [[ -d /run/systemd/system ]]; then
    cat > /etc/systemd/system/qhturbo-agent.service <<EOF
[Unit]
Description=QHturbo control-panel agent (runs requests from the $p plugin)

[Service]
Type=oneshot
ExecStart=$QHT_ROOT/scripts/panel-agent.sh run
TimeoutStartSec=infinity
EOF
    cat > /etc/systemd/system/qhturbo-agent.path <<EOF
[Unit]
Description=QHturbo control-panel agent (watch $SPOOL)

[Path]
PathExistsGlob=$SPOOL/*.req
Unit=qhturbo-agent.service

[Install]
WantedBy=paths.target
EOF
    systemctl daemon-reload
    systemctl enable --now qhturbo-agent.path
    log "systemd units installed: qhturbo-agent.path / qhturbo-agent.service"
  else
    echo "* * * * * root $QHT_ROOT/scripts/panel-agent.sh run" > /etc/cron.d/qhturbo-agent
    log "no systemd: cron runs the agent every minute (/etc/cron.d/qhturbo-agent)"
  fi
  refresh
  log "ready: requests in $SPOOL, lists in $STATE, logs in $JOBS"
}

do_remove() {
  if [[ -d /run/systemd/system ]]; then
    systemctl disable --now qhturbo-agent.path 2>/dev/null || true
    rm -f /etc/systemd/system/qhturbo-agent.path /etc/systemd/system/qhturbo-agent.service
    systemctl daemon-reload
  fi
  rm -f /etc/cron.d/qhturbo-agent
  log "agent removed (lists and logs kept in $STATE and $JOBS)"
}

case "${1:-}" in
  setup) do_setup "${2:-directadmin}" ;;
  run) do_run ;;
  refresh) refresh ;;
  remove) do_remove ;;
  *) sed -n '2,21p' "$0" | sed 's/^# \{0,1\}//'; exit 1 ;;
esac
