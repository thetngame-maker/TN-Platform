import test from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import { findRoot } from '../src/utils.js';

test('findRoot locates platform manifest', async () => {
  const root = await findRoot(path.resolve(import.meta.dirname, '../..'));
  assert.equal(path.basename(root), 'tn-platform-kernel-mvp');
});
