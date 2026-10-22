#!/bin/bash
# aaPanel runs "bash install.sh install|uninstall" from /www/server/panel/plugin/qhturbo.
# The plugin is only a user interface: the work is done by the QHturbo
# scripts, which are downloaded to /opt/qhturbo-src.
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
SRC="${QHT_SRC:-/opt/qhturbo-src}"
REPO=https://github.com/qhsvn/QHTurbo.git

Install() {
  if ! command -v git > /dev/null 2>&1; then
    { command -v apt-get > /dev/null && apt-get install -y -q git; } \
      || { command -v dnf > /dev/null && dnf install -y -q git; } \
      || yum install -y -q git
  fi
  if [ -d "$SRC/.git" ]; then
    git -C "$SRC" pull -q --ff-only || echo "Could not update $SRC (local changes?); keeping it as it is"
  else
    git clone -q --depth 1 "$REPO" "$SRC" || { echo "Could not download $REPO"; exit 1; }
  fi
  mkdir -p /var/log/qhturbo
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
