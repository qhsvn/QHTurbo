#!/bin/sh
# Run by DirectAdmin's plugin manager after the plugin files are in place.
# The plugin pages run as the admin's own Linux user; the work that needs
# root is done by the QHturbo agent (scripts/panel-agent.sh), set up here.
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
DIR="$(cd "$(dirname "$0")/.." && pwd)"
SRC=/opt/qhturbo-src
REPO=https://github.com/qhsvn/QHTurbo.git

chmod 755 "$DIR/admin/index.html" "$DIR/scripts/"*.sh
mkdir -p "$DIR/data"
# Random token that every action link carries (protects against other
# websites sending requests through an admin's browser session).
if [ ! -s "$DIR/data/token" ]; then
  head -c 24 /dev/urandom | od -An -tx1 | tr -d ' \n' > "$DIR/data/token"
fi
chmod 755 "$DIR/data"; chmod 644 "$DIR/data/token"
chown -R diradmin:diradmin "$DIR" 2> /dev/null || true

if [ "$(id -u)" != 0 ]; then
  echo "Plugin files installed. To finish, run as root:"
  echo "  git clone $REPO $SRC && $SRC/scripts/panel-agent.sh setup directadmin"
  exit 0
fi
mkdir -p /var/log/qhturbo
if [ -x "$SRC/scripts/update-src.sh" ]; then
  "$SRC/scripts/update-src.sh" "$SRC" || { echo "Could not update $SRC"; exit 1; }
else
  command -v git > /dev/null 2>&1 || { command -v dnf > /dev/null && dnf install -y -q git; } || { command -v apt-get > /dev/null && apt-get install -y -q git; } || yum install -y -q git
  rm -rf "${SRC:?}.new"
  git clone -q --depth 1 "$REPO" "$SRC.new" || { echo "Could not download $REPO"; exit 1; }
  rm -rf "$SRC"; mv "$SRC.new" "$SRC"
fi
"$SRC/scripts/panel-agent.sh" setup directadmin
echo "QHturbo PHP plugin installed. Open it from the admin menu (Extra Features / Plugins)."
