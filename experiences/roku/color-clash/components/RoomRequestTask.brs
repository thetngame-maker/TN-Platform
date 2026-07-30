sub init()
  m.top.functionName = "execute"
end sub

sub failRequest(message as string)
  m.top.error = message
  m.top.result = "__ERROR__" + message
  m.top.complete = true
end sub

sub finishResponse(code as integer, response as dynamic)
  m.top.statusCode = code

  if response = invalid then response = ""
  if code < 200 or code >= 300
    if response = "" then response = "HTTP " + code.toStr()
    failRequest(response)
    return
  end if

  m.top.result = response
  m.top.complete = true
end sub

sub execute()
  m.top.complete = false
  m.top.result = ""
  m.top.error = ""
  m.top.statusCode = 0

  transfer = CreateObject("roUrlTransfer")
  transfer.SetUrl(m.top.url)
  transfer.AddHeader("Content-Type", "application/json")

  ' GET requests run synchronously inside this background Task thread. This
  ' avoids the missing roUrlEvent seen on the tested Roku after starting a game,
  ' while never blocking the SceneGraph UI thread.
  if m.top.method <> "POST"
    response = transfer.GetToString()
    code = transfer.GetResponseCode()
    finishResponse(code, response)
    return
  end if

  ' Room creation remains asynchronous because this path has already proven
  ' reliable on the device.
  port = CreateObject("roMessagePort")
  transfer.SetMessagePort(port)
  started = transfer.AsyncPostFromString(m.top.payload)

  if not started
    failRequest("REQUEST_START_FAILED")
    return
  end if

  event = wait(10000, port)
  if event = invalid
    transfer.AsyncCancel()
    failRequest("REQUEST_TIMEOUT")
    return
  end if

  if type(event) <> "roUrlEvent"
    failRequest("UNEXPECTED_NETWORK_EVENT")
    return
  end if

  finishResponse(event.GetResponseCode(), event.GetString())
end sub
