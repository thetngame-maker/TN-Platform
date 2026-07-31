sub init()
  m.top.functionName = "run"
end sub

sub finish(status as integer, body as string, errorMessage as string, eventName as string, started as object)
  m.top.elapsedMs = started.TotalMilliseconds()
  m.top.status = status
  m.top.result = body
  m.top.error = errorMessage
  m.top.eventType = eventName
  m.top.complete = true
end sub

sub run()
  m.top.complete = false
  m.top.result = ""
  m.top.error = ""
  m.top.eventType = "STARTING"
  m.top.status = 0
  m.top.elapsedMs = 0

  started = CreateObject("roTimespan")
  port = CreateObject("roMessagePort")
  transfer = CreateObject("roUrlTransfer")
  transfer.SetMessagePort(port)
  transfer.SetUrl(m.top.url)
  transfer.AddHeader("Accept", "application/json")
  transfer.AddHeader("Cache-Control", "no-store, no-cache")
  transfer.AddHeader("Connection", "close")

  if not transfer.AsyncGetToString()
    finish(-1, "", "REQUEST_START_FAILED", "START_FAILED", started)
    return
  end if

  m.top.eventType = "WAITING_FOR_URL_EVENT"
  event = wait(5000, port)

  if event = invalid
    transfer.AsyncCancel()
    finish(-2, "", "REQUEST_TIMEOUT", "TIMEOUT", started)
    return
  end if

  eventName = type(event)
  if eventName <> "roUrlEvent"
    transfer.AsyncCancel()
    finish(-3, "", "UNEXPECTED_EVENT", eventName, started)
    return
  end if

  status = event.GetResponseCode()
  body = event.GetString()
  if body = invalid then body = ""

  if status < 200 or status >= 300
    message = body
    if message = "" then message = "HTTP_" + status.toStr()
    finish(status, body, message, "roUrlEvent", started)
    return
  end if

  if body = ""
    finish(status, "", "EMPTY_RESPONSE", "roUrlEvent", started)
    return
  end if

  finish(status, body, "", "roUrlEvent", started)
end sub
