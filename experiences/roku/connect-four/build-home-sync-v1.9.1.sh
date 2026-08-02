#!/bin/bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT_DIR"

BASE_ZIP="$ROOT_DIR/dist/tn-game-connect-four-v1.9.0-home.zip"
OUTPUT_ZIP="$ROOT_DIR/dist/tn-game-connect-four-v1.9.1-home-sync.zip"
WORK_DIR="$(mktemp -d)"
trap 'rm -rf "$WORK_DIR"' EXIT

./build-tn-game-home-v1.9.sh

if [ ! -f "$BASE_ZIP" ]; then
  echo "Expected v1.9 home build not found: $BASE_ZIP" >&2
  exit 1
fi

unzip -q "$BASE_ZIP" -d "$WORK_DIR/package"

python3 - <<'PY' "$WORK_DIR/package/components/MainScene.brs" "$WORK_DIR/package/components/DisplayLoopTask.brs"
from pathlib import Path
import sys

main_path = Path(sys.argv[1])
loop_path = Path(sys.argv[2])
main = main_path.read_text()
loop = loop_path.read_text()

main = main.replace('m.versionLabel.text = "v1.9 HOME"', 'm.versionLabel.text = "v1.9.1 HOME SYNC"')

old = '''  while true
    clock = CreateObject("roDateTime")
    stateText = getText(baseUrl + "/api/rooms/" + roomCode + "/tv?t=" + clock.AsSeconds().toStr())'''
new = '''  requestCounter = 0
  while true
    requestCounter += 1
    stateText = getText(baseUrl + "/api/rooms/" + roomCode + "/tv?poll=" + requestCounter.toStr())'''
if old not in loop:
    raise SystemExit('Could not find Roku TV polling loop')
loop = loop.replace(old, new, 1)
loop = loop.replace('transfer.AddHeader("Cache-Control", "no-cache")', 'transfer.AddHeader("Cache-Control", "no-store, no-cache, must-revalidate")\n  transfer.AddHeader("Pragma", "no-cache")')

main_path.write_text(main)
loop_path.write_text(loop)
PY

rm -f "$OUTPUT_ZIP"
(
  cd "$WORK_DIR/package"
  zip -qr "$OUTPUT_ZIP" . \
    -x '*.DS_Store' '__MACOSX/*' '*.backup*' '*.v11-backup*' '*.thin-client-backup*'
)

unzip -Z1 "$OUTPUT_ZIP" | grep -qx 'manifest'
unzip -Z1 "$OUTPUT_ZIP" | grep -qx 'components/DisplayLoopTask.brs'
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q 'v1.9.1 HOME SYNC'
unzip -p "$OUTPUT_ZIP" components/DisplayLoopTask.brs | grep -q 'requestCounter'
unzip -p "$OUTPUT_ZIP" components/DisplayLoopTask.brs | grep -q '/tv?poll='
unzip -p "$OUTPUT_ZIP" components/DisplayLoopTask.brs | grep -q 'must-revalidate'
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q 'tn-game-connect-four-server.onrender.com'
! unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q '192\.168\.1\.127'

echo "Created $OUTPUT_ZIP"
