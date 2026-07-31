sub init()
  m.baseUrl = "http://192.168.1.127:8090"
  m.connection = m.top.findNode("connection")
  m.counter = m.top.findNode("counter")
  m.metrics = m.top.findNode("metrics")
  m.message = m.top.findNode("message")
  m.raw = m.top.findNode("raw")
  m.taskA = m.top.findNode("taskA")
  m.taskB = m.top.findNode("taskB")
  m.timer = m.top.findNode("timer")
  m.requests = 0
  m.responses = 0
  m.busy = false
  m.index = 0
  m.sequence = 0
  m.taskA.observeField("complete", "onA")
  m.taskB.observeField("complete", "onB")
  m.timer.observeField("fire", "poll")
  m.timer.control = "start"
  poll()
end sub

sub poll()
  if m.busy then return
  m.busy = true
  m.requests += 1
  m.sequence += 1

  if m.index = 0
    task = m.taskA
    m.index = 1
  else
    task = m.taskB
    m.index = 0
  end if

  task.control = "STOP"
  task.complete = false
  task.status = 0
  task.result = ""
  task.elapsedMs = 0
  task.url = m.baseUrl + "/api/test/state?v=" + m.sequence.toStr()
  task.control = "RUN"

  m.connection.text = "REQUESTING..."
  m.connection.color = "0xFFFFFFFF"
  m.message.text = "GET /api/test/state"
  renderMetrics(0)
end sub

sub onA()
  handle(m.taskA)
end sub

sub onB()
  handle(m.taskB)
end sub

sub handle(task as object)
  if not task.complete then return
  m.busy = false

  if task.status < 200 or task.status >= 300 or task.result = ""
    m.connection.text = "FAILED • STATUS " + task.status.toStr()
    m.connection.color = "0xFF6B6BFF"
    m.message.text = task.result
    m.raw.text = task.result
    renderMetrics(task.elapsedMs)
    return
  end if

  data = ParseJson(task.result)
  if data = invalid
    m.connection.text = "CONNECTED • JSON ERROR"
    m.connection.color = "0xFFD166FF"
    m.message.text = "Response arrived but could not be parsed"
    m.raw.text = task.result
    renderMetrics(task.elapsedMs)
    return
  end if

  m.responses += 1
  m.connection.text = "CONNECTED • LIVE"
  m.connection.color = "0x69F0AEFF"
  m.counter.text = data.counter.toStr()
  m.message.text = "Version " + data.version.toStr() + " • " + data.message + " • " + data.color.toUpper()
  m.raw.text = task.result
  renderMetrics(task.elapsedMs)
end sub

sub renderMetrics(ms as integer)
  m.metrics.text = "Requests: " + m.requests.toStr() + "    Responses: " + m.responses.toStr() + "    Latency: " + ms.toStr() + " ms"
end sub
