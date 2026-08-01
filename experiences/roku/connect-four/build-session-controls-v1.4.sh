#!/bin/bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT_DIR"

BASE_ZIP="$ROOT_DIR/dist/tn-game-connect-four-v1.3.1-sync-fix.zip"
OUTPUT_ZIP="$ROOT_DIR/dist/tn-game-connect-four-v1.4.1-menu-fix.zip"
WORK_DIR="$(mktemp -d)"
trap 'rm -rf "$WORK_DIR"' EXIT

# Build directly from the confirmed working v1.3.1 sync-fix package.
./build-gameplay-polish-v1.3.sh

if [ ! -f "$BASE_ZIP" ]; then
  echo "Expected v1.3.1 base build not found: $BASE_ZIP" >&2
  exit 1
fi

unzip -q "$BASE_ZIP" -d "$WORK_DIR/package"

python3 - <<'PY' "$WORK_DIR/package/components/MainScene.brs"
from pathlib import Path
import sys

path = Path(sys.argv[1])
text = path.read_text()

text = text.replace(
    'm.versionLabel.text = "v1.3.1 SYNC FIX"',
    'm.versionLabel.text = "v1.4.1 MENU FIX"',
)

# Track whether incoming display updates should be ignored after leaving a room.
needle = '  m.busy = false\n'
if needle not in text:
    raise SystemExit('Could not find init busy flag')
text = text.replace(needle, '  m.busy = false\n  m.ignoreTvState = false\n', 1)

# The Back button should leave the current room cleanly and return to mode select.
needle = '''  else if key = "back"
    showLobby()
    return true
  end if'''
replacement = '''  else if key = "back"
    leaveCurrentRoom()
    return true
  end if'''
if needle not in text:
    raise SystemExit('Could not find in-game Back handler')
text = text.replace(needle, replacement, 1)

# Re-enable server display updates only when a new room is intentionally created.
needle = '''sub createRoom()
  m.pollTimer.control = "stop"'''
replacement = '''sub createRoom()
  m.ignoreTvState = false
  m.pollTimer.control = "stop"'''
if needle not in text:
    raise SystemExit('Could not find createRoom start')
text = text.replace(needle, replacement, 1)

# Ignore late responses from the old room after returning to mode selection.
needle = '''sub applyTvState(data as object)
  if data.screen = invalid then return'''
replacement = '''sub applyTvState(data as object)
  if m.ignoreTvState = true then return
  if data.screen = invalid then return'''
if needle not in text:
    raise SystemExit('Could not find applyTvState start')
text = text.replace(needle, replacement, 1)

# Give finished games concise remote instructions while preserving draw handling.
text = text.replace(
    'm.subtitle.text = "No open moves • Press OK to play again"',
    'm.subtitle.text = "OK: play again • BACK: change mode"',
)
text = text.replace(
    'm.subtitle.text = "Press OK to play again"',
    'm.subtitle.text = "OK: play again • BACK: change mode"',
)

# Explicitly hide all game-layer nodes before revealing mode selection. This prevents
# both the board and player cards from remaining over the menu while a late poll returns.
text += '''

sub leaveCurrentRoom()
  m.ignoreTvState = true
  m.pollTimer.control = "stop"
  m.roomCode = ""
  m.busy = false
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
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q 'm.boardGroup.visible = false'
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q 'BACK: change mode'
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q 'DRAW GAME'
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q 'TWO PLAYER MATCH'
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q 'applyWinningHighlight'
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q 'tn-game-connect-four-server.onrender.com'
unzip -p "$OUTPUT_ZIP" components/DisplayLoopTask.brs | grep -q '/tv?t='
! unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q 'applyTurnEmphasis'
! unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q '192\.168\.1\.127'

echo "Created $OUTPUT_ZIP"
