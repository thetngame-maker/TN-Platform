# Color Clash Phone Controller — Sprint 7A

This folder contains the first mobile controller prototype for the Roku game.

## What works now

- Four-character room-code entry
- Player-name entry
- Connected lobby state
- Private card hand designed for a phone
- Card selection
- Play-card interaction
- Draw-card interaction
- TN Game visual styling
- Responsive layout with mobile safe-area support

The current controller runs as a front-end interaction prototype. It does not yet communicate with the Roku over the internet.

## Run locally

From this folder:

```bash
python3 -m http.server 8080
```

Open `http://localhost:8080` on a phone or browser.

## Planned live architecture

1. Roku requests a new room from the TN Platform session service.
2. Service returns a short room code and host token.
3. Roku shows the room code and a QR link to `/play/{roomCode}`.
4. Phone signs in or joins as a guest.
5. Phone receives a private player token.
6. Roku polls the public room state.
7. Phone submits commands such as `PLAY_CARD`, `DRAW_CARD`, and `CHOOSE_COLOR`.
8. Server validates every move and returns public and private state separately.

## Privacy rule

The shared television must never receive the contents of a human player's hand. Public room state includes only player names, card counts, turn order, discard card, active color, and effects. Private hand state is returned only to that player's authenticated controller.

## Next implementation step

Deploy the room-session API, connect the phone page to it, then add a Roku lobby that creates and polls a room. The protocol draft is in `protocol.md`.
