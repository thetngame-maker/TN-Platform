import assert from 'node:assert/strict';
import fs from 'node:fs';

const root = new URL('../experiences/wordpress/tn-game-os/', import.meta.url);
const read = (path) => fs.readFileSync(new URL(path, root), 'utf8');

const bootstrap = read('tn-game-os.php');
const appRouter = read('tn-game-app-router.php');
const routes = read('app/Platform/class-app-router.php');
const trips = read('tn-game-past-trips-ui.php');
const recaps = read('tn-game-adventure-recaps.php');
const css = read('assets/css/adventure-recaps.css');
const js = read('assets/js/adventure-recaps.js');

assert.match(bootstrap, /Version:\s*5\.107\.0/);
assert.match(bootstrap, /define\('TNG_OS_VERSION','5\.107\.0'\)/);
assert.match(appRouter, /TNG_Adventure_Recaps.*tn-game-adventure-recaps\.php/);
assert.match(routes, /'recaps'/);
assert.match(routes, /TNG_Adventure_Recaps::render/);

assert.match(recaps, /tng_os_game_completed/);
assert.match(recaps, /tng_os_trip_archived/);
assert.match(recaps, /check_admin_referer\('tng_adventure_recap_update'\)/);
assert.match(recaps, /get_current_user_id\(\)/);
assert.match(recaps, /'_tng_photo_status', 'value' => 'approved'/);
assert.match(recaps, /navigator\.share|data-tng-share-recap/);
assert.doesNotMatch(recaps, /wp_ajax_nopriv/);
assert.doesNotMatch(recaps, /wp_delete_(post|attachment)/);
assert.doesNotMatch(recaps, /post_status'\s*=>\s*'publish'/);

assert.match(trips, /do_action\('tng_os_trip_archived'/);
assert.match(trips, /add_query_arg\('recap'/);
assert.match(css, /@media\(max-width:520px\)/);
assert.match(js, /navigator\.share/);
assert.match(js, /navigator\.clipboard\.writeText/);

console.log('TN Game OS 5.107.0 Adventure Recaps safety checks passed');
