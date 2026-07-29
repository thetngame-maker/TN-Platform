sub init()
  m.top.functionName = "execute"
end sub

sub execute()
  m.top.error = ""
  transfer = CreateObject("roUrlTransfer")
  transfer.SetUrl(m.top.url)
  transfer.SetCertificatesFile("common:/certs/ca-bundle.crt")
  transfer.InitClientCertificates()
  transfer.AddHeader("Content-Type", "application/json")
  if m.top.method = "POST"
    response = transfer.PostFromString(m.top.payload)
  else
    response = transfer.GetToString()
  end if
  code = transfer.GetResponseCode()
  if code < 200 or code >= 300
    if response = invalid or response = "" then response = "HTTP " + code.toStr()
    m.top.error = response
    m.top.result = ""
  else
    m.top.result = response
  end if
end sub
