import { execFileSync } from 'node:child_process';
import { mkdirSync, rmSync } from 'node:fs';
import { resolve } from 'node:path';
import process from 'node:process';

const root = resolve(import.meta.dirname, '..');
const dist = resolve(root, 'dist');
mkdirSync(dist, { recursive: true });

const releases = [
  {
    source: resolve(root, 'services/platform-core-api'),
    parent: resolve(root, 'services'),
    folder: 'platform-core-api',
    output: resolve(dist, 'tn-platform-core-api-v3.2.0-entity-engine.zip')
  },
  {
    source: resolve(root, 'experiences/wordpress/tn-game-os'),
    parent: resolve(root, 'experiences/wordpress'),
    folder: 'tn-game-os',
    output: resolve(dist, 'tn-game-os-v7.6.1-map-layout-fix.zip')
  }
];

for (const release of releases) {
  rmSync(release.output, { force: true });
  execFileSync('zip', ['-qr', release.output, release.folder, '-x', '*/node_modules/*', '*/data/*', '*/.env'], {
    cwd: release.parent,
    stdio: 'inherit'
  });
  console.log(`Built ${release.output}`);
}
