#!/usr/bin/env bash
# Package the control-panel plugins (committed, so they can be downloaded):
#   plugins/build.sh           ->  plugins/aapanel/qhturbo-aapanel.zip     (aaPanel: App Store -> Import)
#                                  plugins/directadmin/qhturbo.tar.gz      (DirectAdmin: Plugin Manager -> Add)
#   plugins/build.sh --check   fail if a committed package differs from its sources (CI)
# DirectAdmin names the plugin directory after the tarball up to the first
# dot, so the file must stay "qhturbo.tar.gz".
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
aa_zip="$here/aapanel/qhturbo-aapanel.zip"
da_tgz="$here/directadmin/qhturbo.tar.gz"

if [[ "${1:-}" == --check ]]; then
  tmp="$(mktemp -d)"; trap 'rm -rf "$tmp"' EXIT
  mkdir -p "$tmp/aa" "$tmp/da"
  unzip -q "$aa_zip" -d "$tmp/aa"
  tar -xzf "$da_tgz" -C "$tmp/da"
  rc=0
  diff -r -x __pycache__ "$tmp/aa/qhturbo" "$here/aapanel/qhturbo" || { echo "$aa_zip is out of date" >&2; rc=1; }
  diff -r -x data "$tmp/da" "$here/directadmin/qhturbo" || { echo "$da_tgz is out of date" >&2; rc=1; }
  [[ -x "$tmp/da/admin/index.html" && -x "$tmp/da/scripts/install.sh" ]] || { echo "$da_tgz: scripts are not executable" >&2; rc=1; }
  (( rc == 0 )) && echo "plugin packages match their sources" || echo "run plugins/build.sh and commit the packages" >&2
  exit $rc
fi
rm -f "$aa_zip" "$da_tgz"
(cd "$here/aapanel" && zip -q -r -X "$aa_zip" qhturbo -x '*/__pycache__/*' '*.pyc')
# Files at the top of the tarball (DirectAdmin unpacks it into plugins/qhturbo/).
tar --owner=0 --group=0 --numeric-owner --sort=name --mtime='2026-01-01 00:00Z' \
  -czf "$da_tgz" -C "$here/directadmin/qhturbo" --exclude=./data .
echo "$aa_zip"
echo "$da_tgz"
