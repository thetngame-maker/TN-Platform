#!/bin/bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT_DIR"

SOURCE_FILE="$ROOT_DIR/components/MainScene.brs"
LOOP_FILE="$ROOT_DIR/components/DisplayLoopTask.brs"
LOOP_XML="$ROOT_DIR/components/DisplayLoopTask.xml"
SOURCE_BACKUP="$SOURCE_FILE.v11-backup"
LOOP_BACKUP="$LOOP_FILE.v11-backup"
XML_BACKUP="$LOOP_XML.v11-backup"
OLD_FILE="$ROOT_DIR/dist/tn-game-connect-four-v0.3.1-bot.zip"
NEW_FILE="$ROOT_DIR/dist/tn-game-connect-four-v1.1.0-gameplay-polish.zip"

cp "$SOURCE_FILE" "$SOURCE_BACKUP"
cp "$LOOP_FILE" "$LOOP_BACKUP"
cp "$LOOP_XML" "$XML_BACKUP"
restore_source() {
  [ -f "$SOURCE_BACKUP" ] && mv "$SOURCE_BACKUP" "$SOURCE_FILE"
  [ -f "$LOOP_BACKUP" ] && mv "$LOOP_BACKUP" "$LOOP_FILE"
  [ -f "$XML_BACKUP" ] && mv "$XML_BACKUP" "$LOOP_XML"
}
trap restore_source EXIT

./build-thin-client-v1.sh >/dev/null

python3 <<'PY'
from pathlib import Path

main = Path('components/MainScene.brs')
text = main.read_text()
text = text.replace('m.versionLabel.text = "v1.0 THIN"', 'm.versionLabel.text = "v1.1 PLAY"')
text = text.replace(
'''  m.displayLoop = m.top.createChild("DisplayLoopTask")
  m.displayLoop.observeField("stateJson", "onDisplayState")''',
'''  m.displayLoop = m.top.createChild("DisplayLoopTask")
  m.displayLoop.observeField("stateJson", "onDisplayState")
  m.displayLoop.observeField("connectionText", "onConnectionText")
  m.restartVersion = 0''')
text = text.replace(
'''    else if m.state = "finished" and m.roomCode <> ""
      restartGame()
    end if''',
'''    else if m.state = "finished" and m.roomCode <> ""
      m.title.text = "STARTING NEW GAME..."
      m.subtitle.text = "Resetting the board"
      m.restartVersion += 1
      m.displayLoop.restartVersion = m.restartVersion
    end if''')
text = text.replace(
'''  else if key = "back"
    showLobby()
    return true''',
'''  else if key = "back"
    if m.displayLoop <> invalid and m.displayLoop.control = "RUN" then m.displayLoop.control = "STOP"
    showLobby()
    return true''')
text = text.replace(
'''sub onDisplayState(event as object)
  raw = event.getData()''',
'''sub onConnectionText(event as object)
  message = event.getData()
  if message <> invalid and message <> "" and m.state <> "finished" then m.subtitle.text = message
end sub

sub onDisplayState(event as object)
  raw = event.getData()''')
text = text.replace(
'''    renderBoard(data.board)
  else''',
'''    renderBoard(data.board)
    applyWinningHighlight(data.winningCells)
  else''')
text += '''

sub applyWinningHighlight(winningCells as dynamic)
  hasWinner = winningCells <> invalid and winningCells.Count() > 0
  for r = 0 to 5
    for c = 0 to 6
      highlighted = false
      if hasWinner
        for each cell in winningCells
          if cell.row = r and cell.col = c then highlighted = true
        end for
      end if
      if hasWinner and not highlighted
        m.cells[r][c].opacity = 0.28
      else
        m.cells[r][c].opacity = 1.0
      end if
    end for
  end for
end sub
'''
main.write_text(text)

loop = Path('components/DisplayLoopTask.brs')
loop.write_text('''sub init()
  m.top.functionName = "run"
end sub

sub run()
  baseUrl = m.top.baseUrl
  mode = m.top.mode
  difficulty = m.top.difficulty
  if baseUrl = invalid or baseUrl = "" then
    publishFatal("SERVER URL MISSING")
    return
  end if

  createUrl = baseUrl + "/api/rooms/create?mode=" + mode
  if mode = "bot" then createUrl += "&difficulty=" + difficulty
  created = getJson(createUrl)
  if created = invalid or created.code = invalid then
    publishFatal("COULD NOT CREATE ROOM")
    return
  end if

  roomCode = created.code
  lastRestartVersion = m.top.restartVersion
  failures = 0
  m.top.stateJson = FormatJson({
    code: roomCode, screen: "lobby",
    title: mode = "bot" ? "JOIN TO PLAY " + UCase(difficulty) + " BOT" : "JOIN ROOM " + roomCode,
    subtitle: "Open the room on your phone",
    roomLabel: "0 players connected", playersLabel: "Waiting for players",
    players: [], board: [], winningCells: []
  })

  while true
    if m.top.restartVersion <> lastRestartVersion
      lastRestartVersion = m.top.restartVersion
      postJson(baseUrl + "/api/rooms/" + roomCode + "/restart", "{}")
    end if

    stateText = getText(baseUrl + "/api/rooms/" + roomCode + "/tv?t=" + CreateObject("roDateTime").AsSeconds().toStr())
    if stateText <> ""
      failures = 0
      m.top.connectionText = ""
      m.top.stateJson = stateText
    else
      failures += 1
      if failures = 2 then m.top.connectionText = "Connection interrupted — keeping the current board"
      if failures >= 8 then m.top.connectionText = "Reconnecting to TN Game server..."
    end if
    sleep(500)
  end while
end sub

function transferFor(url as string) as object
  transfer = CreateObject("roUrlTransfer")
  transfer.EnableEncodings(true)
  transfer.SetCertificatesFile("common:/certs/ca-bundle.crt")
  transfer.InitClientCertificates()
  transfer.SetUrl(url)
  transfer.AddHeader("Accept", "application/json")
  transfer.AddHeader("Content-Type", "application/json")
  transfer.AddHeader("Cache-Control", "no-store")
  transfer.AddHeader("X-Roku-Reserved-Dev-Id", "")
  transfer.SetMinimumTransferRate(1, 15)
  return transfer
end function

function getJson(url as string) as dynamic
  body = getText(url)
  if body = "" then return invalid
  return ParseJson(body)
end function

function getText(url as string) as string
  transfer = transferFor(url)
  body = transfer.GetToString()
  if body = invalid then return ""
  return body
end function

function postJson(url as string, payload as string) as string
  transfer = transferFor(url)
  body = transfer.PostFromString(payload)
  if body = invalid then return ""
  return body
end function

sub publishFatal(message as string)
  m.top.connectionText = message
  m.top.stateJson = FormatJson({
    screen: "error", title: message,
    subtitle: "Press OK to try again.", roomLabel: "", playersLabel: ""
  })
end sub
''')

xml = Path('components/DisplayLoopTask.xml')
xml_text = xml.read_text()
xml_text = xml_text.replace(
'    <field id="errorText" type="string" alwaysNotify="true" />',
'    <field id="errorText" type="string" alwaysNotify="true" />\n    <field id="connectionText" type="string" alwaysNotify="true" />\n    <field id="restartVersion" type="integer" value="0" alwaysNotify="true" />')
xml.write_text(xml_text)
PY

./build.sh
[ -f "$OLD_FILE" ] || { echo "Missing base Roku build"; exit 1; }
cp "$OLD_FILE" "$NEW_FILE"
echo "Created $NEW_FILE"
