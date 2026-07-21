export const PLATFORM_CONTRACT_VERSION = '1.0.0-alpha.1';

export const entityStatuses = Object.freeze([
  'draft', 'discovered', 'normalized', 'validated', 'canonical',
  'published', 'enriched', 'archived'
]);

export const relationshipStatuses = Object.freeze([
  'discovered', 'validated', 'active', 'superseded', 'archived'
]);

export function createEventEnvelope({ type, source, payload = {}, correlationId = null, id, timestamp }) {
  if (!type || !source || !id) throw new TypeError('type, source, and id are required');
  return {
    id,
    type,
    source,
    timestamp: timestamp ?? new Date().toISOString(),
    correlationId,
    contractVersion: PLATFORM_CONTRACT_VERSION,
    payload
  };
}

export function isEventEnvelope(value) {
  return Boolean(value && typeof value === 'object' && value.id && value.type && value.source && value.timestamp && value.payload !== undefined);
}
