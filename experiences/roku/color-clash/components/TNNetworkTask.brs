sub init()
  m.top.functionName = "run"
end sub

sub finish(code as integer, body as string, failure as string, started as object)
  m.top.statusCode = code
  m.top.body = body
  m.top.failureReason = failure
  m.top.elapsedMs = started.TotalMilliseconds()
  m.top.responseId = m.top.requestId
end sub

sub run()
  requestId = m.top.requestId
  method = m.top.method
  url = m.top.url
  payload = m.top.payload
  started = CreateObject("roTimespan")
  port = CreateObject("roMessagePort")
  transfer = CreateObject("roUrlTransfer")
  transfer.SetMessagePort(port)
  transfer.EnableEncodings(true)
  transfer.InitClientCertificates()
  transfer.SetUrl(url)
  transfer.AddHeader("Accept", "application/json")
  transfer.AddHeader("Content-Type", "application/json")
  transfer.AddHeader("Cache-Control", "no-store")
  transfer.AddHeader("Connection", "close")

  didStart = false
  if method = "POST"
    didStart = transfer.AsyncPostFromString(payload)
  else
    didStart = transfer.AsyncGetToString()
  end if

  if not didStart
    finish(-1, "", "REQUEST_START_FAILED", started)
    return
  end if

  event = wait(10000, port)
  if event = invalid
    transfer.AsyncCancel()
    finish(-2, "", "REQUEST_TIMEOUT", started)
    return
  end if

  if type(event) <> "roUrlEvent"
    transfer.AsyncCancel()
    finish(-3, "", "UNEXPECTED_EVENT: " + type(event), started)
    return
  end if

  response = event.GetString()
  if response = invalid then response = ""
  failure = event.GetFailureReason()
  if failure = invalid then failure = ""
  finish(event.GetResponseCode(), response, failure, started)
end sub
