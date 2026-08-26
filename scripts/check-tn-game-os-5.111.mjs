import assert from 'node:assert/strict';
import fs from 'node:fs';

const root = new URL('../experiences/wordpress/tn-game-os/', import.meta.url);
const read = (path) => fs.readFileSync(new URL(path, root), 'utf8');

const bootstrap = read('tn-game-os.php');
const adventure = read('app/Modules/Destinations/class-adventure-ai.php');
const client = read('assets/js/adventure-ai.js');
const css = read('assets/css/adventure-ai.css');
const router = read('tn-game-app-router.php');

assert.match(bootstrap, /Version:\s*5\.(?:11[1-9]|1[2-9][0-9]|[2-9][0-9]{2})\.\d+/);
assert.match(bootstrap, /define\('TNG_OS_VERSION','5\.(?:11[1-9]|1[2-9][0-9]|[2-9][0-9]{2})\.\d+'\)/);
assert.match(adventure, /Adventure AI · v2/);
assert.match(adventure, /Universal_Map_Registry::dataset\(\)/);
assert.match(adventure, /private function mapped_coordinates\(array \$ids\): array/);
assert.match(adventure, /'start_minutes' => \$start_minutes/);
assert.match(adventure, /'buffer_minutes' => \$buffer_minutes/);
assert.match(adventure, /'plan_version' => 2/);
assert.match(adventure, /is_user_logged_in\(\)/);
assert.match(adventure, /update_user_meta\(/);
assert.match(client, /const recalculate = \(\) =>/);
assert.match(client, /createElementNS/);
assert.match(client, /data-tng-ai-action/);
assert.match(client, /move-up/);
assert.match(client, /move-down/);
assert.match(client, /removedStop/);
assert.match(client, /start_minutes: startMinutes\(\)/);
assert.match(client, /buffer_minutes: Number\(buffer\.value/);
assert.doesNotMatch(client, /localStorage|sessionStorage/);
assert.match(css, /\.tng-ai-workspace/);
assert.match(css, /\.tng-ai-stop__actions button/);
assert.match(css, /@media\(max-width:390px\)/);
assert.match(router, /adventure-ai\.css'.*TNG_OS_VERSION/);
assert.match(router, /adventure-ai\.js'.*TNG_OS_VERSION/);

console.log('TN Game OS 5.111.0 Adventure AI v2 checks passed');
