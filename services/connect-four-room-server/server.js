const http = require('http');
const crypto = require('crypto');
const { URL } = require('url');

const PORT = Number(process.env.PORT || 8070);
const rooms = new Map();

function send(res, status, type, body) {
  const data = Buffer.from(body);
  res.writeHead(status, {
    'Content-Type': type,
    'Content-Length': data.length,
    'Cache-Control': 'no-store',
    'Access-Control-Allow-Origin': '*',
    'Access-Control-Allow-Headers': 'content-type,x-player-token',
    'Access-Control-Allow-Methods': 'GET,POST,OPTIONS',
    Connection: 'close'
  });
  res.end(data);
}

function json(res, status, value) {
  send(res, status, 'application/json; charset=utf-8', JSON.stringify(value));
}

function newCode() {
  return crypto.randomBytes(3).toString('hex').slice(0, 4).toUpperCase();
}

function emptyBoard() {
  return Array.from({ length: 6 }, () => Array(7).fill(0));
}

function touch(room) {
  room.version += 1;
}

function publicPlayers(room) {
  return room.players.map((player, index) => ({
    id: player.id,
    name: player.name,
    piece: index + 1
  }));
}

function publicRoom(room) {
  return {
    code: room.code,
    status: room.status,
    version: room.version,
    players: publicPlayers(room),
    game: room.game
  };
}

function createRoom() {
  const room = {
    code: newCode(),
    status: 'lobby',
    version: 1,
    players: [],
    game: null
  };
  rooms.set(room.code, room);
  return room;
}

function startRoom(room) {
  if (room.players.length < 2) return false;
  room.status = 'playing';
  room.game = {
    board: emptyBoard(),
    turnPlayerId: room.players[0].id,
    winnerName: '',
    message: 'Game started'
  };
  touch(room);
  return true;
}

function tvState(room) {
  const players = publicPlayers(room);
  const playerNames = players.map(player => player.name).join('  •  ');

  if (room.status === 'lobby') {
    return {
      code: room.code,
      version: room.version,
      screen: 'lobby',
      title: `ENTER ROOM ${room.code}`,
      subtitle: `http://192.168.1.127:${PORT}/?room=${room.code}`,
      roomLabel: `${players.length} player(s) connected`,
      playersLabel: playerNames || 'Waiting for players',
      board: emptyBoard()
    };
  }

  const game = room.game || {
    board: emptyBoard(),
    turnPlayerId: '',
    winnerName: '',
    message: ''
  };

  const currentPlayer = players.find(player => player.id === game.turnPlayerId);
  const finished = room.status === 'finished';

  return {
    code: room.code,
    version: room.version,
    screen: finished ? 'finished' : 'playing',
    title: finished
      ? (game.winnerName ? `${game.winnerName.toUpperCase()} WINS!` : 'DRAW GAME')
      : (currentPlayer ? `${currentPlayer.name.toUpperCase()}'S TURN` : 'WAITING'),
    subtitle: finished
      ? `${game.message || 'Game finished'} — Press OK to play again`
      : (game.message || 'Choose a column on your phone'),
    roomLabel: `ROOM ${room.code}`,
    playersLabel: playerNames,
    board: game.board
  };
}

function readBody(req) {
  return new Promise((resolve, reject) => {
    let raw = '';
    req.on('data', chunk => { raw += chunk; });
    req.on('end', () => {
      try {
        resolve(raw ? JSON.parse(raw) : {});
      } catch (error) {
        reject(error);
      }
    });
    req.on('error', reject);
  });
}

function hasWinner(board, piece) {
  const directions = [[0, 1], [1, 0], [1, 1], [1, -1]];
  for (let row = 0; row < 6; row++) {
    for (let col = 0; col < 7; col++) {
      if (board[row][col] !== piece) continue;
      for (const [dr, dc] of directions) {
        let count = 1;
        while (count < 4) {
          const nextRow = row + dr * count;
          const nextCol = col + dc * count;
          if (
            nextRow < 0 || nextRow >= 6 ||
            nextCol < 0 || nextCol >= 7 ||
            board[nextRow][nextCol] !== piece
          ) break;
          count += 1;
        }
        if (count === 4) return true;
      }
    }
  }
  return false;
}

function page(roomCode = '') {
  const buttons = [0, 1, 2, 3, 4, 5, 6]
    .map(column => `<button type="button" onclick="dropPiece(${column})">${column + 1}</button>`)
    .join('');

  return `<!doctype html>
<html>
<head>
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>TN Game Connect Four</title>
<style>
body{font-family:system-ui;background:#06110e;color:#fff;margin:0;padding:28px}
main{max-width:680px;margin:auto}.brand{color:#ff7417;font-weight:900}
input,button{font:inherit;padding:16px;border-radius:14px;border:1px solid #27604c}
input{width:100%;box-sizing:border-box;background:#0b1e18;color:#fff;margin:8px 0 18px}
button{background:#ff7417;color:#fff;font-weight:900;margin:6px}
.columns{display:grid;grid-template-columns:repeat(7,1fr);gap:6px}.columns button{margin:0;padding:18px 0}
.status{color:#a7bdb5;margin:18px 0}
</style>
</head>
<body>
<main>
<h1><span class="brand">TN GAME</span> CONNECT FOUR</h1>
<section id="joinPanel">
<h2>Join the board</h2>
<input id="roomInput" placeholder="Room code" value="${roomCode}" autocomplete="off">
<input id="playerNameInput" placeholder="Player name" autocomplete="nickname">
<button type="button" onclick="joinGame()">JOIN GAME</button>
</section>
<section id="gamePanel" hidden>
<h2 id="turnLabel">Connected</h2>
<div class="status" id="statusLabel"></div>
<div class="columns">${buttons}</div>
</section>
</main>
<script>
let activeRoomCode = '';
let playerToken = '';
let pollTimer = null;

async function joinGame() {
  const roomField = document.getElementById('roomInput');
  const playerField = document.getElementById('playerNameInput');
  activeRoomCode = roomField.value.trim().toUpperCase();
  const playerName = playerField.value.trim();

  if (playerName.length < 2) {
    alert('Enter a player name');
    playerField.focus();
    return;
  }

  const response = await fetch('/api/rooms/' + activeRoomCode + '/join', {
    method: 'POST',
    headers: { 'content-type': 'application/json' },
    body: JSON.stringify({ name: playerName })
  });
  const data = await response.json();
  if (!response.ok) {
    alert(data.error || 'Could not join the game');
    return;
  }

  playerToken = data.player.token;
  document.getElementById('joinPanel').hidden = true;
  document.getElementById('gamePanel').hidden = false;
  pollRoom();
}

async function dropPiece(column) {
  const response = await fetch('/api/rooms/' + activeRoomCode + '/move', {
    method: 'POST',
    headers: { 'content-type': 'application/json', 'x-player-token': playerToken },
    body: JSON.stringify({ column })
  });
  const data = await response.json();
  document.getElementById('statusLabel').textContent = response.ok
    ? 'Piece dropped'
    : (data.error || 'Move failed');
  pollRoom();
}

async function pollRoom() {
  if (!activeRoomCode) return;
  clearTimeout(pollTimer);
  try {
    const response = await fetch('/api/rooms/' + activeRoomCode, { cache: 'no-store' });
    const room = await response.json();
    if (room.game) {
      const currentPlayer = room.players.find(player => player.id === room.game.turnPlayerId);
      document.getElementById('turnLabel').textContent = room.status === 'finished'
        ? (room.game.winnerName ? room.game.winnerName + ' wins!' : 'Draw game')
        : (currentPlayer ? currentPlayer.name + "'s turn" : 'Waiting');
      document.getElementById('statusLabel').textContent = room.game.message || '';
    } else {
      document.getElementById('turnLabel').textContent = 'Waiting for second player';
      document.getElementById('statusLabel').textContent = 'The game starts automatically when two players join.';
    }
  } catch (error) {
    document.getElementById('statusLabel').textContent = 'Reconnecting…';
  }
  pollTimer = setTimeout(pollRoom, 700);
}
</script>
</body>
</html>`;
}

const server = http.createServer(async (req, res) => {
  try {
    const url = new URL(req.url, `http://${req.headers.host || 'localhost'}`);
    if (req.method === 'OPTIONS') return json(res, 204, {});

    if (req.method === 'GET' && url.pathname === '/') {
      return send(res, 200, 'text/html; charset=utf-8', page(url.searchParams.get('room') || ''));
    }

    if (
      (req.method === 'POST' && url.pathname === '/api/rooms') ||
      (req.method === 'GET' && url.pathname === '/api/rooms/create')
    ) {
      return json(res, 201, publicRoom(createRoom()));
    }

    const match = url.pathname.match(/^\/api\/rooms\/([A-Z0-9]{4})(?:\/(join|move|tv|restart))?$/);
    if (!match) return json(res, 404, { error: 'Not found' });

    const room = rooms.get(match[1]);
    if (!room) return json(res, 404, { error: 'Room not found' });

    const action = match[2] || '';

    if (req.method === 'GET' && action === '') {
      return json(res, 200, publicRoom(room));
    }

    if (req.method === 'GET' && action === 'tv') {
      return json(res, 200, tvState(room));
    }

    if (req.method === 'POST' && action === 'join') {
      if (room.status !== 'lobby') return json(res, 409, { error: 'Game already started' });
      if (room.players.length >= 2) return json(res, 409, { error: 'Room is full' });

      const data = await readBody(req);
      const playerName = String(data.name || '').trim().slice(0, 18);
      if (playerName.length < 2) return json(res, 400, { error: 'Enter a player name' });

      const player = {
        id: crypto.randomUUID(),
        token: crypto.randomBytes(16).toString('hex'),
        name: playerName
      };

      room.players.push(player);
      touch(room);

      if (room.players.length === 2) {
        startRoom(room);
      }

      return json(res, 201, { room: publicRoom(room), player });
    }

    if (req.method === 'POST' && action === 'restart') {
      if (room.players.length < 2) return json(res, 409, { error: 'Two players are required' });
      startRoom(room);
      return json(res, 200, tvState(room));
    }

    if (req.method === 'POST' && action === 'move') {
      if (room.status !== 'playing') return json(res, 409, { error: 'Game is not active' });

      const player = room.players.find(candidate => candidate.token === req.headers['x-player-token']);
      if (!player) return json(res, 401, { error: 'Invalid player' });
      if (room.game.turnPlayerId !== player.id) return json(res, 409, { error: 'Not your turn' });

      const data = await readBody(req);
      const column = Number(data.column);
      if (!Number.isInteger(column) || column < 0 || column > 6) {
        return json(res, 400, { error: 'Invalid column' });
      }

      let row = -1;
      for (let candidateRow = 5; candidateRow >= 0; candidateRow--) {
        if (room.game.board[candidateRow][column] === 0) {
          row = candidateRow;
          break;
        }
      }
      if (row < 0) return json(res, 409, { error: 'Column is full' });

      const playerIndex = room.players.findIndex(candidate => candidate.id === player.id);
      const piece = playerIndex + 1;
      room.game.board[row][column] = piece;

      if (hasWinner(room.game.board, piece)) {
        room.status = 'finished';
        room.game.winnerName = player.name;
        room.game.message = player.name + ' connected four!';
      } else if (room.game.board.every(boardRow => boardRow.every(Boolean))) {
        room.status = 'finished';
        room.game.message = 'Draw game';
      } else {
        const nextPlayer = room.players[(playerIndex + 1) % room.players.length];
        room.game.turnPlayerId = nextPlayer.id;
        room.game.message = player.name + ' dropped in column ' + (column + 1);
      }

      touch(room);
      return json(res, 200, publicRoom(room));
    }

    return json(res, 404, { error: 'Not found' });
  } catch (error) {
    console.error(error);
    return json(res, 500, { error: 'Server error' });
  }
});

server.listen(PORT, '0.0.0.0', () => {
  console.log(`TN Game Connect Four: http://0.0.0.0:${PORT}`);
});
