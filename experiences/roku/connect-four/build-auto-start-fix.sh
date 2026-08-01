#!/bin/bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT_DIR"

SOURCE_FILE="$ROOT_DIR/components/MainScene.brs"
BACKUP_FILE="$ROOT_DIR/components/MainScene.brs.auto-start-backup"
OLD_FILE="$ROOT_DIR/dist/tn-game-connect-four-v0.3.1-bot.zip"
NEW_FILE="$ROOT_DIR/dist/tn-game-connect-four-v0.3.3-auto-start.zip"

cp "$SOURCE_FILE" "$BACKUP_FILE"
restore_source() {
  if [ -f "$BACKUP_FILE" ]; then
    mv "$BACKUP_FILE" "$SOURCE_FILE"
  fi
}
trap restore_source EXIT

python3 <<'PY'
from pathlib import Path

path = Path('components/MainScene.brs')
text = path.read_text()

text = text.replace('m.versionLabel.text = "v0.3.2 QR"', 'm.versionLabel.text = "v0.3.3 START"')

old = '''sub applyTvState(data as object)
  if data.screen = invalid then return
  m.state = data.screen
  applyPlayers(data.players, data.currentPlayerId)
  if data.screen = "lobby"
    if data.code <> invalid then m.roomCode = data.code
    showJoinPanel()
  else
    m.joinGroup.visible = false
    m.title.text = valueOr(data.title, "TN GAME CONNECT FOUR")
    m.subtitle.text = valueOr(data.subtitle, "")
    m.roomLabel.text = "ROOM " + m.roomCode
    m.playersLabel.text = valueOr(data.playersLabel, "")
    if data.screen = "playing" or data.screen = "finished"
      m.boardGroup.visible = true
      renderBoard(data.board)
    else
      m.boardGroup.visible = false
      clearBoard()
    end if
  end if
end sub'''

new = '''sub applyTvState(data as object)
  screen = data.screen
  if screen = invalid and data.status <> invalid
    if data.status = "playing" or data.status = "finished"
      screen = data.status
    else
      screen = "lobby"
    end if
  end if
  if screen = invalid then return

  if data.code <> invalid then m.roomCode = data.code
  m.state = screen

  if screen = "lobby"
    applyPlayers(data.players, data.currentPlayerId)
    showJoinPanel()
    return
  end if

  ' Hide the QR panel before processing player cards so the board transition
  ' still happens even when a player field is temporarily incomplete.
  m.joinGroup.visible = false
  m.boardGroup.visible = true
  m.title.text = valueOr(data.title, "TN GAME CONNECT FOUR")
  m.subtitle.text = valueOr(data.subtitle, "")
  m.roomLabel.text = "ROOM " + m.roomCode
  m.playersLabel.text = valueOr(data.playersLabel, "")

  board = data.board
  currentPlayerId = data.currentPlayerId
  if data.game <> invalid
    if board = invalid then board = data.game.board
    if currentPlayerId = invalid then currentPlayerId = data.game.turnPlayerId
  end if

  applyPlayers(data.players, currentPlayerId)
  if screen = "playing" or screen = "finished"
    renderBoard(board)
  else
    m.boardGroup.visible = false
    clearBoard()
  end if
end sub'''

if old not in text:
    raise SystemExit('Expected applyTvState block was not found; source may have changed.')

path.write_text(text.replace(old, new))
PY

./build.sh

if [ ! -f "$OLD_FILE" ]; then
  echo "Error: Expected build output was not found: $OLD_FILE"
  exit 1
fi

cp "$OLD_FILE" "$NEW_FILE"

echo
echo "Auto-start fix package created:"
echo "$NEW_FILE"
