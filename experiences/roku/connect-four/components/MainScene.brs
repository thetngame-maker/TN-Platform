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
  m.top.setFocus(true)
end sub

function onKeyEvent(key as string, press as boolean) as boolean
  if not press then return false
  if key = "OK"
    if m.state = "home" or m.state = "error"
      createRoom()
    else if m.state = "lobby" and m.room <> invalid and m.room.players.Count() > 0
      send("start", "GET", m.baseUrl + "/api/rooms/" + m.room.code + "/start?v=" + m.seq.toStr(), "")
      m.title.text = "STARTING GAME..."
    end if
  else if key = "back"
    m.pollTimer.control = "stop"
    m.room = invalid
    m.state = "home"
    m.title.text = "PRESS OK TO CREATE A ROOM"
    m.subtitle.text = "Phone-controlled multiplayer test"
    m.roomLabel.text = ""
    m.playersLabel.text = ""
    m.boardGroup.visible = false
  end if
  return true
end function

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

sub createRoom()
  m.pollTimer.control = "stop"
  m.room = invalid
  m.state = "creating"
  m.title.text = "CREATING ROOM..."
  m.subtitle.text = "Connecting to the Connect Four server"
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
    m.state = "error"
    m.title.text = "NETWORK ERROR"
    m.subtitle.text = task.statusCode.toStr() + " " + task.failureReason
    return
  end if
  data = ParseJson(task.body)
  if data = invalid
    m.state = "error"
    m.title.text = "UNREADABLE SERVER RESPONSE"
    return
  end if
  m.room = data
  applyRoom()
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
  send("poll", "GET", m.baseUrl + "/api/rooms/" + m.room.code + "?v=" + m.seq.toStr(), "")
end sub

sub applyRoom()
  if m.room.status = "lobby"
    m.state = "lobby"
    m.boardGroup.visible = false
    m.title.text = "ENTER ROOM " + m.room.code
    m.subtitle.text = m.baseUrl + "/?room=" + m.room.code
    m.roomLabel.text = m.room.players.Count().toStr() + " player(s) connected"
    m.playersLabel.text = playerNames()
  else
    m.state = m.room.status
    m.boardGroup.visible = true
    renderBoard()
  end if
end sub

function playerNames() as string
  out = ""
  for each p in m.room.players
    if out <> "" then out += "  •  "
    out += p.name
  end for
  if out = "" then out = "Waiting for players"
  return out
end function

sub renderBoard()
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
    if game.winnerName <> ""
      m.title.text = game.winnerName.toUpper() + " WINS!"
    else
      m.title.text = "DRAW GAME"
    end if
  else
    name = "WAITING"
    for each p in m.room.players
      if p.id = game.turnPlayerId then name = p.name
    end for
    m.title.text = name.toUpper() + "'S TURN"
  end if
  m.subtitle.text = game.message
  m.roomLabel.text = "ROOM " + m.room.code
  m.playersLabel.text = playerNames()
end sub
