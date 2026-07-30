sub init()
  m.top.functionName = "pollLoop"
end sub

sub pollLoop()
  while true
    if not m.top.active or m.top.roomUrl = ""
      sleep(100)
    else
      stamp = CreateObject("roDateTime").AsSeconds().toStr()
      transfer = CreateObject("roUrlTransfer")
      transfer.SetUrl(m.top.roomUrl + "?v=" + stamp)
      transfer.AddHeader("Content-Type", "application/json")

      response = transfer.GetToString()
      code = transfer.GetResponseCode()
      m.top.statusCode = code

      if code >= 200 and code < 300 and response <> invalid and response <> ""
        m.top.error = ""
        m.top.result = response
      else
        if response = invalid or response = "" then response = "HTTP " + code.toStr()
        m.top.error = response
      end if

      sleep(1000)
    end if
  end while
end sub
