#!/usr/bin/env bash
# Shared staging helpers — sourced by package.sh and release.sh.
#
# stage_plugin <dest>
#   Populate <dest> with the shippable plugin payload. Assumes vendor/ is
#   already in --no-dev state; wrap the call in with_release_deps.
#
# with_release_deps <cmd> [args...]
#   Install composer --no-dev, invoke <cmd> [args...], then restore the full
#   dev install via an EXIT trap (runs even on error/ctrl-c).

stage_plugin() {
  local dest="$1"
  [[ -n "${dest}" ]] || { echo "stage_plugin: missing dest argument" >&2; return 1; }

  # Ensure the admin UI bundle exists.
  if [[ ! -f assets/build/index.js ]]; then
    echo "→ building admin UI"
    npm run build
  fi

  mkdir -p "${dest}/assets"

  cp x402-pay.php  "${dest}/"
  cp composer.json "${dest}/"
  cp -R src        "${dest}/"
  find "${dest}/src" -type d -empty -delete 2>/dev/null || true

  # Prune dangling symlinks left over from local path-repo dev (e.g. companion
  # plugins) so `cp -RL` doesn't fail trying to follow them, then drop any
  # namespace directory that's now empty as a result.
  find vendor -type l ! -exec test -e {} \; -delete 2>/dev/null || true
  find vendor -mindepth 1 -type d -empty -delete 2>/dev/null || true
  cp -RL vendor "${dest}/"

  cp -R assets/build "${dest}/assets/"

  [[ -f readme.txt ]] && cp readme.txt "${dest}/"
  [[ -f README.md  ]] && cp README.md  "${dest}/"
  [[ -f LICENSE    ]] && cp LICENSE    "${dest}/"
}

with_release_deps() {
  echo "→ composer install --no-dev (for release)"
  composer install --no-dev --optimize-autoloader --no-progress --quiet
  trap 'echo "→ restoring dev composer install"; composer install --no-progress --quiet >/dev/null' EXIT
  "$@"
}
