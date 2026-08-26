import assert from 'node:assert/strict';
import fs from 'node:fs';

const root = new URL('../experiences/wordpress/tn-game-os/', import.meta.url);
const read = (path) => fs.readFileSync(new URL(path, root), 'utf8');

const bootstrap = read('tn-game-os.php');
const core = read('app/Core/class-plugin.php');
const manager = read('app/Modules/Admin/class-ai-content-manager.php');

assert.match(bootstrap, /Version:\s*5\.106\.0/);
assert.match(bootstrap, /define\('TNG_OS_VERSION','5\.106\.0'\)/);
assert.match(core, /class-ai-content-manager\.php/);
assert.match(core, /AI_Content_Manager::class/);

assert.match(manager, /check_admin_referer\('tng_ai_admin_plan'\)/);
assert.match(manager, /check_admin_referer\('tng_ai_admin_apply'\)/);
assert.match(manager, /check_admin_referer\('tng_ai_admin_restore'\)/);
assert.match(manager, /check_admin_referer\('tng_ai_admin_settings'\)/);
assert.match(manager, /'post_status'\s*=>\s*'draft'/);
assert.match(manager, /'store'\s*=>\s*false/);
assert.match(manager, /'type'\s*=>\s*'json_schema'/);
assert.match(manager, /!empty\(\$plan\['actions'\]\[\$index\]\['applied_at'\]\)/);
assert.doesNotMatch(manager, /wp_delete_post\s*\(/);
assert.doesNotMatch(manager, /'post_status'\s*=>\s*'publish'/);

console.log('TN Game OS 5.106.0 AI Admin safety checks passed');
