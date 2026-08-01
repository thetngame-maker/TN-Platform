sub init()
  m.baseUrl = "http://192.168.1.127:8070"
  m.roomCode = ""
  m.state = "home"
  m.busy = false
  m.requestId = 0

  m.title = m.top.findNode("title")
  m.subtitle = m.top.findNode("subtitle")
  m.boardGroup = m.top.findNode("boardGroup")
  m.roomLabel = m.top.findNode("roomLabel")
  m.playersLabel = m.top.findNode("playersLabel")
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
  m.title.text = "PRESS OK TO CREATE A ROOM"
  m.subtitle.text = "Server-powered Connect Four"
  m.roomLabel.text = ""
  m.playersLabel.text = ""
  m.boardGroup.visible = false
  clearBoard()
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

  if data.screen = "playing" or data.screen = "finished"
    m.boardGroup.visible = true
    renderBoard(data.board)
  else
    m.boardGroup.visible = false
    clearBoard()
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
        setPieceUri(m.cells[r][c], "pkg:/images/token-orange.png")
      else if value = 2
        setPieceUri(m.cells[r][c], "pkg:/images/token-gold.png")
      else
        setPieceUri(m.cells[r][c], "pkg:/images/token-empty.png")
      end if
    end for
  end for
end sub
