#!/bin/bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT_DIR"

BASE_ZIP="$ROOT_DIR/dist/tn-game-connect-four-v1.3.1-sync-fix.zip"
OUTPUT_ZIP="$ROOT_DIR/dist/tn-game-connect-four-v1.4.0-session-controls.zip"
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
    'm.versionLabel.text = "v1.4 CONTROLS"',
)

# The Back button should leave the current room cleanly and return to mode select,
# rather than dropping all the way to the game catalog.
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

# Give finished games concise remote instructions while preserving draw handling.
text = text.replace(
    'm.subtitle.text = "No open moves • Press OK to play again"',
    'm.subtitle.text = "OK: play again • BACK: change mode"',
)
text = text.replace(
    'm.subtitle.text = "Press OK to play again"',
    'm.subtitle.text = "OK: play again • BACK: change mode"',
)

text += '''

sub leaveCurrentRoom()
  m.pollTimer.control = "stop"
  m.roomCode = ""
  m.busy = false
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
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q 'v1.4 CONTROLS'
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q 'leaveCurrentRoom'
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q 'BACK: change mode'
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q 'DRAW GAME'
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q 'TWO PLAYER MATCH'
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q 'applyWinningHighlight'
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q 'tn-game-connect-four-server.onrender.com'
unzip -p "$OUTPUT_ZIP" components/DisplayLoopTask.brs | grep -q '/tv?t='
! unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q 'applyTurnEmphasis'
! unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q '192\.168\.1\.127'

echo "Created $OUTPUT_ZIP"
