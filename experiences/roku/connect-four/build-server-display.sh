#!/bin/bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT_DIR"

SOURCE_FILE="$ROOT_DIR/components/MainScene.brs"
BACKUP_FILE="$ROOT_DIR/components/MainScene.brs.server-display-backup"
OLD_FILE="$ROOT_DIR/dist/tn-game-connect-four-v0.3.1-bot.zip"
NEW_FILE="$ROOT_DIR/dist/tn-game-connect-four-v0.4.0-server-display.zip"

cp "$SOURCE_FILE" "$BACKUP_FILE"
restore_source() {
  if [ -f "$BACKUP_FILE" ]; then mv "$BACKUP_FILE" "$SOURCE_FILE"; fi
}
trap restore_source EXIT

python3 <<'PY'
from pathlib import Path
import re

path = Path('components/MainScene.brs')
text = path.read_text()
text = text.replace('m.versionLabel.text = "v0.3.2 QR"', 'm.versionLabel.text = "v0.4.0 SERVER"')

replacement = '''sub applyTvState(data as object)
  if data = invalid then return

  screen = data.screen
  if screen = invalid then screen = "lobby"
  if data.code <> invalid then m.roomCode = data.code
  m.state = screen

  if screen = "lobby"
    m.joinGroup.visible = true
    m.boardGroup.visible = false
    m.title.text = valueOr(data.title, "JOIN ROOM " + m.roomCode)
    m.subtitle.text = valueOr(data.subtitle, "Scan with your phone to join")
    m.roomLabel.text = valueOr(data.roomLabel, "ROOM " + m.roomCode)
    m.playersLabel.text = valueOr(data.playersLabel, "Waiting for player")
    applyPlayers(data.players, data.currentPlayerId)
    if m.roomCode <> "" then
      m.joinCode.text = m.roomCode
      if data.joinUrl <> invalid
        m.qrPoster.uri = "https://quickchart.io/qr?size=300&margin=1&ecLevel=M&text=" + encodeUrl(data.joinUrl)
      end if
    end if
    return
  end if

  m.joinGroup.visible = false
  m.title.text = valueOr(data.title, "TN GAME CONNECT FOUR")
  m.subtitle.text = valueOr(data.subtitle, "")
  m.roomLabel.text = valueOr(data.roomLabel, "ROOM " + m.roomCode)
  m.playersLabel.text = valueOr(data.playersLabel, "")
  applyPlayers(data.players, data.currentPlayerId)

  if screen = "playing" or screen = "finished"
    m.boardGroup.visible = true
    renderBoard(data.board)
  else
    m.boardGroup.visible = false
    clearBoard()
  end if
end sub'''

pattern = re.compile(r'sub applyTvState\(data as object\).*?^end sub', re.S | re.M)
text, count = pattern.subn(replacement, text, count=1)
if count != 1:
    raise SystemExit('Could not replace applyTvState')
path.write_text(text)
PY

./build.sh
[ -f "$OLD_FILE" ] || { echo "Missing base build output"; exit 1; }
cp "$OLD_FILE" "$NEW_FILE"
echo "Created $NEW_FILE"
