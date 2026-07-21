import { DependencyError, LifecycleError } from './errors.js';

const VALID_STATES = new Set(['discovered','registered','configured','starting','running','paused','stopping','stopped','failed']);

export class ServiceRegistry {
  #records = new Map();
  constructor({ strictDependencies = true } = {}) { this.strictDependencies = strictDependencies; }

  register(service, descriptor = {}) {
    const id = descriptor.id || service?.id;
    if (!id) throw new TypeError('Service id is required');
    if (this.#records.has(id)) throw new LifecycleError(`Service already registered: ${id}`);
    const record = {
      id, name: descriptor.name || service.name || id, version: descriptor.version || service.version || '0.0.0',
      dependencies: [...(descriptor.dependencies || service.dependencies || [])], capabilities: [...(descriptor.capabilities || service.capabilities || [])],
      state: 'registered', service, registeredAt: new Date().toISOString(), startedAt: null, stoppedAt: null, lastError: null
    };
    this.#records.set(id, record);
    return this.snapshot(id);
  }

  get(id) { return this.#records.get(id) || null; }
  has(id) { return this.#records.has(id); }
  list() { return [...this.#records.keys()].map(id => this.snapshot(id)); }
  setState(id, state, error = null) {
    if (!VALID_STATES.has(state)) throw new LifecycleError(`Invalid lifecycle state: ${state}`);
    const record = this.#records.get(id);
    if (!record) throw new LifecycleError(`Unknown service: ${id}`);
    record.state = state;
    if (state === 'running') record.startedAt = new Date().toISOString();
    if (state === 'stopped') record.stoppedAt = new Date().toISOString();
    record.lastError = error ? String(error.message || error) : null;
    return this.snapshot(id);
  }

  assertDependencies(id) {
    const record = this.#records.get(id);
    if (!record) throw new DependencyError(`Unknown service: ${id}`);
    const missing = record.dependencies.filter(dependency => !this.#records.has(dependency));
    if (missing.length && this.strictDependencies) throw new DependencyError(`Missing dependencies for ${id}: ${missing.join(', ')}`, { service: id, missing });
    const unavailable = record.dependencies.filter(dependency => this.#records.get(dependency)?.state !== 'running');
    if (unavailable.length) throw new DependencyError(`Dependencies not running for ${id}: ${unavailable.join(', ')}`, { service: id, unavailable });
  }

  startOrder() {
    const order = [];
    const temporary = new Set();
    const permanent = new Set();
    const visit = id => {
      if (permanent.has(id)) return;
      if (temporary.has(id)) throw new DependencyError(`Circular service dependency involving ${id}`);
      temporary.add(id);
      for (const dependency of this.#records.get(id)?.dependencies || []) {
        if (!this.#records.has(dependency)) {
          if (this.strictDependencies) throw new DependencyError(`Unknown dependency ${dependency} required by ${id}`);
          continue;
        }
        visit(dependency);
      }
      temporary.delete(id); permanent.add(id); order.push(id);
    };
    for (const id of this.#records.keys()) visit(id);
    return order;
  }

  snapshot(id) {
    const record = this.#records.get(id);
    if (!record) return null;
    const { service: _service, ...publicRecord } = record;
    return structuredClone(publicRecord);
  }
}
