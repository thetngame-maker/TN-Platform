#!/bin/bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT_DIR"

BASE_ZIP="$ROOT_DIR/dist/tn-game-connect-four-v1.4.1-menu-fix.zip"
OUTPUT_ZIP="$ROOT_DIR/dist/tn-game-connect-four-v1.5.0-tv-polish.zip"
WORK_DIR="$(mktemp -d)"
trap 'rm -rf "$WORK_DIR"' EXIT

./build-session-controls-v1.4.sh

if [ ! -f "$BASE_ZIP" ]; then
  echo "Expected v1.4.1 base build not found: $BASE_ZIP" >&2
  exit 1
fi

unzip -q "$BASE_ZIP" -d "$WORK_DIR/package"

python3 - <<'PY' "$WORK_DIR/package/components/MainScene.brs"
from pathlib import Path
import sys

path = Path(sys.argv[1])
text = path.read_text()

text = text.replace(
    'm.versionLabel.text = "v1.4.1 MENU FIX"',
    'm.versionLabel.text = "v1.5 TV POLISH"',
)

text = text.replace('m.playerOneTurn.text = "YOUR TURN"', 'm.playerOneTurn.text = "PLAY NOW"')
text = text.replace('m.playerTwoTurn.text = "YOUR TURN"', 'm.playerTwoTurn.text = "PLAY NOW"')
text = text.replace('m.playerTwoTurn.text = "BOT THINKING"', 'm.playerTwoTurn.text = "THINKING..."')
text = text.replace(
    'm.subtitle.text = "OK: play again • BACK: change mode"',
    'm.subtitle.text = "OK TO PLAY AGAIN   •   BACK TO CHANGE MODE"',
)

text += '''

sub applyTvPolish(screen as string)
  m.boardGroup.opacity = 1.0
  if screen = "finished"
    m.title.color = "0xFFD54FFF"
    m.subtitle.color = "0xFFFFFFFF"
  else
    m.title.color = "0xFFFFFFFF"
    m.subtitle.color = "0xA7BDB5FF"
  end if
end sub
'''

marker = '''    applyWinningHighlight(data.winningCells)
  else'''
if marker not in text:
    raise SystemExit('Could not find finished board-render marker')
text = text.replace(
    marker,
    '''    applyWinningHighlight(data.winningCells)
    applyTvPolish(screen)
  else''',
    1,
)

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
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q 'v1.5 TV POLISH'
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q 'applyTvPolish'
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q 'PLAY NOW'
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q 'OK TO PLAY AGAIN'
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q 'applyWinningHighlight'
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q 'tn-game-connect-four-server.onrender.com'
unzip -p "$OUTPUT_ZIP" components/DisplayLoopTask.brs | grep -q '/tv?t='
! unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q '192\.168\.1\.127'

echo "Created $OUTPUT_ZIP"
