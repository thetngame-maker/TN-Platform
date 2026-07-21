export class PlatformService {
  constructor({ id, name = id, version = '0.0.0', dependencies = [], capabilities = [] }) {
    if (!id) throw new TypeError('PlatformService requires id');
    this.id = id; this.name = name; this.version = version; this.dependencies = dependencies; this.capabilities = capabilities;
  }
  async configure(_context) {}
  async start(_context) {}
  async stop(_context) {}
  async health() { return { ok: true, status: 'healthy' }; }
  async metrics() { return {}; }
}
