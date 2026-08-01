#!/bin/bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT_DIR"

BASE_ZIP="$ROOT_DIR/dist/tn-game-connect-four-v1.2.0-two-player.zip"
OUTPUT_ZIP="$ROOT_DIR/dist/tn-game-connect-four-v1.3.1-sync-fix.zip"
WORK_DIR="$(mktemp -d)"
trap 'rm -rf "$WORK_DIR"' EXIT

# Rebuild directly from the proven v1.2 package. The earlier v1.3 card-opacity
# changes are intentionally removed because they interrupted lobby-to-game updates.
./build-two-player-v1.2.sh

if [ ! -f "$BASE_ZIP" ]; then
  echo "Expected v1.2 base build not found: $BASE_ZIP" >&2
  exit 1
fi

unzip -q "$BASE_ZIP" -d "$WORK_DIR/package"

python3 - <<'PY' "$WORK_DIR/package/components/MainScene.brs"
from pathlib import Path
import sys

path = Path(sys.argv[1])
text = path.read_text()

# Keep every tested v1.2 behavior and only apply display-safe changes.
text = text.replace('m.versionLabel.text = "v1.2 2P"', 'm.versionLabel.text = "v1.3.1 SYNC FIX"')

# Do not render the same connection count in both footer labels while waiting.
needle = '''  if screen = "lobby" and mode = "human"
    count = 0'''
replacement = '''  if screen = "lobby" and mode = "human"
    m.roomLabel.text = "ROOM " + m.roomCode
    count = 0'''
if needle not in text:
    raise SystemExit('Could not find two-player lobby block')
text = text.replace(needle, replacement, 1)

# Preserve explicit draw handling without modifying player-card nodes or applyPlayers.
needle = '  if screen = "finished" then m.subtitle.text = "Press OK to play again"'
replacement = '''  if screen = "finished"
    if Instr(1, UCase(m.title.text), "DRAW") > 0
      m.title.text = "DRAW GAME"
      m.subtitle.text = "No open moves • Press OK to play again"
    else
      m.subtitle.text = "Press OK to play again"
    end if
  end if'''
if needle not in text:
    raise SystemExit('Could not find finished-state subtitle')
text = text.replace(needle, replacement, 1)

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
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q 'v1.3.1 SYNC FIX'
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q 'DRAW GAME'
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q 'TWO PLAYER MATCH'
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q 'applyWinningHighlight'
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q 'tn-game-connect-four-server.onrender.com'
unzip -p "$OUTPUT_ZIP" components/DisplayLoopTask.brs | grep -q '/tv?t='
! unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q 'applyTurnEmphasis'
! unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q '192\.168\.1\.127'

echo "Created $OUTPUT_ZIP"
