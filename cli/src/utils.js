import fs from 'node:fs/promises';
import path from 'node:path';

export async function findRoot(start = process.cwd()) {
  let current = path.resolve(start);
  while (true) {
    try { await fs.access(path.join(current, 'platform.yaml')); return current; } catch {}
    const parent = path.dirname(current);
    if (parent === current) throw new Error('Could not find platform.yaml. Run this command inside a TN Platform repository.');
    current = parent;
  }
}

export function printJson(value) { process.stdout.write(`${JSON.stringify(value, null, 2)}\n`); }
export function pass(label, detail = '') { console.log(`✓ ${label}${detail ? ` — ${detail}` : ''}`); }
export function fail(label, detail = '') { console.log(`✗ ${label}${detail ? ` — ${detail}` : ''}`); }
