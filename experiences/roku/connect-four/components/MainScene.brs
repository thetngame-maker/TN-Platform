sub init()
  m.baseUrl = "http://192.168.1.127:8070"
  m.room = invalid
  m.state = "home"
  m.busy = false
  m.requestKind = ""

  m.title = m.top.findNode("title")
  m.subtitle = m.top.findNode("subtitle")
  m.boardGroup = m.top.findNode("boardGroup")
  m.roomLabel = m.top.findNode("roomLabel")
  m.playersLabel = m.top.findNode("playersLabel")
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
    else if m.state = "lobby"
      count = playerCount()
      if count < 2
        m.subtitle.text = "Two players are required to start"
      else
        startGame()
      end if
    end if
  else if key = "back"
    showHome()
  end if

  return true
end function

sub showHome()
  m.pollTimer.control = "stop"
  m.room = invalid
  m.state = "home"
  m.busy = false
  m.requestKind = ""
  m.title.text = "PRESS OK TO CREATE A ROOM"
  m.subtitle.text = "Phone-controlled multiplayer test"
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
      slot = m.boardGroup.createChild("Rectangle")
      slot.width = 92
      slot.height = 92
      slot.translation = [c * 102, r * 102]
      slot.color = "0x173B8FFF"

      piece = m.boardGroup.createChild("Rectangle")
      piece.width = 72
      piece.height = 72
      piece.translation = [c * 102 + 10, r * 102 + 10]
      piece.color = "0xE8F1EDFF"
      row.push(piece)
    end for
    m.cells.push(row)
  end for
end sub

sub clearBoard()
  if m.cells = invalid then return

  for r = 0 to 5
    for c = 0 to 6
      m.cells[r][c].color = "0xE8F1EDFF"
    end for
  end for
end sub

sub createRoom()
  m.pollTimer.control = "stop"
  m.room = invalid
  m.state = "creating"
  m.title.text = "CREATING ROOM..."
  m.subtitle.text = "Connecting to the Connect Four server"
  m.roomLabel.text = ""
  m.playersLabel.text = ""
  m.boardGroup.visible = false
  sendRequest("create", "GET", m.baseUrl + "/api/rooms/create", "")
end sub

sub startGame()
  if m.room = invalid or m.room.code = invalid then return

  m.state = "starting"
  m.title.text = "STARTING GAME..."
  m.subtitle.text = "Preparing the board"

  ' Use POST and continue polling independently of the start response.
  sendRequest("start", "POST", m.baseUrl + "/api/rooms/" + m.room.code + "/start", "{}")
  m.pollTimer.control = "start"
end sub

sub sendRequest(kind as string, method as string, url as string, payload as string)
  if m.busy then return

  m.busy = true
  m.requestKind = kind

  ' responseId only acts as a change notification. We do not compare it to a
  ' global sequence because some Roku models publish Task fields asynchronously.
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
    if kind = "poll" or kind = "start"
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
    if kind = "poll" or kind = "start"
      return
    end if

    m.state = "error"
    m.title.text = "UNREADABLE SERVER RESPONSE"
    m.subtitle.text = "Press OK to try again"
    return
  end if

  if data.code <> invalid
    m.room = data
    applyRoom()
  end if

  if kind = "create"
    if m.room = invalid
      m.state = "error"
      m.title.text = "ROOM CREATION FAILED"
      m.subtitle.text = "Server did not return a room"
      return
    end if

    m.pollTimer.control = "start"
  end if

  ' Always fetch the canonical room after starting. This also supports older
  ' servers that return a small acknowledgement instead of the full room.
  if kind = "start"
    requestRoom()
  end if
end sub

sub onPoll()
  requestRoom()
end sub

sub requestRoom()
  if m.busy then return
  if m.room = invalid or m.room.code = invalid then return

  sendRequest("poll", "GET", m.baseUrl + "/api/rooms/" + m.room.code, "")
end sub

sub applyRoom()
  if m.room = invalid or m.room.status = invalid then return

  if m.room.status = "lobby"
    showLobby()
  else if m.room.status = "playing" or m.room.status = "finished"
    m.state = m.room.status
    m.boardGroup.visible = true
    renderBoard()
  end if
end sub

sub showLobby()
  m.state = "lobby"
  m.boardGroup.visible = false

  count = playerCount()
  if count >= 2
    m.title.text = "PRESS OK TO START"
    m.subtitle.text = "Room " + m.room.code + " is ready"
  else
    m.title.text = "ENTER ROOM " + m.room.code
    m.subtitle.text = m.baseUrl + "/?room=" + m.room.code
  end if

  m.roomLabel.text = count.toStr() + " player(s) connected"
  m.playersLabel.text = playerNames()
end sub

function playerCount() as integer
  if m.room = invalid or m.room.players = invalid then return 0
  return m.room.players.Count()
end function

function playerNames() as string
  if m.room = invalid or m.room.players = invalid then return "Waiting for players"

  out = ""
  for each player in m.room.players
    if out <> "" then out += "  •  "
    if player.name <> invalid
      out += player.name
    else
      out += "Player"
    end if
  end for

  if out = "" then return "Waiting for players"
  return out
end function

sub renderBoard()
  if m.room.game = invalid or m.room.game.board = invalid
    m.title.text = "STARTING GAME..."
    m.subtitle.text = "Waiting for board state"
    return
  end if

  game = m.room.game

  for r = 0 to 5
    for c = 0 to 6
      value = game.board[r][c]
      if value = 1
        m.cells[r][c].color = "0xF97316FF"
      else if value = 2
        m.cells[r][c].color = "0xFFD54FFF"
      else
        m.cells[r][c].color = "0xE8F1EDFF"
      end if
    end for
  end for

  if m.room.status = "finished"
    if game.winnerName <> invalid and game.winnerName <> ""
      m.title.text = game.winnerName.toUpper() + " WINS!"
      m.subtitle.text = game.message
    else
      m.title.text = "DRAW GAME"
      m.subtitle.text = "The board is full"
    end if
  else
    turnName = "WAITING"
    if game.turnPlayerId <> invalid
      for each player in m.room.players
        if player.id = game.turnPlayerId then turnName = player.name
      end for
    end if

    m.title.text = turnName.toUpper() + "'S TURN"
    if game.message <> invalid and game.message <> ""
      m.subtitle.text = game.message
    else
      m.subtitle.text = "Choose a column on your phone"
    end if
  end if

  m.roomLabel.text = "ROOM " + m.room.code
  m.playersLabel.text = playerNames()
end sub
