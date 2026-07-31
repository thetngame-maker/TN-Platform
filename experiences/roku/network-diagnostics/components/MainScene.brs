sub init()
  m.url = "http://192.168.1.127:8090/"
  m.connection = m.top.findNode("connection")
  m.counter = m.top.findNode("counter")
  m.metrics = m.top.findNode("metrics")
  m.message = m.top.findNode("message")
  m.raw = m.top.findNode("raw")
  m.task = m.top.findNode("probeTask")

  m.task.observeField("complete", "onComplete")
  m.connection.text = "RUNNING ONE DIRECT GET..."
  m.message.text = m.url
  m.metrics.text = "One request • No timer • No polling"
  m.raw.text = "Waiting for GetToString()"

  m.task.url = m.url
  m.task.control = "RUN"
end sub

sub onComplete()
  if not m.task.complete then return

  m.metrics.text = "Status: " + m.task.status.toStr() + "    Latency: " + m.task.elapsedMs.toStr() + " ms"
  m.raw.text = m.task.result

  if m.task.status >= 200 and m.task.status < 400 and m.task.result <> ""
    m.connection.text = "DIRECT GET SUCCEEDED"
    m.connection.color = "0x69F0AEFF"
    m.counter.text = "OK"
    m.message.text = "The Roku reached the Mac server"
  else
    m.connection.text = "DIRECT GET FAILED"
    m.connection.color = "0xFF6B6BFF"
    m.counter.text = m.task.status.toStr()
    m.message.text = "Check the Roku debug console and server Terminal"
  end if
end sub
