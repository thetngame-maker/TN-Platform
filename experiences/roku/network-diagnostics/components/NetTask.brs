sub init()
  m.top.functionName = "run"
end sub

sub run()
  m.top.complete = false
  m.top.status = 0
  m.top.result = ""
  m.top.elapsedMs = 0

  started = CreateObject("roTimespan")
  transfer = CreateObject("roUrlTransfer")
  transfer.SetUrl(m.top.url)

  print "SYNC PROBE GET: "; m.top.url
  body = transfer.GetToString()
  status = transfer.GetResponseCode()

  if body = invalid then body = ""
  print "SYNC PROBE STATUS: "; status
  print "SYNC PROBE BODY: "; body

  m.top.elapsedMs = started.TotalMilliseconds()
  m.top.status = status
  m.top.result = body
  m.top.complete = true
end sub
