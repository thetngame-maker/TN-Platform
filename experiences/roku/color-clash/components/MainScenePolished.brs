sub init()
    m.screenMode = "menu"
    m.setupRow = 0
    m.botCount = 3
    m.difficulty = "NORMAL"
    m.effectsOn = true
    m.selectedIndex = 0
    m.handOffset = 0
    m.currentPlayer = 0
    m.direction = 1
    m.gameOver = false
    m.activeColor = "RED"
    m.wildChoiceIndex = 0
    m.cardsPlayed = 0
    m.cardsDrawn = 0
    m.turnCount = 0

    ids = ["menuGroup","setupGroup","gameGroup","wildGroup","pauseGroup","winnerGroup","actionGroup","botsRow","difficultyRow","effectsRow","botChoice","difficultyChoice","effectsChoice","pauseEffects","winnerTitle","winnerMessage","winnerStats","turnLabel","messageLabel","directionLabel","discardCard","discardText","activeColorLabel","selection","playerCount","topBotCount","leftBotCount","rightBotCount","topBotGroup","leftBotGroup","rightBotGroup","deckCount","wildColorCard","wildColorText","actionText","botTimer","bannerTimer"]
    for each id in ids
        m[id] = m.top.findNode(id)
    end for
    m.botTimer.observeField("fire", "onBotTimer")
    m.bannerTimer.observeField("fire", "hideActionBanner")

    m.cardNodes = []
    m.cardTextNodes = []
    for i = 0 to 9
        m.cardNodes.Push(m.top.findNode("card" + i.toStr()))
        m.cardTextNodes.Push(m.top.findNode("cardText" + i.toStr()))
    end for

    m.playerNames = ["YOU", "SCOUT BOT", "BEAR BOT", "FOX BOT"]
    m.wildColors = ["RED", "GREEN", "BLUE", "GOLD"]
    seed = Rnd(0)
    updateSetupUI()
    m.top.setFocus(true)
end sub

function onKeyEvent(key as string, press as boolean) as boolean
    if not press then return false

    if m.screenMode = "menu"
        if key = "OK"
            m.menuGroup.visible = false
            m.setupGroup.visible = true
            m.screenMode = "setup"
            updateSetupUI()
        end if
        return true
    end if

    if m.screenMode = "setup"
        if key = "back"
            showMenu()
        else if key = "up"
            m.setupRow = m.setupRow - 1
            if m.setupRow < 0 then m.setupRow = 2
            updateSetupUI()
        else if key = "down"
            m.setupRow = m.setupRow + 1
            if m.setupRow > 2 then m.setupRow = 0
            updateSetupUI()
        else if key = "left"
            changeSetup(-1)
        else if key = "right"
            changeSetup(1)
        else if key = "OK"
            startNewGame()
        end if
        return true
    end if

    if m.screenMode = "wild"
        if key = "left"
            m.wildChoiceIndex = m.wildChoiceIndex - 1
            if m.wildChoiceIndex < 0 then m.wildChoiceIndex = 3
            updateWildChoice()
        else if key = "right"
            m.wildChoiceIndex = m.wildChoiceIndex + 1
            if m.wildChoiceIndex > 3 then m.wildChoiceIndex = 0
            updateWildChoice()
        else if key = "OK"
            confirmPlayerWild()
        end if
        return true
    end if

    if m.screenMode = "pause"
        if key = "left" or key = "right"
            m.effectsOn = not m.effectsOn
            updateEffectsLabels()
        else if key = "OK" or key = "play"
            resumeGame()
        else if key = "back"
            showMenu()
        end if
        return true
    end if

    if m.screenMode = "winner"
        if key = "OK" then startNewGame()
        if key = "back" then showMenu()
        return true
    end if

    if key = "play"
        pauseGame()
        return true
    end if
    if key = "back"
        showMenu()
        return true
    end if

    if m.currentPlayer <> 0 or m.gameOver then return true
    hand = m.hands[0]
    if key = "left"
        if m.selectedIndex > 0 then m.selectedIndex = m.selectedIndex - 1
        renderPlayerHand()
    else if key = "right"
        if m.selectedIndex < hand.Count() - 1 then m.selectedIndex = m.selectedIndex + 1
        renderPlayerHand()
    else if key = "OK"
        tryPlayerCard()
    else if key = "down"
        playerDrawAndPass()
    end if
    return true
end function

sub changeSetup(delta as integer)
    if m.setupRow = 0
        m.botCount = m.botCount + delta
        if m.botCount < 1 then m.botCount = 3
        if m.botCount > 3 then m.botCount = 1
    else if m.setupRow = 1
        if m.difficulty = "EASY" then m.difficulty = "NORMAL" else m.difficulty = "EASY"
    else
        m.effectsOn = not m.effectsOn
    end if
    updateSetupUI()
end sub

sub updateSetupUI()
    m.botChoice.text = m.botCount.toStr()
    m.difficultyChoice.text = m.difficulty
    m.effectsChoice.text = effectText()
    m.botsRow.color = "0x263238FF"
    m.difficultyRow.color = "0x263238FF"
    m.effectsRow.color = "0x263238FF"
    if m.setupRow = 0 then m.botsRow.color = "0xF97316FF"
    if m.setupRow = 1 then m.difficultyRow.color = "0xF97316FF"
    if m.setupRow = 2 then m.effectsRow.color = "0xF97316FF"
end sub

function effectText() as string
    if m.effectsOn then return "ON"
    return "OFF"
end function

sub updateEffectsLabels()
    m.effectsChoice.text = effectText()
    m.pauseEffects.text = "EFFECTS: " + effectText()
end sub

sub startNewGame()
    m.screenMode = "game"
    m.gameOver = false
    m.setupGroup.visible = false
    m.menuGroup.visible = false
    m.wildGroup.visible = false
    m.pauseGroup.visible = false
    m.winnerGroup.visible = false
    m.actionGroup.visible = false
    m.gameGroup.visible = true
    m.direction = 1
    m.currentPlayer = 0
    m.selectedIndex = 0
    m.handOffset = 0
    m.cardsPlayed = 0
    m.cardsDrawn = 0
    m.turnCount = 0
    m.turnLabel.text = "DEALING..."
    m.messageLabel.text = "Shuffling cards"

    m.hands = []
    for i = 0 to 3
        m.hands.Push([])
    end for
    m.discardPile = []
    buildDeck()
    shuffleDeck()
    for dealRound = 0 to 6
        for playerIndex = 0 to m.botCount
            m.hands[playerIndex].Push(drawCard())
        end for
    end for

    topCard = drawCard()
    while topCard.value = "SKIP" or topCard.value = "REVERSE" or topCard.value = "+2" or topCard.value = "WILD"
        m.deck.Push(topCard)
        shuffleDeck()
        topCard = drawCard()
    end while
    m.discardPile.Push(topCard)
    m.activeColor = topCard.color
    updateBotVisibility()
    renderAll()
    beginTurn()
end sub

sub showMenu()
    m.botTimer.control = "stop"
    m.bannerTimer.control = "stop"
    m.screenMode = "menu"
    m.gameOver = false
    m.setupGroup.visible = false
    m.gameGroup.visible = false
    m.wildGroup.visible = false
    m.pauseGroup.visible = false
    m.winnerGroup.visible = false
    m.actionGroup.visible = false
    m.menuGroup.visible = true
end sub

sub pauseGame()
    if m.screenMode <> "game" then return
    m.botTimer.control = "stop"
    m.screenMode = "pause"
    m.pauseGroup.visible = true
    updateEffectsLabels()
end sub

sub resumeGame()
    m.pauseGroup.visible = false
    m.screenMode = "game"
    if m.currentPlayer <> 0 then m.botTimer.control = "start"
end sub

sub updateBotVisibility()
    m.topBotGroup.visible = m.botCount >= 1
    m.rightBotGroup.visible = m.botCount >= 2
    m.leftBotGroup.visible = m.botCount >= 3
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
    for i = 0 to 3
        m.deck.Push({ color: "WILD", value: "WILD" })
    end for
end sub

sub shuffleDeck()
    count = m.deck.Count()
    if count < 2 then return
    for i = count - 1 to 1 step -1
        j = Rnd(i + 1) - 1
        temp = m.deck[i]
        m.deck[i] = m.deck[j]
        m.deck[j] = temp
    end for
end sub

function drawCard() as object
    if m.deck.Count() = 0 then recycleDiscardPile()
    if m.deck.Count() = 0 then return { color: "RED", value: "0" }
    index = m.deck.Count() - 1
    card = m.deck[index]
    m.deck.Delete(index)
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
    if card.value = "WILD" then return true
    topCard = m.discardPile[m.discardPile.Count() - 1]
    return card.color = m.activeColor or card.value = topCard.value
end function

sub tryPlayerCard()
    hand = m.hands[0]
    if hand.Count() = 0 then return
    card = hand[m.selectedIndex]
    if not isLegal(card)
        m.messageLabel.text = "That card does not match"
        showAction("NO MATCH", "0xC62828FF")
        return
    end if
    if card.value = "WILD"
        m.wildChoiceIndex = 0
        m.screenMode = "wild"
        m.wildGroup.visible = true
        updateWildChoice()
        return
    end if
    playCard(0, m.selectedIndex, "")
end sub

sub updateWildChoice()
    colorName = m.wildColors[m.wildChoiceIndex]
    m.wildColorText.text = colorName
    m.wildColorCard.color = colorHex(colorName)
    m.wildColorText.color = textHex(colorName)
end sub

sub confirmPlayerWild()
    m.wildGroup.visible = false
    m.screenMode = "game"
    playCard(0, m.selectedIndex, m.wildColors[m.wildChoiceIndex])
end sub

sub playerDrawAndPass()
    card = drawCard()
    m.hands[0].Push(card)
    m.cardsDrawn = m.cardsDrawn + 1
    m.selectedIndex = m.hands[0].Count() - 1
    m.messageLabel.text = "You drew " + card.value
    showAction("DRAW", "0x1565C0FF")
    renderAll()
    m.currentPlayer = nextPlayer(0)
    beginTurn()
end sub

sub playCard(player as integer, cardIndex as integer, chosenColor as string)
    hand = m.hands[player]
    card = hand[cardIndex]
    hand.Delete(cardIndex)
    m.discardPile.Push(card)
    m.cardsPlayed = m.cardsPlayed + 1

    if card.value = "WILD"
        if chosenColor = "" then chosenColor = bestColor(hand)
        m.activeColor = chosenColor
        m.messageLabel.text = m.playerNames[player] + " chose " + chosenColor
        showAction("WILD • " + chosenColor, colorHex(chosenColor))
    else
        m.activeColor = card.color
        m.messageLabel.text = m.playerNames[player] + " played " + card.value
    end if

    if player = 0
        if m.selectedIndex >= hand.Count() then m.selectedIndex = hand.Count() - 1
        if m.selectedIndex < 0 then m.selectedIndex = 0
    end if

    renderAll()
    if hand.Count() = 1 then showOneCardWarning(player)
    if hand.Count() = 0
        finishGame(player)
        return
    end if

    nextTurn = nextPlayer(player)
    if card.value = "REVERSE"
        m.direction = m.direction * -1
        nextTurn = nextPlayer(player)
        m.messageLabel.text = m.playerNames[player] + " reversed direction"
        showAction("REVERSE!", "0x512DA8FF")
    else if card.value = "SKIP"
        skipped = nextTurn
        nextTurn = nextPlayer(skipped)
        m.messageLabel.text = m.playerNames[skipped] + " was skipped"
        showAction("SKIP!", "0xF9A825FF")
    else if card.value = "+2"
        victim = nextTurn
        m.hands[victim].Push(drawCard())
        m.hands[victim].Push(drawCard())
        m.cardsDrawn = m.cardsDrawn + 2
        nextTurn = nextPlayer(victim)
        m.messageLabel.text = m.playerNames[victim] + " drew 2"
        showAction("DRAW 2!", "0xC62828FF")
        renderAll()
    end if

    m.currentPlayer = nextTurn
    beginTurn()
end sub

sub showOneCardWarning(player as integer)
    if player = 0
        showAction("ONE CARD LEFT!", "0xF4B942FF")
    else
        showAction(m.playerNames[player] + " • ONE CARD", "0xF4B942FF")
    end if
end sub

sub showAction(text as string, color as string)
    if not m.effectsOn then return
    m.actionText.text = text
    m.actionText.color = color
    m.actionGroup.visible = true
    m.bannerTimer.control = "start"
end sub

sub hideActionBanner()
    m.actionGroup.visible = false
end sub

function nextPlayer(player as integer) as integer
    result = player + m.direction
    if result > m.botCount then result = 0
    if result < 0 then result = m.botCount
    return result
end function

sub beginTurn()
    if m.gameOver then return
    m.turnCount = m.turnCount + 1
    renderAll()
    if m.currentPlayer = 0
        m.turnLabel.text = "YOUR TURN"
        m.turnLabel.color = "0xF4B942FF"
        m.messageLabel.text = "Play a match or press DOWN to draw"
    else
        m.turnLabel.text = m.playerNames[m.currentPlayer] + "'S TURN"
        m.turnLabel.color = "0xFFFFFFFF"
        m.messageLabel.text = botThinkingText(m.currentPlayer)
        m.botTimer.control = "start"
    end if
end sub

function botThinkingText(player as integer) as string
    if player = 1 then return "Scout is checking colors..."
    if player = 2 then return "Bear is planning an attack..."
    return "Fox is looking for an opening..."
end function

sub onBotTimer()
    if m.screenMode <> "game" or m.gameOver or m.currentPlayer = 0 then return
    player = m.currentPlayer
    hand = m.hands[player]
    legalIndexes = []
    for i = 0 to hand.Count() - 1
        if isLegal(hand[i]) then legalIndexes.Push(i)
    end for

    if legalIndexes.Count() > 0
        chosenIndex = chooseBotCard(hand, legalIndexes)
        chosenColor = ""
        if hand[chosenIndex].value = "WILD" then chosenColor = bestColor(hand)
        playCard(player, chosenIndex, chosenColor)
        return
    end if

    drawn = drawCard()
    hand.Push(drawn)
    m.cardsDrawn = m.cardsDrawn + 1
    renderAll()
    if isLegal(drawn)
        chosenColor = ""
        if drawn.value = "WILD" then chosenColor = bestColor(hand)
        playCard(player, hand.Count() - 1, chosenColor)
    else
        m.messageLabel.text = m.playerNames[player] + " drew a card"
        m.currentPlayer = nextPlayer(player)
        beginTurn()
    end if
end sub

function chooseBotCard(hand as object, legalIndexes as object) as integer
    if m.difficulty = "EASY" then return legalIndexes[Rnd(legalIndexes.Count()) - 1]
    bestIndex = legalIndexes[0]
    bestScore = -999
    nextSeat = nextPlayer(m.currentPlayer)
    danger = m.hands[nextSeat].Count() <= 2
    for each index in legalIndexes
        card = hand[index]
        score = colorCount(hand, card.color)
        if card.value = "WILD" then score = 2
        if card.value = "REVERSE" then score = score + 4
        if card.value = "SKIP" then score = score + 5
        if card.value = "+2" then score = score + 7
        if danger and (card.value = "SKIP" or card.value = "+2") then score = score + 8
        if score > bestScore
            bestScore = score
            bestIndex = index
        end if
    end for
    return bestIndex
end function

function bestColor(hand as object) as string
    best = "RED"
    bestCount = -1
    for each color in m.wildColors
        count = colorCount(hand, color)
        if count > bestCount
            bestCount = count
            best = color
        end if
    end for
    return best
end function

function colorCount(hand as object, color as string) as integer
    count = 0
    for each card in hand
        if card.color = color then count = count + 1
    end for
    return count
end function

sub finishGame(winner as integer)
    m.gameOver = true
    m.botTimer.control = "stop"
    m.screenMode = "winner"
    m.winnerGroup.visible = true
    if winner = 0
        m.winnerTitle.text = "YOU WIN!"
        m.winnerMessage.text = "You defeated " + m.botCount.toStr() + " bot players on " + m.difficulty + "."
    else
        m.winnerTitle.text = m.playerNames[winner] + " WINS"
        m.winnerMessage.text = "Try a rematch or adjust the setup."
    end if
    m.winnerStats.text = m.cardsPlayed.toStr() + " CARDS PLAYED • " + m.cardsDrawn.toStr() + " DRAWN • " + m.turnCount.toStr() + " TURNS"
end sub

sub renderAll()
    renderDiscard()
    renderCounts()
    renderPlayerHand()
    if m.direction = 1
        m.directionLabel.text = "CLOCKWISE →"
    else
        m.directionLabel.text = "← COUNTERCLOCKWISE"
    end if
end sub

sub renderDiscard()
    if m.discardPile.Count() = 0 then return
    card = m.discardPile[m.discardPile.Count() - 1]
    if card.value = "WILD" then m.discardCard.color = "0x512DA8FF" else m.discardCard.color = colorHex(card.color)
    m.discardText.text = card.value
    m.discardText.color = "0xFFFFFFFF"
    m.activeColorLabel.text = "ACTIVE: " + m.activeColor
    m.activeColorLabel.color = colorHex(m.activeColor)
    m.deckCount.text = m.deck.Count().toStr() + " LEFT"
end sub

sub renderCounts()
    m.playerCount.text = "YOU • " + m.hands[0].Count().toStr() + " CARDS"
    if m.botCount >= 1 then m.topBotCount.text = countText(m.hands[1].Count())
    if m.botCount >= 2 then m.rightBotCount.text = countText(m.hands[2].Count())
    if m.botCount >= 3 then m.leftBotCount.text = countText(m.hands[3].Count())
end sub

function countText(count as integer) as string
    if count = 1 then return "1 CARD • WARNING"
    return count.toStr() + " CARDS"
end function

sub renderPlayerHand()
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
            y = 775
            if handIndex = m.selectedIndex then y = 745
            cardNode.translation = [x, y]
            textNode.translation = [x, y + 65]
            if card.value = "WILD" then cardNode.color = "0x512DA8FF" else cardNode.color = colorHex(card.color)
            textNode.color = textHex(card.color)
            textNode.text = card.value
            cardNode.visible = true
            textNode.visible = true
        else
            cardNode.visible = false
            textNode.visible = false
        end if
    end for

    selectedSlot = m.selectedIndex - m.handOffset
    selectedX = startX + (selectedSlot * 145) - 5
    m.selection.translation = [selectedX, 738]
    m.selection.visible = true
end sub

function colorHex(colorName as string) as string
    if colorName = "RED" then return "0xC62828FF"
    if colorName = "GREEN" then return "0x2E7D32FF"
    if colorName = "BLUE" then return "0x1565C0FF"
    if colorName = "GOLD" then return "0xF9A825FF"
    return "0x512DA8FF"
end function

function textHex(colorName as string) as string
    if colorName = "GOLD" then return "0x101820FF"
    return "0xFFFFFFFF"
end function
