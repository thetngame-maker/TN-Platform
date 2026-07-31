import { readFile, writeFile } from 'node:fs/promises';
import { join } from 'node:path';

const sourcePath = join(process.cwd(), 'server.js');
const runtimePath = join(process.cwd(), '.server-runtime.mjs');
const source = await readFile(sourcePath, 'utf8');

const original = `function json(res, status, body) {
  res.writeHead(status, {
    'content-type': 'application/json; charset=utf-8',
    'access-control-allow-origin': '*',
    'access-control-allow-headers': 'content-type,authorization,x-player-token',
    'access-control-allow-methods': 'GET,POST,OPTIONS',
    'cache-control': 'no-store'
  });
  res.end(JSON.stringify(body));
}`;

const replacement = `function json(res, status, body) {
  const payload = Buffer.from(JSON.stringify(body), 'utf8');
  res.writeHead(status, {
    'content-type': 'application/json; charset=utf-8',
    'content-length': payload.length,
    'access-control-allow-origin': '*',
    'access-control-allow-headers': 'content-type,authorization,x-player-token',
    'access-control-allow-methods': 'GET,POST,OPTIONS',
    'cache-control': 'no-store',
    connection: 'close'
  });
  res.end(payload);
}`;

if (!source.includes(original)) {
  throw new Error('Could not locate the JSON response helper in server.js');
}

await writeFile(runtimePath, source.replace(original, replacement));
await import(`./.server-runtime.mjs?run=${Date.now()}`);
