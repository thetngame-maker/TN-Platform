import fs from 'node:fs/promises';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { pathToFileURL } from 'node:url';
import { findRoot, printJson, pass, fail } from './utils.js';

async function kernelModule(root) { return import(pathToFileURL(path.join(root, 'kernel/src/index.js')).href); }

export async function info({ json = false } = {}) {
  const root = await findRoot();
  const { loadManifest } = await kernelModule(root);
  const manifest = await loadManifest(path.join(root, 'platform.yaml'));
  const result = { root, platform: manifest.platform, kernel: manifest.kernel, services: manifest.services, experiences: manifest.experiences || [] };
  if (json) printJson(result); else {
    console.log(`${manifest.platform.name} ${manifest.platform.version}`);
    console.log(`Kernel ${manifest.kernel.version}`);
    console.log(`${manifest.services.length} declared services, ${(manifest.experiences || []).length} experiences`);
  }
  return result;
}

export async function doctor({ json = false } = {}) {
  const checks = [];
  let root;
  try { root = await findRoot(); checks.push({ id: 'root', ok: true, detail: root }); }
  catch (error) { checks.push({ id: 'root', ok: false, detail: error.message }); if (json) printJson({ ok: false, checks }); else fail('Repository', error.message); return { ok: false, checks }; }
  try {
    const { loadManifest } = await kernelModule(root);
    const manifest = await loadManifest(path.join(root, 'platform.yaml'));
    checks.push({ id: 'manifest', ok: true, detail: manifest.platform.version });
    for (const service of manifest.services) {
      const exists = await fs.access(path.join(root, service.path)).then(() => true).catch(() => false);
      checks.push({ id: `service:${service.id}`, ok: exists, detail: exists ? service.path : `Missing ${service.path}` });
    }
  } catch (error) { checks.push({ id: 'manifest', ok: false, detail: error.message }); }
  const nodeOk = Number(process.versions.node.split('.')[0]) >= 20;
  checks.push({ id: 'node', ok: nodeOk, detail: process.version });
  const git = spawnSync('git', ['--version'], { encoding: 'utf8' });
  checks.push({ id: 'git', ok: git.status === 0, detail: git.status === 0 ? git.stdout.trim() : 'Git unavailable' });
  const ok = checks.every(check => check.ok);
  if (json) printJson({ ok, checks }); else {
    console.log('TN Platform Doctor');
    for (const check of checks) (check.ok ? pass : fail)(check.id, check.detail);
    console.log(ok ? '\nPlatform is ready.' : '\nPlatform needs attention.');
  }
  return { ok, checks };
}

export async function build() {
  const root = await findRoot();
  const result = spawnSync(process.execPath, [path.join(root, 'scripts/build-releases.mjs')], { cwd: root, stdio: 'inherit' });
  if (result.status !== 0) throw new Error(`Build failed with exit code ${result.status}`);
}

export async function testPlatform() {
  const root = await findRoot();
  const result = spawnSync('npm', ['test'], { cwd: root, stdio: 'inherit', shell: process.platform === 'win32' });
  if (result.status !== 0) throw new Error(`Tests failed with exit code ${result.status}`);
}

export async function createService(name) {
  if (!/^[a-z][a-z0-9-]*$/.test(name || '')) throw new Error('Service name must use lowercase letters, numbers, and hyphens.');
  const root = await findRoot();
  const directory = path.join(root, 'services', name);
  try { await fs.access(directory); throw new Error(`Service already exists: ${name}`); } catch (error) { if (!String(error.message).includes('ENOENT') && !String(error.code).includes('ENOENT')) throw error; }
  await fs.mkdir(path.join(directory, 'src'), { recursive: true });
  await fs.mkdir(path.join(directory, 'test'), { recursive: true });
  await fs.writeFile(path.join(directory, 'package.json'), JSON.stringify({ name: `@tn-platform/${name}`, version: '0.1.0', private: true, type: 'module', scripts: { test: 'node --test' }, engines: { node: '>=20' } }, null, 2) + '\n');
  await fs.writeFile(path.join(directory, 'src/index.js'), `import { PlatformService } from '../../../kernel/src/index.js';\n\nexport class ${toClass(name)}Service extends PlatformService {\n  constructor() { super({ id: '${name}', name: '${title(name)}', version: '0.1.0' }); }\n}\n`);
  await fs.writeFile(path.join(directory, 'README.md'), `# ${title(name)}\n\nKernel-managed TN Platform service.\n`);
  pass('Created service', path.relative(root, directory));
}

function toClass(value) { return value.split('-').map(part => part[0].toUpperCase() + part.slice(1)).join(''); }
function title(value) { return value.split('-').map(part => part[0].toUpperCase() + part.slice(1)).join(' '); }
