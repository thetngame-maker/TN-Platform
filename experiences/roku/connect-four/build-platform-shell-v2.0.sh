#!/bin/bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT_DIR"

BASE_ZIP="$ROOT_DIR/dist/tn-game-connect-four-v1.9.1-home-sync.zip"
OUTPUT_ZIP="$ROOT_DIR/dist/tn-game-platform-v2.0-shell.zip"
WORK_DIR="$(mktemp -d)"
trap 'rm -rf "$WORK_DIR"' EXIT

./build-home-sync-v1.9.1.sh

test -f "$BASE_ZIP"
unzip -q "$BASE_ZIP" -d "$WORK_DIR"

python3 - "$WORK_DIR" <<'PY'
from pathlib import Path
import sys

root = Path(sys.argv[1])
xml_path = root / "components" / "MainScene.xml"
brs_path = root / "components" / "MainScene.brs"

xml = xml_path.read_text()
xml = xml.replace('text="CHOOSE A GAME"', 'text="TN GAME HOME"', 1)
xml = xml.replace(
    'text="One TV. Everyone uses their phone as the controller."',
    'text="Choose a game. Your phone becomes the controller."',
    1,
)
profile_block = '''
      <Poster translation="[1430,150]" width="350" height="104" loadWidth="350" loadHeight="104" loadDisplayMode="scaleToFit" uri="pkg:/images/footer-card.png" />
      <Label text="GUEST PLAYER" translation="[1460,166]" width="290" height="40" horizAlign="center" font="font:MediumBoldSystemFont" color="0xFFFFFFFF" />
      <Label text="ACCOUNT FOUNDATION READY" translation="[1460,207]" width="290" height="30" horizAlign="center" font="font:SmallSystemFont" color="0x7FD5B3FF" />
'''
needle = '    <Group id="lobbyGroup">\n'
if needle not in xml:
    raise SystemExit('lobbyGroup marker not found')
xml = xml.replace(needle, needle + profile_block, 1)
xml = xml.replace(
    'text="Use LEFT and RIGHT to choose a game"',
    'text="TN GAME PLATFORM  •  Choose a title with LEFT and RIGHT"',
    1,
)
xml_path.write_text(xml)

brs = brs_path.read_text()
brs = brs.replace('m.versionLabel.text = "v1.9.1 HOME SYNC"', 'm.versionLabel.text = "v2.0 PLATFORM SHELL"')
brs = brs.replace('m.productTitle.text = "TN GAME CONNECT"', 'm.productTitle.text = "TN GAME"')
brs = brs.replace('m.lobbyMessage.text = "Use LEFT and RIGHT to choose a game"', 'm.lobbyMessage.text = "TN GAME PLATFORM  •  Choose a title with LEFT and RIGHT"')
brs = brs.replace('m.lobbyMessage.text = "Color Clash is the next card game planned"', 'm.lobbyMessage.text = "COLOR CLASH  •  Planned as the next TN Game title"')
brs = brs.replace('m.lobbyMessage.text = "TN Trivia will support teams and local questions"', 'm.lobbyMessage.text = "TN TRIVIA  •  Teams, solo play, and local questions"')
brs_path.write_text(brs)
PY

rm -f "$OUTPUT_ZIP"
(
  cd "$WORK_DIR"
  zip -qr "$OUTPUT_ZIP" .
)

unzip -Z1 "$OUTPUT_ZIP" | grep -qx 'manifest'
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q 'v2.0 PLATFORM SHELL'
unzip -p "$OUTPUT_ZIP" components/MainScene.xml | grep -q 'TN GAME HOME'
unzip -p "$OUTPUT_ZIP" components/MainScene.xml | grep -q 'GUEST PLAYER'
unzip -p "$OUTPUT_ZIP" components/MainScene.xml | grep -q 'ACCOUNT FOUNDATION READY'
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q 'tn-game-connect-four-server.onrender.com'
! unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q '192\.168\.1\.127'

echo "Created TN Game platform shell: $OUTPUT_ZIP"
