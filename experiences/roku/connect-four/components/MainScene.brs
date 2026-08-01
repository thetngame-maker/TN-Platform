sub init()
  m.baseUrl = "http://192.168.1.127:8070"
  m.roomCode = ""
  m.state = "home"
  m.busy = false
  m.requestKind = ""

  m.title = m.top.findNode("title")
  m.subtitle = m.top.findNode("subtitle")
  m.boardGroup = m.top.findNode("boardGroup")
  m.columnLabels = m.top.findNode("columnLabels")
  m.boardPanel = m.top.findNode("boardPanel")
  m.roomLabel = m.top.findNode("roomLabel")
  m.playersLabel = m.top.findNode("playersLabel")
  m.playerOneCard = m.top.findNode("playerOneCard")
  m.playerOneName = m.top.findNode("playerOneName")
  m.playerOneRole = m.top.findNode("playerOneRole")
  m.playerTwoCard = m.top.findNode("playerTwoCard")
  m.playerTwoName = m.top.findNode("playerTwoName")
  m.playerTwoRole = m.top.findNode("playerTwoRole")
  m.net = m.top.findNode("netA")
  m.pollTimer = m.top.findNode("pollTimer")

  m.net.observeField("responseId", "onNetworkResponse")
  m.pollTimer.observeField("fire", "onPoll")

  buildBoard()
  showHome()
  m.top.setFocus(true)
end sub

function onKeyEvent(key as string, press as boolean) as boolean
  if not press then return false

  if key = "OK"
    if m.state = "home" or m.state = "error"
      createRoom()
    else if m.state = "finished" and m.roomCode <> ""
      restartGame()
    end if
  else if key = "back"
    showHome()
  end if

  return true
end function

sub showHome()
  m.pollTimer.control = "stop"
  m.roomCode = ""
  m.state = "home"
  m.busy = false
  m.requestKind = ""
  m.title.text = "PRESS OK TO CREATE A ROOM"
  m.subtitle.text = "Two phones. One shared TV board."
  m.roomLabel.text = ""
  m.playersLabel.text = ""
  showGameChrome(false)
  clearBoard()
end sub

sub showGameChrome(visible as boolean)
  m.boardGroup.visible = visible
  m.columnLabels.visible = visible
  m.boardPanel.visible = visible
  m.playerOneCard.visible = visible
  m.playerOneName.visible = visible
  m.playerOneRole.visible = visible
  m.playerTwoCard.visible = visible
  m.playerTwoName.visible = visible
  m.playerTwoRole.visible = visible
end sub

sub buildBoard()
  m.cells = []
  m.slots = []

  for c = 0 to 6
    label = m.columnLabels.createChild("Label")
    label.text = (c + 1).toStr()
    label.translation = [c * 100, 0]
    label.width = 88
    label.height = 40
    label.horizAlign = "center"
    label.color = "0xA7BDB5FF"
  end for

  for r = 0 to 5
    row = []
    slotRow = []
    for c = 0 to 6
      slot = m.boardGroup.createChild("Rectangle")
      slot.width = 90
      slot.height = 90
      slot.translation = [c * 100, r * 94]
      slot.color = "0x245BC5FF"

      piece = m.boardGroup.createChild("Rectangle")
      piece.width = 68
      piece.height = 68
      piece.translation = [c * 100 + 11, r * 94 + 11]
      piece.color = "0xE8F1EDFF"

      slotRow.push(slot)
      row.push(piece)
    end for
    m.slots.push(slotRow)
    m.cells.push(row)
  end for
end sub

sub clearBoard()
  if m.cells = invalid then return
  for r = 0 to 5
    for c = 0 to 6
      m.cells[r][c].color = "0xE8F1EDFF"
      m.slots[r][c].color = "0x245BC5FF"
    end for
  end for
end sub

sub createRoom()
  m.pollTimer.control = "stop"
  m.roomCode = ""
  m.state = "creating"
  m.title.text = "CREATING ROOM..."
  m.subtitle.text = "Connecting to the game server"
  m.roomLabel.text = ""
  m.playersLabel.text = ""
  showGameChrome(false)
  sendRequest("create", "GET", m.baseUrl + "/api/rooms/create", "")
end sub

sub restartGame()
  if m.roomCode = "" then return
  m.title.text = "STARTING NEW GAME..."
  m.subtitle.text = "Resetting the board"
  sendRequest("restart", "POST", m.baseUrl + "/api/rooms/" + m.roomCode + "/restart", "{}")
end sub

sub requestTvState()
  if m.busy or m.roomCode = "" then return
  sendRequest("tv", "GET", m.baseUrl + "/api/rooms/" + m.roomCode + "/tv", "")
end sub

sub sendRequest(kind as string, method as string, url as string, payload as string)
  if m.busy then return

  m.busy = true
  m.requestKind = kind
  m.net.control = "STOP"
  m.net.requestId = m.net.requestId + 1
  m.net.method = method
  m.net.url = url
  m.net.payload = payload
  m.net.control = "RUN"
end sub

sub onNetworkResponse()
  kind = m.requestKind
  m.requestKind = ""
  m.busy = false

  if m.net.statusCode < 200 or m.net.statusCode >= 300
    if kind = "tv"
      m.subtitle.text = "Reconnecting to game server..."
      return
    end if

    m.state = "error"
    m.title.text = "NETWORK ERROR"
    m.subtitle.text = m.net.statusCode.toStr() + " " + m.net.failureReason
    return
  end if

  data = ParseJson(m.net.body)
  if data = invalid
    if kind = "tv"
      m.subtitle.text = "Waiting for display state..."
      return
    end if

    m.state = "error"
    m.title.text = "UNREADABLE SERVER RESPONSE"
    m.subtitle.text = "Press OK to try again"
    return
  end if

  if kind = "create"
    if data.code = invalid
      m.state = "error"
      m.title.text = "ROOM CREATION FAILED"
      m.subtitle.text = "Server did not return a room code"
      return
    end if

    m.roomCode = data.code
    m.pollTimer.control = "start"
    requestTvState()
    return
  end if

  if kind = "tv" or kind = "restart"
    applyTvState(data)
  end if
end sub

sub onPoll()
  requestTvState()
end sub

sub applyTvState(data as object)
  if data.screen = invalid then return

  m.state = data.screen
  m.title.text = valueOr(data.title, "TN GAME CONNECT FOUR")
  m.subtitle.text = valueOr(data.subtitle, "")
  m.roomLabel.text = valueOr(data.roomLabel, "")
  m.playersLabel.text = valueOr(data.playersLabel, "")
  applyPlayers(data.players, data.currentPlayerId)

  if data.screen = "playing" or data.screen = "finished"
    showGameChrome(true)
    renderBoard(data.board, data.winningCells, data.lastMove)
  else
    showGameChrome(false)
    clearBoard()
    if data.joinUrl <> invalid then m.subtitle.text = data.joinUrl
  end if
end sub

sub applyPlayers(players as dynamic, currentPlayerId as dynamic)
  p1 = invalid
  p2 = invalid
  if players <> invalid
    if players.Count() > 0 then p1 = players[0]
    if players.Count() > 1 then p2 = players[1]
  end if

  if p1 <> invalid
    m.playerOneName.text = p1.name.toUpper()
    m.playerOneRole.text = "ORANGE"
    if currentPlayerId <> invalid and p1.id = currentPlayerId
      m.playerOneCard.color = "0x153B2FFF"
      m.playerOneRole.text = "ORANGE  •  YOUR TURN"
    else
      m.playerOneCard.color = "0x0B1E18FF"
    end if
  else
    m.playerOneName.text = "PLAYER 1"
    m.playerOneRole.text = "ORANGE"
  end if

  if p2 <> invalid
    m.playerTwoName.text = p2.name.toUpper()
    m.playerTwoRole.text = "GOLD"
    if currentPlayerId <> invalid and p2.id = currentPlayerId
      m.playerTwoCard.color = "0x153B2FFF"
      m.playerTwoRole.text = "GOLD  •  YOUR TURN"
    else
      m.playerTwoCard.color = "0x0B1E18FF"
    end if
  else
    m.playerTwoName.text = "PLAYER 2"
    m.playerTwoRole.text = "GOLD"
  end if
end sub

function valueOr(value as dynamic, fallback as string) as string
  if value = invalid then return fallback
  return value
end function

function isWinningCell(winningCells as dynamic, row as integer, col as integer) as boolean
  if winningCells = invalid then return false
  for each cell in winningCells
    if cell.row = row and cell.col = col then return true
  end for
  return false
end function

sub renderBoard(board as dynamic, winningCells as dynamic, lastMove as dynamic)
  if board = invalid then return

  for r = 0 to 5
    for c = 0 to 6
      value = board[r][c]
      if value = 1
        m.cells[r][c].color = "0xF97316FF"
      else if value = 2
        m.cells[r][c].color = "0xFFD54FFF"
      else
        m.cells[r][c].color = "0xE8F1EDFF"
      end if

      if isWinningCell(winningCells, r, c)
        m.slots[r][c].color = "0xFFFFFFFF"
      else if lastMove <> invalid and lastMove.row = r and lastMove.col = c
        m.slots[r][c].color = "0x5ED6B2FF"
      else
        m.slots[r][c].color = "0x245BC5FF"
      end if
    end for
  end for
end sub
