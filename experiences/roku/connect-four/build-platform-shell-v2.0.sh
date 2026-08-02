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
needle = '    <Group id="lobbyGroup">\n'
if needle not in xml:
    raise SystemExit('Platform XML lobbyGroup marker not found')

heading_markers = ['text="TN GAME LIBRARY"', 'text="CHOOSE A GAME"', 'text="TN GAME HOME"']
heading_marker = next((marker for marker in heading_markers if marker in xml), None)
if heading_marker is None:
    raise SystemExit('Platform XML heading marker not found')
xml = xml.replace(heading_marker, 'text="TN GAME HOME"', 1)

subtitle_markers = [
    'text="One TV. Everyone uses their phone as the controller."',
    'text="Choose a game. Your phone becomes the controller."',
]
subtitle_marker = next((marker for marker in subtitle_markers if marker in xml), None)
if subtitle_marker is not None:
    xml = xml.replace(
        subtitle_marker,
        'text="Choose a game. Your phone becomes the controller."',
        1,
    )

profile_block = '''
      <Poster translation="[1430,150]" width="350" height="104" loadWidth="350" loadHeight="104" loadDisplayMode="scaleToFit" uri="pkg:/images/footer-card.png" />
      <Label text="GUEST PLAYER" translation="[1460,166]" width="290" height="40" horizAlign="center" font="font:MediumBoldSystemFont" color="0xFFFFFFFF" />
      <Label text="ACCOUNT FOUNDATION READY" translation="[1460,207]" width="290" height="30" horizAlign="center" font="font:SmallSystemFont" color="0x7FD5B3FF" />
'''
if 'text="GUEST PLAYER"' not in xml:
    xml = xml.replace(needle, needle + profile_block, 1)

xml = xml.replace('text="TN TRIVIA"', 'text="WORD TILES"', 1)
xml = xml.replace('text="TEAMS OR SOLO"', 'text="2–4 PLAYERS"', 1)
xml = xml.replace(
    'text="Use LEFT and RIGHT to choose a game"',
    'text="TN GAME PLATFORM  •  Choose a title with LEFT and RIGHT"',
    1,
)
xml_path.write_text(xml)

brs = brs_path.read_text()
version_markers = [
    'm.versionLabel.text = "v1.9.1 HOME SYNC"',
    'm.versionLabel.text = "v2.0 PLATFORM SHELL"',
]
version_marker = next((marker for marker in version_markers if marker in brs), None)
if version_marker is None:
    raise SystemExit('Platform BrightScript version marker not found')
brs = brs.replace(
    version_marker,
    'm.versionLabel.text = "v2.1 MULTI-GAME LIBRARY"',
    1,
)
brs = brs.replace('m.productTitle.text = "TN GAME CONNECT"', 'm.productTitle.text = "TN GAME"')
brs = brs.replace(
    'm.lobbyMessage.text = "Use LEFT and RIGHT to choose a game"',
    'm.lobbyMessage.text = "TN GAME PLATFORM  •  Choose a title with LEFT and RIGHT"',
)
brs = brs.replace(
    'm.lobbyMessage.text = "Color Clash is the next card game planned"',
    'm.lobbyMessage.text = "COLOR CLASH  •  Fast color-matching card battles"',
)
brs = brs.replace(
    'm.lobbyMessage.text = "TN Trivia will support teams and local questions"',
    'm.lobbyMessage.text = "WORD TILES  •  Build words and compete for points"',
)
brs_path.write_text(brs)
PY

rm -f "$OUTPUT_ZIP"
(
  cd "$WORK_DIR"
  zip -qr "$OUTPUT_ZIP" .
)

unzip -Z1 "$OUTPUT_ZIP" | grep -Fx 'manifest' >/dev/null
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -F 'v2.1 MULTI-GAME LIBRARY' >/dev/null
unzip -p "$OUTPUT_ZIP" components/MainScene.xml | grep -F 'TN GAME HOME' >/dev/null
unzip -p "$OUTPUT_ZIP" components/MainScene.xml | grep -F 'GUEST PLAYER' >/dev/null
unzip -p "$OUTPUT_ZIP" components/MainScene.xml | grep -F 'ACCOUNT FOUNDATION READY' >/dev/null
unzip -p "$OUTPUT_ZIP" components/MainScene.xml | grep -F 'COLOR CLASH' >/dev/null
unzip -p "$OUTPUT_ZIP" components/MainScene.xml | grep -F 'WORD TILES' >/dev/null
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -F 'tn-game-connect-four-server.onrender.com' >/dev/null
if unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -F '192.168.1.127' >/dev/null; then
  echo "Local server address found in platform package" >&2
  exit 1
fi

echo "Created TN Game multi-game library shell: $OUTPUT_ZIP"
