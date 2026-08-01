sub init()
  m.baseUrl = "http://192.168.1.127:8070"
  m.roomCode = ""
  m.state = "home"
  m.busy = false
  m.requestId = 0
  m.playerColors = ["orange", "gold"]

  m.title = m.top.findNode("title")
  m.subtitle = m.top.findNode("subtitle")
  m.boardGroup = m.top.findNode("boardGroup")
  m.roomLabel = m.top.findNode("roomLabel")
  m.playersLabel = m.top.findNode("playersLabel")
  m.playerOneName = m.top.findNode("playerOneName")
  m.playerOneColor = m.top.findNode("playerOneColor")
  m.playerOneTurn = m.top.findNode("playerOneTurn")
  m.playerOneToken = m.top.findNode("playerOneToken")
  m.playerTwoName = m.top.findNode("playerTwoName")
  m.playerTwoColor = m.top.findNode("playerTwoColor")
  m.playerTwoTurn = m.top.findNode("playerTwoTurn")
  m.playerTwoToken = m.top.findNode("playerTwoToken")
  m.pollTimer = m.top.findNode("pollTimer")

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
  m.playerColors = ["orange", "gold"]
  m.title.text = "PRESS OK TO CREATE A ROOM"
  m.subtitle.text = "Choose your colors on the phone"
  m.roomLabel.text = ""
  m.playersLabel.text = ""
  m.boardGroup.visible = false
  resetPlayerCards()
  clearBoard()
end sub

sub resetPlayerCards()
  m.playerOneName.text = "PLAYER 1"
  m.playerOneColor.text = "ORANGE"
  m.playerOneTurn.text = ""
  m.playerOneToken.uri = colorAsset("orange")
  m.playerTwoName.text = "PLAYER 2"
  m.playerTwoColor.text = "GOLD"
  m.playerTwoTurn.text = ""
  m.playerTwoToken.uri = colorAsset("gold")
end sub

sub buildBoard()
  m.cells = []
  for r = 0 to 5
    row = []
    for c = 0 to 6
      slot = m.boardGroup.createChild("Poster")
      slot.width = 88
      slot.height = 88
      slot.loadWidth = 88
      slot.loadHeight = 88
      slot.loadDisplayMode = "scaleToFit"
      slot.translation = [c * 100, r * 94]
      slot.uri = "pkg:/images/token-empty.png"
      row.push(slot)
    end for
    m.cells.push(row)
  end for
end sub

function colorAsset(color as dynamic) as string
  if color = invalid then return "pkg:/images/token-orange.png"
  safeColor = LCase(color.toStr())
  validColors = ["orange", "gold", "blue", "purple", "green", "pink"]
  for each candidate in validColors
    if safeColor = candidate then return "pkg:/images/token-" + safeColor + ".png"
  end for
  return "pkg:/images/token-orange.png"
end function

function colorName(color as dynamic) as string
  if color = invalid then return "ORANGE"
  return UCase(color.toStr())
end function

sub setPieceUri(piece as object, uri as string)
  if piece.uri <> uri then piece.uri = uri
end sub

sub clearBoard()
  if m.cells = invalid then return
  for r = 0 to 5
    for c = 0 to 6
      setPieceUri(m.cells[r][c], "pkg:/images/token-empty.png")
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
  m.boardGroup.visible = false
  sendRequest("create", "GET", m.baseUrl + "/api/rooms/create", "")
end sub

sub restartGame()
  if m.roomCode = "" or m.busy then return
  m.title.text = "STARTING NEW GAME..."
  m.subtitle.text = "Resetting the board"
  sendRequest("restart", "POST", m.baseUrl + "/api/rooms/" + m.roomCode + "/restart", "{}")
end sub

sub requestTvState()
  if m.busy or m.roomCode = "" then return
  sendRequest("tv", "GET", m.baseUrl + "/api/rooms/" + m.roomCode + "/tv?t=" + m.requestId.toStr(), "")
end sub

sub sendRequest(kind as string, method as string, url as string, payload as string)
  if m.busy then return
  m.busy = true
  m.requestId += 1

  task = m.top.createChild("TNNetworkTask")
  task.requestId = m.requestId
  task.requestKind = kind
  task.method = method
  task.url = url
  task.payload = payload
  task.observeField("responseId", "onNetworkResponse")
  task.control = "RUN"
end sub

sub onNetworkResponse(event as object)
  task = event.getRoSGNode()
  if task = invalid then return

  kind = task.requestKind
  statusCode = task.statusCode
  body = task.body
  failureReason = task.failureReason
  task.unobserveField("responseId")
  m.top.removeChild(task)
  m.busy = false

  if statusCode < 200 or statusCode >= 300
    if kind = "tv"
      m.subtitle.text = "Reconnecting to game server..."
      return
    end if
    m.state = "error"
    m.title.text = "NETWORK ERROR"
    m.subtitle.text = statusCode.toStr() + " " + failureReason
    return
  end if

  data = ParseJson(body)
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
    m.boardGroup.visible = true
    renderBoard(data.board)
  else
    m.boardGroup.visible = false
    clearBoard()
  end if
end sub

sub applyPlayers(players as dynamic, currentPlayerId as dynamic)
  m.playerColors = ["orange", "gold"]
  p1 = invalid
  p2 = invalid
  if players <> invalid
    if players.Count() > 0 then p1 = players[0]
    if players.Count() > 1 then p2 = players[1]
  end if

  if p1 <> invalid
    m.playerColors[0] = p1.color
    m.playerOneName.text = UCase(p1.name)
    m.playerOneColor.text = colorName(p1.color)
    m.playerOneToken.uri = colorAsset(p1.color)
    if currentPlayerId <> invalid and p1.id = currentPlayerId then m.playerOneTurn.text = "YOUR TURN" else m.playerOneTurn.text = ""
  else
    m.playerOneName.text = "PLAYER 1"
    m.playerOneColor.text = "CHOOSE COLOR"
    m.playerOneTurn.text = ""
  end if

  if p2 <> invalid
    m.playerColors[1] = p2.color
    m.playerTwoName.text = UCase(p2.name)
    m.playerTwoColor.text = colorName(p2.color)
    m.playerTwoToken.uri = colorAsset(p2.color)
    if currentPlayerId <> invalid and p2.id = currentPlayerId then m.playerTwoTurn.text = "YOUR TURN" else m.playerTwoTurn.text = ""
  else
    m.playerTwoName.text = "PLAYER 2"
    m.playerTwoColor.text = "CHOOSE COLOR"
    m.playerTwoTurn.text = ""
  end if
end sub

function valueOr(value as dynamic, fallback as string) as string
  if value = invalid then return fallback
  return value
end function

sub renderBoard(board as dynamic)
  if board = invalid then return
  for r = 0 to 5
    for c = 0 to 6
      value = board[r][c]
      if value = 1
        setPieceUri(m.cells[r][c], colorAsset(m.playerColors[0]))
      else if value = 2
        setPieceUri(m.cells[r][c], colorAsset(m.playerColors[1]))
      else
        setPieceUri(m.cells[r][c], "pkg:/images/token-empty.png")
      end if
    end for
  end for
end sub
