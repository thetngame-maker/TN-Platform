import assert from 'node:assert/strict';
import fs from 'node:fs';

const root = new URL('../experiences/wordpress/tn-game-os/', import.meta.url);
const read = (path) => fs.readFileSync(new URL(path, root), 'utf8');

const bootstrap = read('tn-game-os.php');
const adventure = read('app/Modules/Destinations/class-adventure-ai.php');
const appRouter = read('app/Platform/class-app-router.php');
const assetRouter = read('tn-game-app-router.php');
const platform = read('tn-game-platform-ui.php');
const trips = read('tn-game-trips-ui.php');
const smoke = read('app/Modules/Admin/class-production-smoke-tests.php');
const editor = read('assets/js/adventure-ai.js');
const library = read('assets/js/saved-adventures.js');
const css = read('assets/css/saved-adventures.css');

assert.match(bootstrap, /Version:\s*5\.(?:11[2-9]|1[2-9][0-9]|[2-9][0-9]{2})\.\d+/);
assert.match(bootstrap, /define\('TNG_OS_VERSION','5\.(?:11[2-9]|1[2-9][0-9]|[2-9][0-9]{2})\.\d+'\)/);
assert.match(appRouter, /'adventures'/);
assert.match(appRouter, /Adventure_AI::render_library/);
assert.match(assetRouter, /saved-adventures\.css/);
assert.match(assetRouter, /saved-adventures\.js/);
assert.match(platform, /'adventures'/);
assert.match(trips, /home_url\('\/adventures\/'\)/);
assert.match(smoke, /PRIVATE_ROUTES = \['trips','adventures'/);

assert.match(adventure, /PLAN_LIBRARY_META/);
assert.match(adventure, /PLAN_LIBRARY_LIMIT = 12/);
assert.match(adventure, /public static function render_library\(\): string/);
assert.match(adventure, /public function ajax_library_action\(\): void/);
assert.match(adventure, /check_ajax_referer\(self::NONCE, 'nonce'\)/);
assert.match(adventure, /if \(!is_user_logged_in\(\)\)/);
assert.match(adventure, /\$operation === 'rename'/);
assert.match(adventure, /\$operation === 'duplicate'/);
assert.doesNotMatch(adventure, /\$operation === 'delete'/);
assert.match(adventure, /get_user_meta\(\$user_id, self::LAST_PLAN_META/);
assert.match(adventure, /get_post_status\(\$id\) !== 'publish'/);

assert.match(editor, /plan_id: plan\.id/);
assert.match(editor, /data-tng-ai-initial/);
assert.match(library, /tng_adventure_library_action/);
assert.match(library, /operation:'rename'/);
assert.match(library, /operation:'duplicate'/);
assert.doesNotMatch(library, /localStorage|sessionStorage/);
assert.match(css, /@media\(max-width:390px\)/);

console.log('TN Game OS 5.112.0 Saved Adventures safety checks passed');
