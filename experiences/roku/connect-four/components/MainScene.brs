sub init()
  m.baseUrl = "http://192.168.1.127:8070"
  m.roomCode = ""
  m.state = "lobby"
  m.busy = false
  m.requestId = 0
  m.playerColors = ["orange", "gold"]
  m.lobbySelection = 0
  m.modeSelection = 0
  m.gameMode = "human"
  m.difficulty = "easy"

  m.productTitle = m.top.findNode("productTitle")
  m.lobbyGroup = m.top.findNode("lobbyGroup")
  m.lobbyMessage = m.top.findNode("lobbyMessage")
  m.connectCard = m.top.findNode("connectCard")
  m.colorClashCard = m.top.findNode("colorClashCard")
  m.triviaCard = m.top.findNode("triviaCard")
  m.connectPrompt = m.top.findNode("connectPrompt")
  m.colorClashPrompt = m.top.findNode("colorClashPrompt")
  m.triviaPrompt = m.top.findNode("triviaPrompt")
  m.modeGroup = m.top.findNode("modeGroup")
  m.modeMessage = m.top.findNode("modeMessage")
  m.modeHuman = m.top.findNode("modeHuman")
  m.modeEasy = m.top.findNode("modeEasy")
  m.modeMedium = m.top.findNode("modeMedium")
  m.modeHard = m.top.findNode("modeHard")
  m.statusCard = m.top.findNode("statusCard")
  m.statusAccent = m.top.findNode("statusAccent")
  m.title = m.top.findNode("title")
  m.subtitle = m.top.findNode("subtitle")
  m.boardGroup = m.top.findNode("boardGroup")
  m.footerCard = m.top.findNode("footerCard")
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
  showLobby()
  m.top.setFocus(true)
end sub

function onKeyEvent(key as string, press as boolean) as boolean
  if not press then return false
  if m.state = "lobby"
    if key = "left"
      m.lobbySelection -= 1
      if m.lobbySelection < 0 then m.lobbySelection = 2
      updateLobbySelection()
    else if key = "right"
      m.lobbySelection += 1
      if m.lobbySelection > 2 then m.lobbySelection = 0
      updateLobbySelection()
    else if key = "OK"
      if m.lobbySelection = 0 then showModeSelect() else m.lobbyMessage.text = "COMING SOON  •  Choose Connect Four to play now"
    else
      return false
    end if
    return true
  end if

  if m.state = "mode"
    if key = "up"
      m.modeSelection -= 1
      if m.modeSelection < 0 then m.modeSelection = 3
      updateModeSelection()
    else if key = "down"
      m.modeSelection += 1
      if m.modeSelection > 3 then m.modeSelection = 0
      updateModeSelection()
    else if key = "OK"
      if m.modeSelection = 0
        m.gameMode = "human"
        m.difficulty = "easy"
      else
        m.gameMode = "bot"
        if m.modeSelection = 1 then m.difficulty = "easy"
        if m.modeSelection = 2 then m.difficulty = "medium"
        if m.modeSelection = 3 then m.difficulty = "hard"
      end if
      createRoom()
    else if key = "back"
      showLobby()
    else
      return false
    end if
    return true
  end if

  if key = "OK"
    if m.state = "error"
      createRoom()
    else if m.state = "finished" and m.roomCode <> ""
      restartGame()
    end if
    return true
  else if key = "back"
    showLobby()
    return true
  end if
  return false
end function

sub showLobby()
  m.pollTimer.control = "stop"
  m.roomCode = ""
  m.state = "lobby"
  m.busy = false
  m.playerColors = ["orange", "gold"]
  m.productTitle.text = "TN GAME CONNECT"
  m.lobbyGroup.visible = true
  m.modeGroup.visible = false
  showGameChrome(false)
  resetPlayerCards()
  clearBoard()
  updateLobbySelection()
end sub

sub showModeSelect()
  m.state = "mode"
  m.productTitle.text = "CONNECT FOUR"
  m.lobbyGroup.visible = false
  m.modeGroup.visible = true
  showGameChrome(false)
  m.modeSelection = 0
  updateModeSelection()
end sub

sub updateLobbySelection()
  m.connectCard.uri = "pkg:/images/lobby-card.png"
  m.colorClashCard.uri = "pkg:/images/lobby-card.png"
  m.triviaCard.uri = "pkg:/images/lobby-card.png"
  m.connectPrompt.text = "AVAILABLE"
  m.colorClashPrompt.text = "COMING SOON"
  m.triviaPrompt.text = "COMING SOON"
  if m.lobbySelection = 0
    m.connectCard.uri = "pkg:/images/lobby-card-selected.png"
    m.connectPrompt.text = "PRESS OK TO PLAY"
    m.lobbyMessage.text = "Use LEFT and RIGHT to choose a game"
  else if m.lobbySelection = 1
    m.colorClashCard.uri = "pkg:/images/lobby-card-selected.png"
    m.lobbyMessage.text = "Color Clash is the next card game planned"
  else
    m.triviaCard.uri = "pkg:/images/lobby-card-selected.png"
    m.lobbyMessage.text = "TN Trivia will support teams and local questions"
  end if
end sub

sub updateModeSelection()
  cards = [m.modeHuman, m.modeEasy, m.modeMedium, m.modeHard]
  for i = 0 to 3
    cards[i].uri = "pkg:/images/mode-card.png"
  end for
  cards[m.modeSelection].uri = "pkg:/images/mode-card-selected.png"
  if m.modeSelection = 0
    m.modeMessage.text = "Two phones compete head-to-head"
  else if m.modeSelection = 1
    m.modeMessage.text = "Easy bot makes relaxed, random moves"
  else if m.modeSelection = 2
    m.modeMessage.text = "Medium bot blocks wins and favors strong columns"
  else
    m.modeMessage.text = "Hard bot plans several moves ahead"
  end if
end sub

sub showGameChrome(visible as boolean)
  m.statusCard.visible = visible
  m.statusAccent.visible = visible
  m.title.visible = visible
  m.subtitle.visible = visible
  m.footerCard.visible = visible
  m.roomLabel.visible = visible
  m.playersLabel.visible = visible
  if not visible then m.boardGroup.visible = false
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
  m.productTitle.text = "CONNECT FOUR"
  m.lobbyGroup.visible = false
  m.modeGroup.visible = false
  showGameChrome(true)
  m.title.text = "CREATING ROOM..."
  if m.gameMode = "bot" then m.subtitle.text = "Preparing " + UCase(m.difficulty) + " bot mode" else m.subtitle.text = "Preparing two-player mode"
  m.roomLabel.text = ""
  m.playersLabel.text = ""
  m.boardGroup.visible = false
  url = m.baseUrl + "/api/rooms/create?mode=" + m.gameMode
  if m.gameMode = "bot" then url += "&difficulty=" + m.difficulty
  sendRequest("create", "GET", url, "")
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
  if kind = "tv" or kind = "restart" then applyTvState(data)
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
    if currentPlayerId <> invalid and p2.id = currentPlayerId
      if p2.isBot = true then m.playerTwoTurn.text = "BOT THINKING" else m.playerTwoTurn.text = "YOUR TURN"
    else
      m.playerTwoTurn.text = ""
    end if
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
