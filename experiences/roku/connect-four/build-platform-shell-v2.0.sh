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
    xml = xml.replace(subtitle_marker, 'text="Choose a game. Your phone becomes the controller."', 1)

profile_block = '''
      <Poster translation="[1430,150]" width="350" height="104" loadWidth="350" loadHeight="104" loadDisplayMode="scaleToFit" uri="pkg:/images/footer-card.png" />
      <Label text="GUEST PLAYER" translation="[1460,166]" width="290" height="40" horizAlign="center" font="font:MediumBoldSystemFont" color="0xFFFFFFFF" />
      <Label text="ACCOUNT FOUNDATION READY" translation="[1460,207]" width="290" height="30" horizAlign="center" font="font:SmallSystemFont" color="0x7FD5B3FF" />
'''
if 'text="GUEST PLAYER"' not in xml:
    xml = xml.replace(needle, needle + profile_block, 1)

xml = xml.replace('text="TN TRIVIA"', 'text="WORD TILES"', 1)
xml = xml.replace('text="TEAMS OR SOLO"', 'text="2–4 PLAYERS"', 1)
xml = xml.replace('text="Use LEFT and RIGHT to choose a game"', 'text="TN GAME PLATFORM  •  Choose a title with LEFT and RIGHT"', 1)

mode_marker = '    <Group id="modeGroup" visible="false">\n'
if mode_marker not in xml:
    raise SystemExit('Color Clash insertion marker not found')
color_clash_group = '''    <Group id="colorClashGroup" visible="false">
      <Label text="COLOR CLASH" translation="[360,154]" width="1200" height="72" horizAlign="center" font="font:LargeBoldSystemFont" color="0xFFFFFFFF" />
      <Label text="Match colors, play action cards, and empty your hand first." translation="[360,222]" width="1200" height="46" horizAlign="center" font="font:MediumBoldSystemFont" color="0xA7BDB5FF" />
      <Poster translation="[510,316]" width="900" height="440" loadWidth="900" loadHeight="440" loadDisplayMode="scaleToFit" uri="pkg:/images/board-card.png" />
      <Poster translation="[610,380]" width="220" height="220" loadWidth="220" loadHeight="220" loadDisplayMode="scaleToFit" uri="pkg:/images/token-purple.png" />
      <Poster translation="[850,420]" width="220" height="220" loadWidth="220" loadHeight="220" loadDisplayMode="scaleToFit" uri="pkg:/images/token-green.png" />
      <Poster translation="[1090,380]" width="220" height="220" loadWidth="220" loadHeight="220" loadDisplayMode="scaleToFit" uri="pkg:/images/token-orange.png" />
      <Label text="ROOM + PHONE CONTROLLER FOUNDATION" translation="[560,654]" width="800" height="52" horizAlign="center" font="font:LargeBoldSystemFont" color="0xFFFFFFFF" />
      <Label text="Next: create rooms, join by QR, deal hands, and play the first round." translation="[490,714]" width="940" height="42" horizAlign="center" font="font:MediumBoldSystemFont" color="0x7FD5B3FF" />
      <Poster translation="[430,890]" width="1060" height="92" loadWidth="1060" loadHeight="92" loadDisplayMode="scaleToFit" uri="pkg:/images/footer-card.png" />
      <Label text="PRESS BACK TO RETURN TO THE TN GAME HOME" translation="[455,910]" width="1010" height="48" horizAlign="center" vertAlign="center" font="font:MediumBoldSystemFont" color="0xFFFFFFFF" />
    </Group>

'''
xml = xml.replace(mode_marker, color_clash_group + mode_marker, 1)
xml_path.write_text(xml)

brs = brs_path.read_text()
version_markers = [
    'm.versionLabel.text = "v1.9.1 HOME SYNC"',
    'm.versionLabel.text = "v2.0 PLATFORM SHELL"',
    'm.versionLabel.text = "v2.1 MULTI-GAME LIBRARY"',
]
version_marker = next((marker for marker in version_markers if marker in brs), None)
if version_marker is None:
    raise SystemExit('Platform BrightScript version marker not found')
brs = brs.replace(version_marker, 'm.versionLabel.text = "v2.2 COLOR CLASH FOUNDATION"', 1)
brs = brs.replace('m.productTitle.text = "TN GAME CONNECT"', 'm.productTitle.text = "TN GAME"')
brs = brs.replace(
    '  m.triviaPrompt = m.top.findNode("triviaPrompt")\n',
    '  m.triviaPrompt = m.top.findNode("triviaPrompt")\n  m.colorClashGroup = m.top.findNode("colorClashGroup")\n',
    1,
)
brs = brs.replace(
    '      if m.lobbySelection = 0 then showModeSelect() else m.lobbyMessage.text = "COMING SOON  •  Choose Connect Four to play now"',
    '      if m.lobbySelection = 0\n        showModeSelect()\n      else if m.lobbySelection = 1\n        showColorClashFoundation()\n      else\n        m.lobbyMessage.text = "WORD TILES  •  Foundation coming after Color Clash"\n      end if',
    1,
)
brs = brs.replace(
    '  m.modeGroup.visible = false\n  m.joinGroup.visible = false',
    '  m.modeGroup.visible = false\n  m.colorClashGroup.visible = false\n  m.joinGroup.visible = false',
    1,
)
brs = brs.replace(
    '  m.lobbyGroup.visible = false\n  m.modeGroup.visible = true',
    '  m.lobbyGroup.visible = false\n  m.colorClashGroup.visible = false\n  m.modeGroup.visible = true',
    1,
)
insert_before = 'sub updateLobbySelection()\n'
if insert_before not in brs:
    raise SystemExit('Color Clash BrightScript insertion marker not found')
color_clash_function = '''sub showColorClashFoundation()
  m.state = "colorClash"
  m.productTitle.text = "COLOR CLASH"
  m.lobbyGroup.visible = false
  m.modeGroup.visible = false
  m.joinGroup.visible = false
  showGameChrome(false)
  m.colorClashGroup.visible = true
end sub

'''
brs = brs.replace(insert_before, color_clash_function + insert_before, 1)
brs = brs.replace('m.colorClashPrompt.text = "COMING SOON"', 'm.colorClashPrompt.text = "FOUNDATION READY"')
brs = brs.replace('m.lobbyMessage.text = "Color Clash is the next card game planned"', 'm.lobbyMessage.text = "COLOR CLASH  •  Press OK to open the game foundation"')
brs = brs.replace('m.lobbyMessage.text = "TN Trivia will support teams and local questions"', 'm.lobbyMessage.text = "WORD TILES  •  Build words and compete for points"')
brs_path.write_text(brs)
PY

rm -f "$OUTPUT_ZIP"
(
  cd "$WORK_DIR"
  zip -qr "$OUTPUT_ZIP" .
)

unzip -Z1 "$OUTPUT_ZIP" | grep -Fx 'manifest' >/dev/null
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -F 'v2.2 COLOR CLASH FOUNDATION' >/dev/null
unzip -p "$OUTPUT_ZIP" components/MainScene.xml | grep -F 'TN GAME HOME' >/dev/null
unzip -p "$OUTPUT_ZIP" components/MainScene.xml | grep -F 'GUEST PLAYER' >/dev/null
unzip -p "$OUTPUT_ZIP" components/MainScene.xml | grep -F 'COLOR CLASH' >/dev/null
unzip -p "$OUTPUT_ZIP" components/MainScene.xml | grep -F 'WORD TILES' >/dev/null
unzip -p "$OUTPUT_ZIP" components/MainScene.xml | grep -F 'ROOM + PHONE CONTROLLER FOUNDATION' >/dev/null
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -F 'showColorClashFoundation' >/dev/null
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -F 'tn-game-connect-four-server.onrender.com' >/dev/null
if unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -F '192.168.1.127' >/dev/null; then
  echo "Local server address found in platform package" >&2
  exit 1
fi

echo "Created TN Game v2.2 Color Clash foundation: $OUTPUT_ZIP"
