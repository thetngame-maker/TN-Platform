#!/bin/bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT_DIR"

SOURCE_FILE="$ROOT_DIR/components/MainScene.brs"
BACKUP_FILE="$ROOT_DIR/components/MainScene.brs.thin-client-backup"
OLD_FILE="$ROOT_DIR/dist/tn-game-connect-four-v0.3.1-bot.zip"
NEW_FILE="$ROOT_DIR/dist/tn-game-connect-four-v1.0.0-thin-client.zip"

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
text = text.replace('m.baseUrl = "http://192.168.1.127:8070"', 'm.baseUrl = "https://tn-game-connect-four-server.onrender.com"')
text = text.replace('m.versionLabel.text = "v0.3.2 QR"', 'm.versionLabel.text = "v1.0 THIN"')

# Initialize one long-running Task. It creates the room and polls the server itself.
needle = '  m.pollTimer.observeField("fire", "onPoll")\n'
insert = '''  m.pollTimer.observeField("fire", "onPoll")
  m.displayLoop = m.top.createChild("DisplayLoopTask")
  m.displayLoop.observeField("stateJson", "onDisplayState")
'''
if needle not in text:
    raise SystemExit('Could not find init insertion point')
text = text.replace(needle, insert, 1)

create_room = '''sub createRoom()
  m.pollTimer.control = "stop"
  m.roomCode = ""
  m.state = "creating"
  m.productTitle.text = "CONNECT FOUR"
  m.lobbyGroup.visible = false
  m.modeGroup.visible = false
  m.joinGroup.visible = false
  showGameChrome(true)
  m.title.text = "CONNECTING TO SERVER..."
  m.subtitle.text = "Starting " + UCase(m.difficulty) + " bot game"
  m.roomLabel.text = ""
  m.playersLabel.text = ""
  m.boardGroup.visible = false

  if m.displayLoop.control = "RUN" then m.displayLoop.control = "STOP"
  m.displayLoop.baseUrl = m.baseUrl
  m.displayLoop.mode = m.gameMode
  m.displayLoop.difficulty = m.difficulty
  m.displayLoop.control = "RUN"
end sub'''
text, count = re.subn(r'sub createRoom\(\).*?^end sub', create_room, text, count=1, flags=re.S | re.M)
if count != 1:
    raise SystemExit('Could not replace createRoom')

apply_state = '''sub onDisplayState(event as object)
  raw = event.getData()
  if raw = invalid or raw = "" then return
  data = ParseJson(raw)
  if data = invalid then return
  applyTvState(data)
end sub

sub applyTvState(data as object)
  screen = valueOr(data.screen, "error")
  if data.code <> invalid then m.roomCode = data.code
  m.state = screen

  m.title.text = valueOr(data.title, "TN GAME CONNECT FOUR")
  m.subtitle.text = valueOr(data.subtitle, "")
  m.roomLabel.text = valueOr(data.roomLabel, "")
  m.playersLabel.text = valueOr(data.playersLabel, "")

  if screen = "lobby"
    m.joinGroup.visible = true
    m.boardGroup.visible = false
    m.joinCode.text = m.roomCode
    joinUrl = m.baseUrl + "/?room=" + m.roomCode
    m.qrPoster.uri = "https://quickchart.io/qr?size=300&margin=1&ecLevel=M&text=" + encodeUrl(joinUrl)
    applyPlayers(data.players, data.currentPlayerId)
    return
  end if

  m.joinGroup.visible = false
  if screen = "playing" or screen = "finished"
    m.boardGroup.visible = true
    applyPlayers(data.players, data.currentPlayerId)
    renderBoard(data.board)
  else
    m.boardGroup.visible = false
    clearBoard()
  end if
end sub'''
text, count = re.subn(r'sub applyTvState\(data as object\).*?^end sub', apply_state, text, count=1, flags=re.S | re.M)
if count != 1:
    raise SystemExit('Could not replace applyTvState')

path.write_text(text)
PY

./build.sh
[ -f "$OLD_FILE" ] || { echo "Missing base Roku build"; exit 1; }
cp "$OLD_FILE" "$NEW_FILE"
echo "Created $NEW_FILE"
