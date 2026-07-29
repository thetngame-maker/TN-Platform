sub init()
  m.top.functionName = "execute"
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
    m.top.error = "REQUEST_START_FAILED"
    m.top.complete = true
    return
  end if

  event = wait(10000, port)
  if event = invalid
    transfer.AsyncCancel()
    m.top.error = "REQUEST_TIMEOUT"
    m.top.complete = true
    return
  end if

  if type(event) <> "roUrlEvent"
    m.top.error = "UNEXPECTED_NETWORK_EVENT"
    m.top.complete = true
    return
  end if

  code = event.GetResponseCode()
  response = event.GetString()
  m.top.statusCode = code

  if code < 200 or code >= 300
    if response = invalid or response = "" then response = "HTTP " + code.toStr()
    m.top.error = response
  else
    m.top.result = response
  end if

  m.top.complete = true
end sub
