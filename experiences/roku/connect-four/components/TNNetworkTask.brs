sub init()
  m.top.functionName = "run"
end sub

sub run()
  startedId = m.top.requestId
  port = CreateObject("roMessagePort")
  transfer = CreateObject("roUrlTransfer")
  transfer.SetMessagePort(port)
  transfer.EnableEncodings(true)
  transfer.InitClientCertificates()
  transfer.SetUrl(m.top.url)
  transfer.AddHeader("Accept", "application/json")
  transfer.AddHeader("Content-Type", "application/json")
  transfer.AddHeader("Cache-Control", "no-store")
  transfer.AddHeader("Connection", "close")
  ok = false
  if m.top.method = "POST"
    ok = transfer.AsyncPostFromString(m.top.payload)
  else
    ok = transfer.AsyncGetToString()
  end if
  if not ok
    finish(startedId, -1, "", "REQUEST_START_FAILED")
    return
  end if
  event = wait(10000, port)
  if event = invalid
    transfer.AsyncCancel()
    finish(startedId, -2, "", "REQUEST_TIMEOUT")
    return
  end if
  if type(event) <> "roUrlEvent"
    finish(startedId, -3, "", "UNEXPECTED_EVENT")
    return
  end if
  response = event.GetString()
  if response = invalid then response = ""
  reason = event.GetFailureReason()
  if reason = invalid then reason = ""
  finish(startedId, event.GetResponseCode(), response, reason)
end sub

sub finish(id as integer, code as integer, body as string, reason as string)
  m.top.statusCode = code
  m.top.body = body
  m.top.failureReason = reason
  m.top.responseId = id
end sub
