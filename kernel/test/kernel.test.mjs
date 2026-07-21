import test from 'node:test';
import assert from 'node:assert/strict';
import { Kernel, PlatformService, validateManifest, parseSimpleYaml } from '../src/index.js';

const manifest = {
  platform: { id: 'test', name: 'Test', version: '1.0.0' },
  kernel: { version: '0.1.0', strictDependencies: true },
  services: [
    { id: 'a', version: '1.0.0', dependencies: [] },
    { id: 'b', version: '1.0.0', dependencies: ['a'] }
  ]
};

test('manifest validation detects required data', () => {
  assert.equal(validateManifest(manifest).valid, true);
  assert.equal(validateManifest({}).valid, false);
});

test('simple yaml parser handles manifest structures', () => {
  const value = parseSimpleYaml('platform:\n  id: tn\nservices:\n  - id: core\n    enabled: true\n    dependencies: []\n');
  assert.equal(value.platform.id, 'tn');
  assert.equal(value.services[0].id, 'core');
  assert.equal(value.services[0].enabled, true);
});

test('kernel starts in dependency order and stops in reverse', async () => {
  const calls = [];
  class Service extends PlatformService {
    async start() { calls.push(`start:${this.id}`); }
    async stop() { calls.push(`stop:${this.id}`); }
  }
  const kernel = new Kernel({ manifest, logger: { error() {} } });
  kernel.register(new Service({ id: 'b', dependencies: ['a'] }));
  kernel.register(new Service({ id: 'a' }));
  await kernel.start();
  assert.deepEqual(calls, ['start:a','start:b']);
  assert.equal((await kernel.health()).ok, true);
  await kernel.stop();
  assert.deepEqual(calls, ['start:a','start:b','stop:b','stop:a']);
});

test('event bus delivers wildcard and typed subscriptions', async () => {
  const kernel = new Kernel({ manifest, logger: { error() {} } });
  const received = [];
  kernel.events.subscribe('custom', event => received.push(`typed:${event.payload.value}`));
  kernel.events.subscribe('*', event => { if (event.type === 'custom') received.push(`all:${event.payload.value}`); });
  const result = await kernel.events.publish('custom', { value: 7 });
  assert.equal(result.delivered, 2);
  assert.deepEqual(received, ['typed:7','all:7']);
});
