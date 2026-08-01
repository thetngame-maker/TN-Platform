sub init()
  m.baseUrl = "http://192.168.1.127:8070"
  m.roomCode = ""
  m.state = "home"
  m.busy = false
  m.nextNet = 0
  m.netAKind = ""
  m.netBKind = ""

  m.title = m.top.findNode("title")
  m.subtitle = m.top.findNode("subtitle")
  m.boardGroup = m.top.findNode("boardGroup")
  m.columnLabels = m.top.findNode("columnLabels")
  m.boardPanel = m.top.findNode("boardPanel")
  m.boardShadow = m.top.findNode("boardShadow")
  m.roomLabel = m.top.findNode("roomLabel")
  m.playersLabel = m.top.findNode("playersLabel")
  m.playerOneCard = m.top.findNode("playerOneCard")
  m.playerOneSwatch = m.top.findNode("playerOneSwatch")
  m.playerOneName = m.top.findNode("playerOneName")
  m.playerOneRole = m.top.findNode("playerOneRole")
  m.playerTwoCard = m.top.findNode("playerTwoCard")
  m.playerTwoSwatch = m.top.findNode("playerTwoSwatch")
  m.playerTwoName = m.top.findNode("playerTwoName")
  m.playerTwoRole = m.top.findNode("playerTwoRole")
  m.netA = m.top.findNode("netA")
  m.netB = m.top.findNode("netB")
  m.pollTimer = m.top.findNode("pollTimer")

  m.netA.observeField("responseId", "onNetAResponse")
  m.netB.observeField("responseId", "onNetBResponse")
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
  m.netAKind = ""
  m.netBKind = ""
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
  m.boardShadow.visible = visible
  m.playerOneCard.visible = visible
  m.playerOneSwatch.visible = visible
  m.playerOneName.visible = visible
  m.playerOneRole.visible = visible
  m.playerTwoCard.visible = visible
  m.playerTwoSwatch.visible = visible
  m.playerTwoName.visible = visible
  m.playerTwoRole.visible = visible
end sub

sub buildBoard()
  m.cells = []
  m.slots = []
  for c = 0 to 6
    label = m.columnLabels.createChild("Label")
    label.text = (c + 1).toStr()
    label.translation = [c * 102, 0]
    label.width = 92
    label.height = 42
    label.horizAlign = "center"
    label.color = "0x7FA99CFF"
  end for
  for r = 0 to 5
    row = []
    slotRow = []
    for c = 0 to 6
      slot = m.boardGroup.createChild("Rectangle")
      slot.width = 94
      slot.height = 94
      slot.translation = [c * 102, r * 96]
      slot.color = "0x245BC5FF"
      piece = m.boardGroup.createChild("Rectangle")
      piece.width = 72
      piece.height = 72
      piece.translation = [c * 102 + 11, r * 96 + 11]
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
  m.subtitle.text = "Connecting to the TN Game server"
  m.roomLabel.text = ""
  m.playersLabel.text = ""
  showGameChrome(false)
  sendRequest("create", "GET", m.baseUrl + "/api/rooms/create", "")
end sub

sub restartGame()
  if m.roomCode = "" then return
  m.pollTimer.control = "stop"
  m.title.text = "NEW ROUND"
  m.subtitle.text = "Resetting the board..."
  sendRequest("restart", "POST", m.baseUrl + "/api/rooms/" + m.roomCode + "/restart", "{}")
end sub

sub requestTvState()
  if m.busy or m.roomCode = "" then return
  sendRequest("tv", "GET", m.baseUrl + "/api/rooms/" + m.roomCode + "/tv", "")
end sub

sub sendRequest(kind as string, method as string, url as string, payload as string)
  if m.busy then return
  m.busy = true
  if m.nextNet = 0
    task = m.netA
    m.netAKind = kind
    m.nextNet = 1
  else
    task = m.netB
    m.netBKind = kind
    m.nextNet = 0
  end if
  task.control = "STOP"
  task.requestId = task.requestId + 1
  task.method = method
  task.url = url
  task.payload = payload
  task.control = "RUN"
end sub

sub onNetAResponse()
  kind = m.netAKind
  m.netAKind = ""
  handleNetworkResponse(m.netA, kind)
end sub

sub onNetBResponse()
  kind = m.netBKind
  m.netBKind = ""
  handleNetworkResponse(m.netB, kind)
end sub

sub handleNetworkResponse(task as object, kind as string)
  if kind = "" then return
  m.busy = false

  if task.statusCode < 200 or task.statusCode >= 300
    if kind = "tv"
      m.subtitle.text = "Reconnecting to game server..."
      scheduleNextPoll()
      return
    end if
    m.state = "error"
    m.title.text = "NETWORK ERROR"
    m.subtitle.text = task.statusCode.toStr() + " " + task.failureReason
    return
  end if

  data = ParseJson(task.body)
  if data = invalid
    if kind = "tv"
      m.subtitle.text = "Waiting for display state..."
      scheduleNextPoll()
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
    m.state = "lobby"
    m.title.text = "JOIN ROOM " + m.roomCode
    m.subtitle.text = m.baseUrl + "/?room=" + m.roomCode
    m.roomLabel.text = "0 of 2 players connected"
    m.playersLabel.text = "Waiting for players"
    scheduleNextPoll()
    return
  end if

  if kind = "tv" or kind = "restart"
    applyTvState(data)
    scheduleNextPoll()
  end if
end sub

sub scheduleNextPoll()
  if m.roomCode = "" then return
  m.pollTimer.control = "stop"
  m.pollTimer.control = "start"
end sub

sub onPoll()
  m.pollTimer.control = "stop"
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
    if currentPlayerId <> invalid and p1.id = currentPlayerId
      m.playerOneCard.color = "0x153B2FFF"
      m.playerOneRole.text = "ORANGE  •  PLAYING"
    else
      m.playerOneCard.color = "0x0B1E18FF"
      m.playerOneRole.text = "ORANGE"
    end if
  else
    m.playerOneName.text = "PLAYER 1"
    m.playerOneRole.text = "ORANGE"
  end if
  if p2 <> invalid
    m.playerTwoName.text = p2.name.toUpper()
    if currentPlayerId <> invalid and p2.id = currentPlayerId
      m.playerTwoCard.color = "0x153B2FFF"
      m.playerTwoRole.text = "GOLD  •  PLAYING"
    else
      m.playerTwoCard.color = "0x0B1E18FF"
      m.playerTwoRole.text = "GOLD"
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
