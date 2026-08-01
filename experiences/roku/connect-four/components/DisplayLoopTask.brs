sub init()
  m.top.functionName = "run"
end sub

sub run()
  baseUrl = m.top.baseUrl
  mode = m.top.mode
  difficulty = m.top.difficulty
  if baseUrl = invalid or baseUrl = "" then
    publishError("SERVER URL MISSING", "The Roku channel has no server address.")
    return
  end if

  createUrl = baseUrl + "/api/rooms/create?mode=" + mode
  if mode = "bot" then createUrl += "&difficulty=" + difficulty

  createdText = getText(createUrl)
  if createdText = "" then
    publishError("COULD NOT REACH SERVER", "The room request returned no data.")
    return
  end if

  created = ParseJson(createdText)
  if created = invalid then
    publishError("BAD SERVER RESPONSE", "Room JSON could not be read.")
    return
  end if
  if created.code = invalid then
    publishError("ROOM CODE MISSING", createdText)
    return
  end if

  roomCode = created.code
  m.top.stateJson = FormatJson({
    screen: "lobby",
    code: roomCode,
    title: "JOIN ROOM " + roomCode,
    subtitle: "Loading hosted game display...",
    roomLabel: "ROOM " + roomCode,
    playersLabel: "Connected to TN Game server",
    players: []
  })

  while true
    clock = CreateObject("roDateTime")
    stateText = getText(baseUrl + "/api/rooms/" + roomCode + "/tv?t=" + clock.AsSeconds().toStr())
    if stateText <> "" then
      m.top.stateJson = stateText
    else
      publishError("RECONNECTING TO GAME SERVER", "Room " + roomCode + " is temporarily unavailable.")
    end if
    sleep(500)
  end while
end sub

function getText(url as string) as string
  transfer = CreateObject("roUrlTransfer")
  transfer.SetCertificatesFile("common:/certs/ca-bundle.crt")
  transfer.AddHeader("X-Roku-Reserved-Dev-Id", "")
  transfer.InitClientCertificates()
  transfer.EnableEncodings(true)
  transfer.SetUrl(url)
  transfer.AddHeader("Accept", "application/json")
  transfer.AddHeader("Cache-Control", "no-cache")
  transfer.SetMinimumTransferRate(1, 15)

  body = transfer.GetToString()
  if body = invalid then return ""
  return body
end function

sub publishError(message as string, detail as string)
  m.top.errorText = message
  m.top.stateJson = FormatJson({
    screen: "error",
    title: message,
    subtitle: detail,
    roomLabel: "",
    playersLabel: "Press BACK and try again."
  })
end sub
