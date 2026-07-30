sub init()
  m.top.functionName = "runNetworkManager"
end sub

sub runNetworkManager()
  roomUrl = m.top.roomUrl
  if roomUrl = ""
    m.top.networkState = "error"
    m.top.error = "ROOM_URL_MISSING"
    return
  end if

  m.top.networkState = "connecting"

  while true
    stamp = CreateObject("roDateTime").AsSeconds().toStr()
    transfer = CreateObject("roUrlTransfer")
    transfer.SetUrl(roomUrl + "?v=" + stamp)
    transfer.AddHeader("Content-Type", "application/json")

    response = transfer.GetToString()
    code = transfer.GetResponseCode()
    m.top.statusCode = code

    if code >= 200 and code < 300 and response <> invalid and response <> ""
      m.top.error = ""
      m.top.networkState = "connected"
      m.top.snapshot = response
    else
      if response = invalid or response = "" then response = "HTTP " + code.toStr()
      m.top.error = response
      m.top.networkState = "reconnecting"
    end if

    sleep(1000)
  end while
end sub
