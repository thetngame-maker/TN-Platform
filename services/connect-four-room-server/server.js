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
function json(res, status, value) { send(res, status, 'application/json; charset=utf-8', JSON.stringify(value)); }
function newCode() { return crypto.randomBytes(3).toString('hex').slice(0, 4).toUpperCase(); }
function emptyBoard() { return Array.from({ length: 6 }, () => Array(7).fill(0)); }
function touch(room) { room.version += 1; }
function publicRoom(room) {
  return {
    code: room.code,
    status: room.status,
    version: room.version,
    players: room.players.map((p, i) => ({ id: p.id, name: p.name, piece: i + 1 })),
    game: room.game
  };
}
function createRoom() {
  const room = { code: newCode(), status: 'lobby', version: 1, players: [], game: null };
  rooms.set(room.code, room);
  return room;
}
function readBody(req) {
  return new Promise((resolve, reject) => {
    let raw = '';
    req.on('data', chunk => { raw += chunk; });
    req.on('end', () => { try { resolve(raw ? JSON.parse(raw) : {}); } catch (error) { reject(error); } });
    req.on('error', reject);
  });
}
function hasWinner(board, piece) {
  const directions = [[0,1],[1,0],[1,1],[1,-1]];
  for (let row = 0; row < 6; row++) for (let col = 0; col < 7; col++) {
    if (board[row][col] !== piece) continue;
    for (const [dr, dc] of directions) {
      let count = 1;
      while (count < 4) {
        const r = row + dr * count;
        const c = col + dc * count;
        if (r < 0 || r >= 6 || c < 0 || c >= 7 || board[r][c] !== piece) break;
        count++;
      }
      if (count === 4) return true;
    }
  }
  return false;
}
function page(roomCode = '') {
  const buttons = [0,1,2,3,4,5,6].map(c => `<button onclick="drop(${c})">${c + 1}</button>`).join('');
  return `<!doctype html><html><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{font-family:system-ui;background:#06110e;color:#fff;margin:0;padding:28px}main{max-width:680px;margin:auto}.brand{color:#ff7417;font-weight:900}input,button{font:inherit;padding:16px;border-radius:14px;border:1px solid #27604c}input{width:100%;box-sizing:border-box;background:#0b1e18;color:#fff;margin:8px 0 18px}button{background:#ff7417;color:#fff;font-weight:900;margin:6px}.columns{display:grid;grid-template-columns:repeat(7,1fr);gap:6px}.columns button{margin:0;padding:18px 0}.status{color:#a7bdb5;margin:18px 0}</style><main><h1><span class="brand">TN GAME</span> CONNECT FOUR</h1><section id="join"><h2>Join the board</h2><input id="room" placeholder="Room code" value="${roomCode}"><input id="name" placeholder="Player name"><button onclick="join()">JOIN GAME</button></section><section id="game" hidden><h2 id="turn">Connected</h2><div class="status" id="status"></div><div class="columns">${buttons}</div></section></main><script>let code='',token='';async function join(){code=room.value.trim().toUpperCase();const r=await fetch('/api/rooms/'+code+'/join',{method:'POST',headers:{'content-type':'application/json'},body:JSON.stringify({name:name.value})});const d=await r.json();if(!r.ok)return alert(d.error);token=d.player.token;join.hidden=true;game.hidden=false;poll()}async function drop(column){const r=await fetch('/api/rooms/'+code+'/move',{method:'POST',headers:{'content-type':'application/json','x-player-token':token},body:JSON.stringify({column})});const d=await r.json();status.textContent=r.ok?'Piece dropped':d.error;poll()}async function poll(){if(!code)return;const r=await fetch('/api/rooms/'+code);const d=await r.json();if(d.game){const p=d.players.find(x=>x.id===d.game.turnPlayerId);turn.textContent=d.status==='finished'?(d.game.winnerName?d.game.winnerName+' wins!':'Draw game'):(p?p.name+"'s turn":'Waiting')}setTimeout(poll,700)}</script></html>`;
}

const server = http.createServer(async (req, res) => {
  const url = new URL(req.url, `http://${req.headers.host || 'localhost'}`);
  if (req.method === 'OPTIONS') return json(res, 204, {});
  if (req.method === 'GET' && url.pathname === '/') return send(res, 200, 'text/html; charset=utf-8', page(url.searchParams.get('room') || ''));
  if ((req.method === 'POST' && url.pathname === '/api/rooms') || (req.method === 'GET' && url.pathname === '/api/rooms/create')) {
    return json(res, 201, publicRoom(createRoom()));
  }

  const match = url.pathname.match(/^\/api\/rooms\/([A-Z0-9]{4})(?:\/(join|start|move))?$/);
  if (!match) return json(res, 404, { error: 'Not found' });
  const room = rooms.get(match[1]);
  if (!room) return json(res, 404, { error: 'Room not found' });
  if (req.method === 'GET' && !match[2]) return json(res, 200, publicRoom(room));

  if (req.method === 'POST' && match[2] === 'join') {
    if (room.status !== 'lobby') return json(res, 409, { error: 'Game already started' });
    if (room.players.length >= 2) return json(res, 409, { error: 'Room is full' });
    const data = await readBody(req);
    const name = String(data.name || '').trim().slice(0, 18);
    if (name.length < 2) return json(res, 400, { error: 'Enter a player name' });
    const player = { id: crypto.randomUUID(), token: crypto.randomBytes(16).toString('hex'), name };
    room.players.push(player);
    touch(room);
    return json(res, 201, { room: publicRoom(room), player });
  }

  if ((req.method === 'GET' || req.method === 'POST') && match[2] === 'start') {
    if (room.players.length < 1) return json(res, 409, { error: 'At least one player must join' });
    if (room.status === 'lobby') {
      room.status = 'playing';
      room.game = { board: emptyBoard(), turnPlayerId: room.players[0].id, winnerName: '', message: 'Game started' };
      touch(room);
    }
    return json(res, 200, publicRoom(room));
  }

  if (req.method === 'POST' && match[2] === 'move') {
    if (room.status !== 'playing') return json(res, 409, { error: 'Game is not active' });
    const player = room.players.find(p => p.token === req.headers['x-player-token']);
    if (!player) return json(res, 401, { error: 'Invalid player' });
    if (room.game.turnPlayerId !== player.id) return json(res, 409, { error: 'Not your turn' });
    const data = await readBody(req);
    const col = Number(data.column);
    if (!Number.isInteger(col) || col < 0 || col > 6) return json(res, 400, { error: 'Invalid column' });
    let row = -1;
    for (let r = 5; r >= 0; r--) if (room.game.board[r][col] === 0) { row = r; break; }
    if (row < 0) return json(res, 409, { error: 'Column is full' });
    const index = room.players.findIndex(p => p.id === player.id);
    const piece = index + 1;
    room.game.board[row][col] = piece;
    if (hasWinner(room.game.board, piece)) {
      room.status = 'finished';
      room.game.winnerName = player.name;
      room.game.message = player.name + ' connected four!';
    } else if (room.game.board.every(r => r.every(Boolean))) {
      room.status = 'finished';
      room.game.message = 'Draw game';
    } else {
      const next = room.players.length === 1 ? player : room.players[(index + 1) % room.players.length];
      room.game.turnPlayerId = next.id;
      room.game.message = player.name + ' dropped in column ' + (col + 1);
    }
    touch(room);
    return json(res, 200, publicRoom(room));
  }

  return json(res, 404, { error: 'Not found' });
});

server.listen(PORT, '0.0.0.0', () => console.log(`TN Game Connect Four: http://0.0.0.0:${PORT}`));
