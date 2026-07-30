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
  sequence = 0
  updateId = 0

  while true
    sequence += 1
    port = CreateObject("roMessagePort")
    transfer = CreateObject("roUrlTransfer")
    transfer.SetMessagePort(port)
    transfer.SetUrl(roomUrl + "?v=" + sequence.toStr())
    transfer.AddHeader("Content-Type", "application/json")
    transfer.AddHeader("Cache-Control", "no-cache")

    started = transfer.AsyncGetToString()
    if not started
      m.top.error = "REQUEST_START_FAILED"
      m.top.networkState = "reconnecting"
    else
      event = wait(5000, port)

      if event = invalid
        transfer.AsyncCancel()
        m.top.error = "REQUEST_TIMEOUT"
        m.top.networkState = "reconnecting"
      else if type(event) <> "roUrlEvent"
        transfer.AsyncCancel()
        m.top.error = "UNEXPECTED_NETWORK_EVENT"
        m.top.networkState = "reconnecting"
      else
        code = event.GetResponseCode()
        response = event.GetString()
        m.top.statusCode = code

        if code >= 200 and code < 300 and response <> invalid and response <> ""
          m.top.error = ""
          m.top.snapshot = response
          updateId += 1
          m.top.updateId = updateId
          m.top.networkState = "connected"
        else
          if response = invalid or response = "" then response = "HTTP " + code.toStr()
          m.top.error = response
          m.top.networkState = "reconnecting"
        end if
      end if
    end if

    sleep(1000)
  end while
end sub
