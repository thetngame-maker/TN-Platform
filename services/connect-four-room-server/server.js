const http = require('http');
const crypto = require('crypto');
const { URL } = require('url');

const PORT = Number(process.env.PORT || 8070);
const rooms = new Map();
const COLORS = {
  orange: { name: 'Orange', hex: '#f97316' },
  gold: { name: 'Gold', hex: '#ffd54f' },
  blue: { name: 'Blue', hex: '#3b82f6' },
  purple: { name: 'Purple', hex: '#a855f7' },
  green: { name: 'Green', hex: '#22c55e' },
  pink: { name: 'Pink', hex: '#ec4899' }
};

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
    piece: index + 1,
    color: player.color,
    colorName: COLORS[player.color].name,
    colorHex: COLORS[player.color].hex
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
  const room = { code: newCode(), status: 'lobby', version: 1, players: [], game: null };
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
    winnerPiece: 0,
    winningCells: [],
    lastMove: null,
    message: `${room.players[0].name} goes first`
  };
  touch(room);
  return true;
}

function findWinningCells(board, piece) {
  const directions = [[0, 1], [1, 0], [1, 1], [1, -1]];
  for (let row = 0; row < 6; row++) {
    for (let col = 0; col < 7; col++) {
      if (board[row][col] !== piece) continue;
      for (const [dr, dc] of directions) {
        const cells = [];
        for (let offset = 0; offset < 4; offset++) {
          const nextRow = row + dr * offset;
          const nextCol = col + dc * offset;
          if (nextRow < 0 || nextRow >= 6 || nextCol < 0 || nextCol >= 7 || board[nextRow][nextCol] !== piece) break;
          cells.push({ row: nextRow, col: nextCol });
        }
        if (cells.length === 4) return cells;
      }
    }
  }
  return [];
}

function tvState(room) {
  const players = publicPlayers(room);
  const playerNames = players.map(player => player.name).join('  •  ');
  if (room.status === 'lobby') {
    return {
      code: room.code,
      version: room.version,
      screen: 'lobby',
      title: `JOIN ROOM ${room.code}`,
      subtitle: 'Choose your name and color on each phone',
      joinUrl: `http://192.168.1.127:${PORT}/?room=${room.code}`,
      roomLabel: `${players.length} of 2 players connected`,
      playersLabel: playerNames || 'Waiting for players',
      board: emptyBoard(),
      players,
      winningCells: [],
      lastMove: null
    };
  }

  const game = room.game || { board: emptyBoard(), turnPlayerId: '', winnerName: '', winnerPiece: 0, winningCells: [], lastMove: null, message: '' };
  const currentPlayer = players.find(player => player.id === game.turnPlayerId);
  const finished = room.status === 'finished';
  return {
    code: room.code,
    version: room.version,
    screen: finished ? 'finished' : 'playing',
    title: finished ? (game.winnerName ? `${game.winnerName.toUpperCase()} WINS!` : 'DRAW GAME') : (currentPlayer ? `${currentPlayer.name.toUpperCase()}'S TURN` : 'WAITING'),
    subtitle: finished ? `${game.message || 'Game finished'}  •  Press OK to play again` : (game.message || 'Choose a column on your phone'),
    roomLabel: `ROOM ${room.code}`,
    playersLabel: playerNames,
    board: game.board,
    players,
    currentPlayerId: game.turnPlayerId,
    winningCells: game.winningCells || [],
    lastMove: game.lastMove || null
  };
}

function readBody(req) {
  return new Promise((resolve, reject) => {
    let raw = '';
    req.on('data', chunk => { raw += chunk; });
    req.on('end', () => {
      try { resolve(raw ? JSON.parse(raw) : {}); } catch (error) { reject(error); }
    });
    req.on('error', reject);
  });
}

function page(roomCode = '') {
  const buttons = [0,1,2,3,4,5,6].map(column => `<button class="column" type="button" data-column="${column}" onclick="dropPiece(${column})">${column + 1}</button>`).join('');
  const colorButtons = Object.entries(COLORS).map(([id, color]) => `<button class="color-choice" type="button" data-color="${id}" style="--choice:${color.hex}" onclick="chooseColor('${id}')"><span></span>${color.name}</button>`).join('');

  return `<!doctype html>
<html><head>
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#06110e"><title>TN Game Connect Four</title>
<style>
:root{color-scheme:dark;--orange:#ff7417;--panel:#0b1e18;--muted:#a7bdb5;--selected:#fff}
*{box-sizing:border-box}body{font-family:system-ui,-apple-system,sans-serif;background:#06110e;color:#fff;margin:0;min-height:100vh;padding:calc(24px + env(safe-area-inset-top)) 22px calc(28px + env(safe-area-inset-bottom))}
main{max-width:680px;margin:auto}.brand{color:var(--orange);font-weight:950}h1{font-size:clamp(34px,8vw,58px);line-height:1.03;margin:0 0 30px}h2{font-size:clamp(28px,7vw,44px);margin:0 0 10px}
.card{background:var(--panel);border:1px solid #21483c;border-radius:24px;padding:22px;box-shadow:0 20px 50px #0007}label{display:block;color:var(--muted);font-weight:700;margin:14px 0 7px}
input,button{font:inherit;border-radius:16px;border:1px solid #27604c}input{width:100%;padding:17px;background:#081712;color:#fff;font-size:20px}button{background:var(--orange);color:#fff;font-weight:900;padding:17px;cursor:pointer}button:disabled{opacity:.28;cursor:not-allowed;filter:grayscale(.5)}
.join{width:100%;margin-top:18px}.status{color:var(--muted);font-size:19px;min-height:29px;margin:10px 0 22px}.player{display:flex;align-items:center;gap:10px;color:var(--muted);font-weight:750;margin:10px 0 20px}.dot{width:18px;height:18px;border-radius:50%;background:var(--player-color,#f97316);box-shadow:0 0 0 4px color-mix(in srgb,var(--player-color,#f97316) 28%,transparent)}
.colors{display:grid;grid-template-columns:repeat(3,1fr);gap:9px}.color-choice{background:#081712;color:#d9e6e1;padding:12px 8px;font-size:14px;border-color:#21483c}.color-choice span{display:block;width:28px;height:28px;border-radius:50%;margin:0 auto 6px;background:var(--choice);box-shadow:0 0 0 3px #ffffff1c}.color-choice.selected{border-color:#fff;box-shadow:0 0 0 2px var(--choice) inset}.color-choice.taken{opacity:.25;pointer-events:none}
.columns{display:grid;grid-template-columns:repeat(7,1fr);gap:7px}.column{margin:0;padding:20px 0;font-size:22px;min-width:0}.column.ready{box-shadow:0 0 0 2px #ffb06f inset,0 8px 22px #ff741733}.hint{text-align:center;color:#739b8e;font-size:14px;margin-top:18px}
</style></head><body><main>
<h1><span class="brand">TN GAME</span><br>CONNECT FOUR</h1>
<section id="joinPanel" class="card"><h2>Join the board</h2>
<label for="roomInput">Room code</label><input id="roomInput" placeholder="ABCD" value="${roomCode}" maxlength="4" autocomplete="off" autocapitalize="characters" oninput="refreshColors()">
<label for="playerNameInput">Player name</label><input id="playerNameInput" placeholder="Your name" maxlength="18" autocomplete="nickname">
<label>Choose your color</label><div class="colors">${colorButtons}</div>
<button class="join" type="button" onclick="joinGame()">JOIN GAME</button></section>
<section id="gamePanel" class="card" hidden><h2 id="turnLabel">Connected</h2><div class="player"><span id="pieceDot" class="dot"></span><span id="playerLabel"></span></div><div class="status" id="statusLabel"></div><div class="columns">${buttons}</div><div class="hint" id="hintLabel">Your phone is the controller. Watch the TV for the board.</div></section>
</main><script>
const COLORS=${JSON.stringify(COLORS)};
let activeRoomCode='',playerToken='',playerId='',playerPiece=1,playerColor='orange',pollTimer=null,movePending=false,colorTimer=null;
const columnButtons=()=>[...document.querySelectorAll('.column')];
function chooseColor(color){playerColor=color;document.querySelectorAll('.color-choice').forEach(button=>button.classList.toggle('selected',button.dataset.color===color));}
chooseColor('orange');
async function refreshColors(){clearTimeout(colorTimer);colorTimer=setTimeout(async()=>{const code=document.getElementById('roomInput').value.trim().toUpperCase();document.querySelectorAll('.color-choice').forEach(b=>b.classList.remove('taken'));if(code.length!==4)return;try{const response=await fetch('/api/rooms/'+code,{cache:'no-store'});const room=await response.json();if(!response.ok)return;const used=new Set(room.players.map(p=>p.color));document.querySelectorAll('.color-choice').forEach(button=>button.classList.toggle('taken',used.has(button.dataset.color)));if(used.has(playerColor)){const available=Object.keys(COLORS).find(color=>!used.has(color));if(available)chooseColor(available);}}catch(error){}},180);}
refreshColors();
function setButtons(enabled,board){columnButtons().forEach((button,column)=>{const full=board&&board[0]&&board[0][column]!==0;button.disabled=!enabled||full||movePending;button.classList.toggle('ready',enabled&&!full&&!movePending);});}
async function joinGame(){const roomField=document.getElementById('roomInput'),playerField=document.getElementById('playerNameInput');activeRoomCode=roomField.value.trim().toUpperCase();const playerName=playerField.value.trim();if(activeRoomCode.length!==4)return alert('Enter the 4-character room code');if(playerName.length<2)return alert('Enter a player name');const response=await fetch('/api/rooms/'+activeRoomCode+'/join',{method:'POST',headers:{'content-type':'application/json'},body:JSON.stringify({name:playerName,color:playerColor})});const data=await response.json();if(!response.ok)return alert(data.error||'Could not join the game');playerToken=data.player.token;playerId=data.player.id;const me=data.room.players.find(player=>player.id===playerId);playerPiece=me?.piece||1;playerColor=me?.color||playerColor;document.getElementById('playerLabel').textContent=playerName+' • '+COLORS[playerColor].name;document.getElementById('pieceDot').style.setProperty('--player-color',COLORS[playerColor].hex);document.getElementById('joinPanel').hidden=true;document.getElementById('gamePanel').hidden=false;pollRoom();}
async function dropPiece(column){if(movePending)return;movePending=true;setButtons(false);try{const response=await fetch('/api/rooms/'+activeRoomCode+'/move',{method:'POST',headers:{'content-type':'application/json','x-player-token':playerToken},body:JSON.stringify({column})});const data=await response.json();document.getElementById('statusLabel').textContent=response.ok?'Piece dropped in column '+(column+1):(data.error||'Move failed');if(response.ok&&navigator.vibrate)navigator.vibrate(45);}catch(error){document.getElementById('statusLabel').textContent='Connection interrupted. Trying again…';}finally{movePending=false;pollRoom();}}
async function pollRoom(){if(!activeRoomCode)return;clearTimeout(pollTimer);try{const response=await fetch('/api/rooms/'+activeRoomCode,{cache:'no-store'});const room=await response.json();if(!response.ok)throw new Error(room.error||'Room unavailable');if(room.game){const current=room.players.find(player=>player.id===room.game.turnPlayerId);const mine=room.status==='playing'&&room.game.turnPlayerId===playerId;document.getElementById('turnLabel').textContent=room.status==='finished'?(room.game.winnerName?room.game.winnerName+' wins!':'Draw game'):(mine?'Your turn':(current?current.name+"'s turn":'Waiting'));document.getElementById('statusLabel').textContent=room.game.message||'';document.getElementById('hintLabel').textContent=room.status==='finished'?'The TV host can start another round.':(mine?'Choose an open column.':'Buttons unlock when it is your turn.');setButtons(mine,room.game.board);}else{document.getElementById('turnLabel').textContent='Waiting for player two';document.getElementById('statusLabel').textContent='The game starts automatically when two players join.';setButtons(false);}}catch(error){document.getElementById('statusLabel').textContent='Reconnecting…';setButtons(false);}pollTimer=setTimeout(pollRoom,500);}
</script></body></html>`;
}

const server = http.createServer(async (req, res) => {
  try {
    const url = new URL(req.url, `http://${req.headers.host || 'localhost'}`);
    if (req.method === 'OPTIONS') return json(res, 204, {});
    if (req.method === 'GET' && url.pathname === '/') return send(res, 200, 'text/html; charset=utf-8', page(url.searchParams.get('room') || ''));
    if ((req.method === 'POST' && url.pathname === '/api/rooms') || (req.method === 'GET' && url.pathname === '/api/rooms/create')) return json(res, 201, publicRoom(createRoom()));

    const match = url.pathname.match(/^\/api\/rooms\/([A-Z0-9]{4})(?:\/(join|move|tv|restart))?$/);
    if (!match) return json(res, 404, { error: 'Not found' });
    const room = rooms.get(match[1]);
    if (!room) return json(res, 404, { error: 'Room not found' });
    const action = match[2] || '';
    if (req.method === 'GET' && action === '') return json(res, 200, publicRoom(room));
    if (req.method === 'GET' && action === 'tv') return json(res, 200, tvState(room));

    if (req.method === 'POST' && action === 'join') {
      if (room.status !== 'lobby') return json(res, 409, { error: 'Game already started' });
      if (room.players.length >= 2) return json(res, 409, { error: 'Room is full' });
      const data = await readBody(req);
      const playerName = String(data.name || '').trim().slice(0, 18);
      const requestedColor = String(data.color || (room.players.length === 0 ? 'orange' : 'gold')).toLowerCase();
      if (playerName.length < 2) return json(res, 400, { error: 'Enter a player name' });
      if (!COLORS[requestedColor]) return json(res, 400, { error: 'Choose a valid color' });
      if (room.players.some(player => player.color === requestedColor)) return json(res, 409, { error: COLORS[requestedColor].name + ' is already taken' });
      const player = { id: crypto.randomUUID(), token: crypto.randomBytes(16).toString('hex'), name: playerName, color: requestedColor };
      room.players.push(player); touch(room); if (room.players.length === 2) startRoom(room);
      return json(res, 201, { room: publicRoom(room), player });
    }

    if (req.method === 'POST' && action === 'restart') {
      if (room.players.length < 2) return json(res, 409, { error: 'Two players are required' });
      startRoom(room); return json(res, 200, tvState(room));
    }

    if (req.method === 'POST' && action === 'move') {
      if (room.status !== 'playing') return json(res, 409, { error: 'Game is not active' });
      const player = room.players.find(candidate => candidate.token === req.headers['x-player-token']);
      if (!player) return json(res, 401, { error: 'Invalid player' });
      if (room.game.turnPlayerId !== player.id) return json(res, 409, { error: 'Not your turn' });
      const data = await readBody(req); const column = Number(data.column);
      if (!Number.isInteger(column) || column < 0 || column > 6) return json(res, 400, { error: 'Invalid column' });
      let row = -1; for (let candidateRow = 5; candidateRow >= 0; candidateRow--) { if (room.game.board[candidateRow][column] === 0) { row = candidateRow; break; } }
      if (row < 0) return json(res, 409, { error: 'Column is full' });
      const playerIndex = room.players.findIndex(candidate => candidate.id === player.id); const piece = playerIndex + 1;
      room.game.board[row][column] = piece; room.game.lastMove = { row, col: column, piece };
      const winningCells = findWinningCells(room.game.board, piece);
      if (winningCells.length === 4) { room.status = 'finished'; room.game.winnerName = player.name; room.game.winnerPiece = piece; room.game.winningCells = winningCells; room.game.message = player.name + ' connected four!'; }
      else if (room.game.board.every(boardRow => boardRow.every(Boolean))) { room.status = 'finished'; room.game.message = 'Every space is filled'; }
      else { const nextPlayer = room.players[(playerIndex + 1) % room.players.length]; room.game.turnPlayerId = nextPlayer.id; room.game.message = player.name + ' dropped in column ' + (column + 1); }
      touch(room); return json(res, 200, publicRoom(room));
    }
    return json(res, 404, { error: 'Not found' });
  } catch (error) { console.error(error); return json(res, 500, { error: 'Server error' }); }
});

server.listen(PORT, '0.0.0.0', () => console.log(`TN Game Connect Four: http://0.0.0.0:${PORT}`));
