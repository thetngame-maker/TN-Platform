#!/bin/bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT_DIR"

BASE_ZIP="$ROOT_DIR/dist/tn-game-connect-four-v1.0.1-display-sync.zip"
OUTPUT_ZIP="$ROOT_DIR/dist/tn-game-connect-four-v1.1.0-complete-loop.zip"
WORK_DIR="$(mktemp -d)"
trap 'rm -rf "$WORK_DIR"' EXIT

# Preserve the proven Render URL, QR flow, and persistent DisplayLoopTask sync.
./build-render-production.sh
cp "$ROOT_DIR/dist/tn-game-connect-four-render-production.zip" "$BASE_ZIP"

unzip -q "$BASE_ZIP" -d "$WORK_DIR/package"

python3 - <<'PY' "$WORK_DIR/package/components/MainScene.brs"
from pathlib import Path
import sys

path = Path(sys.argv[1])
text = path.read_text()

text = text.replace('m.versionLabel.text = "v1.0.1 SYNC"', 'm.versionLabel.text = "v1.1 LOOP"')
text = text.replace('m.versionLabel.text = "v1.0 THIN"', 'm.versionLabel.text = "v1.1 LOOP"')

old = '''  m.title.text = valueOr(data.title, "TN GAME CONNECT FOUR")
  m.subtitle.text = valueOr(data.subtitle, "")
  m.roomLabel.text = valueOr(data.roomLabel, "")'''
new = '''  m.title.text = valueOr(data.title, "TN GAME CONNECT FOUR")
  m.subtitle.text = valueOr(data.subtitle, "")
  if screen = "finished" then m.subtitle.text = "Press OK to play again"
  m.roomLabel.text = valueOr(data.roomLabel, "")'''
if old in text:
    text = text.replace(old, new, 1)

old = '''    m.boardGroup.visible = true
    applyPlayers(data.players, data.currentPlayerId)
    renderBoard(data.board)'''
new = '''    m.boardGroup.visible = true
    applyPlayers(data.players, data.currentPlayerId)
    if screen = "finished"
      m.playerOneTurn.text = ""
      m.playerTwoTurn.text = ""
    end if
    renderBoard(data.board)
    applyWinningHighlight(data.winningCells)'''
if old not in text:
    raise SystemExit('Could not find playing/finished render block')
text = text.replace(old, new, 1)

text += '''

sub applyWinningHighlight(winningCells as dynamic)
  hasWinner = false
  if winningCells <> invalid then hasWinner = winningCells.Count() > 0
  for r = 0 to 5
    for c = 0 to 6
      highlighted = false
      if hasWinner
        for each cell in winningCells
          if cell.row = r and cell.col = c then highlighted = true
        end for
      end if
      if hasWinner and not highlighted
        m.cells[r][c].opacity = 0.32
      else
        m.cells[r][c].opacity = 1.0
      end if
    end for
  end for
end sub
'''

path.write_text(text)
PY

# Package the CONTENTS so manifest remains at ZIP root.
rm -f "$OUTPUT_ZIP"
(
  cd "$WORK_DIR/package"
  zip -qr "$OUTPUT_ZIP" . \
    -x '*.DS_Store' '__MACOSX/*' '*.backup*' '*.v11-backup*'
)

unzip -Z1 "$OUTPUT_ZIP" | grep -qx 'manifest'
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q 'v1.1 LOOP'
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q 'applyWinningHighlight'
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q 'tn-game-connect-four-server.onrender.com'
unzip -Z1 "$OUTPUT_ZIP" | grep -qx 'components/DisplayLoopTask.brs'
! unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q '192\.168\.1\.127'

echo "Created $OUTPUT_ZIP"
