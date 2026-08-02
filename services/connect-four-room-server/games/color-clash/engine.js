const crypto = require('crypto');

const COLORS = ['orange', 'gold', 'blue', 'purple', 'green', 'pink'];
const rooms = new Map();

function code() {
  const alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
  let value = '';
  do {
    value = Array.from({ length: 4 }, () => alphabet[Math.floor(Math.random() * alphabet.length)]).join('');
  } while (rooms.has(value));
  return value;
}

function token() {
  return crypto.randomBytes(12).toString('hex');
}

function shuffle(values) {
  const copy = [...values];
  for (let i = copy.length - 1; i > 0; i -= 1) {
    const j = Math.floor(Math.random() * (i + 1));
    [copy[i], copy[j]] = [copy[j], copy[i]];
  }
  return copy;
}

function buildDeck() {
  const cards = [];
  for (const color of COLORS) {
    for (let value = 1; value <= 9; value += 1) {
      cards.push({ id: `${color}-${value}-a`, color, value: String(value) });
      cards.push({ id: `${color}-${value}-b`, color, value: String(value) });
    }
  }
  return shuffle(cards);
}

function draw(room) {
  if (!room.deck.length) {
    const top = room.discard.pop();
    room.deck = shuffle(room.discard);
    room.discard = top ? [top] : [];
  }
  return room.deck.pop() || null;
}

function start(room) {
  room.deck = buildDeck();
  room.discard = [];
  room.turn = 0;
  room.winnerId = null;
  room.phase = 'playing';
  room.lastMove = `${room.players[0].name} goes first`;
  for (const player of room.players) {
    player.hand = [];
    for (let i = 0; i < 7; i += 1) player.hand.push(draw(room));
  }
  room.discard.push(draw(room));
}

function createRoom() {
  const room = {
    code: code(),
    game: 'color-clash',
    phase: 'lobby',
    players: [],
    deck: [],
    discard: [],
    turn: 0,
    winnerId: null,
    lastMove: 'Waiting for players',
    createdAt: Date.now(),
  };
  rooms.set(room.code, room);
  return room;
}

function findRoom(roomCode) {
  return rooms.get(String(roomCode || '').toUpperCase()) || null;
}

function joinRoom(room, { name, color }) {
  if (room.players.length >= 2) throw new Error('Room is full');
  const safeName = String(name || '').trim().slice(0, 18);
  if (safeName.length < 2) throw new Error('Enter your player name');
  const safeColor = COLORS.includes(color) ? color : COLORS[room.players.length];
  const player = {
    id: token(),
    token: token(),
    name: safeName,
    color: safeColor,
    hand: [],
  };
  room.players.push(player);
  room.lastMove = `${player.name} joined Color Clash`;
  if (room.players.length === 2) start(room);
  return player;
}

function currentPlayer(room) {
  return room.players[room.turn] || null;
}

function publicPlayers(room) {
  return room.players.map((player) => ({
    id: player.id,
    name: player.name,
    color: player.color,
    cardCount: player.hand.length,
  }));
}

function boardFor(room) {
  const board = Array.from({ length: 6 }, () => Array(7).fill(0));
  const top = room.discard[room.discard.length - 1];
  if (top) board[2][3] = 1;
  return board;
}

function tvState(room) {
  const current = currentPlayer(room);
  const top = room.discard[room.discard.length - 1] || null;
  const winner = room.players.find((player) => player.id === room.winnerId);
  if (room.phase === 'lobby') {
    return {
      game: 'color-clash',
      screen: 'lobby',
      code: room.code,
      players: publicPlayers(room),
      currentPlayerId: null,
      playersLabel: `${room.players.length} of 2 players connected`,
    };
  }
  return {
    game: 'color-clash',
    screen: room.phase === 'finished' ? 'finished' : 'playing',
    code: room.code,
    title: winner ? `${winner.name.toUpperCase()} WINS!` : `${current.name.toUpperCase()}'S TURN`,
    subtitle: winner ? `${winner.name} emptied their hand` : `MATCH ${top ? `${top.color.toUpperCase()} ${top.value}` : ''} • ${room.lastMove}`,
    players: publicPlayers(room),
    currentPlayerId: current ? current.id : null,
    playersLabel: publicPlayers(room).map((player) => `${player.name}: ${player.cardCount} cards`).join('  •  '),
    discard: top,
    board: boardFor(room),
  };
}

function playerState(room, playerToken) {
  const player = room.players.find((candidate) => candidate.token === playerToken);
  if (!player) throw new Error('Player session not found');
  const current = currentPlayer(room);
  const top = room.discard[room.discard.length - 1] || null;
  return {
    game: 'color-clash',
    room: room.code,
    phase: room.phase,
    player: { id: player.id, name: player.name, color: player.color },
    hand: player.hand,
    discard: top,
    isTurn: Boolean(current && current.id === player.id && room.phase === 'playing'),
    currentPlayerName: current ? current.name : '',
    winnerId: room.winnerId,
    players: publicPlayers(room),
    lastMove: room.lastMove,
  };
}

function playCard(room, playerToken, index) {
  const player = room.players.find((candidate) => candidate.token === playerToken);
  if (!player) throw new Error('Player session not found');
  const current = currentPlayer(room);
  if (!current || current.id !== player.id) throw new Error('Wait for your turn');
  const cardIndex = Number(index);
  const card = player.hand[cardIndex];
  if (!card) throw new Error('Card not found');
  const top = room.discard[room.discard.length - 1];
  if (top && card.color !== top.color && card.value !== top.value) {
    throw new Error(`Play a ${top.color} card or a ${top.value}`);
  }
  player.hand.splice(cardIndex, 1);
  room.discard.push(card);
  room.lastMove = `${player.name} played ${card.color} ${card.value}`;
  if (player.hand.length === 0) {
    room.phase = 'finished';
    room.winnerId = player.id;
    return;
  }
  room.turn = (room.turn + 1) % room.players.length;
}

function drawCard(room, playerToken) {
  const player = room.players.find((candidate) => candidate.token === playerToken);
  if (!player) throw new Error('Player session not found');
  const current = currentPlayer(room);
  if (!current || current.id !== player.id) throw new Error('Wait for your turn');
  const card = draw(room);
  if (card) player.hand.push(card);
  room.lastMove = `${player.name} drew a card`;
  room.turn = (room.turn + 1) % room.players.length;
}

function restart(room) {
  if (room.players.length === 2) start(room);
}

function json(res, status, value) {
  const body = JSON.stringify(value);
  res.writeHead(status, {
    'Content-Type': 'application/json; charset=utf-8',
    'Content-Length': Buffer.byteLength(body),
    'Cache-Control': 'no-store',
  });
  res.end(body);
}

async function readJson(req) {
  let body = '';
  for await (const chunk of req) body += chunk;
  if (!body) return {};
  return JSON.parse(body);
}

async function handleRequest(req, res, url) {
  if (req.method === 'GET' && url.pathname === '/api/rooms/create' && url.searchParams.get('game') === 'color-clash') {
    const room = createRoom();
    json(res, 200, { code: room.code, game: room.game });
    return true;
  }

  let match = url.pathname.match(/^\/api\/rooms\/([A-Za-z0-9]+)\/join$/);
  if (req.method === 'POST' && match) {
    const room = findRoom(match[1]);
    if (!room) return false;
    try {
      const payload = await readJson(req);
      const player = joinRoom(room, payload);
      json(res, 200, { player: { id: player.id, token: player.token, name: player.name, color: player.color }, game: room.game });
    } catch (error) {
      json(res, 400, { error: error.message });
    }
    return true;
  }

  match = url.pathname.match(/^\/api\/rooms\/([A-Za-z0-9]+)\/tv$/);
  if (req.method === 'GET' && match) {
    const room = findRoom(match[1]);
    if (!room) return false;
    json(res, 200, tvState(room));
    return true;
  }

  match = url.pathname.match(/^\/api\/rooms\/([A-Za-z0-9]+)\/restart$/);
  if (req.method === 'POST' && match) {
    const room = findRoom(match[1]);
    if (!room) return false;
    restart(room);
    json(res, 200, tvState(room));
    return true;
  }

  match = url.pathname.match(/^\/api\/color-clash\/rooms\/([A-Za-z0-9]+)\/player$/);
  if (req.method === 'GET' && match) {
    const room = findRoom(match[1]);
    if (!room) return false;
    try {
      json(res, 200, playerState(room, url.searchParams.get('token')));
    } catch (error) {
      json(res, 404, { error: error.message });
    }
    return true;
  }

  match = url.pathname.match(/^\/api\/color-clash\/rooms\/([A-Za-z0-9]+)\/(play|draw)$/);
  if (req.method === 'POST' && match) {
    const room = findRoom(match[1]);
    if (!room) return false;
    try {
      const payload = await readJson(req);
      if (match[2] === 'play') playCard(room, payload.token, payload.index);
      else drawCard(room, payload.token);
      json(res, 200, playerState(room, payload.token));
    } catch (error) {
      json(res, 400, { error: error.message });
    }
    return true;
  }

  return false;
}

module.exports = { handleRequest, findRoom };
