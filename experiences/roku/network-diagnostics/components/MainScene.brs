sub init()
  m.url = "http://192.168.1.127:8090/"
  m.connection = m.top.findNode("connection")
  m.counter = m.top.findNode("counter")
  m.metrics = m.top.findNode("metrics")
  m.message = m.top.findNode("message")
  m.event = m.top.findNode("event")
  m.failure = m.top.findNode("failure")
  m.raw = m.top.findNode("raw")
  m.task = m.top.findNode("probeTask")
  m.countdown = m.top.findNode("countdown")
  m.seconds = 10

  m.task.observeField("complete", "onComplete")
  m.countdown.observeField("fire", "onTick")

  m.message.text = m.url
  m.task.url = m.url
  m.countdown.control = "start"
  m.task.control = "RUN"
end sub

sub onTick()
  if m.task.complete
    m.countdown.control = "stop"
    return
  end if

  m.seconds -= 1
  if m.seconds < 0 then m.seconds = 0
  m.counter.text = m.seconds.toStr()
end sub

sub onComplete()
  if not m.task.complete then return
  m.countdown.control = "stop"

  m.metrics.text = "Status: " + m.task.status.toStr() + "    Latency: " + m.task.elapsedMs.toStr() + " ms"
  m.event.text = "Event: " + m.task.eventType
  m.failure.text = "Failure: " + m.task.failureReason
  m.raw.text = "Body: " + m.task.result

  if m.task.status >= 200 and m.task.status < 400
    m.connection.text = "ASYNC GET SUCCEEDED"
    m.connection.color = "0x69F0AEFF"
    m.counter.text = "OK"
  else if m.task.status = -2
    m.connection.text = "NO NETWORK EVENT • TIMEOUT"
    m.connection.color = "0xFF6B6BFF"
    m.counter.text = "TIMEOUT"
  else
    m.connection.text = "ASYNC GET FAILED"
    m.connection.color = "0xFF6B6BFF"
    m.counter.text = m.task.status.toStr()
  end if
end sub
