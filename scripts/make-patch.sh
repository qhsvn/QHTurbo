#!/usr/bin/env bash
# Export your edits in a qhturbo worktree as a new patch at the end of the
# series, so they are reproducible and survive worktree resets.
#   QHT_PHP=8.5 scripts/make-patch.sh qhturbo QHT-ENG-001-short-description
#
# Patches already in the series are staged in the worktree index (build.sh
# applies them with --index); anything not staged is your new work.
source "$(dirname "$0")/lib.sh"

variant="${1:-}"; name="${2:-}"
uses_patches "$variant" || die "Usage: $0 <qhturbo|qhturbo-pgo> <QHT-XXX-nnn-name>"
[[ "$name" =~ ^QHT-(ENG|MEM|JIT|WEB|SEC)-[0-9]{3}[A-Za-z0-9._-]*$ ]] \
  || die "Patch name must start with a task id, e.g. QHT-ENG-001-dispatch"
wt="$WORKTREES/$variant"
meta="$WORKTREES/.meta-$variant"
file="$PATCH_DIR/$name.patch"
[[ -e "$file" ]] && die "$file already exists"

# Stage new files too (build output is ignored by php-src's .gitignore).
git -C "$wt" add -A

# Tree of upstream + current series, built in a scratch index.
tmp_index="$(mktemp)"
trap 'rm -f "$tmp_index"' EXIT
GIT_INDEX_FILE="$tmp_index" git -C "$wt" read-tree "$PHP_COMMIT"
while read -r p; do
  [[ -n "$p" ]] && GIT_INDEX_FILE="$tmp_index" git -C "$wt" apply --cached "$p"
done < <(patch_list)
series_tree="$(GIT_INDEX_FILE="$tmp_index" git -C "$wt" write-tree)"
current_tree="$(git -C "$wt" write-tree)"
[[ "$series_tree" != "$current_tree" ]] || die "No changes in $wt compared to the patch series"
mkdir -p "$PATCH_DIR"
git -C "$wt" diff --binary "$series_tree" "$current_tree" > "$file"
echo "$name.patch" >> "$PATCH_DIR/series"
source_stamp "$variant" > "$meta/stamp"
git -C "$wt" diff HEAD --binary | sha256sum | cut -d' ' -f1 > "$meta/diff"
log "Wrote $file ($(grep -c '^diff --git' "$file") files) and appended it to patches/$QHT_PHP/series"
log "Now: build, run tests + benchmarks, and log the result in logs/development.md"
