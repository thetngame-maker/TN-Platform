#!/bin/bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT_DIR"

BASE_ZIP="$ROOT_DIR/dist/tn-game-connect-four-v1.3.1-sync-fix.zip"
OUTPUT_ZIP="$ROOT_DIR/dist/tn-game-connect-four-v1.4.1-menu-fix.zip"
WORK_DIR="$(mktemp -d)"
trap 'rm -rf "$WORK_DIR"' EXIT

./build-gameplay-polish-v1.3.sh

if [ ! -f "$BASE_ZIP" ]; then
  echo "Expected v1.3.1 base build not found: $BASE_ZIP" >&2
  exit 1
fi

unzip -q "$BASE_ZIP" -d "$WORK_DIR/package"

python3 - <<'PY' "$WORK_DIR/package/components/MainScene.brs"
from pathlib import Path
import re
import sys

path = Path(sys.argv[1])
text = path.read_text()

text = text.replace('m.versionLabel.text = "v1.3.1 SYNC FIX"', 'm.versionLabel.text = "v1.4.1 MENU FIX"')

# Add one state guard without depending on the exact generated applyTvState layout.
needle = '  m.busy = false\n'
if needle not in text:
    raise SystemExit('Could not find init busy flag')
text = text.replace(needle, '  m.busy = false\n  m.ignoreTvState = false\n', 1)

# Back leaves the room and returns to mode selection.
old = '''  else if key = "back"
    showLobby()
    return true
  end if'''
new = '''  else if key = "back"
    leaveCurrentRoom()
    return true
  end if'''
if old not in text:
    raise SystemExit('Could not find in-game Back handler')
text = text.replace(old, new, 1)

# Re-enable display updates whenever a new room is created.
text, count = re.subn(
    r'(sub createRoom\(\)\s*\n)',
    r'\1  m.ignoreTvState = false\n',
    text,
    count=1,
)
if count != 1:
    raise SystemExit('Could not patch createRoom')

# Ignore any final stateJson event delivered after the user leaves the room.
text, count = re.subn(
    r'(sub onDisplayState\(event as object\)\s*\n)',
    r'\1  if m.ignoreTvState = true then return\n',
    text,
    count=1,
)
if count != 1:
    raise SystemExit('Could not patch onDisplayState')

text = text.replace('m.subtitle.text = "No open moves • Press OK to play again"', 'm.subtitle.text = "OK: play again • BACK: change mode"')
text = text.replace('m.subtitle.text = "Press OK to play again"', 'm.subtitle.text = "OK: play again • BACK: change mode"')

text += '''

sub leaveCurrentRoom()
  m.ignoreTvState = true
  m.pollTimer.control = "stop"
  if m.displayLoop <> invalid then m.displayLoop.control = "STOP"
  m.roomCode = ""
  m.busy = false
  m.state = "mode"
  m.joinGroup.visible = false
  m.boardGroup.visible = false
  m.statusCard.visible = false
  m.statusAccent.visible = false
  m.title.visible = false
  m.subtitle.visible = false
  m.footerCard.visible = false
  m.roomLabel.visible = false
  m.playersLabel.visible = false
  clearBoard()
  resetPlayerCards()
  showModeSelect()
end sub
'''

path.write_text(text)
PY

rm -f "$OUTPUT_ZIP"
(
  cd "$WORK_DIR/package"
  zip -qr "$OUTPUT_ZIP" . \
    -x '*.DS_Store' '__MACOSX/*' '*.backup*' '*.v11-backup*' '*.thin-client-backup*'
)

unzip -Z1 "$OUTPUT_ZIP" | grep -qx 'manifest'
unzip -Z1 "$OUTPUT_ZIP" | grep -qx 'components/DisplayLoopTask.brs'
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q 'v1.4.1 MENU FIX'
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q 'm.ignoreTvState = true'
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q 'if m.ignoreTvState = true then return'
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q 'm.displayLoop.control = "STOP"'
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q 'BACK: change mode'
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q 'DRAW GAME'
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q 'TWO PLAYER MATCH'
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q 'applyWinningHighlight'
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q 'tn-game-connect-four-server.onrender.com'
unzip -p "$OUTPUT_ZIP" components/DisplayLoopTask.brs | grep -q '/tv?t='
! unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q 'applyTurnEmphasis'
! unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q '192\.168\.1\.127'

echo "Created $OUTPUT_ZIP"
