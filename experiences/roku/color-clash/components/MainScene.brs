sub init()
    m.background = m.top.findNode("background")
    m.status = m.top.findNode("status")
    m.top.setFocus(true)
end sub

function onKeyEvent(key as string, press as boolean) as boolean
    if not press then return false

    if key = "OK"
        setDiagnosticState("0x2E7D32FF", "OK RECEIVED - GREEN")
        return true
    else if key = "left"
        setDiagnosticState("0x1565C0FF", "LEFT RECEIVED - BLUE")
        return true
    else if key = "right"
        setDiagnosticState("0x6A1B9AFF", "RIGHT RECEIVED - PURPLE")
        return true
    else if key = "up"
        setDiagnosticState("0xEF6C00FF", "UP RECEIVED - ORANGE")
        return true
    else if key = "down"
        setDiagnosticState("0x00695CFF", "DOWN RECEIVED - TEAL")
        return true
    else if key = "back"
        return false
    end if

    return false
end function

sub setDiagnosticState(color as string, message as string)
    m.background.color = color
    m.status.text = message
end sub
