#!/usr/bin/env bash
# Package the control-panel plugins:
#   plugins/build.sh            ->  plugins/aapanel/qhturbo-aapanel.zip (committed, for download)
#   plugins/build.sh --check    fail if the committed .zip differs from the sources (CI)
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
zipfile="$here/aapanel/qhturbo-aapanel.zip"
if [[ "${1:-}" == --check ]]; then
  tmp="$(mktemp -d)"; trap 'rm -rf "$tmp"' EXIT
  unzip -q "$zipfile" -d "$tmp"
  diff -r -x __pycache__ "$tmp/qhturbo" "$here/aapanel/qhturbo" \
    || { echo "$zipfile is out of date: run plugins/build.sh and commit it" >&2; exit 1; }
  echo "$zipfile matches plugins/aapanel/qhturbo"
  exit 0
fi
rm -f "$zipfile"
(cd "$here/aapanel" && zip -q -r -X "$zipfile" qhturbo -x '*/__pycache__/*' '*.pyc')
echo "$zipfile"
