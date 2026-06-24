#!/usr/bin/env bash
# Assemble the WordPress.org SVN layout (trunk/, tags/<version>/, assets/) in
# dist/svn/. Mirrors the curated runtime file set from package.sh, but writes an
# unpacked folder tree instead of a zip — SVN stores plugin files unpacked.

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${REPO_ROOT}"

version="$(grep -oE "Version:[[:space:]]+[0-9]+\.[0-9]+\.[0-9]+" x402-pay.php | grep -oE "[0-9]+\.[0-9]+\.[0-9]+")"
[[ -n "${version}" ]] || { echo "could not read Version from x402-pay.php"; exit 1; }
echo "→ staging x402-pay ${version}"

if [[ ! -f assets/build/index.js ]]; then
  echo "→ building admin UI"
  npm run build
fi

# Strip dev deps so the staged tree ships only runtime code. Restore the dev
# install on exit regardless of success.
echo "→ composer install --no-dev (for release)"
composer install --no-dev --optimize-autoloader --no-progress --quiet
trap 'echo "→ restoring dev composer install"; composer install --no-progress --quiet >/dev/null' EXIT

stage="${REPO_ROOT}/dist/svn"
trunk="${stage}/trunk"
tag="${stage}/tags/${version}"
svnassets="${stage}/assets"

rm -rf "${stage}"
mkdir -p "${trunk}/assets" "${tag}" "${svnassets}"

# --- trunk: the plugin itself (same set package.sh zips) ---
cp x402-pay.php "${trunk}/"
cp composer.json "${trunk}/"
cp -R src "${trunk}/"
find "${trunk}/src" -type d -empty -delete 2>/dev/null || true
# Prune dangling symlinks from local path-repo dev so cp -RL doesn't fail.
find vendor -type l ! -exec test -e {} \; -delete 2>/dev/null || true
find vendor -mindepth 1 -type d -empty -delete 2>/dev/null || true
cp -RL vendor "${trunk}/"
cp -R assets/build "${trunk}/assets/"
[[ -f readme.txt ]] && cp readme.txt "${trunk}/"
[[ -f README.md ]]  && cp README.md  "${trunk}/"
[[ -f LICENSE ]]    && cp LICENSE    "${trunk}/"

# --- tags/<version>: frozen copy of trunk ---
cp -R "${trunk}/." "${tag}/"

# --- assets: marketing images only (never in trunk) ---
if [[ -d .wordpress-org ]]; then
  find .wordpress-org -maxdepth 1 -type f \( -name '*.png' -o -name '*.jpg' -o -name '*.gif' \) \
    -exec cp {} "${svnassets}/" \;
fi

echo "→ dist/svn/ ready:"
( cd "${stage}" && find . -maxdepth 2 -type d | sort )
