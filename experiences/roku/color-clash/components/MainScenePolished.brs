sub init()
  m.baseUrl = "http://192.168.1.127:8080"
  m.mode = "home"
  m.room = invalid
  m.pollBusy = false
  m.activePollTask = invalid
  m.activeStartTask = invalid
  m.activeRecoveryTask = invalid
  m.startPending = false
  ids = ["homeGroup","loadingGroup","lobbyGroup","gameGroup","errorGroup","loadingMessage","joinUrl","qrCode","roomCode","lobbyStatus","playerList","startButton","startLabel","turnLabel","gameMessage","deckCount","discardCard","discardText","activeColor","gamePlayers","errorText","pollTimer","startRecoveryTimer","createTask"]
  for each id in ids
    m[id] = m.top.findNode(id)
  end for
  m.joinUrl.text = m.baseUrl
  m.pollTimer.observeField("fire", "pollRoom")
  m.startRecoveryTimer.observeField("fire", "recoverStartedRoom")
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
  m.startRecoveryTimer.control = "stop"
  m.pollBusy = false
  m.startPending = false
  m.room = invalid
  stopTask(m.createTask)
  destroyPollTask()
  destroyStartTask()
  destroyRecoveryTask()
  showOnly("home")
end sub

sub createRoom()
  m.pollTimer.control = "stop"
  m.startRecoveryTimer.control = "stop"
  m.pollBusy = false
  m.startPending = false
  destroyPollTask()
  destroyStartTask()
  destroyRecoveryTask()
  showOnly("loading")
  m.loadingMessage.text = "Creating a live room on " + m.baseUrl
  runTask(m.createTask, "POST", m.baseUrl + "/api/rooms", "{}")
end sub

sub pollRoom()
  if m.startPending then return
  if m.room = invalid or m.pollBusy then return
  m.pollBusy = true
  destroyPollTask()
  task = m.top.createChild("RoomRequestTask")
  m.activePollTask = task
  task.observeField("complete", "onDynamicPollComplete")
  task.method = "GET"
  task.url = m.baseUrl + "/api/rooms/" + m.room.code + "?v=" + m.room.version.toStr()
  task.payload = ""
  task.complete = false
  task.control = "RUN"
end sub

sub destroyPollTask()
  if m.activePollTask <> invalid
    m.activePollTask.unobserveField("complete")
    m.activePollTask.control = "STOP"
    m.top.removeChild(m.activePollTask)
    m.activePollTask = invalid
  end if
end sub

sub startGame()
  if m.activeStartTask <> invalid or m.startPending then return
  m.pollTimer.control = "stop"
  m.pollBusy = false
  destroyPollTask()
  m.startPending = true
  m.startLabel.text = "STARTING..."
  m.startButton.color = "0x8A3D0BFF"

  task = m.top.createChild("RoomRequestTask")
  m.activeStartTask = task
  task.observeField("complete", "onDynamicStartComplete")
  task.method = "GET"
  task.url = m.baseUrl + "/api/rooms/" + m.room.code + "/start?t=" + CreateObject("roDateTime").AsSeconds().toStr()
  task.payload = ""
  task.complete = false
  task.control = "RUN"

  m.startRecoveryTimer.control = "start"
  recoverStartedRoom()
end sub

sub recoverStartedRoom()
  if not m.startPending or m.room = invalid then return
  if m.activeRecoveryTask <> invalid then return
  task = m.top.createChild("RoomRequestTask")
  m.activeRecoveryTask = task
  task.observeField("complete", "onRecoveryComplete")
  task.method = "GET"
  task.url = m.baseUrl + "/api/rooms/" + m.room.code + "?recover=" + CreateObject("roDateTime").AsSeconds().toStr()
  task.payload = ""
  task.complete = false
  task.control = "RUN"
end sub

sub destroyStartTask()
  if m.activeStartTask <> invalid
    m.activeStartTask.unobserveField("complete")
    m.activeStartTask.control = "STOP"
    m.top.removeChild(m.activeStartTask)
    m.activeStartTask = invalid
  end if
end sub

sub destroyRecoveryTask()
  if m.activeRecoveryTask <> invalid
    m.activeRecoveryTask.unobserveField("complete")
    m.activeRecoveryTask.control = "STOP"
    m.top.removeChild(m.activeRecoveryTask)
    m.activeRecoveryTask = invalid
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
  handleResult(m.createTask, "create")
end sub

sub onDynamicPollComplete()
  task = m.activePollTask
  if task = invalid or not task.complete then return
  m.pollBusy = false
  handleResult(task, "poll")
  destroyPollTask()
end sub

sub onDynamicStartComplete()
  task = m.activeStartTask
  if task = invalid or not task.complete then return
  if task.error = ""
    data = ParseJson(task.result)
    if data <> invalid and data.status <> "lobby"
      m.room = data
      enterGameBoard()
      return
    end if
  end if
  destroyStartTask()
end sub

sub onRecoveryComplete()
  task = m.activeRecoveryTask
  if task = invalid or not task.complete then return
  if task.error = ""
    data = ParseJson(task.result)
    if data <> invalid
      m.room = data
      if m.room.status <> "lobby"
        destroyRecoveryTask()
        enterGameBoard()
        return
      end if
    end if
  end if
  destroyRecoveryTask()
end sub

sub enterGameBoard()
  m.startPending = false
  m.pollBusy = false
  m.startRecoveryTimer.control = "stop"
  destroyStartTask()
  destroyRecoveryTask()
  showOnly("game")
  renderGame()
  m.pollTimer.control = "start"
end sub

sub handleResult(task as object, kind as string)
  if task.error <> ""
    showError(task.error)
    return
  end if
  data = ParseJson(task.result)
  if data = invalid
    showError("The room server returned an unreadable response.")
    return
  end if
  m.room = data
  if kind = "create"
    showOnly("lobby")
    renderLobby()
    m.pollTimer.control = "start"
    pollRoom()
  else
    if m.room.status = "lobby"
      showOnly("lobby")
      renderLobby()
    else
      enterGameBoard()
    end if
  end if
end sub

sub renderLobby()
  m.roomCode.text = m.room.code
  joinLink = m.baseUrl + "/?room=" + m.room.code
  m.joinUrl.text = joinLink
  m.qrCode.uri = "https://api.qrserver.com/v1/create-qr-code/?size=420x420&margin=12&data=" + urlEncode(joinLink)
  count = m.room.players.Count()
  m.lobbyStatus.text = count.toStr() + " player" + plural(count) + " connected • LIVE"
  names = ""
  for each player in m.room.players
    if names <> "" then names += "    •    "
    names += "✓ " + player.name
  end for
  if names = "" then names = "Scan the QR code to join"
  m.playerList.text = names
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

sub renderGame()
  if m.room.game = invalid then return
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
  if m.room.status = "finished"
    m.turnLabel.text = "TRAIL COMPLETE"
  end if
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
  m.startRecoveryTimer.control = "stop"
  m.pollBusy = false
  m.startPending = false
  destroyPollTask()
  destroyStartTask()
  destroyRecoveryTask()
  m.errorText.text = message + chr(10) + chr(10) + "Confirm the Mac room server is running and both devices are on the same Wi-Fi."
  showOnly("error")
end sub