sub init()
    m.screenMode = "menu"
    m.selectedIndex = 0
    m.playerTurn = true
    m.turnStep = 0

    m.menuGroup = m.top.findNode("menuGroup")
    m.gameGroup = m.top.findNode("gameGroup")
    m.turnLabel = m.top.findNode("turnLabel")
    m.messageLabel = m.top.findNode("messageLabel")
    m.discardCard = m.top.findNode("discardCard")
    m.discardText = m.top.findNode("discardText")
    m.selection = m.top.findNode("selection")
    m.botTimer = m.top.findNode("botTimer")
    m.botTimer.observeField("fire", "onBotTimer")

    m.cardColors = [
        "0xC62828FF",
        "0x2E7D32FF",
        "0x1565C0FF",
        "0xF9A825FF",
        "0xC62828FF",
        "0x1565C0FF",
        "0x2E7D32FF"
    ]
    m.cardValues = ["2", "5", "8", "1", "SKIP", "3", "7"]

    m.top.setFocus(true)
    updateSelection()
end sub

function onKeyEvent(key as string, press as boolean) as boolean
    if not press then return false

    if m.screenMode = "menu"
        if key = "OK"
            showGame()
            return true
        end if
        return false
    end if

    if key = "back"
        showMenu()
        return true
    end if

    if not m.playerTurn then return true

    if key = "left"
        if m.selectedIndex > 0 then m.selectedIndex = m.selectedIndex - 1
        updateSelection()
        return true
    else if key = "right"
        if m.selectedIndex < 6 then m.selectedIndex = m.selectedIndex + 1
        updateSelection()
        return true
    else if key = "OK"
        playSelectedCard()
        return true
    end if

    return false
end function

sub showGame()
    m.screenMode = "game"
    m.menuGroup.visible = false
    m.gameGroup.visible = true
    m.playerTurn = true
    m.turnLabel.text = "YOUR TURN"
    m.turnLabel.color = "0xF4B942FF"
    m.messageLabel.text = "Choose a card"
    updateSelection()
end sub

sub showMenu()
    m.screenMode = "menu"
    m.botTimer.control = "stop"
    m.gameGroup.visible = false
    m.menuGroup.visible = true
end sub

sub updateSelection()
    x = 338 + (m.selectedIndex * 180)
    m.selection.translation = [x, 778]
end sub

sub playSelectedCard()
    m.discardCard.color = m.cardColors[m.selectedIndex]
    m.discardText.text = m.cardValues[m.selectedIndex]
    if m.selectedIndex = 3
        m.discardText.color = "0x101820FF"
    else
        m.discardText.color = "0xFFFFFFFF"
    end if

    m.playerTurn = false
    m.turnStep = 0
    m.turnLabel.text = "SCOUT BOT'S TURN"
    m.turnLabel.color = "0xFFFFFFFF"
    m.messageLabel.text = "You played " + m.cardValues[m.selectedIndex]
    m.botTimer.control = "start"
end sub

sub onBotTimer()
    if m.screenMode <> "game" then return

    if m.turnStep = 0
        m.discardCard.color = "0x1565C0FF"
        m.discardText.text = "4"
        m.discardText.color = "0xFFFFFFFF"
        m.turnLabel.text = "FOX BOT'S TURN"
        m.messageLabel.text = "Scout played 4"
        m.turnStep = 1
        m.botTimer.control = "start"
    else if m.turnStep = 1
        m.discardCard.color = "0xF9A825FF"
        m.discardText.text = "6"
        m.discardText.color = "0x101820FF"
        m.turnLabel.text = "BEAR BOT'S TURN"
        m.messageLabel.text = "Fox played 6"
        m.turnStep = 2
        m.botTimer.control = "start"
    else if m.turnStep = 2
        m.discardCard.color = "0x2E7D32FF"
        m.discardText.text = "9"
        m.discardText.color = "0xFFFFFFFF"
        m.turnLabel.text = "YOUR TURN"
        m.turnLabel.color = "0xF4B942FF"
        m.messageLabel.text = "Bear played 9"
        m.playerTurn = true
        m.turnStep = 0
    end if
end sub
