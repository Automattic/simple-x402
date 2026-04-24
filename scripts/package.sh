#!/usr/bin/env bash
# Build distribution-ready zips in dist/. Called from package.json scripts.
# Usage: scripts/package.sh [main | companion | all]  (default: all)

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${REPO_ROOT}"
mkdir -p dist

pack_main() {
  if [[ ! -f assets/build/index.js ]]; then
    echo "→ building admin UI"
    npm run build
  fi

  # Strip dev deps (phpunit, phpcs, etc.) so the zip ships only what the
  # plugin needs at runtime. Saved dev install is restored at the end,
  # regardless of whether packaging succeeded.
  echo "→ composer install --no-dev (for release)"
  composer install --no-dev --optimize-autoloader --no-progress --quiet
  trap 'echo "→ restoring dev composer install"; composer install --no-progress --quiet >/dev/null' RETURN

  local zipdest="${REPO_ROOT}/dist/simple-x402.zip"
  local tmp
  tmp="$(mktemp -d)"

  local root="${tmp}/simple-x402"
  mkdir -p "${root}/assets"
  cp simple-x402.php "${root}/"
  cp -R src "${root}/"
  # -L follows symlinks — vendor/automattic/simple-x402-jetpack is a
  # composer path-repo symlink into companions/, so dereferencing copies
  # the real files in. Without this, the zip would contain a broken link.
  cp -RL vendor "${root}/"
  cp -R assets/build "${root}/assets/"
  [[ -f README.md ]] && cp README.md "${root}/"
  [[ -f LICENSE ]]   && cp LICENSE   "${root}/"

  rm -f "${zipdest}"
  ( cd "${tmp}" && zip -qr "${zipdest}" simple-x402 \
      -x '*.DS_Store' \
         'simple-x402/vendor/automattic/simple-x402-jetpack/tests/*' \
         'simple-x402/vendor/automattic/simple-x402-jetpack/WPCOM_*.md' \
         'simple-x402/vendor/automattic/simple-x402-jetpack/.gitignore' )
  rm -rf "${tmp}"
  echo "→ dist/simple-x402.zip"
}

pack_companion() {
  local zipdest="${REPO_ROOT}/dist/simple-x402-jetpack.zip"
  rm -f "${zipdest}"
  ( cd companions && zip -qr "${zipdest}" simple-x402-jetpack \
      -x 'simple-x402-jetpack/tests/*' \
         'simple-x402-jetpack/vendor/*' \
         'simple-x402-jetpack/WPCOM_*.md' \
         'simple-x402-jetpack/.gitignore' \
         '*.DS_Store' )
  echo "→ dist/simple-x402-jetpack.zip"
}

case "${1:-all}" in
  main)      pack_main ;;
  companion) pack_companion ;;
  all)       pack_main; pack_companion ;;
  *) echo "unknown target: ${1} (expected: main | companion | all)" >&2; exit 1 ;;
esac
