sub init()
  m.baseUrl = "http://192.168.1.127:8080"
  m.mode = "home"
  m.room = invalid
  m.requestBusy = false
  m.activeRequestTask = invalid
  m.startPending = false

  ids = ["homeGroup","loadingGroup","lobbyGroup","gameGroup","errorGroup","loadingMessage","joinUrl","qrCode","startSignal","roomCode","lobbyStatus","playerList","startButton","startLabel","turnLabel","gameMessage","deckCount","discardCard","discardText","activeColor","gamePlayers","errorText","pollTimer","createTask"]
  for each id in ids
    m[id] = m.top.findNode(id)
  end for

  m.joinUrl.text = m.baseUrl
  m.pollTimer.observeField("fire", "pollRoom")
  m.createTask.observeField("complete", "onCreateComplete")
  m.top.setFocus(true)
end sub

function onKeyEvent(key as string, press as boolean) as boolean
  if not press then return false
  if m.mode = "home"
    if key = "OK" then createRoom()
    return true
  else if m.mode = "error"
    if key = "OK" then createRoom()
    if key = "back" then showHome()
    return true
  else if m.mode = "lobby"
    if key = "OK" and not m.startPending and m.room <> invalid and m.room.players.Count() > 0 then startGame()
    if key = "back" then showHome()
    return true
  else if m.mode = "game"
    if key = "back" then showHome()
    return true
  end if
  return true
end function

sub showOnly(name as string)
  m.homeGroup.visible = name = "home"
  m.loadingGroup.visible = name = "loading"
  m.lobbyGroup.visible = name = "lobby"
  m.gameGroup.visible = name = "game"
  m.errorGroup.visible = name = "error"
  m.mode = name
end sub

sub showHome()
  m.pollTimer.control = "stop"
  m.requestBusy = false
  m.startPending = false
  m.room = invalid
  m.startSignal.uri = ""
  stopTask(m.createTask)
  destroyRequestTask()
  showOnly("home")
end sub

sub createRoom()
  m.pollTimer.control = "stop"
  m.requestBusy = false
  m.startPending = false
  m.startSignal.uri = ""
  destroyRequestTask()
  showOnly("loading")
  m.loadingMessage.text = "Creating a live room on " + m.baseUrl
  runTask(m.createTask, "POST", m.baseUrl + "/api/rooms", "{}")
end sub

sub startGame()
  if m.room = invalid or m.startPending then return

  m.startPending = true
  showOnly("game")
  m.turnLabel.text = "DEALING CARDS..."
  m.gameMessage.text = "Waiting for live table"
  m.deckCount.text = "-- LEFT"
  m.discardText.text = "..."
  m.activeColor.text = "CONNECTING"
  m.gamePlayers.text = playerSummary()

  ' Fire-and-forget start signal. Poster loading runs independently from the
  ' room polling Task, so the TV never waits on a start callback.
  stamp = CreateObject("roDateTime").AsSeconds().toStr()
  m.startSignal.uri = m.baseUrl + "/api/rooms/" + m.room.code + "/start-signal.png?t=" + stamp

  ' Keep the same polling path that already detects players in the lobby.
  m.pollTimer.control = "start"
  if not m.requestBusy then pollRoom()
end sub

sub pollRoom()
  if m.room = invalid or m.requestBusy then return
  requestRoom()
end sub

sub requestRoom()
  if m.room = invalid or m.requestBusy then return

  m.requestBusy = true
  destroyRequestTask()

  task = m.top.createChild("RoomRequestTask")
  m.activeRequestTask = task
  task.observeField("result", "onRequestResult")
  task.method = "GET"

  stamp = CreateObject("roDateTime").AsSeconds().toStr()
  task.url = m.baseUrl + "/api/rooms/" + m.room.code + "?v=" + stamp
  task.payload = ""
  task.control = "RUN"
end sub

sub destroyRequestTask()
  if m.activeRequestTask <> invalid
    m.activeRequestTask.unobserveField("result")
    m.activeRequestTask.control = "STOP"
    m.top.removeChild(m.activeRequestTask)
    m.activeRequestTask = invalid
  end if
end sub

sub stopTask(task as object)
  if task <> invalid then task.control = "STOP"
end sub

sub runTask(task as object, method as string, url as string, payload as string)
  task.control = "STOP"
  task.method = method
  task.url = url
  task.payload = payload
  task.complete = false
  task.control = "RUN"
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
  showOnly("lobby")
  renderLobby()
  m.pollTimer.control = "start"
  pollRoom()
end sub

sub onRequestResult()
  task = m.activeRequestTask
  if task = invalid then return

  response = task.result
  if response = invalid or response = "" then return

  requestError = task.error
  m.requestBusy = false
  destroyRequestTask()

  if requestError <> ""
    if m.startPending
      m.gameMessage.text = "Reconnecting to live table..."
      return
    end if
    showError(requestError)
    return
  end if

  data = ParseJson(response)
  if data = invalid
    if m.startPending
      m.gameMessage.text = "Waiting for live table..."
      return
    end if
    showError("The room server returned an unreadable response.")
    return
  end if

  m.room = data
  if m.room.status = "lobby"
    if m.startPending
      m.gameMessage.text = "Dealing cards on server..."
    else
      showOnly("lobby")
      renderLobby()
    end if
  else
    m.startPending = false
    showOnly("game")
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
  m.pollTimer.control = "stop"
  m.requestBusy = false
  m.startPending = false
  destroyRequestTask()
  m.errorText.text = message + chr(10) + chr(10) + "Confirm the Mac room server is running and both devices are on the same Wi-Fi."
  showOnly("error")
end sub
