export class PlatformExtension {
  constructor(manifest) {
    if (!manifest?.id || !manifest?.version) throw new TypeError('Extension id and version are required');
    this.manifest = Object.freeze({ ...manifest });
  }

  async register(_context) {
    throw new Error('register(context) must be implemented');
  }
}

export class DiscoveryProvider extends PlatformExtension {
  async discover(_input, _context) { throw new Error('discover must be implemented'); }
  async normalize(_record, _context) { throw new Error('normalize must be implemented'); }
  async validate(_record, _context) { return { valid: true, errors: [] }; }
}
