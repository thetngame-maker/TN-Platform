#!/bin/bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT_DIR"

SOURCE_ZIP="$ROOT_DIR/dist/tn-game-platform-v2.4-launcher.zip"
OUTPUT_ZIP="$ROOT_DIR/dist/tn-game-connect-four-dev.zip"

./build-multi-game-launcher-v2.4.sh

if [ ! -f "$SOURCE_ZIP" ]; then
  echo "Expected scene router package not found: $SOURCE_ZIP" >&2
  exit 1
fi

cp "$SOURCE_ZIP" "$OUTPUT_ZIP"

unzip -Z1 "$OUTPUT_ZIP" | grep -Fx 'manifest' >/dev/null
unzip -Z1 "$OUTPUT_ZIP" | grep -Fx 'components/DisplayLoopTask.brs' >/dev/null
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -F 'v2.5 SCENE ROUTER FOUNDATION' >/dev/null
unzip -p "$OUTPUT_ZIP" components/MainScene.xml | grep -F 'TN GAME HOME' >/dev/null
unzip -p "$OUTPUT_ZIP" components/MainScene.xml | grep -F 'GUEST PLAYER' >/dev/null
unzip -p "$OUTPUT_ZIP" components/MainScene.xml | grep -F 'ACCOUNT FOUNDATION READY' >/dev/null
unzip -p "$OUTPUT_ZIP" components/MainScene.xml | grep -F 'COLOR CLASH' >/dev/null
unzip -p "$OUTPUT_ZIP" components/MainScene.xml | grep -F 'WORD TILES' >/dev/null
unzip -p "$OUTPUT_ZIP" components/MainScene.xml | grep -F 'ROOM PAIRING READY' >/dev/null
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -F 'sub launchSelectedGame()' >/dev/null
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -F 'startColorClashPairing()' >/dev/null
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -F 'applyColorClashPairingState' >/dev/null
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -F '/color-clash?room=' >/dev/null
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -F 'Press OK to create a Color Clash room' >/dev/null
unzip -p "$OUTPUT_ZIP" components/DisplayLoopTask.brs | grep -F 'requestCounter' >/dev/null
unzip -p "$OUTPUT_ZIP" components/DisplayLoopTask.brs | grep -F '/tv?poll=' >/dev/null
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -F 'tn-game-connect-four-server.onrender.com' >/dev/null
if unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -F 'Coming in a future update' >/dev/null; then
  echo "Obsolete Color Clash coming-soon behavior found" >&2
  exit 1
fi
if unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -F '192.168.1.127' >/dev/null; then
  echo "Local server address found in development package" >&2
  exit 1
fi

echo "Created TN Game v2.5 scene router foundation package: $OUTPUT_ZIP"
