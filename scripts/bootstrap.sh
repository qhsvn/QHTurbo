#!/usr/bin/env bash
# Clone upstream php-src and fetch the pinned tag(s), verifying each commit.
# Safe to re-run: an existing clone is verified, never deleted.
#   QHT_PHP=7.4 scripts/bootstrap.sh     one branch (default 8.5)
#   scripts/bootstrap.sh --all           every branch in config/versions/
source "$(dirname "$0")/lib.sh"

check_tools() {
  local missing=() t
  for t in git make gcc autoconf bison re2c pkg-config python3; do
    command -v "$t" >/dev/null || missing+=("$t")
  done
  if ((${#missing[@]})); then
    die "Missing tools: ${missing[*]}
Ubuntu: sudo apt install -y git build-essential autoconf bison re2c pkg-config \\
  libxml2-dev libssl-dev zlib1g-dev libsqlite3-dev python3"
  fi
}

fetch_version() {
  local env="$QHT_ROOT/config/versions/$1.env" tag commit actual
  # shellcheck source=/dev/null
  tag="$(. "$env" && echo "$PHP_TAG")"
  # shellcheck source=/dev/null
  commit="$(. "$env" && echo "$PHP_COMMIT")"
  if ! git -C "$PHP_SRC" rev-parse -q --verify "refs/tags/$tag" >/dev/null; then
    log "Fetching $tag"
    git -C "$PHP_SRC" fetch -q --depth 1 origin "refs/tags/$tag:refs/tags/$tag" \
      || die "fetch of $tag failed"
  fi
  actual="$(git -C "$PHP_SRC" rev-parse "$tag^{commit}")"
  [[ "$actual" == "$commit" ]] \
    || die "Tag $tag resolves to $actual, expected $commit (config/versions/$1.env). Stop and verify the upstream source."
  log "Verified $tag = $commit"
}

check_tools
mkdir -p "$QHT_LAB"

if [[ ! -d "$PHP_SRC/.git" ]]; then
  log "Cloning $PHP_REPO ($PHP_TAG) into $PHP_SRC"
  git clone -q --depth 1 --branch "$PHP_TAG" "$PHP_REPO" "$PHP_SRC"
fi

if [[ "${1:-}" == --all ]]; then
  for v in "${SUPPORTED_PHP[@]}"; do fetch_version "$v"; done
else
  fetch_version "$QHT_PHP"
  warn_if_eol
fi
log "Next: QHT_PHP=$QHT_PHP scripts/build.sh baseline"
