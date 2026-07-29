sub init()
  m.baseUrl = "http://192.168.1.127:8080"
  m.mode = "home"
  m.room = invalid
  m.pollBusy = false
  ids = ["homeGroup","loadingGroup","lobbyGroup","gameGroup","errorGroup","loadingMessage","joinUrl","roomCode","lobbyStatus","playerList","startButton","startLabel","turnLabel","gameMessage","deckCount","discardCard","discardText","activeColor","gamePlayers","errorText","pollTimer","createTask","pollTask","startTask"]
  for each id in ids
    m[id] = m.top.findNode(id)
  end for
  m.joinUrl.text = m.baseUrl
  m.pollTimer.observeField("fire", "pollRoom")
  m.createTask.observeField("complete", "onCreateComplete")
  m.pollTask.observeField("complete", "onPollComplete")
  m.startTask.observeField("complete", "onStartComplete")
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
    if key = "OK" and m.room <> invalid and m.room.players.Count() > 0 then startGame()
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
  m.pollBusy = false
  m.room = invalid
  stopTask(m.createTask)
  stopTask(m.pollTask)
  stopTask(m.startTask)
  showOnly("home")
end sub

sub createRoom()
  m.pollTimer.control = "stop"
  m.pollBusy = false
  showOnly("loading")
  m.loadingMessage.text = "Creating a live room on " + m.baseUrl
  runTask(m.createTask, "POST", m.baseUrl + "/api/rooms", "{}")
end sub

sub pollRoom()
  if m.room = invalid or m.pollBusy then return
  m.pollBusy = true
  runTask(m.pollTask, "GET", m.baseUrl + "/api/rooms/" + m.room.code, "")
end sub

sub startGame()
  m.pollTimer.control = "stop"
  m.pollBusy = false
  m.startLabel.text = "STARTING..."
  runTask(m.startTask, "POST", m.baseUrl + "/api/rooms/" + m.room.code + "/start", "{}")
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

sub onPollComplete()
  if not m.pollTask.complete then return
  m.pollBusy = false
  handleResult(m.pollTask, "poll")
end sub

sub onStartComplete()
  if not m.startTask.complete then return
  handleResult(m.startTask, "start")
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
  else if kind = "start"
    showOnly("game")
    renderGame()
    m.pollTimer.control = "start"
  else
    if m.room.status = "lobby"
      showOnly("lobby")
      renderLobby()
    else
      showOnly("game")
      renderGame()
    end if
  end if
end sub

sub renderLobby()
  m.roomCode.text = m.room.code
  count = m.room.players.Count()
  m.lobbyStatus.text = count.toStr() + " player" + plural(count) + " connected"
  names = ""
  for each player in m.room.players
    if names <> "" then names += "    •    "
    names += "✓ " + player.name
  end for
  if names = "" then names = "Waiting for phones to join..."
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
  m.pollBusy = false
  m.errorText.text = message + chr(10) + chr(10) + "Confirm the Mac room server is running and both devices are on the same Wi-Fi."
  showOnly("error")
end sub