#!/usr/bin/env bash
# Local dev orchestrator. Runs three processes under one Ctrl-C:
# asset watcher, wpcom stub, wp-now. See LOCAL_DEV.md.

set -euo pipefail

STUB_PORT="${STUB_PORT:-9002}"
STUB_HOST="${STUB_HOST:-localhost}"
STUB_URL="http://${STUB_HOST}:${STUB_PORT}"

# WP 7.0 is where the Connectors API that drives the facilitator picker lives.
WP_VERSION="${WP_VERSION:-7.0-RC2}"

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${REPO_ROOT}"

if ! command -v wp-now >/dev/null 2>&1; then
  echo "wp-now not found on PATH. Install with: npm i -g @wp-now/wp-now" >&2
  exit 1
fi

if [[ ! -d node_modules ]]; then
  echo "→ installing npm deps (first run)"
  npm install
fi

# Ensure there's a built bundle before wp-now serves its first admin request.
if [[ ! -f assets/build/index.js ]]; then
  echo "→ initial asset build"
  npm run build
fi

# Companion zip the blueprint installs over HTTP from the stub server. Rebuilt
# each run so companion-source edits land on the next dev.sh restart.
mkdir -p scripts/dev-artifacts
rm -f scripts/dev-artifacts/simple-x402-jetpack.zip
( cd companions && zip -qr "${REPO_ROOT}/scripts/dev-artifacts/simple-x402-jetpack.zip" simple-x402-jetpack -x 'simple-x402-jetpack/tests/*' 'simple-x402-jetpack/vendor/*' '*.DS_Store' )

cleanup() {
  local code=$?
  [[ -n "${WATCH_PID:-}" ]] && kill "${WATCH_PID}" 2>/dev/null || true
  [[ -n "${STUB_PID:-}" ]]  && kill "${STUB_PID}"  2>/dev/null || true
  exit "${code}"
}
trap cleanup EXIT INT TERM

npm run start >/tmp/x402-watch.log 2>&1 &
WATCH_PID=$!
echo "→ asset watcher running (log: /tmp/x402-watch.log)"

php -S "${STUB_HOST}:${STUB_PORT}" "${REPO_ROOT}/scripts/wpcom-stub.php" >/tmp/x402-stub.log 2>&1 &
STUB_PID=$!
# Give the stub a moment to bind before wp-now starts probing.
sleep 0.5
echo "→ stub listening on ${STUB_URL} (log: /tmp/x402-stub.log)"

echo "→ wp-now starting (WP=${WP_VERSION}, PHP=8.1) with SIMPLE_X402_JETPACK_DEV_URL=${STUB_URL}"
SIMPLE_X402_JETPACK_DEV_URL="${STUB_URL}" wp-now start \
  --wp="${WP_VERSION}" \
  --php=8.1 \
  --blueprint="${REPO_ROOT}/scripts/dev-blueprint.json" \
  "$@"
