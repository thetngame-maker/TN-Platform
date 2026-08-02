#!/bin/bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT_DIR"

SOURCE_ZIP="$ROOT_DIR/dist/tn-game-platform-v2.0-shell.zip"
OUTPUT_ZIP="$ROOT_DIR/dist/tn-game-connect-four-dev.zip"

./build-platform-shell-v2.0.sh

if [ ! -f "$SOURCE_ZIP" ]; then
  echo "Expected development source package not found: $SOURCE_ZIP" >&2
  exit 1
fi

cp "$SOURCE_ZIP" "$OUTPUT_ZIP"

unzip -Z1 "$OUTPUT_ZIP" | grep -qx 'manifest'
unzip -Z1 "$OUTPUT_ZIP" | grep -qx 'components/DisplayLoopTask.brs'
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q 'v2.0 PLATFORM SHELL'
unzip -p "$OUTPUT_ZIP" components/MainScene.xml | grep -q 'TN GAME HOME'
unzip -p "$OUTPUT_ZIP" components/MainScene.xml | grep -q 'GUEST PLAYER'
unzip -p "$OUTPUT_ZIP" components/MainScene.xml | grep -q 'ACCOUNT FOUNDATION READY'
unzip -p "$OUTPUT_ZIP" components/MainScene.xml | grep -q 'COLOR CLASH'
unzip -p "$OUTPUT_ZIP" components/MainScene.xml | grep -q 'TN TRIVIA'
unzip -p "$OUTPUT_ZIP" components/DisplayLoopTask.brs | grep -q 'requestCounter'
unzip -p "$OUTPUT_ZIP" components/DisplayLoopTask.brs | grep -q '/tv?poll='
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q 'tn-game-connect-four-server.onrender.com'
! unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q '192\.168\.1\.127'

echo "Created TN Game v2.0 platform development package: $OUTPUT_ZIP"
