#!/usr/bin/env bash
set -euo pipefail

# TN Game OS safe production deployment.
#
# Usage from the WordPress application root:
#   bash /path/to/TN-Platform/experiences/wordpress/tn-game-os/deploy-safe.sh \
#     /path/to/TN-Platform/experiences/wordpress/tn-game-os
#
# Important:
# - This intentionally does NOT use rsync --delete. Production currently has
#   standalone TN Game UI plugin entry files in the tn-game-os directory that
#   must be preserved until every one is tracked by GitHub.
# - TN Game OS is forced to the beginning of WordPress's active_plugins list so
#   legacy standalone UI plugins can safely read TNG_OS_PATH / TNG_OS_URL.

SOURCE_DIR="${1:-}"
WP_ROOT="${WP_ROOT:-$(pwd)}"
PLUGIN_DIR="$WP_ROOT/wp-content/plugins/tn-game-os"
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP_ROOT="${TNG_BACKUP_ROOT:-$HOME/tmp/deploy/backups}"

if [[ -z "$SOURCE_DIR" || ! -d "$SOURCE_DIR" ]]; then
  echo "ERROR: pass the TN Game OS source directory as argument 1." >&2
  exit 1
fi

if [[ ! -f "$WP_ROOT/wp-config.php" ]]; then
  echo "ERROR: $WP_ROOT does not look like a WordPress root." >&2
  exit 1
fi

if [[ ! -f "$SOURCE_DIR/tn-game-os.php" ]]; then
  echo "ERROR: source does not contain tn-game-os.php." >&2
  exit 1
fi

mkdir -p "$BACKUP_ROOT"

if [[ -d "$PLUGIN_DIR" ]]; then
  cp -a "$PLUGIN_DIR" "$BACKUP_ROOT/tn-game-os-before-$STAMP"
  echo "✓ Backup created: $BACKUP_ROOT/tn-game-os-before-$STAMP"
fi

echo "=== PHP lint before deployment ==="
while IFS= read -r -d '' file; do
  php -l "$file" >/dev/null || {
    echo "PHP syntax error: $file" >&2
    exit 1
  }
done < <(find "$SOURCE_DIR" -type f -name '*.php' -print0)
echo "✓ Source PHP lint passed"

mkdir -p "$PLUGIN_DIR"

# Do not use --delete here. See header comment.
rsync -a "$SOURCE_DIR/" "$PLUGIN_DIR/"
echo "✓ Files deployed non-destructively"

cd "$WP_ROOT"

php -l "$PLUGIN_DIR/tn-game-os.php" >/dev/null
echo "✓ Deployed bootstrap lint passed"

# Ensure the core plugin is active.
if ! wp plugin is-active tn-game-os/tn-game-os.php >/dev/null 2>&1; then
  wp plugin activate tn-game-os/tn-game-os.php
fi

# TN Game OS must load before the standalone UI plugins. Some legacy UI files
# check TNG_OS_PATH / TNG_OS_URL during plugin load, before plugins_loaded.
wp eval '
$plugins = get_option("active_plugins", []);
$core = "tn-game-os/tn-game-os.php";
$plugins = array_values(array_filter($plugins, static function ($plugin) use ($core) {
    return $plugin !== $core;
}));
array_unshift($plugins, $core);
update_option("active_plugins", $plugins);
echo "TN Game OS placed first in active plugin load order.\n";
'

wp cache flush || true

# Smoke checks that avoid invoking every front-end route.
wp eval '
echo "TNG_OS_VERSION=" . (defined("TNG_OS_VERSION") ? TNG_OS_VERSION : "missing") . "\n";
echo "TNG_OS_PATH=" . (defined("TNG_OS_PATH") ? "defined" : "missing") . "\n";
echo "TNG_OS_URL=" . (defined("TNG_OS_URL") ? "defined" : "missing") . "\n";
'

echo "=== Active TN Game plugins ==="
wp plugin list --fields=name,status,version --format=csv \
  | awk -F, '$2=="active" && $1 ~ /^tn-game/ {print}' || true

echo
echo "=========================================="
echo "TN GAME OS SAFE DEPLOYMENT COMPLETE"
echo "Backup: $BACKUP_ROOT/tn-game-os-before-$STAMP"
echo "=========================================="
