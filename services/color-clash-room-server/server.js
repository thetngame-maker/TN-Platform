import http from 'node:http';
import crypto from 'node:crypto';
import { readFile } from 'node:fs/promises';
import { extname, join, normalize } from 'node:path';

const PORT = Number(process.env.PORT || 8080);
const WEB_ROOT = join(process.cwd(), '../../experiences/web/color-clash-controller');
const rooms = new Map();
const alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
const colors = ['RED', 'GREEN', 'BLUE', 'GOLD'];

function code() {
  let value = '';
  do value = Array.from({ length: 4 }, () => alphabet[crypto.randomInt(alphabet.length)]).join('');
  while (rooms.has(value));
  return value;
}

function json(res, status, body) {
  res.writeHead(status, {
    'content-type': 'application/json; charset=utf-8',
    'access-control-allow-origin': '*',
    'access-control-allow-headers': 'content-type,authorization,x-player-token',
    'access-control-allow-methods': 'GET,POST,OPTIONS',
    'cache-control': 'no-store'
  });
  res.end(JSON.stringify(body));
}

async function body(req) {
  let raw = '';
  for await (const chunk of req) raw += chunk;
  if (!raw) return {};
  try { return JSON.parse(raw); } catch { return null; }
}

function touch(room) {
  room.version += 1;
  room.updatedAt = Date.now();
}

function buildDeck() {
  const deck = [];
  for (const color of colors) {
    for (let number = 0; number <= 9; number += 1) deck.push({ id: crypto.randomUUID(), color, value: String(number) });
    for (const value of ['SKIP', 'REVERSE', '+2']) deck.push({ id: crypto.randomUUID(), color, value });
  }
  for (let i = 0; i < 4; i += 1) deck.push({ id: crypto.randomUUID(), color: 'WILD', value: 'WILD' });
  for (let i = deck.length - 1; i > 0; i -= 1) {
    const j = crypto.randomInt(i + 1);
    [deck[i], deck[j]] = [deck[j], deck[i]];
  }
  return deck;
}

function draw(room) {
  if (!room.game.deck.length && room.game.discard.length > 1) {
    const top = room.game.discard.pop();
    room.game.deck = room.game.discard;
    room.game.discard = [top];
    for (let i = room.game.deck.length - 1; i > 0; i -= 1) {
      const j = crypto.randomInt(i + 1);
      [room.game.deck[i], room.game.deck[j]] = [room.game.deck[j], room.game.deck[i]];
    }
  }
  return room.game.deck.pop() || null;
}

function nextIndex(room, from = room.game.turnIndex) {
  const count = room.players.length;
  return (from + room.game.direction + count) % count;
}

function publicRoom(room) {
  const game = room.game ? {
    activeColor: room.game.activeColor,
    topCard: room.game.discard.at(-1),
    deckCount: room.game.deck.length,
    turnPlayerId: room.players[room.game.turnIndex]?.id || null,
    direction: room.game.direction,
    winnerId: room.game.winnerId,
    message: room.game.message,
    players: room.players.map(player => ({ id: player.id, name: player.name, cardCount: player.hand.length }))
  } : null;
  return {
    code: room.code,
    status: room.status,
    version: room.version,
    players: room.players.map(({ id, name, joinedAt }) => ({ id, name, joinedAt })),
    game
  };
}

function privateState(room, player) {
  return {
    room: publicRoom(room),
    player: {
      id: player.id,
      name: player.name,
      hand: player.hand,
      isTurn: room.status === 'playing' && room.players[room.game.turnIndex]?.id === player.id
    }
  };
}

function findPlayer(room, req, data = {}) {
  const token = req.headers['x-player-token'] || data.token;
  return room.players.find(player => player.token === token);
}

function legal(room, card) {
  const top = room.game.discard.at(-1);
  return card.value === 'WILD' || card.color === room.game.activeColor || card.value === top.value;
}

function startGame(room) {
  room.game = {
    deck: buildDeck(), discard: [], activeColor: 'RED', turnIndex: 0,
    direction: 1, winnerId: null, message: 'Game started'
  };
  for (const player of room.players) player.hand = [];
  for (let round = 0; round < 7; round += 1) {
    for (const player of room.players) player.hand.push(draw(room));
  }
  let top = draw(room);
  while (top && ['SKIP', 'REVERSE', '+2', 'WILD'].includes(top.value)) {
    room.game.deck.unshift(top);
    top = draw(room);
  }
  room.game.discard.push(top);
  room.game.activeColor = top.color;
  room.status = 'playing';
}

function applyCard(room, playerIndex, card, chosenColor) {
  const game = room.game;
  game.discard.push(card);
  game.activeColor = card.value === 'WILD' ? (colors.includes(chosenColor) ? chosenColor : 'RED') : card.color;
  game.message = `${room.players[playerIndex].name} played ${card.value}`;
  if (room.players[playerIndex].hand.length === 0) {
    game.winnerId = room.players[playerIndex].id;
    room.status = 'finished';
    game.message = `${room.players[playerIndex].name} wins!`;
    return;
  }

  let target = nextIndex(room, playerIndex);
  if (card.value === 'REVERSE') {
    game.direction *= -1;
    target = nextIndex(room, playerIndex);
  } else if (card.value === 'SKIP') {
    target = nextIndex(room, target);
  } else if (card.value === '+2') {
    for (let i = 0; i < 2; i += 1) {
      const drawn = draw(room);
      if (drawn) room.players[target].hand.push(drawn);
    }
    target = nextIndex(room, target);
  }
  game.turnIndex = target;
}

async function api(req, res, url) {
  if (req.method === 'OPTIONS') return json(res, 204, {});
  if (req.method === 'POST' && url.pathname === '/api/rooms') {
    const roomCode = code();
    const room = { code: roomCode, status: 'lobby', version: 1, players: [], game: null, createdAt: Date.now(), updatedAt: Date.now() };
    rooms.set(roomCode, room);
    return json(res, 201, publicRoom(room));
  }

  const match = url.pathname.match(/^\/api\/rooms\/([A-Z0-9]{4})(?:\/(join|start|state|play|draw))?$/);
  if (!match) return false;
  const room = rooms.get(match[1]);
  if (!room) return json(res, 404, { error: 'Room not found' });

  if (req.method === 'GET' && !match[2]) return json(res, 200, publicRoom(room));

  if (req.method === 'POST' && match[2] === 'join') {
    const data = await body(req);
    const name = String(data?.name || '').trim().slice(0, 18);
    if (name.length < 2) return json(res, 400, { error: 'Player name is required' });
    if (room.status !== 'lobby') return json(res, 409, { error: 'Game already started' });
    if (room.players.length >= 4) return json(res, 409, { error: 'Room is full' });
    const player = { id: crypto.randomUUID(), token: crypto.randomBytes(18).toString('hex'), name, joinedAt: Date.now(), hand: [] };
    room.players.push(player);
    touch(room);
    return json(res, 201, { room: publicRoom(room), player: { id: player.id, name: player.name, token: player.token } });
  }

  if ((req.method === 'POST' || req.method === 'GET') && match[2] === 'start') {
    if (room.status === 'playing' || room.status === 'finished') return json(res, 200, publicRoom(room));
    if (room.players.length < 1) return json(res, 409, { error: 'At least one player must join' });
    if (room.status !== 'lobby') return json(res, 409, { error: 'Game cannot be started' });
    startGame(room);
    touch(room);
    return json(res, 200, publicRoom(room));
  }

  if (req.method === 'GET' && match[2] === 'state') {
    const player = findPlayer(room, req);
    if (!player) return json(res, 401, { error: 'Invalid player token' });
    return json(res, 200, privateState(room, player));
  }

  if (req.method === 'POST' && match[2] === 'play') {
    const data = await body(req);
    const player = findPlayer(room, req, data || {});
    if (!player) return json(res, 401, { error: 'Invalid player token' });
    if (room.status !== 'playing') return json(res, 409, { error: 'Game is not active' });
    const playerIndex = room.players.indexOf(player);
    if (playerIndex !== room.game.turnIndex) return json(res, 409, { error: 'It is not your turn' });
    const cardIndex = player.hand.findIndex(card => card.id === data?.cardId);
    if (cardIndex < 0) return json(res, 404, { error: 'Card not found' });
    const card = player.hand[cardIndex];
    if (!legal(room, card)) return json(res, 409, { error: 'That card does not match' });
    player.hand.splice(cardIndex, 1);
    applyCard(room, playerIndex, card, String(data?.chosenColor || '').toUpperCase());
    touch(room);
    return json(res, 200, privateState(room, player));
  }

  if (req.method === 'POST' && match[2] === 'draw') {
    const data = await body(req);
    const player = findPlayer(room, req, data || {});
    if (!player) return json(res, 401, { error: 'Invalid player token' });
    if (room.status !== 'playing') return json(res, 409, { error: 'Game is not active' });
    const playerIndex = room.players.indexOf(player);
    if (playerIndex !== room.game.turnIndex) return json(res, 409, { error: 'It is not your turn' });
    const card = draw(room);
    if (card) player.hand.push(card);
    room.game.message = `${player.name} drew a card`;
    room.game.turnIndex = nextIndex(room, playerIndex);
    touch(room);
    return json(res, 200, privateState(room, player));
  }

  return json(res, 405, { error: 'Method not allowed' });
}

async function staticFile(req, res, url) {
  const requested = url.pathname === '/' ? '/index.html' : url.pathname;
  const safe = normalize(requested).replace(/^([.][.][/\\])+/, '');
  const file = join(WEB_ROOT, safe);
  try {
    const data = await readFile(file);
    const type = { '.html': 'text/html', '.css': 'text/css', '.js': 'text/javascript', '.json': 'application/json' }[extname(file)] || 'application/octet-stream';
    res.writeHead(200, { 'content-type': `${type}; charset=utf-8`, 'cache-control': 'no-store' });
    res.end(data);
  } catch {
    res.writeHead(404, { 'content-type': 'text/plain; charset=utf-8' });
    res.end('Not found');
  }
}

const server = http.createServer(async (req, res) => {
  const url = new URL(req.url, `http://${req.headers.host || 'localhost'}`);
  try {
    if (url.pathname.startsWith('/api/')) {
      const handled = await api(req, res, url);
      if (handled === false) json(res, 404, { error: 'API route not found' });
      return;
    }
    await staticFile(req, res, url);
  } catch (error) {
    console.error(error);
    json(res, 500, { error: 'Internal server error' });
  }
});

setInterval(() => {
  const cutoff = Date.now() - 6 * 60 * 60 * 1000;
  for (const [roomCode, room] of rooms) if (room.updatedAt < cutoff) rooms.delete(roomCode);
}, 15 * 60 * 1000).unref();

server.listen(PORT, '0.0.0.0', () => console.log(`Color Clash room server: http://0.0.0.0:${PORT}`));