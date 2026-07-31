sub init()
  m.top.functionName = "run"
end sub

sub run()
  m.top.complete = false
  started = CreateObject("roTimespan")
  transfer = CreateObject("roUrlTransfer")
  transfer.SetUrl(m.top.url)
  transfer.AddHeader("Accept", "application/json")
  transfer.AddHeader("Cache-Control", "no-cache")
  transfer.AddHeader("Connection", "close")
  body = transfer.GetToString()
  m.top.elapsedMs = started.TotalMilliseconds()
  m.top.status = transfer.GetResponseCode()
  m.top.result = body
  m.top.complete = true
end sub
