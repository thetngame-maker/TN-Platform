import assert from 'node:assert/strict';
import fs from 'node:fs';

const root = new URL('../experiences/wordpress/tn-game-os/', import.meta.url);
const read = (path) => fs.readFileSync(new URL(path, root), 'utf8');

const bootstrap = read('tn-game-os.php');
const core = read('app/Core/class-plugin.php');
const router = read('app/Platform/class-app-router.php');
const admin = read('app/Modules/Admin/class-admin.php');
const smoke = read('app/Modules/Admin/class-production-smoke-tests.php');
const css = read('assets/admin/production-smoke-tests.css');

const version = bootstrap.match(/Version:\s*(\d+\.\d+\.\d+)/)?.[1] || '';
const [major, minor] = version.split('.').map(Number);
assert.ok(major > 5 || (major === 5 && minor >= 109), 'Production Smoke Tests require TN Game OS 5.109.0 or newer');
assert.match(bootstrap, new RegExp(`define\\('TNG_OS_VERSION','${version.replaceAll('.', '\\.')}'\\)`));
assert.match(core, /class-production-smoke-tests\.php/);
assert.match(core, /Production_Smoke_Tests::class/);
assert.match(router, /public static function routes\(\):array/);
assert.match(admin, /Production Smoke Tests/);
assert.match(admin, /tng-production-smoke-tests/);

assert.match(smoke, /check_admin_referer\('tng_production_smoke_run'\)/);
assert.match(smoke, /check_admin_referer\('tng_production_smoke_export'\)/);
assert.match(smoke, /wp_safe_remote_get/);
assert.match(smoke, /tn-game\.webmanifest/);
assert.match(smoke, /tn-game-sw\.js/);
assert.match(smoke, /x-tng-offline-safe/);
assert.match(smoke, /PRIVATE_ROUTES/);
assert.match(smoke, /TNG_Launch_Gate::enabled/);
assert.doesNotMatch(smoke, /wp_delete_(post|attachment)/);
assert.doesNotMatch(smoke, /wp_update_post/);
assert.doesNotMatch(smoke, /update_user_meta/);
assert.doesNotMatch(smoke, /delete_option/);
assert.match(css, /@media\(max-width:520px\)/);

console.log('TN Game OS 5.109.0 Production Smoke Tests safety checks passed');
