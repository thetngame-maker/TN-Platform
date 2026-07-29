sub init()
    m.screenMode = "menu"
    m.selectedIndex = 0
    m.handOffset = 0
    m.currentPlayer = 0
    m.direction = 1
    m.gameOver = false

    m.menuGroup = m.top.findNode("menuGroup")
    m.gameGroup = m.top.findNode("gameGroup")
    m.winnerGroup = m.top.findNode("winnerGroup")
    m.winnerTitle = m.top.findNode("winnerTitle")
    m.winnerMessage = m.top.findNode("winnerMessage")
    m.turnLabel = m.top.findNode("turnLabel")
    m.messageLabel = m.top.findNode("messageLabel")
    m.discardCard = m.top.findNode("discardCard")
    m.discardText = m.top.findNode("discardText")
    m.selection = m.top.findNode("selection")
    m.playerCount = m.top.findNode("playerCount")
    m.topBotCount = m.top.findNode("topBotCount")
    m.leftBotCount = m.top.findNode("leftBotCount")
    m.rightBotCount = m.top.findNode("rightBotCount")
    m.deckCount = m.top.findNode("deckCount")
    m.botTimer = m.top.findNode("botTimer")
    m.botTimer.observeField("fire", "onBotTimer")

    m.cardNodes = []
    m.cardTextNodes = []
    for i = 0 to 9
        m.cardNodes.Push(m.top.findNode("card" + i.toStr()))
        m.cardTextNodes.Push(m.top.findNode("cardText" + i.toStr()))
    end for

    m.playerNames = ["YOU", "SCOUT BOT", "BEAR BOT", "FOX BOT"]
    m.top.setFocus(true)
end sub

function onKeyEvent(key as string, press as boolean) as boolean
    if not press then return false

    if m.screenMode = "menu"
        if key = "OK"
            startNewGame()
            return true
        end if
        return false
    end if

    if m.screenMode = "winner"
        if key = "OK"
            startNewGame()
            return true
        else if key = "back"
            showMenu()
            return true
        end if
        return true
    end if

    if key = "back"
        showMenu()
        return true
    end if

    if m.currentPlayer <> 0 or m.gameOver then return true

    playerHand = m.hands[0]
    if key = "left"
        if m.selectedIndex > 0 then m.selectedIndex = m.selectedIndex - 1
        renderPlayerHand()
        return true
    else if key = "right"
        if m.selectedIndex < playerHand.Count() - 1 then m.selectedIndex = m.selectedIndex + 1
        renderPlayerHand()
        return true
    else if key = "OK"
        tryPlayerCard()
        return true
    else if key = "down"
        playerDrawAndPass()
        return true
    end if

    return false
end function

sub startNewGame()
    m.screenMode = "game"
    m.gameOver = false
    m.menuGroup.visible = false
    m.winnerGroup.visible = false
    m.gameGroup.visible = true
    m.direction = 1
    m.currentPlayer = 0
    m.selectedIndex = 0
    m.handOffset = 0
    m.hands = [[], [], [], []]
    m.discardPile = []
    buildDeck()
    shuffleDeck()

    for round = 0 to 6
        for player = 0 to 3
            m.hands[player].Push(drawCard())
        end for
    end for

    topCard = drawCard()
    while topCard.value = "SKIP" or topCard.value = "REVERSE" or topCard.value = "+2"
        m.deck.Push(topCard)
        shuffleDeck()
        topCard = drawCard()
    end while
    m.discardPile.Push(topCard)

    m.messageLabel.text = "Match color or symbol"
    renderAll()
    beginTurn()
end sub

sub showMenu()
    m.botTimer.control = "stop"
    m.screenMode = "menu"
    m.gameOver = false
    m.winnerGroup.visible = false
    m.gameGroup.visible = false
    m.menuGroup.visible = true
end sub

sub buildDeck()
    m.deck = []
    colors = ["RED", "GREEN", "BLUE", "GOLD"]
    for each color in colors
        for number = 0 to 9
            m.deck.Push({ color: color, value: number.toStr() })
        end for
        m.deck.Push({ color: color, value: "SKIP" })
        m.deck.Push({ color: color, value: "REVERSE" })
        m.deck.Push({ color: color, value: "+2" })
    end for
end sub

sub shuffleDeck()
    if m.deck.Count() < 2 then return
    Randomize(0)
    for i = m.deck.Count() - 1 to 1 step -1
        j = Rnd(i + 1) - 1
        temp = m.deck[i]
        m.deck[i] = m.deck[j]
        m.deck[j] = temp
    end for
end sub

function drawCard() as object
    if m.deck.Count() = 0 then recycleDiscardPile()
    if m.deck.Count() = 0 then return { color: "RED", value: "0" }
    card = m.deck[m.deck.Count() - 1]
    m.deck.Delete(m.deck.Count() - 1)
    return card
end function

sub recycleDiscardPile()
    if m.discardPile.Count() <= 1 then return
    topCard = m.discardPile[m.discardPile.Count() - 1]
    m.discardPile.Delete(m.discardPile.Count() - 1)
    for each card in m.discardPile
        m.deck.Push(card)
    end for
    m.discardPile = [topCard]
    shuffleDeck()
end sub

function isLegal(card as object) as boolean
    topCard = m.discardPile[m.discardPile.Count() - 1]
    return card.color = topCard.color or card.value = topCard.value
end function

sub tryPlayerCard()
    hand = m.hands[0]
    if hand.Count() = 0 then return
    card = hand[m.selectedIndex]
    if not isLegal(card)
        m.messageLabel.text = "That card does not match"
        return
    end if
    playCard(0, m.selectedIndex)
end sub

sub playerDrawAndPass()
    card = drawCard()
    m.hands[0].Push(card)
    m.messageLabel.text = "You drew " + card.value
    m.selectedIndex = m.hands[0].Count() - 1
    renderAll()
    m.currentPlayer = nextPlayer(0)
    beginTurn()
end sub

sub playCard(player as integer, cardIndex as integer)
    hand = m.hands[player]
    card = hand[cardIndex]
    hand.Delete(cardIndex)
    m.discardPile.Push(card)
    m.messageLabel.text = m.playerNames[player] + " played " + card.value

    if player = 0
        if m.selectedIndex >= hand.Count() then m.selectedIndex = hand.Count() - 1
        if m.selectedIndex < 0 then m.selectedIndex = 0
    end if

    renderAll()
    if hand.Count() = 0
        finishGame(player)
        return
    end if

    nextTurn = nextPlayer(player)

    if card.value = "REVERSE"
        m.direction = m.direction * -1
        nextTurn = nextPlayer(player)
        m.messageLabel.text = m.playerNames[player] + " reversed direction"
    else if card.value = "SKIP"
        skipped = nextTurn
        nextTurn = nextPlayer(skipped)
        m.messageLabel.text = m.playerNames[skipped] + " was skipped"
    else if card.value = "+2"
        victim = nextTurn
        m.hands[victim].Push(drawCard())
        m.hands[victim].Push(drawCard())
        nextTurn = nextPlayer(victim)
        m.messageLabel.text = m.playerNames[victim] + " drew 2"
        renderAll()
    end if

    m.currentPlayer = nextTurn
    beginTurn()
end sub

function nextPlayer(player as integer) as integer
    result = player + m.direction
    if result > 3 then result = 0
    if result < 0 then result = 3
    return result
end function

sub beginTurn()
    if m.gameOver then return
    renderAll()
    name = m.playerNames[m.currentPlayer]
    if m.currentPlayer = 0
        m.turnLabel.text = "YOUR TURN"
        m.turnLabel.color = "0xF4B942FF"
        m.messageLabel.text = "Play a match or press DOWN to draw"
        renderPlayerHand()
    else
        m.turnLabel.text = name + "'S TURN"
        m.turnLabel.color = "0xFFFFFFFF"
        m.botTimer.control = "start"
    end if
end sub

sub onBotTimer()
    if m.screenMode <> "game" or m.gameOver or m.currentPlayer = 0 then return
    player = m.currentPlayer
    hand = m.hands[player]
    legalIndex = -1

    for i = 0 to hand.Count() - 1
        if isLegal(hand[i])
            legalIndex = i
            exit for
        end if
    end for

    if legalIndex >= 0
        playCard(player, legalIndex)
        return
    end if

    drawn = drawCard()
    hand.Push(drawn)
    renderAll()
    if isLegal(drawn)
        m.messageLabel.text = m.playerNames[player] + " drew and played"
        playCard(player, hand.Count() - 1)
    else
        m.messageLabel.text = m.playerNames[player] + " drew a card"
        m.currentPlayer = nextPlayer(player)
        beginTurn()
    end if
end sub

sub finishGame(winner as integer)
    m.gameOver = true
    m.botTimer.control = "stop"
    m.screenMode = "winner"
    m.winnerGroup.visible = true
    if winner = 0
        m.winnerTitle.text = "YOU WIN!"
        m.winnerMessage.text = "You cleared your hand first."
    else
        m.winnerTitle.text = m.playerNames[winner] + " WINS"
        m.winnerMessage.text = "Press OK for a rematch."
    end if
end sub

sub renderAll()
    renderDiscard()
    renderCounts()
    renderPlayerHand()
end sub

sub renderDiscard()
    if m.discardPile.Count() = 0 then return
    card = m.discardPile[m.discardPile.Count() - 1]
    m.discardCard.color = colorHex(card.color)
    m.discardText.text = card.value
    m.discardText.color = textHex(card.color)
    m.deckCount.text = m.deck.Count().toStr() + " LEFT"
end sub

sub renderCounts()
    m.playerCount.text = "YOU • " + m.hands[0].Count().toStr() + " CARDS"
    m.topBotCount.text = m.hands[1].Count().toStr() + " CARDS"
    m.rightBotCount.text = m.hands[2].Count().toStr() + " CARDS"
    m.leftBotCount.text = m.hands[3].Count().toStr() + " CARDS"
end sub

sub renderPlayerHand()
    if m.hands = invalid then return
    hand = m.hands[0]
    count = hand.Count()

    if count = 0
        for i = 0 to 9
            m.cardNodes[i].visible = false
            m.cardTextNodes[i].visible = false
        end for
        m.selection.visible = false
        return
    end if

    if m.selectedIndex >= count then m.selectedIndex = count - 1
    if m.selectedIndex < 0 then m.selectedIndex = 0

    if m.selectedIndex < m.handOffset then m.handOffset = m.selectedIndex
    if m.selectedIndex > m.handOffset + 9 then m.handOffset = m.selectedIndex - 9
    maxOffset = count - 10
    if maxOffset < 0 then maxOffset = 0
    if m.handOffset > maxOffset then m.handOffset = maxOffset

    visibleCount = count - m.handOffset
    if visibleCount > 10 then visibleCount = 10
    startX = 235
    if visibleCount < 10 then startX = 960 - ((visibleCount * 145) / 2)

    for slot = 0 to 9
        cardNode = m.cardNodes[slot]
        textNode = m.cardTextNodes[slot]
        handIndex = m.handOffset + slot
        if handIndex < count
            card = hand[handIndex]
            x = startX + (slot * 145)
            cardNode.translation = [x, 760]
            textNode.translation = [x, 825]
            cardNode.color = colorHex(card.color)
            textNode.text = card.value
            textNode.color = textHex(card.color)
            cardNode.visible = true
            textNode.visible = true
        else
            cardNode.visible = false
            textNode.visible = false
        end if
    end for

    selectedSlot = m.selectedIndex - m.handOffset
    selectedX = startX + (selectedSlot * 145) - 5
    m.selection.translation = [selectedX, 755]
    m.selection.visible = m.currentPlayer = 0
end sub

function colorHex(colorName as string) as string
    if colorName = "RED" then return "0xC62828FF"
    if colorName = "GREEN" then return "0x2E7D32FF"
    if colorName = "BLUE" then return "0x1565C0FF"
    return "0xF9A825FF"
end function

function textHex(colorName as string) as string
    if colorName = "GOLD" then return "0x101820FF"
    return "0xFFFFFFFF"
end function