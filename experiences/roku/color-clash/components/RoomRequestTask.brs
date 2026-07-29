sub init()
  m.top.functionName = "execute"
end sub

sub failRequest(message as string)
  m.top.error = message
  m.top.result = "__ERROR__" + message
  m.top.complete = true
end sub

sub execute()
  m.top.complete = false
  m.top.result = ""
  m.top.error = ""
  m.top.statusCode = 0

  port = CreateObject("roMessagePort")
  transfer = CreateObject("roUrlTransfer")
  transfer.SetMessagePort(port)
  transfer.SetUrl(m.top.url)
  transfer.AddHeader("Content-Type", "application/json")
  transfer.EnableEncodings(true)

  started = false
  if m.top.method = "POST"
    started = transfer.AsyncPostFromString(m.top.payload)
  else
    started = transfer.AsyncGetToString()
  end if

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

  code = event.GetResponseCode()
  response = event.GetString()
  m.top.statusCode = code

  if code < 200 or code >= 300
    if response = invalid or response = "" then response = "HTTP " + code.toStr()
    failRequest(response)
    return
  end if

  m.top.result = response
  m.top.complete = true
end sub
