sub init()
  m.baseUrl = "http://192.168.1.127:8070"
  m.room = invalid
  m.state = "home"
  m.busy = false
  m.seq = 0
  m.nextTask = 0
  m.pending = ""
  m.title = m.top.findNode("title")
  m.subtitle = m.top.findNode("subtitle")
  m.boardGroup = m.top.findNode("boardGroup")
  m.roomLabel = m.top.findNode("roomLabel")
  m.playersLabel = m.top.findNode("playersLabel")
  m.netA = m.top.findNode("netA")
  m.netB = m.top.findNode("netB")
  m.pollTimer = m.top.findNode("pollTimer")
  m.netA.observeField("responseId", "onNetA")
  m.netB.observeField("responseId", "onNetB")
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
    else if m.state = "lobby" and m.room <> invalid
      playerCount = 0
      if m.room.players <> invalid then playerCount = m.room.players.Count()

      if playerCount < 2
        m.subtitle.text = "Two players are required to start"
      else
        m.state = "starting"
        m.title.text = "STARTING GAME..."
        m.subtitle.text = "Preparing the board"
        send("start", "GET", m.baseUrl + "/api/rooms/" + m.room.code + "/start?v=" + m.seq.toStr(), "")
      end if
    else if m.state = "finished" and m.room <> invalid
      m.state = "starting"
      m.title.text = "STARTING NEW GAME..."
      m.subtitle.text = "Resetting the board"
      send("start", "GET", m.baseUrl + "/api/rooms/" + m.room.code + "/start?v=" + m.seq.toStr(), "")
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
  m.pending = ""
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
  send("create", "GET", m.baseUrl + "/api/rooms/create?v=" + m.seq.toStr(), "")
end sub

sub send(kind as string, method as string, url as string, payload as string)
  if m.busy then return

  m.busy = true
  m.pending = kind
  m.seq += 1

  if m.nextTask = 0
    task = m.netA
    m.nextTask = 1
  else
    task = m.netB
    m.nextTask = 0
  end if

  task.control = "STOP"
  task.requestId = m.seq
  task.method = method
  task.url = url
  task.payload = payload
  task.control = "RUN"
end sub

sub onNetA()
  handleResponse(m.netA)
end sub

sub onNetB()
  handleResponse(m.netB)
end sub

sub handleResponse(task as object)
  if task.responseId <> m.seq then return

  kind = m.pending
  m.pending = ""
  m.busy = false

  if task.statusCode < 200 or task.statusCode >= 300
    if kind = "poll" and m.room <> invalid
      m.subtitle.text = "Reconnecting to game server..."
      return
    end if

    m.state = "error"
    m.title.text = "NETWORK ERROR"
    m.subtitle.text = task.statusCode.toStr() + " " + task.failureReason
    return
  end if

  data = ParseJson(task.body)
  if data = invalid
    if kind = "start" and m.room <> invalid
      requestRoom()
      return
    end if

    m.state = "error"
    m.title.text = "UNREADABLE SERVER RESPONSE"
    m.subtitle.text = "Press OK to try again"
    return
  end if

  ' The start endpoint may return only an acknowledgement. Never replace the
  ' current room unless the response actually contains a room code.
  if data.code <> invalid
    m.room = data
    applyRoom()
  else if kind = "create"
    m.state = "error"
    m.title.text = "ROOM CREATION FAILED"
    m.subtitle.text = "Server did not return a room code"
    return
  end if

  if kind = "create"
    m.pollTimer.control = "start"
    requestRoom()
  else if kind = "start"
    requestRoom()
  end if
end sub

sub onPoll()
  requestRoom()
end sub

sub requestRoom()
  if m.room = invalid or m.busy then return
  if m.room.code = invalid then return
  send("poll", "GET", m.baseUrl + "/api/rooms/" + m.room.code + "?v=" + m.seq.toStr(), "")
end sub

sub applyRoom()
  if m.room = invalid or m.room.status = invalid then return

  if m.room.status = "lobby"
    m.state = "lobby"
    m.boardGroup.visible = false
    m.title.text = "ENTER ROOM " + m.room.code
    m.subtitle.text = m.baseUrl + "/?room=" + m.room.code

    playerCount = 0
    if m.room.players <> invalid then playerCount = m.room.players.Count()
    m.roomLabel.text = playerCount.toStr() + " player(s) connected"
    m.playersLabel.text = playerNames()

    if playerCount >= 2
      m.title.text = "PRESS OK TO START"
      m.subtitle.text = "Room " + m.room.code + " is ready"
    end if
  else if m.room.status = "playing" or m.room.status = "finished"
    m.state = m.room.status
    m.boardGroup.visible = true
    renderBoard()
  else
    m.state = m.room.status
    m.title.text = "WAITING FOR GAME..."
    m.subtitle.text = "Room " + m.room.code
  end if
end sub

function playerNames() as string
  out = ""
  if m.room = invalid or m.room.players = invalid then return "Waiting for players"

  for each p in m.room.players
    if out <> "" then out += "  •  "
    if p.name <> invalid
      out += p.name
    else
      out += "Player"
    end if
  end for

  if out = "" then out = "Waiting for players"
  return out
end function

sub renderBoard()
  if m.room = invalid or m.room.game = invalid then
    m.title.text = "STARTING GAME..."
    m.subtitle.text = "Waiting for board state"
    return
  end if

  game = m.room.game
  if game.board = invalid then
    m.title.text = "STARTING GAME..."
    m.subtitle.text = "Waiting for board state"
    return
  end if

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
    else
      m.title.text = "DRAW GAME"
    end if
    m.subtitle.text = "Press OK to play again"
  else
    name = "WAITING"
    if m.room.players <> invalid and game.turnPlayerId <> invalid
      for each p in m.room.players
        if p.id = game.turnPlayerId then name = p.name
      end for
    end if
    m.title.text = name.toUpper() + "'S TURN"
    if game.message <> invalid and game.message <> ""
      m.subtitle.text = game.message
    else
      m.subtitle.text = "Choose a column on your phone"
    end if
  end if

  m.roomLabel.text = "ROOM " + m.room.code
  m.playersLabel.text = playerNames()
end sub
