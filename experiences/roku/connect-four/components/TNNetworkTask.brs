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
  transfer.AddHeader("Cache-Control", "no-store")
  transfer.AddHeader("Connection", "close")
  if not transfer.AsyncGetToString()
    m.top.statusCode = -1
    m.top.failureReason = "REQUEST_START_FAILED"
    m.top.body = ""
    m.top.responseId = startedId
    return
  end if
  event = wait(10000, port)
  if event = invalid
    transfer.AsyncCancel()
    m.top.statusCode = -2
    m.top.failureReason = "REQUEST_TIMEOUT"
    m.top.body = ""
    m.top.responseId = startedId
    return
  end if
  if type(event) <> "roUrlEvent"
    m.top.statusCode = -3
    m.top.failureReason = "UNEXPECTED_EVENT"
    m.top.body = ""
    m.top.responseId = startedId
    return
  end if
  body = event.GetString()
  if body = invalid then body = ""
  reason = event.GetFailureReason()
  if reason = invalid then reason = ""
  m.top.statusCode = event.GetResponseCode()
  m.top.failureReason = reason
  m.top.body = body
  m.top.responseId = startedId
end sub
