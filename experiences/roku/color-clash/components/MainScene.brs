sub init()
    m.status = m.top.findNode("status")
    m.top.setFocus(true)
end sub

function onKeyEvent(key as string, press as boolean) as boolean
    if not press then return false

    if key = "OK"
        m.status.text = "Success! Remote input works."
        return true
    else if key = "back"
        return false
    end if

    return true
end function
