import { DependencyContainer } from './container.js';
import { EventBus } from './event-bus.js';
import { ServiceRegistry } from './service-registry.js';
import { LifecycleError } from './errors.js';

export class Kernel {
  constructor({ manifest, logger = console } = {}) {
    if (!manifest) throw new TypeError('Kernel requires a validated manifest');
    this.manifest = manifest;
    this.logger = logger;
    this.container = new DependencyContainer();
    this.events = new EventBus({ onError: (error, event) => logger.error?.('Event handler failed', { error, event }) });
    this.registry = new ServiceRegistry({ strictDependencies: manifest.kernel?.strictDependencies !== false });
    this.state = 'created';
    this.container.register('kernel', this);
    this.container.register('manifest', manifest);
    this.container.register('events', this.events);
    this.container.register('services', this.registry);
    this.container.register('logger', logger);
  }

  register(service, descriptor = {}) {
    const result = this.registry.register(service, descriptor);
    this.container.register(`service:${result.id}`, service);
    return result;
  }

  resolve(id) { return this.container.resolve(id.startsWith('service:') ? id : `service:${id}`); }

  async configure() {
    this.state = 'configuring';
    for (const id of this.registry.startOrder()) {
      const record = this.registry.get(id);
      if (typeof record.service.configure === 'function') await record.service.configure({ kernel: this, manifest: this.manifest, config: this.serviceConfig(id) });
      this.registry.setState(id, 'configured');
    }
    this.state = 'configured';
  }

  serviceConfig(id) { return (this.manifest.services || []).find(service => service.id === id) || {}; }

  async start() {
    if (!['created','configured','stopped'].includes(this.state)) throw new LifecycleError(`Kernel cannot start from ${this.state}`);
    if (this.state !== 'configured') await this.configure();
    this.state = 'starting';
    await this.events.publish('kernel.starting', { platform: this.manifest.platform }, { source: 'kernel' });
    for (const id of this.registry.startOrder()) {
      const record = this.registry.get(id);
      this.registry.assertDependencies(id);
      this.registry.setState(id, 'starting');
      try {
        if (typeof record.service.start === 'function') await record.service.start({ kernel: this });
        this.registry.setState(id, 'running');
        await this.events.publish('service.started', { service: this.registry.snapshot(id) }, { source: 'kernel' });
      } catch (error) {
        this.registry.setState(id, 'failed', error);
        this.state = 'failed';
        await this.events.publish('service.failed', { serviceId: id, error: error.message }, { source: 'kernel' });
        throw error;
      }
    }
    this.state = 'running';
    await this.events.publish('kernel.started', { services: this.registry.list() }, { source: 'kernel' });
    return this.health();
  }

  async stop() {
    if (!['running','failed','configured'].includes(this.state)) return this.health();
    this.state = 'stopping';
    await this.events.publish('kernel.stopping', {}, { source: 'kernel' });
    for (const id of this.registry.startOrder().reverse()) {
      const record = this.registry.get(id);
      if (!['running','failed','configured'].includes(record.state)) continue;
      this.registry.setState(id, 'stopping');
      try { if (typeof record.service.stop === 'function') await record.service.stop({ kernel: this }); this.registry.setState(id, 'stopped'); }
      catch (error) { this.registry.setState(id, 'failed', error); }
    }
    this.state = 'stopped';
    await this.events.publish('kernel.stopped', {}, { source: 'kernel' });
    return this.health();
  }

  async health() {
    const services = [];
    for (const summary of this.registry.list()) {
      const service = this.registry.get(summary.id).service;
      let check = { ok: summary.state === 'running', status: summary.state };
      try { if (typeof service.health === 'function') check = await service.health(); }
      catch (error) { check = { ok: false, status: 'failed', error: error.message }; }
      services.push({ ...summary, health: check });
    }
    return {
      ok: this.state === 'running' && services.every(service => service.health?.ok !== false),
      state: this.state,
      platform: this.manifest.platform,
      kernel: { version: this.manifest.kernel.version },
      services,
      timestamp: new Date().toISOString()
    };
  }
}
