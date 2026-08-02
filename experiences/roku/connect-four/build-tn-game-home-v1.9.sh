#!/bin/bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT_DIR"

BASE_ZIP="$ROOT_DIR/dist/tn-game-connect-four-v1.5.0-tv-polish.zip"
OUTPUT_ZIP="$ROOT_DIR/dist/tn-game-connect-four-v1.9.0-home.zip"
WORK_DIR="$(mktemp -d)"
trap 'rm -rf "$WORK_DIR"' EXIT

./build-tv-polish-v1.5.sh

if [ ! -f "$BASE_ZIP" ]; then
  echo "Expected v1.5 TV build not found: $BASE_ZIP" >&2
  exit 1
fi

unzip -q "$BASE_ZIP" -d "$WORK_DIR/package"

python3 - <<'PY' "$WORK_DIR/package/components/MainScene.brs" "$WORK_DIR/package/components/MainScene.xml"
from pathlib import Path
import sys

brs_path = Path(sys.argv[1])
xml_path = Path(sys.argv[2])
brs = brs_path.read_text()
xml = xml_path.read_text()

brs = brs.replace('m.versionLabel.text = "v1.5 TV POLISH"', 'm.versionLabel.text = "v1.9 HOME"')
brs = brs.replace('m.productTitle.text = "TN GAME CONNECT"', 'm.productTitle.text = "GAME LIBRARY"')
brs = brs.replace('m.lobbyMessage.text = "Use LEFT and RIGHT to choose a game"', 'm.lobbyMessage.text = "Choose a game • Press OK to play"')
brs = brs.replace('m.lobbyMessage.text = "Color Clash is the next card game planned"', 'm.lobbyMessage.text = "COLOR CLASH • Coming in a future update"')
brs = brs.replace('m.lobbyMessage.text = "TN Trivia will support teams and local questions"', 'm.lobbyMessage.text = "TN TRIVIA • Teams, local questions, and live play"')

# Make Back from the Connect Four mode menu return to the TN Game library.
brs = brs.replace('''    else if key = "back"
      showLobby()
    else''', '''    else if key = "back"
      showLobby()
    else''', 1)

xml = xml.replace('id="productTitle" text="TN GAME CONNECT"', 'id="productTitle" text="GAME LIBRARY"')
xml = xml.replace('text="CHOOSE A GAME"', 'text="TN GAME LIBRARY"')
xml = xml.replace('text="One TV. Everyone uses their phone as the controller."', 'text="One TV • Phones become controllers • More games coming soon"')
xml = xml.replace('id="lobbyMessage" text="Use LEFT and RIGHT to choose a game"', 'id="lobbyMessage" text="Choose a game • Press OK to play"')

brs_path.write_text(brs)
xml_path.write_text(xml)
PY

rm -f "$OUTPUT_ZIP"
(
  cd "$WORK_DIR/package"
  zip -qr "$OUTPUT_ZIP" . \
    -x '*.DS_Store' '__MACOSX/*' '*.backup*' '*.v11-backup*' '*.thin-client-backup*'
)

unzip -Z1 "$OUTPUT_ZIP" | grep -qx 'manifest'
unzip -Z1 "$OUTPUT_ZIP" | grep -qx 'components/DisplayLoopTask.brs'
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q 'v1.9 HOME'
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q 'GAME LIBRARY'
unzip -p "$OUTPUT_ZIP" components/MainScene.xml | grep -q 'TN GAME LIBRARY'
unzip -p "$OUTPUT_ZIP" components/MainScene.xml | grep -q 'COLOR CLASH'
unzip -p "$OUTPUT_ZIP" components/MainScene.xml | grep -q 'TN TRIVIA'
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q 'tn-game-connect-four-server.onrender.com'
unzip -p "$OUTPUT_ZIP" components/DisplayLoopTask.brs | grep -q '/tv?t='
! unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q '192\.168\.1\.127'

echo "Created $OUTPUT_ZIP"
