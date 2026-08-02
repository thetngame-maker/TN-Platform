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
      <Label id="colorClashStatus" text="ROOM PAIRING READY" translation="[560,654]" width="800" height="52" horizAlign="center" font="font:LargeBoldSystemFont" color="0xFFFFFFFF" />
      <Label id="colorClashDetail" text="Create a room, scan the QR code, and connect up to two phones." translation="[490,714]" width="940" height="42" horizAlign="center" font="font:MediumBoldSystemFont" color="0x7FD5B3FF" />
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
    'm.versionLabel.text = "v2.2 COLOR CLASH FOUNDATION"',
]
version_marker = next((marker for marker in version_markers if marker in brs), None)
if version_marker is None:
    raise SystemExit('Platform BrightScript version marker not found')
brs = brs.replace(version_marker, 'm.versionLabel.text = "v2.3 COLOR CLASH PAIRING"', 1)
brs = brs.replace('m.productTitle.text = "TN GAME CONNECT"', 'm.productTitle.text = "TN GAME"')

brs = brs.replace(
    '  m.gameMode = "human"\n  m.difficulty = "easy"',
    '  m.gameMode = "human"\n  m.difficulty = "easy"\n  m.activeGame = "connect-four"',
    1,
)
brs = brs.replace(
    '  m.triviaPrompt = m.top.findNode("triviaPrompt")\n',
    '  m.triviaPrompt = m.top.findNode("triviaPrompt")\n  m.colorClashGroup = m.top.findNode("colorClashGroup")\n  m.colorClashStatus = m.top.findNode("colorClashStatus")\n  m.colorClashDetail = m.top.findNode("colorClashDetail")\n',
    1,
)
brs = brs.replace(
    '      if m.lobbySelection = 0 then showModeSelect() else m.lobbyMessage.text = "COMING SOON  •  Choose Connect Four to play now"',
    '      if m.lobbySelection = 0\n        m.activeGame = "connect-four"\n        showModeSelect()\n      else if m.lobbySelection = 1\n        startColorClashPairing()\n      else\n        m.lobbyMessage.text = "WORD TILES  •  Foundation coming after Color Clash"\n      end if',
    1,
)
brs = brs.replace(
    '  m.modeGroup.visible = false\n  m.joinGroup.visible = false',
    '  m.modeGroup.visible = false\n  m.colorClashGroup.visible = false\n  m.joinGroup.visible = false',
    1,
)
brs = brs.replace(
    '  m.playerColors = ["orange", "gold"]\n  m.productTitle.text = "TN GAME CONNECT"',
    '  m.playerColors = ["orange", "gold"]\n  m.activeGame = "connect-four"\n  m.productTitle.text = "TN GAME"',
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
color_clash_functions = '''sub startColorClashPairing()
  m.activeGame = "color-clash"
  m.gameMode = "human"
  m.difficulty = "easy"
  createRoom()
end sub

sub showColorClashPairing()
  m.productTitle.text = "COLOR CLASH"
  m.lobbyGroup.visible = false
  m.modeGroup.visible = false
  m.boardGroup.visible = false
  m.colorClashGroup.visible = true
  showGameChrome(true)
end sub

sub applyColorClashPairingState(data as object)
  applyPlayers(data.players, data.currentPlayerId)
  playerCount = 0
  if data.players <> invalid then playerCount = data.players.Count()
  showColorClashPairing()
  m.roomLabel.text = "COLOR CLASH ROOM  " + m.roomCode
  m.playersLabel.text = playerCount.toStr() + " of 2 players connected"
  if playerCount = 0
    m.colorClashStatus.text = "WAITING FOR PLAYERS"
    m.colorClashDetail.text = "Scan the QR code with the first phone to join."
    showJoinPanel()
  else if playerCount = 1
    m.colorClashStatus.text = "PLAYER 1 CONNECTED"
    m.colorClashDetail.text = "Scan again with the second phone to complete pairing."
    showJoinPanel()
  else
    m.joinGroup.visible = false
    m.colorClashGroup.visible = true
    m.title.text = "COLOR CLASH PLAYERS READY"
    m.subtitle.text = "Two phones paired successfully"
    m.colorClashStatus.text = "PAIRING COMPLETE"
    m.colorClashDetail.text = "Next milestone: deal cards and play the first Color Clash round."
  end if
end sub

'''
brs = brs.replace(insert_before, color_clash_functions + insert_before, 1)

brs = brs.replace('m.colorClashPrompt.text = "COMING SOON"', 'm.colorClashPrompt.text = "ROOM PAIRING READY"')
brs = brs.replace('m.lobbyMessage.text = "Color Clash is the next card game planned"', 'm.lobbyMessage.text = "COLOR CLASH  •  Press OK to create a room and pair phones"')
brs = brs.replace('m.lobbyMessage.text = "TN Trivia will support teams and local questions"', 'm.lobbyMessage.text = "WORD TILES  •  Build words and compete for points"')

brs = brs.replace(
    '  joinUrl = m.baseUrl + "/?room=" + m.roomCode',
    '  joinUrl = m.baseUrl + "/?room=" + m.roomCode\n  if m.activeGame = "color-clash" then joinUrl += "&game=color-clash"',
    1,
)
brs = brs.replace(
    '  m.title.text = "JOIN ROOM " + m.roomCode\n  if m.gameMode = "bot"',
    '  if m.activeGame = "color-clash"\n    m.title.text = "JOIN COLOR CLASH ROOM " + m.roomCode\n    m.subtitle.text = "Scan with two phones to pair players"\n  else\n    m.title.text = "JOIN ROOM " + m.roomCode\n  end if\n  if m.activeGame <> "color-clash" and m.gameMode = "bot"',
    1,
)
brs = brs.replace(
    '  else\n    m.subtitle.text = "Scan with each phone to join"\n  end if\n  m.roomLabel.text',
    '  else if m.activeGame <> "color-clash"\n    m.subtitle.text = "Scan with each phone to join"\n  end if\n  m.roomLabel.text',
    1,
)

brs = brs.replace(
    '  m.productTitle.text = "CONNECT FOUR"',
    '  if m.activeGame = "color-clash" then m.productTitle.text = "COLOR CLASH" else m.productTitle.text = "CONNECT FOUR"',
    1,
)
brs = brs.replace(
    '  if m.gameMode = "bot" then m.subtitle.text = "Preparing " + UCase(m.difficulty) + " bot mode" else m.subtitle.text = "Preparing two-player mode"',
    '  if m.activeGame = "color-clash"\n    m.subtitle.text = "Preparing Color Clash room pairing"\n  else if m.gameMode = "bot"\n    m.subtitle.text = "Preparing " + UCase(m.difficulty) + " bot mode"\n  else\n    m.subtitle.text = "Preparing two-player mode"\n  end if',
    1,
)
brs = brs.replace(
    '  url = m.baseUrl + "/api/rooms/create?mode=" + m.gameMode',
    '  url = m.baseUrl + "/api/rooms/create?mode=" + m.gameMode\n  if m.activeGame = "color-clash" then url += "&game=color-clash"',
    1,
)
brs = brs.replace(
    'sub applyTvState(data as object)\n  if data.screen = invalid then return',
    'sub applyTvState(data as object)\n  if data.screen = invalid then return\n  if m.activeGame = "color-clash"\n    applyColorClashPairingState(data)\n    return\n  end if',
    1,
)

brs_path.write_text(brs)
PY

rm -f "$OUTPUT_ZIP"
(
  cd "$WORK_DIR"
  zip -qr "$OUTPUT_ZIP" .
)

unzip -Z1 "$OUTPUT_ZIP" | grep -Fx 'manifest' >/dev/null
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -F 'v2.3 COLOR CLASH PAIRING' >/dev/null
unzip -p "$OUTPUT_ZIP" components/MainScene.xml | grep -F 'TN GAME HOME' >/dev/null
unzip -p "$OUTPUT_ZIP" components/MainScene.xml | grep -F 'GUEST PLAYER' >/dev/null
unzip -p "$OUTPUT_ZIP" components/MainScene.xml | grep -F 'COLOR CLASH' >/dev/null
unzip -p "$OUTPUT_ZIP" components/MainScene.xml | grep -F 'WORD TILES' >/dev/null
unzip -p "$OUTPUT_ZIP" components/MainScene.xml | grep -F 'ROOM PAIRING READY' >/dev/null
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -F 'startColorClashPairing' >/dev/null
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -F 'applyColorClashPairingState' >/dev/null
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -F '&game=color-clash' >/dev/null
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -F 'tn-game-connect-four-server.onrender.com' >/dev/null
if unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -F '192.168.1.127' >/dev/null; then
  echo "Local server address found in platform package" >&2
  exit 1
fi

echo "Created TN Game v2.3 Color Clash room pairing: $OUTPUT_ZIP"
