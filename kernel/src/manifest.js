import fs from 'node:fs/promises';
import path from 'node:path';
import { parseSimpleYaml } from './simple-yaml.js';
import { KernelError } from './errors.js';

export function validateManifest(manifest) {
  const errors = [];
  if (!manifest?.platform?.id) errors.push('platform.id is required');
  if (!manifest?.platform?.name) errors.push('platform.name is required');
  if (!manifest?.platform?.version) errors.push('platform.version is required');
  if (!manifest?.kernel?.version) errors.push('kernel.version is required');
  if (!Array.isArray(manifest?.services)) errors.push('services must be an array');
  const ids = new Set();
  for (const service of manifest?.services || []) {
    if (!service.id) errors.push('every service requires id');
    if (ids.has(service.id)) errors.push(`duplicate service id: ${service.id}`);
    ids.add(service.id);
    if (!service.version) errors.push(`service ${service.id || '<unknown>'} requires version`);
    if (!Array.isArray(service.dependencies)) errors.push(`service ${service.id || '<unknown>'} dependencies must be an array`);
  }
  for (const service of manifest?.services || []) {
    for (const dependency of service.dependencies || []) {
      if (!ids.has(dependency)) errors.push(`service ${service.id} references unknown dependency ${dependency}`);
    }
  }
  return { valid: errors.length === 0, errors };
}

export async function loadManifest(filePath = path.resolve('platform.yaml')) {
  let text;
  try { text = await fs.readFile(filePath, 'utf8'); }
  catch (error) { throw new KernelError(`Unable to read manifest: ${filePath}`, 'MANIFEST_READ_FAILED', { cause: error.message }); }
  let manifest;
  try { manifest = parseSimpleYaml(text); }
  catch (error) { throw new KernelError(`Unable to parse manifest: ${error.message}`, 'MANIFEST_PARSE_FAILED'); }
  const result = validateManifest(manifest);
  if (!result.valid) throw new KernelError('Manifest validation failed', 'MANIFEST_INVALID', { errors: result.errors });
  return Object.freeze(manifest);
}
