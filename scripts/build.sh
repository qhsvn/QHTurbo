#!/usr/bin/env bash
# Build one PHP CLI variant into build/<php>/<variant>/bin/php.
# The PHP branch comes from QHT_PHP (7.4 ... 8.5, default 8.5).
#
#   baseline     upstream tag, BASE_CFLAGS
#   qhturbo      upstream tag + patches/, BASE_CFLAGS      (isolates the patches)
#   qhturbo-pgo  upstream tag + patches/, PGO-optimised     (release build)
#
# Each variant has its own git worktree in worktrees/<php>/<variant>, generated
# only from the upstream commit + patches/<php>/. Manual edits in a worktree are never
# discarded: the script stops and asks you to export them as a patch first.
source "$(dirname "$0")/lib.sh"

variant="${1:-}"
is_variant "$variant" || die "Usage: $0 <${VARIANTS[*]// /|}>"
git -C "$PHP_SRC" rev-parse -q --verify "$PHP_COMMIT^{commit}" >/dev/null 2>&1 \
  || die "$PHP_TAG not fetched; run QHT_PHP=$QHT_PHP scripts/bootstrap.sh first"
warn_if_eol

wt="$WORKTREES/$variant"
meta="$WORKTREES/.meta-$variant"
want_stamp="$(source_stamp "$variant")"

diff_hash() { git -C "$wt" diff HEAD --binary | sha256sum | cut -d' ' -f1; }

apply_patches() {
  uses_patches "$variant" || return 0
  local p
  while read -r p; do
    [[ -z "$p" ]] && continue
    log "Applying $(basename "$p")"
    git -C "$wt" apply --index --whitespace=nowarn "$p" || die "Patch failed: $p"
  done < <(patch_list)
}

prepare_worktree() {
  mkdir -p "$WORKTREES"
  if [[ ! -d "$wt" ]]; then
    log "Creating worktree $wt at $PHP_COMMIT"
    git -C "$PHP_SRC" worktree add --detach "$wt" "$PHP_COMMIT" >/dev/null || die "worktree add failed"
  elif [[ "$(cat "$meta/stamp" 2>/dev/null)" == "$want_stamp" ]]; then
    log "Worktree up to date (incremental build)"
    return 1
  else
    [[ "$(diff_hash)" == "$(cat "$meta/diff" 2>/dev/null)" ]] || die \
"Worktree $wt has edits that are not in patches/.
Export them first (scripts/make-patch.sh $variant <name>) or back them up, then re-run."
    log "Patch series changed; resetting worktree to $PHP_COMMIT"
    git -C "$wt" reset --hard -q "$PHP_COMMIT" || die "reset failed"
  fi
  apply_patches
  mkdir -p "$meta"
  echo "$want_stamp" > "$meta/stamp"
  diff_hash > "$meta/diff"
  return 0
}

variant_flags() {
  local cflags="$BASE_CFLAGS" ldflags=""
  if [[ "$variant" == *-pgo ]]; then
    cflags+="${PGO_EXTRA_CFLAGS:+ $PGO_EXTRA_CFLAGS}"
    ldflags+="$PGO_EXTRA_LDFLAGS"
  fi
  echo "$cflags|$ldflags|${CONFIGURE_FLAGS[*]}"
}

configure() {
  local flags cflags ldflags
  flags="$(variant_flags)"
  cflags="${flags%%|*}"; ldflags="${flags#*|}"; ldflags="${ldflags%%|*}"
  log "Configuring ($variant): CFLAGS='$cflags' LDFLAGS='$ldflags'"
  (cd "$wt" && ./buildconf --force >/dev/null \
    && ./configure "${CONFIGURE_FLAGS[@]}" CFLAGS="$cflags" LDFLAGS="$ldflags" > "$meta/configure.log" 2>&1) \
    || die "configure failed, see $meta/configure.log"
  # Objects built with other flags must not be reused.
  make -C "$wt" clean > /dev/null 2>&1 || true
  echo "$flags" > "$meta/configured"
}

build_plain() {
  log "make -j$JOBS"
  make -C "$wt" -j"$JOBS" > "$meta/make.log" 2>&1 || die "make failed, see $meta/make.log"
}

build_pgo() {
  log "PGO step 1/3: instrumented build"
  make -C "$wt" -j"$JOBS" prof-clean > /dev/null 2>&1 || true
  make -C "$wt" -j"$JOBS" prof-gen > "$meta/make.log" 2>&1 || die "prof-gen failed, see $meta/make.log"
  log "PGO step 2/3: training run"
  "$QHT_ROOT/scripts/pgo-train.sh" "$wt/sapi/cli/php" "$wt" > "$meta/train.log" 2>&1 \
    || die "training failed, see $meta/train.log"
  log "PGO step 3/3: optimised rebuild"
  # -fprofile-partial-training: code the training run never reached keeps
  # normal -O2 optimisation instead of being optimised for size.
  make -C "$wt" prof-clean > /dev/null 2>&1
  make -C "$wt" -j"$JOBS" prof-use EXTRA_CFLAGS="-fprofile-partial-training -Wno-missing-profile -Wno-coverage-mismatch" >> "$meta/make.log" 2>&1 \
    || die "prof-use failed, see $meta/make.log"
}

changed=0
prepare_worktree && changed=1
if (( changed )) || [[ ! -f "$wt/Makefile" ]] \
   || [[ "$(cat "$meta/configured" 2>/dev/null)" != "$(variant_flags)" ]]; then
  configure
fi

if [[ "$variant" == *-pgo ]]; then build_pgo; else build_plain; fi

out="$BUILD_DIR/$variant"
mkdir -p "$out/bin" "$out/lib"
cp "$wt/sapi/cli/php" "$out/bin/php"
rm -f "$out/lib/"*.so
if [[ -f "$wt/modules/opcache.so" ]]; then cp "$wt/modules/opcache.so" "$out/lib/"; fi
{
  echo "variant=$variant"
  echo "source_stamp=$want_stamp"
  echo "upstream=$PHP_TAG $PHP_COMMIT"
  echo "patches=$(patch_list | xargs -r -n1 basename | tr '\n' ' ')"
  echo "configured=$(cat "$meta/configured")"
  echo "sha256=$(sha256sum "$out/bin/php" | cut -d' ' -f1)"
  [[ -f "$out/lib/opcache.so" ]] && echo "opcache_so_sha256=$(sha256sum "$out/lib/opcache.so" | cut -d' ' -f1)"
  echo "compiler=$(${CC:-gcc} --version | head -1)"
  opcache_args "$variant"
  "$out/bin/php" -n "${OPCACHE_ARGS[@]}" -v | head -1 | sed 's/^/version=/'
  "$out/bin/php" -n "${OPCACHE_ARGS[@]}" -d opcache.enable_cli=1 -r \
    'echo "opcache=", (function_exists("opcache_get_status") ? "yes" : "no"), "\n";'
} > "$out/BUILD-INFO.txt"
log "Built $out/bin/php"
cat "$out/BUILD-INFO.txt" >&2
