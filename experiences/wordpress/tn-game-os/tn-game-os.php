<?php
/**
 * Plugin Name: TN Game OS
 * Plugin URI: https://thetngame.com
 * Description: Modular tourism operating system for Traveler listings, trails, food, maps, Explorer progression, reusable assets, destinations, and developer tools.
 * Version: 5.33.0
 * Author: The TN Game
 * Text Domain: tn-game-os
 */
if (!defined('ABSPATH')) exit;

define('TNG_OS_VERSION', '5.33.0');
define('TNG_OS_FILE', __FILE__);
define('TNG_OS_PATH', plugin_dir_path(__FILE__));
define('TNG_OS_URL', plugin_dir_url(__FILE__));

require_once TNG_OS_PATH . 'app/Core/class-module-interface.php';
require_once TNG_OS_PATH . 'app/Core/class-container.php';
require_once TNG_OS_PATH . 'app/Core/class-plugin.php';
require_once TNG_OS_PATH . 'app/Modules/Frontend/class-gameplay-notifications.php';
require_once TNG_OS_PATH . 'app/Modules/Frontend/class-mobile-session-recovery.php';
require_once TNG_OS_PATH . 'app/Modules/Destinations/class-destination-intelligence-center.php';
require_once TNG_OS_PATH . 'app/Modules/Destinations/class-destination-health-dashboard.php';
require_once TNG_OS_PATH . 'app/Modules/Destinations/class-destination-ai-profiles.php';
require_once TNG_OS_PATH . 'app/Modules/Destinations/class-smart-recommendation-engine.php';
require_once TNG_OS_PATH . 'app/Modules/Destinations/class-explore-nearby-widget.php';
require_once TNG_OS_PATH . 'app/Modules/Destinations/class-smart-day-planner.php';
require_once TNG_OS_PATH . 'app/Modules/Destinations/class-my-trip-workspace.php';
require_once TNG_OS_PATH . 'app/Modules/Destinations/class-personalized-trip-builder.php';
require_once TNG_OS_PATH . 'app/Modules/Destinations/class-live-trip-optimizer.php';
require_once TNG_OS_PATH . 'app/Modules/Destinations/class-live-trip-companion.php';
require_once TNG_OS_PATH . 'app/Modules/Destinations/class-multi-stop-route-navigator.php';
require_once TNG_OS_PATH . 'app/Modules/Destinations/class-active-trip-mode.php';
require_once TNG_OS_PATH . 'app/Modules/Destinations/class-trip-completion-rewards.php';
require_once TNG_OS_PATH . 'app/Modules/Destinations/class-past-trips-journal.php';
require_once TNG_OS_PATH . 'app/Modules/Destinations/class-trip-explorer-integration.php';
require_once TNG_OS_PATH . 'app/Modules/Destinations/class-explorer-journal.php';
require_once TNG_OS_PATH . 'app/Modules/Destinations/class-explorer-timeline-bridge.php';
require_once TNG_OS_PATH . 'app/Modules/Destinations/class-public-explorer-profile.php';
require_once TNG_OS_PATH . 'app/Modules/Destinations/class-explorer-profile-settings.php';

// Bootstrap the modular plugin core.
$plugin = new \TNG_OS\Core\Plugin();
$plugin->boot();

// Legacy/front-end feature modules retained during the staged migration.
$legacy_modules = array(
    'tn-game-app-router.php',
    'tn-game-platform-ui.php',
    'tn-game-directory-ui.php',
    'tn-game-map-ui.php',
    'tn-game-profile-ui.php',
    'tn-game-games-ui.php',
    'tn-game-library-ui.php',
    'tn-game-trail-ui.php',
    'tn-game-event-ui.php',
    'tn-game-place-ui.php',
    'tn-game-search-ui.php',
    'tn-game-settings-ui.php',
    'tn-game-social-ui.php',
    'tn-game-progress-ui.php',
    'tn-game-active-trip-ui.php',
    'tn-game-trip-data.php',
    'tn-game-trip-builder-ui.php',
    'tn-game-trips-ui.php',
    'tn-game-past-trips-ui.php',
    'tn-game-trip-dock.php',
    'tn-game-builder-ui.php',
    'tn-game-visual-builder.php',
    'tn-game-play-ui.php',
    'tn-game-runtime-ui.php',
    'tn-game-runtime-map.php',
    'tn-game-checkpoint-compat.php',
    'tn-game-game-progression.php',
    'tn-game-developer-gps.php',
    'tn-game-developer-test-report.php',
    'tn-game-developer-collapse.php',
    'tn-game-game-audit.php',
    'tn-game-runtime-qa.php',
    'tn-game-test-certification.php',
    'tn-game-gameplay-ui-milestone.php',
);

foreach ($legacy_modules as $module_file) {
    $module_path = TNG_OS_PATH . $module_file;
    if (is_readable($module_path)) require_once $module_path;
}
