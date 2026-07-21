import crypto from 'node:crypto';
import os from 'node:os';

const startedAt = Date.now();
const services = new Map();
const logs = [];
const counters = new Map();
const gauges = new Map();
const MAX_LOGS = 500;

export function correlationId(req) {
  return req.headers['x-correlation-id'] || crypto.randomUUID();
}

export function requestContext(req, res, next) {
  const id = correlationId(req);
  req.correlationId = id;
  res.setHeader('x-correlation-id', id);
  const started = Date.now();
  res.on('finish', () => {
    increment('http_requests_total', 1, { method: req.method, route: req.route?.path || req.path, status: res.statusCode });
    observeGauge('http_last_duration_ms', Date.now() - started);
  });
  next();
}

export function registerService(service) {
  const normalized = {
    id: service.id,
    name: service.name || service.id,
    version: service.version || '0.0.0',
    status: service.status || 'running',
    description: service.description || '',
    capabilities: service.capabilities || [],
    dependencies: service.dependencies || [],
    endpoints: service.endpoints || [],
    registered_at: service.registered_at || new Date().toISOString(),
    last_seen_at: new Date().toISOString(),
  };
  services.set(normalized.id, normalized);
  return normalized;
}

export function listServices() { return [...services.values()]; }

export function log(entry) {
  const item = {
    id: crypto.randomUUID(),
    timestamp: new Date().toISOString(),
    level: entry.level || 'info',
    service: entry.service || 'platform-core',
    event: entry.event || 'Log',
    message: entry.message || '',
    correlation_id: entry.correlation_id || null,
    metadata: entry.metadata || {},
  };
  logs.unshift(item);
  if (logs.length > MAX_LOGS) logs.length = MAX_LOGS;
  return item;
}

export function listLogs({ service = '', level = '', q = '', limit = 100 } = {}) {
  let rows = logs;
  if (service) rows = rows.filter(x => x.service === service);
  if (level) rows = rows.filter(x => x.level === level);
  if (q) { const n = q.toLowerCase(); rows = rows.filter(x => JSON.stringify(x).toLowerCase().includes(n)); }
  return rows.slice(0, Math.min(500, Number(limit) || 100));
}

function metricKey(name, labels = {}) { return `${name}|${JSON.stringify(labels)}`; }
export function increment(name, value = 1, labels = {}) {
  const key = metricKey(name, labels);
  counters.set(key, { name, type: 'counter', value: (counters.get(key)?.value || 0) + value, labels });
}
export function observeGauge(name, value, labels = {}) {
  const key = metricKey(name, labels);
  gauges.set(key, { name, type: 'gauge', value, labels });
}
export function metrics() {
  return {
    uptime_seconds: Math.floor((Date.now() - startedAt) / 1000),
    process: { pid: process.pid, node: process.version, memory: process.memoryUsage(), platform: process.platform, hostname: os.hostname() },
    counters: [...counters.values()],
    gauges: [...gauges.values()],
  };
}

export function configurationSnapshot(config) {
  return {
    environment: process.env.NODE_ENV || 'production',
    log_level: process.env.LOG_LEVEL || 'info',
    data_dir: process.env.DATA_DIR || './data',
    browser_headless: config.browserHeadless,
    request_timeout_ms: config.REQUEST_TIMEOUT_MS,
    cache_ttl_seconds: config.CACHE_TTL_SECONDS,
    max_events_per_sync: config.MAX_EVENTS_PER_SYNC,
    allowed_groups: config.allowedGroups,
    features: {
      knowledge: true,
      graph: true,
      discovery: true,
      browser_intelligence: true,
      platform_registry: true,
      structured_logging: true,
      metrics: true,
    },
  };
}

export function platformHealth(extra = {}) {
  return {
    ok: true,
    status: 'healthy',
    service: 'tn-platform-core',
    version: '3.1.0',
    uptime_seconds: Math.floor((Date.now() - startedAt) / 1000),
    timestamp: new Date().toISOString(),
    services: listServices(),
    ...extra,
  };
}
