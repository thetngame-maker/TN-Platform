import { DependencyError } from './errors.js';

export class DependencyContainer {
  #values = new Map();
  #factories = new Map();
  #resolving = new Set();

  register(id, value, { replace = false } = {}) {
    if (!replace && (this.#values.has(id) || this.#factories.has(id))) throw new DependencyError(`Dependency already registered: ${id}`);
    this.#factories.delete(id);
    this.#values.set(id, value);
    return value;
  }

  factory(id, creator, { singleton = true, replace = false } = {}) {
    if (typeof creator !== 'function') throw new TypeError('Factory creator must be a function');
    if (!replace && (this.#values.has(id) || this.#factories.has(id))) throw new DependencyError(`Dependency already registered: ${id}`);
    this.#values.delete(id);
    this.#factories.set(id, { creator, singleton });
  }

  has(id) { return this.#values.has(id) || this.#factories.has(id); }

  resolve(id) {
    if (this.#values.has(id)) return this.#values.get(id);
    const record = this.#factories.get(id);
    if (!record) throw new DependencyError(`Dependency not found: ${id}`);
    if (this.#resolving.has(id)) throw new DependencyError(`Circular dependency detected while resolving ${id}`);
    this.#resolving.add(id);
    try {
      const value = record.creator(this);
      if (record.singleton) this.#values.set(id, value);
      return value;
    } finally { this.#resolving.delete(id); }
  }

  entries() { return [...new Set([...this.#values.keys(), ...this.#factories.keys()])]; }
}
