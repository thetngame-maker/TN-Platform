import assert from 'node:assert/strict';
import fs from 'node:fs';

const root = new URL('../experiences/wordpress/tn-game-os/', import.meta.url);
const read = (path) => fs.readFileSync(new URL(path, root), 'utf8');

const bootstrap = read('tn-game-os.php');
const appRouter = read('tn-game-app-router.php');
const offline = read('tn-game-offline-mode.php');
const css = read('assets/css/offline-mode.css');
const client = read('assets/js/offline-mode.js');

const version = bootstrap.match(/Version:\s*(\d+\.\d+\.\d+)/)?.[1] || '';
const [major, minor] = version.split('.').map(Number);
assert.ok(major > 5 || (major === 5 && minor >= 108), 'Offline Mode requires TN Game OS 5.108.0 or newer');
assert.match(bootstrap, new RegExp(`define\\('TNG_OS_VERSION','${version.replaceAll('.', '\\.')}'\\)`));
assert.match(appRouter, /TNG_Offline_Mode.*tn-game-offline-mode\.php/);

assert.match(offline, /tn-game-sw\\\.js/);
assert.match(offline, /tn-game\\\.webmanifest/);
assert.match(offline, /Service-Worker-Allowed: \/|Service-Worker-Allowed: \/'/);
assert.match(offline, /X-TNG-Offline-Safe: 1/);
assert.match(offline, /is_user_logged_in\(\)/);
assert.match(offline, /response\.headers\.get\('X-TNG-Offline-Safe'\)===\s*'1'/);
assert.match(offline, /if\(request\.method!==\s*'GET'\)return/);
assert.match(offline, /wp-admin|wp-login\.php|admin-ajax\.php|wp-json/);
assert.doesNotMatch(offline, /addEventListener\(['"]sync['"]/);
assert.doesNotMatch(offline, /request\.method===\s*['"]POST['"]/);

assert.match(client, /navigator\.serviceWorker\.register/);
assert.match(client, /scope:\s*['"]\/['"]/);
assert.match(client, /Private screens stay network-only/);
assert.match(css, /@media\(max-width:520px\)/);

console.log('TN Game OS 5.108.0 Offline Mode safety checks passed');
