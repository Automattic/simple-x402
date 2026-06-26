#!/usr/bin/env bash
# Deploy the current plugin version to the WordPress.org SVN directory.
#
# Usage:
#   scripts/release.sh [--commit] [--svn-dir <path>]
#
# Default behaviour: stage everything (trunk, tag, assets) and print the
# exact `svn ci` command to review — nothing is committed.
# Pass --commit to have the script run the commit itself.
#
# The SVN working copy is cached at dist/svn/ (covered by /dist/ in
# .gitignore) and kept current with `svn up` on subsequent runs.
#
# Override the working copy path with --svn-dir or the SVN_DIR env var.

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${REPO_ROOT}"

# shellcheck source=lib/stage.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib/stage.sh"

SVN_URL="https://plugins.svn.wordpress.org/x402-pay"
DO_COMMIT=false
SVN_DIR="${SVN_DIR:-${REPO_ROOT}/dist/svn}"

# ── Argument parsing ──────────────────────────────────────────────────────────
while [[ $# -gt 0 ]]; do
  case "$1" in
    --commit)  DO_COMMIT=true; shift ;;
    --svn-dir) SVN_DIR="$2"; shift 2 ;;
    *) echo "Unknown argument: $1" >&2; exit 1 ;;
  esac
done

# ── Resolve version ───────────────────────────────────────────────────────────
VERSION="$(awk '/^ \* Version:/{print $NF; exit}' x402-pay.php)"
if [[ -z "${VERSION}" ]]; then
  echo "✗ Could not read version from x402-pay.php" >&2
  exit 1
fi
echo "→ version: ${VERSION}"

# ── Validate version consistency ──────────────────────────────────────────────
errors=0

check_version() {
  local label="$1" actual="$2"
  if [[ "${actual}" != "${VERSION}" ]]; then
    echo "✗ ${label} is '${actual}', expected '${VERSION}'" >&2
    errors=$(( errors + 1 ))
  fi
}

CONST_VER="$(awk -F"'" '/define.*X402_PAY_VERSION/{print $4; exit}' x402-pay.php)"
STABLE_TAG="$(awk '/^Stable tag:/{print $NF; exit}' readme.txt)"
PKG_VER="$(node -p "require('./package.json').version" 2>/dev/null)"

check_version "X402_PAY_VERSION constant (x402-pay.php)" "${CONST_VER}"
check_version "Stable tag (readme.txt)"                   "${STABLE_TAG}"
check_version "version (package.json)"                    "${PKG_VER}"

if ! grep -qF "= ${VERSION} =" readme.txt; then
  echo "✗ readme.txt changelog has no '= ${VERSION} =' entry" >&2
  errors=$(( errors + 1 ))
fi

if (( errors > 0 )); then
  echo "" >&2
  echo "Fix the version mismatches above, then re-run." >&2
  exit 1
fi
echo "→ version strings consistent"

# ── Clean working tree ────────────────────────────────────────────────────────
if [[ -n "$(git status --porcelain 2>/dev/null)" ]]; then
  echo "✗ Git working tree is dirty. Commit or stash changes before releasing." >&2
  exit 1
fi
echo "→ git working tree clean"

# ── SVN checkout / update ─────────────────────────────────────────────────────
mkdir -p "$(dirname "${SVN_DIR}")"
if [[ -d "${SVN_DIR}/.svn" ]]; then
  echo "→ updating SVN checkout at ${SVN_DIR}"
  svn up "${SVN_DIR}/trunk"  --set-depth infinity
  svn up "${SVN_DIR}/assets" --set-depth infinity
  svn up "${SVN_DIR}/tags"   --set-depth immediates
else
  echo "→ checking out ${SVN_URL}"
  echo "  (first run — fetching trunk and assets; tag list only, no tag contents)"
  svn co "${SVN_URL}" "${SVN_DIR}" --depth immediates
  svn up "${SVN_DIR}/trunk"  --set-depth infinity
  svn up "${SVN_DIR}/assets" --set-depth infinity
  svn up "${SVN_DIR}/tags"   --set-depth immediates
fi

# ── Guard: don't re-release ───────────────────────────────────────────────────
if [[ -d "${SVN_DIR}/tags/${VERSION}" ]]; then
  echo "✗ tags/${VERSION} already exists in SVN — has this version already been released?" >&2
  exit 1
fi

# ── Stage trunk ───────────────────────────────────────────────────────────────
echo "→ staging plugin files into trunk"
tmp="$(mktemp -d)"
with_release_deps stage_plugin "${tmp}/plugin"
rsync -a --delete --exclude='.svn' "${tmp}/plugin/" "${SVN_DIR}/trunk/"
rm -rf "${tmp}"

# ── Stage listing artwork ─────────────────────────────────────────────────────
echo "→ staging listing artwork into assets"
mkdir -p "${SVN_DIR}/assets"
find .wordpress-org -maxdepth 1 -type f ! -name 'README.md' -exec cp {} "${SVN_DIR}/assets/" \;

# ── Reconcile SVN adds/deletes ────────────────────────────────────────────────
echo "→ reconciling SVN state"

svn_sync_dir() {
  local dir="$1"
  # Add all new/unversioned files and directories recursively.
  svn add --force "${dir}" 2>/dev/null || true
  # Schedule removal of any tracked file that is now missing locally.
  svn status "${dir}" | awk '/^!/{print $2}' | while IFS= read -r f; do
    svn rm "${f}"
  done
}

svn_sync_dir "${SVN_DIR}/trunk"
svn_sync_dir "${SVN_DIR}/assets"

# ── Create tag via local svn copy ─────────────────────────────────────────────
echo "→ creating tag tags/${VERSION}"
svn cp "${SVN_DIR}/trunk" "${SVN_DIR}/tags/${VERSION}"

# ── Review / commit ───────────────────────────────────────────────────────────
echo ""
svn status "${SVN_DIR}"
echo ""

CI_CMD="svn ci \"${SVN_DIR}\" -m \"Release ${VERSION}\""

if [[ "${DO_COMMIT}" == "true" ]]; then
  echo "→ committing to WordPress.org"
  echo "  (you may be prompted for your WordPress.org username and password)"
  echo ""
  eval "${CI_CMD}"
  echo ""
  echo "✓ Released ${VERSION}"
  echo "  https://wordpress.org/plugins/x402-pay/"
else
  echo "Dry run complete — nothing has been committed."
  echo "Review the output above, then commit with either:"
  echo ""
  echo "  ${CI_CMD}"
  echo ""
  echo "  — or —"
  echo ""
  echo "  scripts/release.sh --commit"
fi
