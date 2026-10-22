#!/bin/sh
# Shipped in packages built by scripts/package.sh as share/qhturbo/setup.sh.
# Run it once after extracting the package on the server:
#   sudo /opt/qhturbo/<php>/share/qhturbo/setup.sh
# It copies the default configuration files that do not exist yet (existing
# files are never overwritten) and sizes the PHP-FPM pool for THIS server.
set -eu
prefix="$(cd "$(dirname "$0")/../.." && pwd)"
dist="$prefix/share/qhturbo/etc.dist"
cpus="$(nproc)"
[ -d "$dist" ] || { echo "missing $dist" >&2; exit 1; }
cd "$dist"
find . -type f | while read -r f; do
  dest="$prefix/etc/${f#./}"
  if [ -e "$dest" ]; then
    cmp -s "$f" "$dest" || echo "kept existing $dest (default: $dist/${f#./})"
    continue
  fi
  mkdir -p "$(dirname "$dest")"
  cp -p "$f" "$dest"
  case "$dest" in
    */php-fpm.d/www.conf)
      sed -i -e "s/^pm\.max_children = .*/pm.max_children = $((cpus * 5))/" \
        -e "s/^pm\.start_servers = .*/pm.start_servers = $cpus/" \
        -e "s/^pm\.min_spare_servers = .*/pm.min_spare_servers = $cpus/" \
        -e "s/^pm\.max_spare_servers = .*/pm.max_spare_servers = $((cpus * 2))/" "$dest"
      echo "created $dest (sized for $cpus CPUs; check memory with share/qhturbo/fpm-tune.py)"
      ;;
    *) echo "created $dest" ;;
  esac
done
