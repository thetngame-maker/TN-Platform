sub init()
  m.top.functionName = "run"
end sub

sub run()
  baseUrl = m.top.baseUrl
  mode = m.top.mode
  difficulty = m.top.difficulty
  if baseUrl = invalid or baseUrl = "" then
    publishError("SERVER URL MISSING")
    return
  end if

  createUrl = baseUrl + "/api/rooms/create?mode=" + mode
  if mode = "bot" then createUrl += "&difficulty=" + difficulty

  created = getJson(createUrl)
  if created = invalid or created.code = invalid then
    publishError("COULD NOT CREATE ROOM")
    return
  end if

  roomCode = created.code
  while true
    stateText = getText(baseUrl + "/api/rooms/" + roomCode + "/tv?t=" + CreateObject("roDateTime").AsSeconds().toStr())
    if stateText <> "" then
      m.top.stateJson = stateText
    else
      publishError("RECONNECTING TO GAME SERVER")
    end if
    sleep(500)
  end while
end sub

function getJson(url as string) as dynamic
  body = getText(url)
  if body = "" then return invalid
  return ParseJson(body)
end function

function getText(url as string) as string
  transfer = CreateObject("roUrlTransfer")
  transfer.EnableEncodings(true)
  transfer.InitClientCertificates()
  transfer.SetCertificatesFile("common:/certs/ca-bundle.crt")
  transfer.SetUrl(url)
  transfer.AddHeader("Accept", "application/json")
  transfer.AddHeader("Cache-Control", "no-store")
  transfer.SetConnectTimeout(10)
  transfer.SetMinimumTransferRate(1, 15)
  body = transfer.GetToString()
  if body = invalid then return ""
  return body
end function

sub publishError(message as string)
  m.top.errorText = message
  m.top.stateJson = FormatJson({
    screen: "error",
    title: message,
    subtitle: "The TV will retry when you start a new game.",
    roomLabel: "",
    playersLabel: ""
  })
end sub
