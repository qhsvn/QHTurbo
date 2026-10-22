#!/bin/sh
# Run by DirectAdmin's plugin manager before the plugin is removed.
# Domains switched to QHturbo keep running on it; switch them back in the
# plugin first, or run: /opt/qhturbo-src/scripts/directadmin.sh uninstall --php=<version>
if [ "$(id -u)" = 0 ] && [ -x /opt/qhturbo-src/scripts/panel-agent.sh ]; then
  /opt/qhturbo-src/scripts/panel-agent.sh remove
fi
echo "QHturbo PHP plugin removed."
