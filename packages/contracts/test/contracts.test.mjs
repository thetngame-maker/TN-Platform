import test from 'node:test';
import assert from 'node:assert/strict';
import { createEventEnvelope, isEventEnvelope, entityStatuses } from '../src/index.js';

test('creates a valid event envelope', () => {
  const event = createEventEnvelope({ id: 'evt-1', type: 'EntityCreated', source: 'knowledge', payload: { id: 'ent-1' } });
  assert.equal(event.type, 'EntityCreated');
  assert.equal(isEventEnvelope(event), true);
});

test('canonical is a supported entity status', () => {
  assert.equal(entityStatuses.includes('canonical'), true);
});
