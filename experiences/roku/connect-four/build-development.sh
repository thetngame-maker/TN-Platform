#!/bin/bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT_DIR"

SOURCE_ZIP="$ROOT_DIR/dist/tn-game-platform-v2.4-launcher.zip"
OUTPUT_ZIP="$ROOT_DIR/dist/tn-game-connect-four-dev.zip"

./build-multi-game-launcher-v2.4.sh

if [ ! -f "$SOURCE_ZIP" ]; then
  echo "Expected modular platform package not found: $SOURCE_ZIP" >&2
  exit 1
fi

cp "$SOURCE_ZIP" "$OUTPUT_ZIP"

unzip -Z1 "$OUTPUT_ZIP" | grep -Fx 'manifest' >/dev/null
unzip -Z1 "$OUTPUT_ZIP" | grep -Fx 'components/DisplayLoopTask.brs' >/dev/null
unzip -Z1 "$OUTPUT_ZIP" | grep -Fx 'components/GameRouter.brs' >/dev/null
unzip -p "$OUTPUT_ZIP" components/MainScene.xml | grep -F 'pkg:/components/GameRouter.brs' >/dev/null
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -F 'v3.0 MODULE ARCHITECTURE' >/dev/null
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -F 'routeForSelection(m.lobbySelection)' >/dev/null
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -F 'controllerUrlForGame(m.baseUrl, m.activeRoute, m.roomCode)' >/dev/null
unzip -p "$OUTPUT_ZIP" components/GameRouter.brs | grep -F '"connect-four"' >/dev/null
unzip -p "$OUTPUT_ZIP" components/GameRouter.brs | grep -F '"color-clash"' >/dev/null
unzip -p "$OUTPUT_ZIP" components/GameRouter.brs | grep -F '"word-tiles"' >/dev/null
unzip -p "$OUTPUT_ZIP" components/GameRouter.brs | grep -F '"/color-clash"' >/dev/null
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -F 'tn-game-connect-four-server.onrender.com' >/dev/null
if unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -F '192.168.1.127' >/dev/null; then
  echo "Local server address found in development package" >&2
  exit 1
fi

echo "Created TN Game v3.0 modular platform package: $OUTPUT_ZIP"
