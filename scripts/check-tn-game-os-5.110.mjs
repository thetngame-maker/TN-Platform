import assert from 'node:assert/strict';
import fs from 'node:fs';

const root = new URL('../experiences/wordpress/tn-game-os/', import.meta.url);
const read = (path) => fs.readFileSync(new URL(path, root), 'utf8');

const bootstrap = read('tn-game-os.php');
const router = read('app/Platform/class-app-router.php');
const offline = read('tn-game-offline-mode.php');
const client = read('assets/js/offline-mode.js');
const css = read('assets/css/offline-mode.css');
const profile = read('tn-game-profile-ui.php');

assert.match(bootstrap, /Version:\s*5\.110\.0/);
assert.match(bootstrap, /define\('TNG_OS_VERSION','5\.110\.0'\)/);
assert.match(router, /'offline'/);
assert.match(router, /TNG_Offline_Mode::render_screen/);

assert.match(offline, /private static function packs\(\): array/);
assert.match(offline, /TNG_OFFLINE_PACK_SAVE/);
assert.match(offline, /TNG_OFFLINE_PACK_REMOVE/);
assert.match(offline, /TNG_OFFLINE_PACK_STATUS/);
assert.match(offline, /PUBLIC_ROUTES\.includes\(route\)/);
assert.match(offline, /response\.headers\.get\('X-TNG-Offline-Safe'\)===\s*'1'/);
assert.match(offline, /credentials:'omit'/);
assert.doesNotMatch(offline, /addEventListener\(['"]sync['"]/);
assert.doesNotMatch(offline, /request\.method===\s*['"]POST['"]/);
assert.doesNotMatch(offline, /wp_ajax/);

assert.match(client, /MessageChannel/);
assert.match(client, /navigator\.storage\.estimate/);
assert.match(client, /TNG_OFFLINE_PACK_SAVE/);
assert.match(client, /TNG_OFFLINE_PACK_REMOVE/);
assert.match(profile, /home_url\('\/offline\/'\)/);
assert.match(css, /@media\(max-width:620px\)/);

console.log('TN Game OS 5.110.0 Offline Packs safety checks passed');
