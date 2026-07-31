sub init()
  m.top.functionName = "run"
end sub

sub finish(status as integer, body as string, eventType as string, failureReason as string, started as object)
  m.top.elapsedMs = started.TotalMilliseconds()
  m.top.status = status
  m.top.result = body
  m.top.eventType = eventType
  m.top.failureReason = failureReason
  m.top.complete = true
end sub

sub run()
  m.top.complete = false
  m.top.status = 0
  m.top.result = ""
  m.top.eventType = "STARTING"
  m.top.failureReason = ""
  m.top.elapsedMs = 0

  started = CreateObject("roTimespan")
  port = CreateObject("roMessagePort")
  transfer = CreateObject("roUrlTransfer")
  transfer.SetMessagePort(port)
  transfer.EnableEncodings(true)
  transfer.InitClientCertificates()
  transfer.SetUrl(m.top.url)
  transfer.AddHeader("Accept", "*/*")
  transfer.AddHeader("Cache-Control", "no-store")
  transfer.AddHeader("Connection", "close")

  print "ASYNC PROBE URL: "; m.top.url
  didStart = transfer.AsyncGetToString()
  print "ASYNC PROBE STARTED: "; didStart

  if not didStart
    finish(-1, "AsyncGetToString returned false", "START_FAILED", "Request could not be started", started)
    return
  end if

  event = wait(10000, port)
  if event = invalid
    transfer.AsyncCancel()
    print "ASYNC PROBE TIMEOUT"
    finish(-2, "No roUrlEvent arrived within 10 seconds", "TIMEOUT", "No network event received", started)
    return
  end if

  eventType = type(event)
  print "ASYNC PROBE EVENT TYPE: "; eventType

  if eventType <> "roUrlEvent"
    transfer.AsyncCancel()
    finish(-3, "Unexpected event: " + eventType, eventType, "Unexpected message-port event", started)
    return
  end if

  status = event.GetResponseCode()
  body = event.GetString()
  if body = invalid then body = ""

  failureReason = event.GetFailureReason()
  if failureReason = invalid then failureReason = ""

  print "ASYNC PROBE STATUS: "; status
  print "ASYNC PROBE FAILURE: "; failureReason
  print "ASYNC PROBE BODY: "; body

  finish(status, body, eventType, failureReason, started)
end sub
