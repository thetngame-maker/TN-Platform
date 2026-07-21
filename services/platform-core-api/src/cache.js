const cache = new Map();

export function getCached(key) {
  const item = cache.get(key);
  if (!item) return null;
  if (item.expiresAt < Date.now()) {
    cache.delete(key);
    return null;
  }
  return item.value;
}

export function setCached(key, value, ttlSeconds) {
  if (ttlSeconds <= 0) return;
  cache.set(key, { value, expiresAt: Date.now() + ttlSeconds * 1000 });
}
