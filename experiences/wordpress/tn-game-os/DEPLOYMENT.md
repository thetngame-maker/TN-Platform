# TN Game OS deployment contract

Production currently runs TN Game OS plus a set of standalone TN Game UI plugin entry files from the same `wp-content/plugins/tn-game-os/` directory.

## Rules

1. **Never deploy this directory with `rsync --delete`** until every production TN Game UI entry file is tracked in GitHub.
2. **TN Game OS must load before the standalone TN Game UI plugins.** Several compatibility-era UI plugins read `TNG_OS_PATH` and `TNG_OS_URL` during plugin bootstrap.
3. **Lint the complete source tree before deployment.** Do not patch production PHP by search/replace.
4. **Back up the complete production `tn-game-os` directory before every deployment.**
5. **Deploy known commits only.** Record the exact Git SHA used for production.
6. **Use `deploy-safe.sh`** for repository-to-production deployments.

## Why load order matters

The TN Game OS bootstrap defines:

```php
define('TNG_OS_PATH', plugin_dir_path(__FILE__));
define('TNG_OS_URL', plugin_dir_url(__FILE__));
```

Some standalone UI plugins use these constants before WordPress reaches `plugins_loaded`. If a standalone UI plugin is ordered before `tn-game-os/tn-game-os.php` in the `active_plugins` option, the request can fail with errors such as:

```text
Undefined constant "TNG_OS_URL"
```

The safe deployment script ensures `tn-game-os/tn-game-os.php` is first in the active plugin list.

## Production smoke test

After deployment verify:

- `/` renders the TN Game Explore home experience.
- `/map/` renders the Adventure Map rather than the fallback/blank state.
- `/trips/` loads the Trips surface.
- WordPress admin remains accessible.
- Content Studio / Local Discovery surfaces load when present in the deployed commit.
- PHP error logs contain no new TN Game fatal errors.

## Rollback

Restore the complete directory backup created by `deploy-safe.sh`, preserve the prior active-plugin order with TN Game OS first, and flush the WordPress cache.
