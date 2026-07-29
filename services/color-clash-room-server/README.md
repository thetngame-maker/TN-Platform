# Color Clash live room server

This Sprint 8A service creates real room codes and synchronizes the host lobby with phone players.

## Run

From the repository root:

```bash
cd services/color-clash-room-server
npm start
```

The server listens on port 8080 and serves both the API and controller files.

Host lobby:

```text
http://localhost:8080/host.html
```

Phone controller on the same Wi-Fi:

```text
http://YOUR_MAC_IP:8080
```

Create a room in the host lobby, enter its four-character code on the phone, and join. The player name should appear in the host lobby within one second. Pressing Start Game changes the room to `playing` and automatically opens the hand view on connected phones.

Rooms are stored in memory for this local proof. Production deployment will replace this with persistent shared storage and authentication.
