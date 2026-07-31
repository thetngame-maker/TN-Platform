sub init()
  m.top.functionName = "run"
end sub

sub finish(status as integer, body as string, started as object)
  m.top.elapsedMs = started.TotalMilliseconds()
  m.top.status = status
  m.top.result = body
  m.top.complete = true
end sub

sub run()
  m.top.complete = false
  m.top.status = 0
  m.top.result = ""
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
    finish(-1, "REQUEST_START_FAILED", started)
    return
  end if

  event = wait(5000, port)
  if event = invalid
    transfer.AsyncCancel()
    finish(-2, "REQUEST_TIMEOUT", started)
    return
  end if

  if type(event) <> "roUrlEvent"
    transfer.AsyncCancel()
    finish(-3, "UNEXPECTED_EVENT: " + type(event), started)
    return
  end if

  body = event.GetString()
  if body = invalid then body = ""
  finish(event.GetResponseCode(), body, started)
end sub
