#!/usr/bin/env bash
# Build a private OpenSSL 1.1.1 into $QHT_LAB/deps/openssl-1.1 for PHP 7.4 /
# 8.0, which cannot compile against OpenSSL 3 (Ubuntu 22.04+, Debian 12+).
# PHP binaries find it through an rpath; the system OpenSSL is untouched.
#
# WARNING: OpenSSL 1.1.1 is end-of-life upstream (since 2023-09). Like PHP
# 7.4 itself, use it only for legacy applications with a migration plan.
source "$(dirname "$0")/lib.sh"

TAG=OpenSSL_1_1_1w
dest="$QHT_LAB/deps/openssl-1.1"
src="$QHT_LAB/deps/src/openssl-1.1"

if [[ -x "$dest/bin/openssl" ]]; then
  log "Already built: $("$dest/bin/openssl" version)"
  exit 0
fi
mkdir -p "$QHT_LAB/deps/src"
if [[ ! -d "$src/.git" ]]; then
  log "Cloning OpenSSL $TAG"
  git clone -q --depth 1 --branch "$TAG" https://github.com/openssl/openssl.git "$src"
fi
log "Building OpenSSL $TAG into $dest"
(cd "$src" && ./config --prefix="$dest" --openssldir="$dest/ssl" shared zlib \
  -Wl,-rpath,"$dest/lib" > "$QHT_LAB/deps/openssl-1.1-build.log" 2>&1 \
  && make -j"$JOBS" >> "$QHT_LAB/deps/openssl-1.1-build.log" 2>&1 \
  && make install_sw install_ssldirs >> "$QHT_LAB/deps/openssl-1.1-build.log" 2>&1) \
  || die "OpenSSL build failed, see $QHT_LAB/deps/openssl-1.1-build.log"
# Use the system CA bundle so HTTPS (Composer, curl) verifies certificates.
if [[ -f /etc/ssl/certs/ca-certificates.crt ]]; then
  ln -sf /etc/ssl/certs/ca-certificates.crt "$dest/ssl/cert.pem"
fi
log "Built $("$dest/bin/openssl" version)"
printf '\033[1;33m[qht] WARNING:\033[0m OpenSSL 1.1.1 is end-of-life; only for PHP 7.4/8.0 legacy builds.\n' >&2
