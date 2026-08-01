const http = require('http');
const crypto = require('crypto');
const { URL } = require('url');

const PORT = Number(process.env.PORT || 8070);
const rooms = new Map();
const COLORS = ['orange', 'gold', 'blue', 'purple', 'green', 'pink'];
const BOT_NAMES = { easy: 'Rookie Bot', medium: 'Smart Bot', hard: 'Expert Bot' };

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
  let code = '';
  do code = crypto.randomBytes(3).toString('hex').slice(0, 4).toUpperCase();
  while (rooms.has(code));
  return code;
}

function emptyBoard() {
  return Array.from({ length: 6 }, () => Array(7).fill(0));
}

function cloneBoard(board) {
  return board.map(row => row.slice());
}

function touch(room) {
  room.version += 1;
}

function publicPlayers(room) {
  return room.players.map((player, index) => ({
    id: player.id,
    name: player.name,
    color: player.color,
    isBot: Boolean(player.isBot),
    piece: index + 1
  }));
}

function publicRoom(room) {
  return {
    code: room.code,
    status: room.status,
    mode: room.mode,
    difficulty: room.difficulty,
    version: room.version,
    players: publicPlayers(room),
    game: room.game
  };
}

function normalizeDifficulty(value) {
  const difficulty = String(value || 'easy').toLowerCase();
  return ['easy', 'medium', 'hard'].includes(difficulty) ? difficulty : 'easy';
}

function createRoom({ mode = 'human', difficulty = 'easy' } = {}) {
  const safeMode = mode === 'bot' ? 'bot' : 'human';
  const room = {
    code: newCode(),
    status: 'lobby',
    mode: safeMode,
    difficulty: safeMode === 'bot' ? normalizeDifficulty(difficulty) : '',
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
    winnerPiece: 0,
    winningCells: [],
    lastMove: null,
    message: `${room.players[0].name} goes first`
  };
  touch(room);
  if (room.players[0].isBot) scheduleBot(room);
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

function validColumns(board) {
  return [0, 1, 2, 3, 4, 5, 6].filter(column => board[0][column] === 0);
}

function dropOnBoard(board, column, piece) {
  for (let row = 5; row >= 0; row--) {
    if (board[row][column] === 0) {
      board[row][column] = piece;
      return row;
    }
  }
  return -1;
}

function winningColumn(board, piece) {
  for (const column of validColumns(board)) {
    const copy = cloneBoard(board);
    dropOnBoard(copy, column, piece);
    if (findWinningCells(copy, piece).length === 4) return column;
  }
  return -1;
}

function scoreWindow(window, piece) {
  const opponent = piece === 1 ? 2 : 1;
  const mine = window.filter(value => value === piece).length;
  const theirs = window.filter(value => value === opponent).length;
  const empty = window.filter(value => value === 0).length;
  if (mine === 4) return 100000;
  if (mine === 3 && empty === 1) return 120;
  if (mine === 2 && empty === 2) return 18;
  if (theirs === 3 && empty === 1) return -140;
  if (theirs === 2 && empty === 2) return -14;
  return 0;
}

function evaluateBoard(board, piece) {
  let score = board.reduce((sum, row) => sum + (row[3] === piece ? 8 : 0), 0);
  for (let row = 0; row < 6; row++) {
    for (let col = 0; col < 4; col++) score += scoreWindow(board[row].slice(col, col + 4), piece);
  }
  for (let col = 0; col < 7; col++) {
    for (let row = 0; row < 3; row++) score += scoreWindow([board[row][col], board[row + 1][col], board[row + 2][col], board[row + 3][col]], piece);
  }
  for (let row = 0; row < 3; row++) {
    for (let col = 0; col < 4; col++) {
      score += scoreWindow([board[row][col], board[row + 1][col + 1], board[row + 2][col + 2], board[row + 3][col + 3]], piece);
      score += scoreWindow([board[row + 3][col], board[row + 2][col + 1], board[row + 1][col + 2], board[row][col + 3]], piece);
    }
  }
  return score;
}

function minimax(board, depth, alpha, beta, maximizing) {
  const botWin = findWinningCells(board, 2).length === 4;
  const humanWin = findWinningCells(board, 1).length === 4;
  const columns = validColumns(board);
  if (botWin) return { score: 1000000 + depth, column: -1 };
  if (humanWin) return { score: -1000000 - depth, column: -1 };
  if (depth === 0 || columns.length === 0) return { score: evaluateBoard(board, 2), column: columns[0] ?? -1 };

  const ordered = [3, 2, 4, 1, 5, 0, 6].filter(column => columns.includes(column));
  let bestColumn = ordered[0];
  if (maximizing) {
    let bestScore = -Infinity;
    for (const column of ordered) {
      const copy = cloneBoard(board);
      dropOnBoard(copy, column, 2);
      const score = minimax(copy, depth - 1, alpha, beta, false).score;
      if (score > bestScore) { bestScore = score; bestColumn = column; }
      alpha = Math.max(alpha, bestScore);
      if (alpha >= beta) break;
    }
    return { score: bestScore, column: bestColumn };
  }

  let bestScore = Infinity;
  for (const column of ordered) {
    const copy = cloneBoard(board);
    dropOnBoard(copy, column, 1);
    const score = minimax(copy, depth - 1, alpha, beta, true).score;
    if (score < bestScore) { bestScore = score; bestColumn = column; }
    beta = Math.min(beta, bestScore);
    if (alpha >= beta) break;
  }
  return { score: bestScore, column: bestColumn };
}

function chooseBotColumn(room) {
  const board = room.game.board;
  const columns = validColumns(board);
  if (!columns.length) return -1;
  if (room.difficulty === 'easy') return columns[Math.floor(Math.random() * columns.length)];
  const win = winningColumn(board, 2);
  if (win >= 0) return win;
  const block = winningColumn(board, 1);
  if (block >= 0) return block;
  if (room.difficulty === 'medium') {
    const preference = [3, 2, 4, 1, 5, 0, 6].filter(column => columns.includes(column));
    return Math.random() < 0.75 ? preference[0] : preference[Math.floor(Math.random() * preference.length)];
  }
  return minimax(board, 5, -Infinity, Infinity, true).column;
}

function finishMove(room, player, column) {
  const playerIndex = room.players.findIndex(candidate => candidate.id === player.id);
  const piece = playerIndex + 1;
  const row = dropOnBoard(room.game.board, column, piece);
  if (row < 0) return { ok: false, error: 'Column is full' };
  room.game.lastMove = { row, col: column, piece };
  const winningCells = findWinningCells(room.game.board, piece);
  if (winningCells.length === 4) {
    room.status = 'finished';
    room.game.winnerName = player.name;
    room.game.winnerPiece = piece;
    room.game.winningCells = winningCells;
    room.game.message = `${player.name} connected four!`;
  } else if (room.game.board.every(boardRow => boardRow.every(Boolean))) {
    room.status = 'finished';
    room.game.message = 'Every space is filled';
  } else {
    const nextPlayer = room.players[(playerIndex + 1) % room.players.length];
    room.game.turnPlayerId = nextPlayer.id;
    room.game.message = `${player.name} dropped in column ${column + 1}`;
  }
  touch(room);
  return { ok: true };
}

function scheduleBot(room) {
  if (room.status !== 'playing') return;
  const bot = room.players.find(player => player.isBot && player.id === room.game.turnPlayerId);
  if (!bot) return;
  setTimeout(() => {
    const current = rooms.get(room.code);
    if (!current || current.status !== 'playing' || current.game.turnPlayerId !== bot.id) return;
    const column = chooseBotColumn(current);
    if (column >= 0) finishMove(current, bot, column);
  }, 650);
}

function tvState(room) {
  const players = publicPlayers(room);
  const playerNames = players.map(player => player.name).join('  •  ');
  const requiredHumans = room.mode === 'bot' ? 1 : 2;
  if (room.status === 'lobby') {
    return {
      code: room.code, mode: room.mode, difficulty: room.difficulty, version: room.version, screen: 'lobby',
      title: room.mode === 'bot' ? `JOIN TO PLAY ${room.difficulty.toUpperCase()} BOT` : `JOIN ROOM ${room.code}`,
      subtitle: 'Open the room on your phone', joinUrl: `http://192.168.1.127:${PORT}/?room=${room.code}`,
      roomLabel: `${players.filter(player => !player.isBot).length} of ${requiredHumans} players connected`,
      playersLabel: playerNames || 'Waiting for players', board: emptyBoard(), players, winningCells: [], lastMove: null
    };
  }
  const game = room.game || { board: emptyBoard(), turnPlayerId: '', winnerName: '', winnerPiece: 0, winningCells: [], lastMove: null, message: '' };
  const currentPlayer = players.find(player => player.id === game.turnPlayerId);
  const finished = room.status === 'finished';
  return {
    code: room.code, mode: room.mode, difficulty: room.difficulty, version: room.version,
    screen: finished ? 'finished' : 'playing',
    title: finished ? (game.winnerName ? `${game.winnerName.toUpperCase()} WINS!` : 'DRAW GAME') : (currentPlayer ? `${currentPlayer.name.toUpperCase()}'S TURN` : 'WAITING'),
    subtitle: finished ? `${game.message || 'Game finished'}  •  Press OK to play again` : (currentPlayer?.isBot ? `${room.difficulty.toUpperCase()} bot is thinking…` : (game.message || 'Choose a column on your phone')),
    roomLabel: `ROOM ${room.code}`, playersLabel: playerNames, board: game.board, players,
    currentPlayerId: game.turnPlayerId, winningCells: game.winningCells || [], lastMove: game.lastMove || null
  };
}

function readBody(req) {
  return new Promise((resolve, reject) => {
    let raw = '';
    req.on('data', chunk => { raw += chunk; });
    req.on('end', () => { try { resolve(raw ? JSON.parse(raw) : {}); } catch (error) { reject(error); } });
    req.on('error', reject);
  });
}

function page(roomCode = '') {
  const buttons = [0,1,2,3,4,5,6].map(column => `<button class="column" type="button" data-column="${column}" onclick="dropPiece(${column})">${column + 1}</button>`).join('');
  const swatches = COLORS.map((color, index) => `<button class="swatch ${index === 0 ? 'selected' : ''}" type="button" data-color="${color}" aria-label="${color}" onclick="selectColor('${color}')"></button>`).join('');
  return `<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#06110e"><title>TN Game Connect Four</title><style>
:root{color-scheme:dark;--orange:#f97316;--gold:#ffd54f;--blue:#3b82f6;--purple:#a855f7;--green:#22c55e;--pink:#ec4899;--panel:#0b1e18;--muted:#a7bdb5}*{box-sizing:border-box}body{font-family:system-ui,-apple-system,sans-serif;background:#06110e;color:#fff;margin:0;min-height:100vh;padding:calc(24px + env(safe-area-inset-top)) 22px calc(28px + env(safe-area-inset-bottom))}main{max-width:680px;margin:auto}.brand{color:var(--orange);font-weight:950}h1{font-size:clamp(34px,8vw,58px);line-height:1.03;margin:0 0 30px}h2{font-size:clamp(28px,7vw,44px);margin:0 0 10px}.card{background:var(--panel);border:1px solid #21483c;border-radius:26px;padding:22px;box-shadow:0 20px 50px #0007}label{display:block;color:var(--muted);font-weight:800;margin:14px 0 7px}input,button{font:inherit;border-radius:16px;border:1px solid #27604c}input{width:100%;padding:17px;background:#081712;color:#fff;font-size:20px}button{background:var(--orange);color:#fff;font-weight:900;padding:17px;cursor:pointer}button:disabled{opacity:.28;cursor:not-allowed;filter:grayscale(.5)}.join{width:100%;margin-top:18px}.status{color:var(--muted);font-size:19px;min-height:29px;margin:10px 0 22px}.player{display:flex;align-items:center;gap:10px;color:var(--muted);font-weight:800;margin:10px 0 20px}.dot{width:22px;height:22px;border-radius:50%;background:var(--player-color,var(--orange))}.colors{display:grid;grid-template-columns:repeat(6,1fr);gap:10px}.swatch{aspect-ratio:1;padding:0;border-radius:50%;background:var(--swatch);border:4px solid transparent}.swatch.selected{border-color:#fff;transform:scale(1.08)}.swatch[data-color=orange]{--swatch:var(--orange)}.swatch[data-color=gold]{--swatch:var(--gold)}.swatch[data-color=blue]{--swatch:var(--blue)}.swatch[data-color=purple]{--swatch:var(--purple)}.swatch[data-color=green]{--swatch:var(--green)}.swatch[data-color=pink]{--swatch:var(--pink)}.columns{display:grid;grid-template-columns:repeat(7,1fr);gap:7px}.column{margin:0;padding:20px 0;font-size:22px;min-width:0}.column.ready{box-shadow:0 0 0 2px #ffb06f inset,0 8px 22px #ff741733}.hint{text-align:center;color:#739b8e;font-size:14px;margin-top:18px}</style></head><body><main><h1><span class="brand">TN GAME</span><br>CONNECT FOUR</h1><section id="joinPanel" class="card"><h2>Join the board</h2><label for="roomInput">Room code</label><input id="roomInput" placeholder="ABCD" value="${roomCode}" maxlength="4" autocomplete="off" autocapitalize="characters"><label for="playerNameInput">Player name</label><input id="playerNameInput" placeholder="Your name" maxlength="18" autocomplete="nickname"><label>Choose your color</label><div class="colors">${swatches}</div><button class="join" type="button" onclick="joinGame()">JOIN GAME</button></section><section id="gamePanel" class="card" hidden><h2 id="turnLabel">Connected</h2><div class="player"><span id="pieceDot" class="dot"></span><span id="playerLabel"></span></div><div class="status" id="statusLabel"></div><div class="columns">${buttons}</div><div class="hint" id="hintLabel">Your phone is the controller. Watch the TV for the board.</div></section></main><script>
let activeRoomCode='',playerToken='',playerId='',selectedColor='orange',pollTimer=null,movePending=false;const colorHex={orange:'#f97316',gold:'#ffd54f',blue:'#3b82f6',purple:'#a855f7',green:'#22c55e',pink:'#ec4899'};const columnButtons=()=>[...document.querySelectorAll('.column')];function selectColor(color){selectedColor=color;document.querySelectorAll('.swatch').forEach(button=>button.classList.toggle('selected',button.dataset.color===color));}function setButtons(enabled,board){columnButtons().forEach((button,column)=>{const full=board&&board[0]&&board[0][column]!==0;button.disabled=!enabled||full||movePending;button.classList.toggle('ready',enabled&&!full&&!movePending);});}
async function joinGame(){activeRoomCode=document.getElementById('roomInput').value.trim().toUpperCase();const playerName=document.getElementById('playerNameInput').value.trim();if(activeRoomCode.length!==4)return alert('Enter the 4-character room code');if(playerName.length<2)return alert('Enter a player name');const response=await fetch('/api/rooms/'+activeRoomCode+'/join',{method:'POST',headers:{'content-type':'application/json'},body:JSON.stringify({name:playerName,color:selectedColor})});const data=await response.json();if(!response.ok)return alert(data.error||'Could not join the game');playerToken=data.player.token;playerId=data.player.id;selectedColor=data.room.players.find(player=>player.id===playerId)?.color||selectedColor;document.getElementById('playerLabel').textContent=playerName+' • '+selectedColor[0].toUpperCase()+selectedColor.slice(1);document.getElementById('pieceDot').style.setProperty('--player-color',colorHex[selectedColor]);document.getElementById('joinPanel').hidden=true;document.getElementById('gamePanel').hidden=false;pollRoom();}
async function dropPiece(column){if(movePending)return;movePending=true;setButtons(false);try{const response=await fetch('/api/rooms/'+activeRoomCode+'/move',{method:'POST',headers:{'content-type':'application/json','x-player-token':playerToken},body:JSON.stringify({column})});const data=await response.json();document.getElementById('statusLabel').textContent=response.ok?'Piece dropped in column '+(column+1):(data.error||'Move failed');if(response.ok&&navigator.vibrate)navigator.vibrate(45);}catch{document.getElementById('statusLabel').textContent='Connection interrupted. Trying again…';}finally{movePending=false;pollRoom();}}
async function pollRoom(){if(!activeRoomCode)return;clearTimeout(pollTimer);try{const response=await fetch('/api/rooms/'+activeRoomCode,{cache:'no-store'});const room=await response.json();if(!response.ok)throw new Error();if(room.game){const current=room.players.find(player=>player.id===room.game.turnPlayerId);const mine=room.status==='playing'&&room.game.turnPlayerId===playerId;document.getElementById('turnLabel').textContent=room.status==='finished'?(room.game.winnerName?room.game.winnerName+' wins!':'Draw game'):(mine?'Your turn':(current?.isBot?current.name+' is thinking':current?current.name+"'s turn":'Waiting'));document.getElementById('statusLabel').textContent=room.game.message||'';document.getElementById('hintLabel').textContent=room.status==='finished'?'The TV host can start another round.':(mine?'Choose an open column.':'Buttons unlock when it is your turn.');setButtons(mine,room.game.board);}else{document.getElementById('turnLabel').textContent='Waiting to start';document.getElementById('statusLabel').textContent=room.mode==='bot'?'The bot joins automatically after you connect.':'The game starts automatically when two players join.';setButtons(false);}}catch{document.getElementById('statusLabel').textContent='Reconnecting…';setButtons(false);}pollTimer=setTimeout(pollRoom,500);}</script></body></html>`;
}

const server = http.createServer(async (req, res) => {
  try {
    const url = new URL(req.url, `http://${req.headers.host || 'localhost'}`);
    if (req.method === 'OPTIONS') return json(res, 204, {});
    if (req.method === 'GET' && url.pathname === '/') return send(res, 200, 'text/html; charset=utf-8', page(url.searchParams.get('room') || ''));
    if ((req.method === 'POST' && url.pathname === '/api/rooms') || (req.method === 'GET' && url.pathname === '/api/rooms/create')) return json(res, 201, publicRoom(createRoom({ mode: url.searchParams.get('mode') || 'human', difficulty: url.searchParams.get('difficulty') || 'easy' })));
    const match = url.pathname.match(/^\/api\/rooms\/([A-Z0-9]{4})(?:\/(join|move|tv|restart))?$/);
    if (!match) return json(res, 404, { error: 'Not found' });
    const room = rooms.get(match[1]);
    if (!room) return json(res, 404, { error: 'Room not found' });
    const action = match[2] || '';
    if (req.method === 'GET' && action === '') return json(res, 200, publicRoom(room));
    if (req.method === 'GET' && action === 'tv') return json(res, 200, tvState(room));
    if (req.method === 'POST' && action === 'join') {
      if (room.status !== 'lobby') return json(res, 409, { error: 'Game already started' });
      const humanLimit = room.mode === 'bot' ? 1 : 2;
      if (room.players.filter(player => !player.isBot).length >= humanLimit) return json(res, 409, { error: 'Room is full' });
      const data = await readBody(req);
      const playerName = String(data.name || '').trim().slice(0, 18);
      if (playerName.length < 2) return json(res, 400, { error: 'Enter a player name' });
      const requestedColor = String(data.color || 'orange').toLowerCase();
      if (!COLORS.includes(requestedColor)) return json(res, 400, { error: 'Choose a valid color' });
      if (room.players.some(player => player.color === requestedColor)) return json(res, 409, { error: 'That color is already taken' });
      const player = { id: crypto.randomUUID(), token: crypto.randomBytes(16).toString('hex'), name: playerName, color: requestedColor, isBot: false };
      room.players.push(player); touch(room);
      if (room.mode === 'bot') {
        const botColor = COLORS.find(color => color !== requestedColor) || 'gold';
        room.players.push({ id: crypto.randomUUID(), token: '', name: BOT_NAMES[room.difficulty], color: botColor, isBot: true });
        startRoom(room);
      } else if (room.players.length === 2) startRoom(room);
      return json(res, 201, { room: publicRoom(room), player });
    }
    if (req.method === 'POST' && action === 'restart') {
      if (room.players.length < 2) return json(res, 409, { error: 'Two players are required' });
      startRoom(room); return json(res, 200, tvState(room));
    }
    if (req.method === 'POST' && action === 'move') {
      if (room.status !== 'playing') return json(res, 409, { error: 'Game is not active' });
      const player = room.players.find(candidate => !candidate.isBot && candidate.token === req.headers['x-player-token']);
      if (!player) return json(res, 401, { error: 'Invalid player' });
      if (room.game.turnPlayerId !== player.id) return json(res, 409, { error: 'Not your turn' });
      const data = await readBody(req); const column = Number(data.column);
      if (!Number.isInteger(column) || column < 0 || column > 6) return json(res, 400, { error: 'Invalid column' });
      const result = finishMove(room, player, column);
      if (!result.ok) return json(res, 409, { error: result.error });
      scheduleBot(room); return json(res, 200, publicRoom(room));
    }
    return json(res, 404, { error: 'Not found' });
  } catch (error) { console.error(error); return json(res, 500, { error: 'Server error' }); }
});

server.listen(PORT, '0.0.0.0', () => console.log(`TN Game Connect Four: http://0.0.0.0:${PORT}`));
