# Color Clash Room Protocol Draft

## Create room

`POST /api/game-rooms`

Request:

```json
{
  "game": "color-clash",
  "host": "roku",
  "maxPlayers": 4
}
```

Response:

```json
{
  "roomId": "uuid",
  "roomCode": "TR7K",
  "hostToken": "secret",
  "joinUrl": "https://thetngame.com/play/TR7K",
  "expiresAt": "ISO-8601"
}
```

## Join room

`POST /api/game-rooms/{roomCode}/players`

```json
{
  "displayName": "Dakota",
  "accountToken": "optional"
}
```

Returns a private `playerToken` and public player profile.

## Public TV state

`GET /api/game-rooms/{roomId}/state`

```json
{
  "revision": 12,
  "status": "playing",
  "activePlayerId": "player-1",
  "activeColor": "GOLD",
  "discard": {"color":"GOLD","value":"2"},
  "players": [
    {"id":"player-1","name":"Dakota","cardCount":6,"connected":true}
  ],
  "lastAction": {"type":"PLAY_CARD","playerId":"player-1","value":"2"}
}
```

The TV state never contains private hands.

## Private controller state

`GET /api/game-rooms/{roomId}/players/me/state`

Authorization: `Bearer {playerToken}`

```json
{
  "revision": 12,
  "isMyTurn": true,
  "activeColor": "GOLD",
  "hand": [
    {"id":"card-22","color":"GREEN","value":"4","legal":false},
    {"id":"card-23","color":"GOLD","value":"6","legal":true}
  ]
}
```

## Submit command

`POST /api/game-rooms/{roomId}/commands`

Authorization: `Bearer {playerToken}`

```json
{
  "commandId": "client-generated-uuid",
  "expectedRevision": 12,
  "type": "PLAY_CARD",
  "payload": {"cardId":"card-23"}
}
```

Supported command types:

- `READY`
- `PLAY_CARD`
- `DRAW_CARD`
- `CHOOSE_COLOR`
- `LEAVE_ROOM`
- `REMATCH_VOTE`

The server must validate turn ownership, legal moves, card ownership, room revision, and authentication before applying a command.
