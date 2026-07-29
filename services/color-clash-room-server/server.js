import http from 'node:http';
import crypto from 'node:crypto';
import { readFile } from 'node:fs/promises';
import { extname, join, normalize } from 'node:path';

const PORT = Number(process.env.PORT || 8080);
const WEB_ROOT = join(process.cwd(), '../../experiences/web/color-clash-controller');
const rooms = new Map();
const alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

function code() {
  let value = '';
  do {
    value = Array.from({ length: 4 }, () => alphabet[crypto.randomInt(alphabet.length)]).join('');
  } while (rooms.has(value));
  return value;
}

function json(res, status, body) {
  res.writeHead(status, {
    'content-type': 'application/json; charset=utf-8',
    'access-control-allow-origin': '*',
    'access-control-allow-headers': 'content-type',
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

function publicRoom(room) {
  return {
    code: room.code,
    status: room.status,
    version: room.version,
    players: room.players.map(({ id, name, joinedAt }) => ({ id, name, joinedAt }))
  };
}

function touch(room) {
  room.version += 1;
  room.updatedAt = Date.now();
}

async function api(req, res, url) {
  if (req.method === 'OPTIONS') return json(res, 204, {});
  if (req.method === 'POST' && url.pathname === '/api/rooms') {
    const roomCode = code();
    const room = { code: roomCode, status: 'lobby', version: 1, players: [], createdAt: Date.now(), updatedAt: Date.now() };
    rooms.set(roomCode, room);
    return json(res, 201, publicRoom(room));
  }

  const match = url.pathname.match(/^\/api\/rooms\/([A-Z0-9]{4})(?:\/(join|start))?$/);
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

  if (req.method === 'POST' && match[2] === 'start') {
    if (room.players.length < 1) return json(res, 409, { error: 'At least one player must join' });
    room.status = 'playing';
    touch(room);
    return json(res, 200, publicRoom(room));
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
