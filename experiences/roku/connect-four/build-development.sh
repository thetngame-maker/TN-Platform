#!/bin/bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT_DIR"

SOURCE_ZIP="$ROOT_DIR/dist/tn-game-connect-four-v1.5.0-tv-polish.zip"
OUTPUT_ZIP="$ROOT_DIR/dist/tn-game-connect-four-dev.zip"

# Build the latest Connect Four development milestone.
./build-tv-polish-v1.5.sh

if [ ! -f "$SOURCE_ZIP" ]; then
  echo "Expected development source package not found: $SOURCE_ZIP" >&2
  exit 1
fi

cp "$SOURCE_ZIP" "$OUTPUT_ZIP"

unzip -Z1 "$OUTPUT_ZIP" | grep -qx 'manifest'
unzip -Z1 "$OUTPUT_ZIP" | grep -qx 'components/DisplayLoopTask.brs'
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q 'v1.5 TV POLISH'
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q 'applyTvPolish'
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q 'tn-game-connect-four-server.onrender.com'
unzip -p "$OUTPUT_ZIP" components/DisplayLoopTask.brs | grep -q '/tv?t='
! unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q '192\.168\.1\.127'

echo "Created stable development package: $OUTPUT_ZIP"
