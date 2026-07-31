sub init()
  m.baseUrl = "http://192.168.1.127:8080"
  m.appState = "home"
  m.room = invalid
  m.busy = false
  m.nextTask = 0
  m.requestSeq = 0
  m.pendingKind = ""
  m.startPending = false

  ids = ["homeGroup","loadingGroup","lobbyGroup","gameGroup","errorGroup","loadingMessage","joinUrl","qrCode","roomCode","lobbyStatus","playerList","startButton","startLabel","turnLabel","gameMessage","deckCount","discardCard","discardText","activeColor","gamePlayers","errorText","netA","netB","pollTimer"]
  for each id in ids
    m[id] = m.top.findNode(id)
  end for

  m.netA.observeField("responseId", "onNetA")
  m.netB.observeField("responseId", "onNetB")
  m.pollTimer.observeField("fire", "onPoll")
  m.joinUrl.text = m.baseUrl
  transitionTo("home")
  m.top.setFocus(true)
end sub

function onKeyEvent(key as string, press as boolean) as boolean
  if not press then return false
  if m.appState = "home"
    if key = "OK" then createRoom()
  else if m.appState = "error"
    if key = "OK" then createRoom()
    if key = "back" then transitionTo("home")
  else if m.appState = "lobby"
    if key = "OK" and not m.startPending and m.room <> invalid and m.room.players.Count() > 0 then startGame()
    if key = "back" then transitionTo("home")
  else if key = "back"
    transitionTo("home")
  end if
  return true
end function

sub transitionTo(state as string)
  m.appState = state
  m.homeGroup.visible = state = "home"
  m.loadingGroup.visible = state = "creating"
  m.lobbyGroup.visible = state = "lobby"
  m.gameGroup.visible = state = "starting" or state = "playing" or state = "finished" or state = "reconnecting"
  m.errorGroup.visible = state = "error"
  if state = "home"
    m.pollTimer.control = "stop"
    m.room = invalid
    m.busy = false
    m.startPending = false
  end if
end sub

sub createRoom()
  m.pollTimer.control = "stop"
  m.room = invalid
  m.startPending = false
  transitionTo("creating")
  m.loadingMessage.text = "Creating a live room on " + m.baseUrl
  sendRequest("create", "POST", m.baseUrl + "/api/rooms", "{}")
end sub

sub sendRequest(kind as string, method as string, url as string, payload as string)
  if m.busy then return
  m.busy = true
  m.pendingKind = kind
  m.requestSeq += 1
  if m.nextTask = 0
    task = m.netA
    m.nextTask = 1
  else
    task = m.netB
    m.nextTask = 0
  end if
  task.control = "STOP"
  task.requestId = m.requestSeq
  task.method = method
  task.url = url
  task.payload = payload
  task.control = "RUN"
end sub

sub onNetA()
  handleNetwork(m.netA)
end sub

sub onNetB()
  handleNetwork(m.netB)
end sub

sub handleNetwork(task as object)
  if task.responseId <> m.requestSeq then return
  kind = m.pendingKind
  m.pendingKind = ""
  m.busy = false

  if task.statusCode < 200 or task.statusCode >= 300
    if kind = "poll"
      showReconnectState(task.failureReason)
      return
    end if
    showError("Network request failed: " + task.statusCode.toStr() + " " + task.failureReason)
    return
  end if

  data = ParseJson(task.body)
  if data = invalid
    showError("The room server returned unreadable JSON.")
    return
  end if

  if kind = "create"
    m.room = data
    transitionTo("lobby")
    renderLobby()
    m.pollTimer.control = "start"
    requestRoom()
  else if kind = "start"
    m.room = data
    m.startPending = false
    applyRoomState()
    requestRoom()
  else if kind = "poll"
    m.room = data
    applyRoomState()
  end if
end sub

sub onPoll()
  requestRoom()
end sub

sub requestRoom()
  if m.room = invalid or m.busy then return
  stamp = CreateObject("roDateTime").AsSeconds().toStr() + m.requestSeq.toStr()
  sendRequest("poll", "GET", m.baseUrl + "/api/rooms/" + m.room.code + "?v=" + stamp, "")
end sub

sub startGame()
  if m.room = invalid or m.busy or m.startPending then return
  m.startPending = true
  transitionTo("starting")
  m.turnLabel.text = "DEALING CARDS..."
  m.gameMessage.text = "Starting live table..."
  m.deckCount.text = "-- LEFT"
  m.discardText.text = "..."
  m.activeColor.text = "CONNECTING"
  m.gamePlayers.text = playerSummary()
  stamp = CreateObject("roDateTime").AsSeconds().toStr() + m.requestSeq.toStr()
  sendRequest("start", "GET", m.baseUrl + "/api/rooms/" + m.room.code + "/start?v=" + stamp, "")
end sub

sub applyRoomState()
  if m.room = invalid then return
  if m.room.status = "lobby"
    transitionTo("lobby")
    renderLobby()
  else if m.room.status = "playing"
    m.startPending = false
    transitionTo("playing")
    renderGame()
  else if m.room.status = "finished"
    m.startPending = false
    transitionTo("finished")
    renderGame()
  end if
end sub

sub showReconnectState(reason as string)
  if m.appState = "lobby"
    m.lobbyStatus.text = "Reconnecting... " + reason
  else
    transitionTo("reconnecting")
    m.gameMessage.text = "Reconnecting to live table..."
  end if
end sub

sub renderLobby()
  m.roomCode.text = m.room.code
  joinLink = m.baseUrl + "/?room=" + m.room.code
  m.joinUrl.text = joinLink
  m.qrCode.uri = m.baseUrl + "/api/qr?data=" + urlEncode(joinLink)
  count = m.room.players.Count()
  m.lobbyStatus.text = count.toStr() + " player" + plural(count) + " connected • LIVE"
  m.playerList.text = playerSummary()
  if count > 0
    m.startButton.color = "0xF97316FF"
    m.startLabel.color = "0xFFFFFFFF"
    m.startLabel.text = "START GAME"
  else
    m.startButton.color = "0x18382DFF"
    m.startLabel.color = "0x91A9A0FF"
    m.startLabel.text = "WAITING FOR A PLAYER"
  end if
end sub

function playerSummary() as string
  names = ""
  if m.room <> invalid and m.room.players <> invalid
    for each player in m.room.players
      if names <> "" then names += "    •    "
      names += "✓ " + player.name
    end for
  end if
  if names = "" then names = "Scan the QR code to join"
  return names
end function

sub renderGame()
  if m.room = invalid or m.room.game = invalid
    m.turnLabel.text = "DEALING CARDS..."
    m.gameMessage.text = "Waiting for live state"
    return
  end if
  game = m.room.game
  turnName = "WAITING"
  list = ""
  for each player in game.players
    if player.id = game.turnPlayerId then turnName = player.name
    if list <> "" then list += "    •    "
    list += player.name + ": " + player.cardCount.toStr()
  end for
  m.turnLabel.text = turnName.toUpper() + "'S TURN"
  m.gameMessage.text = game.message
  m.deckCount.text = game.deckCount.toStr() + " LEFT"
  m.discardText.text = game.topCard.value
  m.discardCard.color = cardColor(game.topCard.color)
  m.activeColor.text = "ACTIVE: " + game.activeColor
  m.activeColor.color = textColor(game.activeColor)
  m.gamePlayers.text = list
  if m.room.status = "finished" then m.turnLabel.text = "TRAIL COMPLETE"
end sub

function urlEncode(value as string) as string
  encoded = value.Replace("%", "%25")
  encoded = encoded.Replace(":", "%3A").Replace("/", "%2F").Replace("?", "%3F")
  encoded = encoded.Replace("=", "%3D").Replace("&", "%26").Replace(" ", "%20")
  return encoded
end function

function plural(count as integer) as string
  if count = 1 then return ""
  return "s"
end function

function cardColor(color as string) as string
  if color = "RED" then return "0xC62828FF"
  if color = "GREEN" then return "0x2E7D32FF"
  if color = "BLUE" then return "0x1565C0FF"
  if color = "GOLD" then return "0xF9A825FF"
  return "0x512DA8FF"
end function

function textColor(color as string) as string
  if color = "RED" then return "0xFF8A80FF"
  if color = "GREEN" then return "0x69F0AEFF"
  if color = "BLUE" then return "0x82B1FFFF"
  if color = "GOLD" then return "0xFFD180FF"
  return "0xFFFFFFFF"
end function

sub showError(message as string)
  m.pollTimer.control = "stop"
  m.busy = false
  m.startPending = false
  m.errorText.text = message + chr(10) + chr(10) + "Confirm the Mac room server is running and both devices are on the same Wi-Fi."
  transitionTo("error")
end sub
