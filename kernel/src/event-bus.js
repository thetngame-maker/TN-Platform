import crypto from 'node:crypto';

export class EventBus {
  #handlers = new Map();
  #history = [];
  constructor({ historyLimit = 250, onError = null } = {}) { this.historyLimit = historyLimit; this.onError = onError; }

  subscribe(type, handler) {
    if (typeof handler !== 'function') throw new TypeError('Event handler must be a function');
    const handlers = this.#handlers.get(type) || new Set();
    handlers.add(handler);
    this.#handlers.set(type, handlers);
    return () => this.unsubscribe(type, handler);
  }

  unsubscribe(type, handler) { return this.#handlers.get(type)?.delete(handler) || false; }

  async publish(type, payload = {}, metadata = {}) {
    const event = Object.freeze({
      id: metadata.id || crypto.randomUUID(), type, source: metadata.source || 'kernel',
      timestamp: metadata.timestamp || new Date().toISOString(), correlationId: metadata.correlationId || null,
      payload
    });
    this.#history.unshift(event);
    if (this.#history.length > this.historyLimit) this.#history.length = this.historyLimit;
    const handlers = [...(this.#handlers.get(type) || []), ...(this.#handlers.get('*') || [])];
    const results = await Promise.allSettled(handlers.map(handler => handler(event)));
    for (const result of results) if (result.status === 'rejected' && this.onError) this.onError(result.reason, event);
    return { event, delivered: handlers.length, failures: results.filter(result => result.status === 'rejected').length };
  }

  history({ type = '', limit = 100 } = {}) {
    const events = type ? this.#history.filter(event => event.type === type) : this.#history;
    return events.slice(0, Math.max(0, Number(limit) || 100));
  }
}
