#!/bin/bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT_DIR"

BASE_ZIP="$ROOT_DIR/dist/tn-game-connect-four-v1.1.0-complete-loop.zip"
OUTPUT_ZIP="$ROOT_DIR/dist/tn-game-connect-four-v1.2.0-two-player.zip"
WORK_DIR="$(mktemp -d)"
trap 'rm -rf "$WORK_DIR"' EXIT

# Build directly on the proven Render + persistent sync + restart foundation.
./build-complete-loop-v1.1.sh

if [ ! -f "$BASE_ZIP" ]; then
  echo "Expected v1.1 base build not found: $BASE_ZIP" >&2
  exit 1
fi

unzip -q "$BASE_ZIP" -d "$WORK_DIR/package"

python3 - <<'PY' "$WORK_DIR/package/components/MainScene.brs"
from pathlib import Path
import sys

path = Path(sys.argv[1])
text = path.read_text()
text = text.replace('m.versionLabel.text = "v1.1 LOOP"', 'm.versionLabel.text = "v1.2 2P"')

needle = '''  m.title.text = valueOr(data.title, "TN GAME CONNECT FOUR")
  m.subtitle.text = valueOr(data.subtitle, "")
  if screen = "finished" then m.subtitle.text = "Press OK to play again"
  m.roomLabel.text = valueOr(data.roomLabel, "")
  m.playersLabel.text = valueOr(data.playersLabel, "")'''
replacement = '''  m.title.text = valueOr(data.title, "TN GAME CONNECT FOUR")
  m.subtitle.text = valueOr(data.subtitle, "")
  m.roomLabel.text = valueOr(data.roomLabel, "")
  m.playersLabel.text = valueOr(data.playersLabel, "")

  mode = valueOr(data.mode, "")
  if screen = "lobby" and mode = "human"
    count = 0
    if data.players <> invalid then count = data.players.Count()
    m.title.text = "TWO PLAYER MATCH"
    if count = 0
      m.subtitle.text = "Scan with the first phone to join"
      m.playersLabel.text = "0 of 2 players connected"
    else if count = 1
      m.subtitle.text = "Player 1 joined • Scan with a second phone"
      m.playersLabel.text = "1 of 2 players connected"
    else
      m.subtitle.text = "Both players connected • Starting match"
      m.playersLabel.text = "2 of 2 players connected"
    end if
  end if

  if screen = "finished" then m.subtitle.text = "Press OK to play again"'''
if needle not in text:
    raise SystemExit('Could not find display header block for two-player polish')
text = text.replace(needle, replacement, 1)

needle = '''  if screen = "lobby"
    showJoinPanelFromState(data)'''
replacement = '''  if screen = "lobby"
    m.playerOneTurn.text = ""
    m.playerTwoTurn.text = ""
    showJoinPanelFromState(data)'''
if needle in text:
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
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q 'v1.2 2P'
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q 'TWO PLAYER MATCH'
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q 'applyWinningHighlight'
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q 'tn-game-connect-four-server.onrender.com'
unzip -p "$OUTPUT_ZIP" components/DisplayLoopTask.brs | grep -q '/tv?t='
! unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q '192\.168\.1\.127'

echo "Created $OUTPUT_ZIP"
