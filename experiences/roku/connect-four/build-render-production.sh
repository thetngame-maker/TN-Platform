#!/bin/bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT_DIR"

THIN_ZIP="$ROOT_DIR/dist/tn-game-connect-four-v1.0.0-thin-client.zip"
OUTPUT_ZIP="$ROOT_DIR/dist/tn-game-connect-four-render-production.zip"

# Build from the proven persistent DisplayLoopTask client rather than the
# older one-request-per-poll MainScene networking path.
./build-thin-client-v1.sh

if [ ! -f "$THIN_ZIP" ]; then
  echo "Expected thin-client Roku build not found: $THIN_ZIP" >&2
  exit 1
fi

cp "$THIN_ZIP" "$OUTPUT_ZIP"

# Validate Roku package structure and production networking.
unzip -Z1 "$OUTPUT_ZIP" | grep -qx 'manifest' || {
  echo "Invalid Roku package: manifest is not at ZIP root" >&2
  exit 1
}

unzip -Z1 "$OUTPUT_ZIP" | grep -qx 'components/DisplayLoopTask.brs' || {
  echo "Invalid Roku package: persistent display task is missing" >&2
  exit 1
}

if unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q '192\.168\.1\.127'; then
  echo "Invalid Roku package: local server URL is still present" >&2
  exit 1
fi

unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q 'tn-game-connect-four-server.onrender.com' || {
  echo "Invalid Roku package: Render URL is missing" >&2
  exit 1
}

# Confirm this is the persistent polling build that receives lobby -> playing
# transitions without recreating SceneGraph network task nodes.
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q 'DisplayLoopTask' || {
  echo "Invalid Roku package: MainScene is not using DisplayLoopTask" >&2
  exit 1
}

unzip -p "$OUTPUT_ZIP" components/DisplayLoopTask.brs | grep -q '/tv?t=' || {
  echo "Invalid Roku package: continuous TV-state polling is missing" >&2
  exit 1
}

echo "Created $OUTPUT_ZIP"
