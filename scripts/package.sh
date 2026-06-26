#!/usr/bin/env bash
# Build a distribution-ready zip for the main plugin in dist/.

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${REPO_ROOT}"
mkdir -p dist

# shellcheck source=lib/stage.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib/stage.sh"

zipdest="${REPO_ROOT}/dist/x402-pay.zip"
tmp="$(mktemp -d)"

with_release_deps stage_plugin "${tmp}/x402-pay"

rm -f "${zipdest}"
( cd "${tmp}" && zip -qr "${zipdest}" x402-pay -x '*.DS_Store' )
rm -rf "${tmp}"
echo "→ dist/x402-pay.zip"
