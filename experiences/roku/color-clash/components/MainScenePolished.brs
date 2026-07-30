sub init()
  m.baseUrl = "http://192.168.1.127:8080"
  m.appState = "home"
  m.room = invalid
  m.startPending = false
  m.pollBusy = false
  m.pollIndex = 0
  m.pollSequence = 0

  ids = ["homeGroup","loadingGroup","lobbyGroup","gameGroup","errorGroup","loadingMessage","joinUrl","qrCode","startSignal","roomCode","lobbyStatus","playerList","startButton","startLabel","turnLabel","gameMessage","deckCount","discardCard","discardText","activeColor","gamePlayers","errorText","createTask","pollTaskA","pollTaskB","pollTimer"]
  for each id in ids
    m[id] = m.top.findNode(id)
  end for

  m.joinUrl.text = m.baseUrl
  m.createTask.observeField("complete", "onCreateComplete")
  m.pollTaskA.observeField("complete", "onPollAComplete")
  m.pollTaskB.observeField("complete", "onPollBComplete")
  m.pollTimer.observeField("fire", "onPollTimer")
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
  else if m.appState = "starting" or m.appState = "playing" or m.appState = "finished" or m.appState = "reconnecting"
    if key = "back" then transitionTo("home")
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
    stopPolling()
    m.room = invalid
    m.startPending = false
    m.startSignal.uri = ""
  end if
end sub

sub createRoom()
  stopPolling()
  m.room = invalid
  m.startPending = false
  m.startSignal.uri = ""
  transitionTo("creating")
  m.loadingMessage.text = "Creating a live room on " + m.baseUrl
  runCreateTask()
end sub

sub runCreateTask()
  m.createTask.control = "STOP"
  m.createTask.method = "POST"
  m.createTask.url = m.baseUrl + "/api/rooms"
  m.createTask.payload = "{}"
  m.createTask.complete = false
  m.createTask.result = ""
  m.createTask.error = ""
  m.createTask.statusCode = 0
  m.createTask.control = "RUN"
end sub

sub onCreateComplete()
  if not m.createTask.complete then return
  if m.createTask.error <> ""
    showError(m.createTask.error)
    return
  end if

  data = ParseJson(m.createTask.result)
  if data = invalid
    showError("The room server returned an unreadable response.")
    return
  end if

  m.room = data
  transitionTo("lobby")
  renderLobby()
  startPolling()
end sub

sub startPolling()
  if m.room = invalid then return
  stopPolling()
  m.pollBusy = false
  m.pollIndex = 0
  m.pollSequence = 0
  m.pollTimer.control = "start"
  runNextPoll()
end sub

sub stopPolling()
  if m.pollTimer <> invalid then m.pollTimer.control = "stop"
  if m.pollTaskA <> invalid then m.pollTaskA.control = "STOP"
  if m.pollTaskB <> invalid then m.pollTaskB.control = "STOP"
  m.pollBusy = false
end sub

sub onPollTimer()
  runNextPoll()
end sub

sub runNextPoll()
  if m.room = invalid or m.pollBusy then return

  m.pollBusy = true
  m.pollSequence += 1
  if m.pollIndex = 0
    task = m.pollTaskA
    m.pollIndex = 1
  else
    task = m.pollTaskB
    m.pollIndex = 0
  end if

  task.control = "STOP"
  task.method = "GET"
  task.url = m.baseUrl + "/api/rooms/" + m.room.code + "?v=" + m.pollSequence.toStr()
  task.payload = ""
  task.complete = false
  task.result = ""
  task.error = ""
  task.statusCode = 0
  task.control = "RUN"
end sub

sub onPollAComplete()
  handlePollComplete(m.pollTaskA)
end sub

sub onPollBComplete()
  handlePollComplete(m.pollTaskB)
end sub

sub handlePollComplete(task as object)
  if not task.complete then return
  m.pollBusy = false

  if task.error <> ""
    showReconnectState()
    return
  end if

  response = task.result
  if response = invalid or response = "" then return

  data = ParseJson(response)
  if data = invalid then return

  m.room = data
  applyRoomState()
end sub

sub showReconnectState()
  if m.appState = "lobby"
    m.lobbyStatus.text = "Reconnecting to room server..."
  else if m.appState = "starting" or m.appState = "playing" or m.appState = "reconnecting"
    transitionTo("reconnecting")
    m.gameMessage.text = "Reconnecting to live table..."
  end if
end sub

sub startGame()
  if m.room = invalid or m.startPending then return
  m.startPending = true
  transitionTo("starting")
  m.turnLabel.text = "DEALING CARDS..."
  m.gameMessage.text = "Waiting for the live table"
  m.deckCount.text = "-- LEFT"
  m.discardText.text = "..."
  m.activeColor.text = "CONNECTING"
  m.gamePlayers.text = playerSummary()

  stamp = CreateObject("roDateTime").AsSeconds().toStr()
  m.startSignal.uri = m.baseUrl + "/api/rooms/" + m.room.code + "/start-signal.png?t=" + stamp
end sub

sub applyRoomState()
  if m.room = invalid then return

  if m.room.status = "lobby"
    if m.startPending
      transitionTo("starting")
      m.gameMessage.text = "Dealing cards on server..."
    else
      transitionTo("lobby")
      renderLobby()
    end if
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
    m.gameMessage.text = "Waiting for the live table"
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
  encoded = value
  encoded = encoded.Replace("%", "%25")
  encoded = encoded.Replace(":", "%3A")
  encoded = encoded.Replace("/", "%2F")
  encoded = encoded.Replace("?", "%3F")
  encoded = encoded.Replace("=", "%3D")
  encoded = encoded.Replace("&", "%26")
  encoded = encoded.Replace(" ", "%20")
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
  stopPolling()
  m.startPending = false
  m.errorText.text = message + chr(10) + chr(10) + "Confirm the Mac room server is running and both devices are on the same Wi-Fi."
  transitionTo("error")
end sub
