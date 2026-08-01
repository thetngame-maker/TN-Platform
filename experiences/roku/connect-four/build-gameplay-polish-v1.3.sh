#!/bin/bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT_DIR"

BASE_ZIP="$ROOT_DIR/dist/tn-game-connect-four-v1.2.0-two-player.zip"
OUTPUT_ZIP="$ROOT_DIR/dist/tn-game-connect-four-v1.3.0-gameplay-polish.zip"
WORK_DIR="$(mktemp -d)"
trap 'rm -rf "$WORK_DIR"' EXIT

# Preserve the proven Render, bot, two-player, sync, win-highlight, and restart foundation.
./build-two-player-v1.2.sh

if [ ! -f "$BASE_ZIP" ]; then
  echo "Expected v1.2 base build not found: $BASE_ZIP" >&2
  exit 1
fi

unzip -q "$BASE_ZIP" -d "$WORK_DIR/package"

python3 - <<'PY' "$WORK_DIR/package/components/MainScene.brs" "$WORK_DIR/package/components/MainScene.xml"
from pathlib import Path
import sys

brs_path = Path(sys.argv[1])
xml_path = Path(sys.argv[2])
text = brs_path.read_text()
xml = xml_path.read_text()

text = text.replace('m.versionLabel.text = "v1.2 2P"', 'm.versionLabel.text = "v1.3 POLISH"')

# Give the two player-card posters IDs so the active player can be emphasized safely.
xml = xml.replace(
    '<Poster translation="[-430,72]" width="300" height="380"',
    '<Poster id="playerOneCard" translation="[-430,72]" width="300" height="380"',
    1,
)
xml = xml.replace(
    '<Poster translation="[830,72]" width="300" height="380"',
    '<Poster id="playerTwoCard" translation="[830,72]" width="300" height="380"',
    1,
)

needle = '  m.playerOneName = m.top.findNode("playerOneName")\n'
if needle not in text:
    raise SystemExit('Could not find player-one init block')
text = text.replace(needle, '  m.playerOneCard = m.top.findNode("playerOneCard")\n' + needle, 1)

needle = '  m.playerTwoName = m.top.findNode("playerTwoName")\n'
if needle not in text:
    raise SystemExit('Could not find player-two init block')
text = text.replace(needle, '  m.playerTwoCard = m.top.findNode("playerTwoCard")\n' + needle, 1)

# Add active-player emphasis after player labels are updated.
needle = '''  else
    m.playerTwoName.text = "PLAYER 2"
    m.playerTwoColor.text = "CHOOSE COLOR"
    m.playerTwoTurn.text = ""
  end if
end sub'''
replacement = '''  else
    m.playerTwoName.text = "PLAYER 2"
    m.playerTwoColor.text = "CHOOSE COLOR"
    m.playerTwoTurn.text = ""
  end if
  applyTurnEmphasis(currentPlayerId, p1, p2)
end sub'''
if needle not in text:
    raise SystemExit('Could not find applyPlayers ending')
text = text.replace(needle, replacement, 1)

text += '''

sub applyTurnEmphasis(currentPlayerId as dynamic, p1 as dynamic, p2 as dynamic)
  if m.playerOneCard = invalid or m.playerTwoCard = invalid then return
  m.playerOneCard.opacity = 1.0
  m.playerTwoCard.opacity = 1.0
  if currentPlayerId = invalid then return
  if p1 <> invalid and p1.id = currentPlayerId
    m.playerOneCard.opacity = 1.0
    m.playerTwoCard.opacity = 0.62
  else if p2 <> invalid and p2.id = currentPlayerId
    m.playerOneCard.opacity = 0.62
    m.playerTwoCard.opacity = 1.0
  end if
end sub
'''

# Improve final-state messaging, including an explicit draw message.
needle = '  if screen = "finished" then m.subtitle.text = "Press OK to play again"'
replacement = '''  if screen = "finished"
    if Instr(1, UCase(m.title.text), "DRAW") > 0
      m.title.text = "DRAW GAME"
      m.subtitle.text = "No open moves • Press OK to play again"
    else
      m.subtitle.text = "Press OK to play again"
    end if
    if m.playerOneCard <> invalid then m.playerOneCard.opacity = 1.0
    if m.playerTwoCard <> invalid then m.playerTwoCard.opacity = 1.0
  end if'''
if needle not in text:
    raise SystemExit('Could not find finished-state subtitle')
text = text.replace(needle, replacement, 1)

brs_path.write_text(text)
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
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q 'v1.3 POLISH'
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q 'applyTurnEmphasis'
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q 'DRAW GAME'
unzip -p "$OUTPUT_ZIP" components/MainScene.xml | grep -q 'id="playerOneCard"'
unzip -p "$OUTPUT_ZIP" components/MainScene.xml | grep -q 'id="playerTwoCard"'
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q 'applyWinningHighlight'
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q 'tn-game-connect-four-server.onrender.com'
! unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q '192\.168\.1\.127'

echo "Created $OUTPUT_ZIP"
