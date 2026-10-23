#!/bin/bash
# aaPanel runs "bash install.sh install|uninstall" from /www/server/panel/plugin/qhturbo.
# The plugin is only a user interface: the work is done by the QHturbo
# scripts, which are downloaded to /opt/qhturbo-src.
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
SRC="${QHT_SRC:-/opt/qhturbo-src}"
REPO=https://github.com/qhsvn/QHTurbo.git

Install() {
  mkdir -p /var/log/qhturbo
  # aaPanel hides this script's output: keep a log for troubleshooting.
  exec > >(tee -a /var/log/qhturbo/plugin-install.log) 2>&1
  echo "== $(date '+%Y-%m-%d %H:%M:%S') QHturbo aaPanel plugin install"
  if [ -x "$SRC/scripts/update-src.sh" ]; then
    "$SRC/scripts/update-src.sh" "$SRC" || { echo "Could not update $SRC"; exit 1; }
  else
    # First install (or a copy older than update-src.sh): fetch it directly.
    command -v git > /dev/null 2>&1 || { command -v apt-get > /dev/null && apt-get install -y -q git; } \
      || { command -v dnf > /dev/null && dnf install -y -q git; } || yum install -y -q git
    rm -rf "${SRC:?}.new"
    git clone -q --depth 1 "$REPO" "$SRC.new" || { echo "Could not download $REPO"; exit 1; }
    rm -rf "$SRC"; mv "$SRC.new" "$SRC"
    echo "QHturbo scripts downloaded to $SRC: $(cd "$SRC" && git log -1 --format='%h %cd' --date=short)"
  fi
  echo 'Install OK'
}

Uninstall() {
  # Sites switched to QHturbo keep running on it, and the QHturbo PHP
  # builds stay in /opt/qhturbo. Switch sites back in the plugin first, or
  # run: /opt/qhturbo-src/scripts/aapanel.sh uninstall --php=<version>
  rm -rf /www/server/panel/plugin/qhturbo
  echo 'Uninstall OK'
}

if [ "${1}" == 'install' ]; then
  Install
elif [ "${1}" == 'uninstall' ]; then
  Uninstall
else
  echo 'Error!'; exit 1
fi
