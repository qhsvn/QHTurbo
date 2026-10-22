#!/usr/bin/env bash
# Package an installed tree (scripts/install.sh) as a tarball for servers
# running the SAME distro release as the build machine (the binaries link
# system libraries such as libcurl, ICU, libzip).
#   QHT_PHP=8.5 scripts/package.sh [--variant=baseline] [--user=www --group=www]
# Output: $QHT_LAB/dist/qhturbo-php<version>-<distro>-<arch>.tar.gz (+ .sha256)
# On the server:  sudo tar -xzf <file> -C /   (creates /opt/qhturbo/<php>)
#                 sudo /opt/qhturbo/<php>/share/qhturbo/setup.sh
# The configuration ships in share/qhturbo/etc.dist, so extracting a newer
# package never overwrites the server's etc/; setup.sh copies missing files
# and sizes PHP-FPM for the server's CPU count.
export QHT_PROFILE=full
source "$(dirname "$0")/lib.sh"

stage="$QHT_LAB/dist/stage-$QHT_PHP"
rm -rf "$stage"
"$QHT_ROOT/scripts/install.sh" --destdir="$stage" "$@" 2>/dev/null \
  || die "install into staging failed (run scripts/install.sh --destdir=$stage to see why)"

root="$stage$QHT_PREFIX"
version="$("$root/bin/php" -n -r 'echo PHP_VERSION;')"
distro="$(. /etc/os-release && echo "$ID$VERSION_ID")"
name="qhturbo-php$version-$distro-$(uname -m)"
# Record the shared libraries the target must provide.
{
  echo "Built on: $(. /etc/os-release && echo "$PRETTY_NAME"), $(${CC:-gcc} --version | head -1)"
  echo "Install the same packages on the target (Debian/Ubuntu names):"
  ldd "$root/bin/php" "$root/sbin/php-fpm" | awk '/=>/ && $3 ~ /^\// {print $3}' | sort -u | while read -r lib; do
    # merged-/usr systems register libraries under /usr/lib or /lib
    dpkg -S "$lib" 2>/dev/null || dpkg -S "/usr$lib" 2>/dev/null || dpkg -S "${lib#/usr}" 2>/dev/null || true
  done | grep -v '^diversion' | cut -d: -f1 | sort -u
} > "$root/share/qhturbo/DEPENDENCIES.txt"

# Debug symbols stay in the build tree; the package ships stripped binaries.
find "$root/bin" "$root/sbin" "$root/lib" -type f \( -perm -u+x -o -name '*.so' \) \
  -exec sh -c 'file "$1" | grep -q ELF && strip --strip-debug "$1"' _ {} \;

# Configuration: shipped as defaults, installed by setup.sh on the target.
mv "$root/etc" "$root/share/qhturbo/etc.dist"
mkdir -p "$root/etc"
install -m 755 "$QHT_ROOT/scripts/pkg-setup.sh" "$root/share/qhturbo/setup.sh"

mkdir -p "$QHT_LAB/dist"
tar -C "$stage" -czf "$QHT_LAB/dist/$name.tar.gz" "${QHT_PREFIX#/}"
(cd "$QHT_LAB/dist" && sha256sum "$name.tar.gz" > "$name.tar.gz.sha256")
log "Package: $QHT_LAB/dist/$name.tar.gz ($(du -h "$QHT_LAB/dist/$name.tar.gz" | cut -f1))"
log "On the server: tar -xzf $name.tar.gz -C / && $QHT_PREFIX/share/qhturbo/setup.sh"
