#!/usr/bin/env bash
set -euo pipefail

TARGET="${1:-$HOME/public_html}"
SOURCE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_DIR="$TARGET/wp-content/plugins/tn-game-os"
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP_ROOT="$HOME/tn-game-deploy-backups"
BACKUP_DIR="$BACKUP_ROOT/tn-game-os-local-discovery-$STAMP"

FILES=(
  "tn-game-local-discovery.php"
  "tn-game-discovery-media-importer.php"
  "tn-game-apify-budget-safeguards.php"
  "tn-game-local-discovery-hardening.php"
  "app/Modules/Sources/class-content-studio-ops-dashboard.php"
  "app/Modules/Sources/class-local-discovery.php"
  "app/Modules/Sources/class-local-discovery-destination-linker.php"
  "app/Modules/Sources/class-town-scanner.php"
  "app/Modules/Sources/class-town-changes-inbox.php"
  "app/Modules/Sources/class-town-monitor.php"
  "app/Modules/Sources/class-server-cron-heartbeat.php"
  "app/Modules/Sources/class-apify-usage-dashboard.php"
  "app/Modules/Sources/class-apify-budget-safeguards.php"
  "app/Modules/Sources/class-content-studio-alerts.php"
  "app/Modules/Sources/class-town-scan-scope.php"
)

echo "=== TN Game Local Discovery production deployment ==="
echo "Source: $SOURCE_ROOT"
echo "Target: $TARGET"
echo "Backup: $BACKUP_DIR"

if [[ ! -f "$TARGET/wp-config.php" ]]; then
  echo "ERROR: $TARGET does not look like a WordPress root (wp-config.php missing)." >&2
  exit 1
fi

if [[ ! -f "$PLUGIN_DIR/tn-game-os.php" ]]; then
  echo "ERROR: TN Game OS core is missing at $PLUGIN_DIR/tn-game-os.php" >&2
  exit 1
fi

for rel in "${FILES[@]}"; do
  if [[ ! -f "$SOURCE_ROOT/$rel" ]]; then
    echo "ERROR: source file missing: $SOURCE_ROOT/$rel" >&2
    exit 1
  fi
  php -l "$SOURCE_ROOT/$rel" >/dev/null
  echo "✓ lint $rel"
done

mkdir -p "$BACKUP_ROOT"
cp -a "$PLUGIN_DIR" "$BACKUP_DIR"
echo "✓ production TN Game OS backed up"

for rel in "${FILES[@]}"; do
  mkdir -p "$(dirname "$PLUGIN_DIR/$rel")"
  cp "$SOURCE_ROOT/$rel" "$PLUGIN_DIR/$rel"
  echo "✓ copied $rel"
done

cd "$TARGET"

wp eval '
$plugins = get_option("active_plugins", []);
$core = "tn-game-os/tn-game-os.php";
if (!in_array($core, $plugins, true)) {
    fwrite(STDERR, "TN Game OS core is not active.\n");
    exit(1);
}
$plugins = array_values(array_filter($plugins, static fn($plugin) => $plugin !== $core));
array_unshift($plugins, $core);
update_option("active_plugins", $plugins);
echo "TN Game OS moved to first plugin-load position.\n";
' --skip-plugins --skip-themes

COMPANIONS=(
  "tn-game-os/tn-game-local-discovery.php"
  "tn-game-os/tn-game-discovery-media-importer.php"
  "tn-game-os/tn-game-apify-budget-safeguards.php"
  "tn-game-os/tn-game-local-discovery-hardening.php"
)

for plugin in "${COMPANIONS[@]}"; do
  wp plugin activate "$plugin" --skip-plugins --skip-themes
  echo "✓ active $plugin"
done

wp cache flush --skip-plugins --skip-themes || true

echo
echo "=== TN Game plugin order ==="
wp eval '
foreach ((array)get_option("active_plugins", []) as $i => $plugin) {
    if (strpos($plugin, "tn-game-os/") === 0) echo $i . "  " . $plugin . PHP_EOL;
}
'

echo
echo "=== Companion plugin status ==="
wp plugin list --fields=name,status,version --format=table | grep -E 'tn-game-(local-discovery|discovery-media-importer|apify-budget-safeguards|local-discovery-hardening)' || true

echo
echo "=== Cron verification ==="
wp cron event list --fields=hook,next_run_gmt,next_run_relative,recurrence --format=table | grep -E 'tng_(server_cron_heartbeat|town_monitor_cron)' || true

echo
echo "Deployment complete."
echo "Backup: $BACKUP_DIR"
echo "Cloudways server cron should run every 5 minutes:"
echo "cd $TARGET && wp cron event run --due-now --quiet"
echo
echo "Smoke test: /, /map/, /trips/, Content Studio Overview, Local Discovery, Town Scanner, Changes Inbox, Town Monitoring, Cron Reliability, Apify Usage, Usage Safeguards, Alerts, Scan Scope."
