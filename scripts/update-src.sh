#!/usr/bin/env bash
# Bring the QHturbo scripts the panel plugins use (/opt/qhturbo-src) to the
# latest version on GitHub. The directory is managed by the plugins: local
# edits there are replaced (builds live in /opt/qhturbo-lab, not here).
#   sudo scripts/update-src.sh [dir]
set -euo pipefail
export PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin${PATH:+:$PATH}"
SRC="${1:-${QHT_SRC:-/opt/qhturbo-src}}"
REPO="${QHT_REPO:-https://github.com/qhsvn/QHTurbo.git}"
BRANCH="${QHT_BRANCH:-main}"
export HOME="${HOME:-/root}"   # git wants one; panel processes may not set it

if ! command -v git > /dev/null 2>&1; then
  { command -v apt-get > /dev/null && apt-get install -y -q git; } \
    || { command -v dnf > /dev/null && dnf install -y -q git; } \
    || { command -v yum > /dev/null && yum install -y -q git; } \
    || { echo "git is not installed and could not be installed"; exit 1; }
fi
git config --global --add safe.directory "$SRC" 2> /dev/null || true
if [[ -d "$SRC/.git" ]]; then
  before="$(git -C "$SRC" log -1 --format='%h %cd' --date=short 2> /dev/null || echo none)"
  git -C "$SRC" remote set-url origin "$REPO"
  git -C "$SRC" fetch -q --depth 1 origin "$BRANCH"
  git -C "$SRC" checkout -q -f -B "$BRANCH" FETCH_HEAD
  git -C "$SRC" clean -q -fd
else
  before=none
  rm -rf "${SRC:?}"
  git clone -q --depth 1 --branch "$BRANCH" "$REPO" "$SRC"
fi
after="$(git -C "$SRC" log -1 --format='%h %cd' --date=short)"
echo "QHturbo scripts in $SRC: $before -> $after"
