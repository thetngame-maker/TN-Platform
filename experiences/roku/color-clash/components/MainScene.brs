sub init()
    m.botCount = 2
    m.difficulties = ["Easy", "Normal"]
    m.difficultyIndex = 1

    m.botLabel = m.top.findNode("botLabel")
    m.difficultyLabel = m.top.findNode("difficultyLabel")
    m.status = m.top.findNode("status")
    m.button = m.top.findNode("button")

    updateLabels()
end sub

sub updateLabels()
    m.botLabel.text = "Bots: " + m.botCount.toStr()
    m.difficultyLabel.text = "Difficulty: " + m.difficulties[m.difficultyIndex]
end sub

function onKeyEvent(key as string, press as boolean) as boolean
    if not press then return false

    if key = "left"
        if m.botCount > 1 then m.botCount = m.botCount - 1
        updateLabels()
        return true
    else if key = "right"
        if m.botCount < 3 then m.botCount = m.botCount + 1
        updateLabels()
        return true
    else if key = "up" or key = "down"
        m.difficultyIndex = 1 - m.difficultyIndex
        updateLabels()
        return true
    else if key = "OK"
        m.status.text = "Success! Remote input is working."
        m.button.color = "0xD97706FF"
        return true
    end if

    return false
end function
