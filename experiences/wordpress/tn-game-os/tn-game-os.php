<?php
/**
 * Plugin Name: TN Game OS
 * Plugin URI: https://thetngame.com
 * Description: Modular tourism operating system for Traveler listings, trails, food, maps, Explorer progression, reusable assets, destinations, and developer tools.
 * Version: 5.62.0
 * Author: The TN Game
 * Text Domain: tn-game-os
 */
if (!defined('ABSPATH')) exit;

define('TNG_OS_VERSION', '5.62.0');
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
require_once TNG_OS_PATH . 'app/Modules/Destinations/class-explorer-showcase.php';
require_once TNG_OS_PATH . 'app/Modules/Destinations/class-explorer-discovery.php';
require_once TNG_OS_PATH . 'app/Modules/Destinations/class-explorer-network-intelligence.php';
require_once TNG_OS_PATH . 'app/Modules/Destinations/class-coordinate-intelligence.php';
require_once TNG_OS_PATH . 'app/Modules/Destinations/class-coordinate-quality-controls.php';
require_once TNG_OS_PATH . 'app/Modules/Destinations/class-coordinate-source-resolver.php';
require_once TNG_OS_PATH . 'app/Modules/Destinations/class-knowledge-graph-core.php';
require_once TNG_OS_PATH . 'app/Modules/Destinations/class-knowledge-graph-inspector.php';
require_once TNG_OS_PATH . 'tn-game-checkpoint-compat.php';
require_once TNG_OS_PATH . 'tn-game-developer-test-report.php';
require_once TNG_OS_PATH . 'tn-game-developer-collapse.php';
require_once TNG_OS_PATH . 'tn-game-test-certification.php';
require_once TNG_OS_PATH . 'tn-game-game-audit.php';
require_once TNG_OS_PATH . 'tn-game-runtime-qa.php';
require_once TNG_OS_PATH . 'tn-game-gameplay-ui-milestone.php';
require_once TNG_OS_PATH . 'tn-game-game-session-lifecycle.php';
require_once TNG_OS_PATH . 'tn-game-game-session-ownership.php';
require_once TNG_OS_PATH . 'tn-game-player-state-polish.php';
require_once TNG_OS_PATH . 'tn-game-game-completion-sync.php';
require_once TNG_OS_PATH . 'tn-game-game-completion-handoff.php';
require_once TNG_OS_PATH . 'tn-game-game-explorer-integration.php';
require_once TNG_OS_PATH . 'tn-game-progression-milestones.php';
require_once TNG_OS_PATH . 'tn-game-progression-profile-bridge.php';
require_once TNG_OS_PATH . 'tn-game-explorer-normalization.php';
require_once TNG_OS_PATH . 'tn-game-live-top-sight-visits.php';
require_once TNG_OS_PATH . 'tn-game-category-progression.php';
require_once TNG_OS_PATH . 'tn-game-social-intelligence.php';
require_once TNG_OS_PATH . 'tn-game-social-discovery.php';
require_once TNG_OS_PATH . 'tn-game-social-pattern-intelligence.php';
require_once TNG_OS_PATH . 'tn-game-content-idea-generator.php';
require_once TNG_OS_PATH . 'tn-game-content-production.php';
require_once TNG_OS_PATH . 'tn-game-instagram-browser.php';
require_once TNG_OS_PATH . 'tn-game-instagram-capture.php';
require_once TNG_OS_PATH . 'tn-game-content-calendar.php';
require_once TNG_OS_PATH . 'tn-game-content-campaigns.php';
require_once TNG_OS_PATH . 'tn-game-content-campaign-integration.php';
require_once TNG_OS_PATH . 'tn-game-content-pillars.php';
require_once TNG_OS_PATH . 'tn-game-content-signal-fusion.php';
require_once TNG_OS_PATH . 'tn-game-content-auto-planner.php';
require_once TNG_OS_PATH . 'tn-game-content-storyline.php';

register_activation_hook(__FILE__, ['TNG_OS\\Core\\Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['TNG_OS\\Core\\Plugin', 'deactivate']);
add_action('plugins_loaded', static function () { TNG_OS\Core\Plugin::instance()->boot(); }, 5);
add_action('plugins_loaded', static function () {
    $container = new TNG_OS\Core\Container();
    $modules = [
        new TNG_OS\Modules\Frontend\Gameplay_Notifications(), new TNG_OS\Modules\Frontend\Mobile_Session_Recovery(),
        new TNG_OS\Modules\Destinations\Destination_Intelligence_Center(), new TNG_OS\Modules\Destinations\Destination_Health_Dashboard(), new TNG_OS\Modules\Destinations\Destination_AI_Profiles(), new TNG_OS\Modules\Destinations\Smart_Recommendation_Engine(), new TNG_OS\Modules\Destinations\Explore_Nearby_Widget(), new TNG_OS\Modules\Destinations\Smart_Day_Planner(), new TNG_OS\Modules\Destinations\My_Trip_Workspace(), new TNG_OS\Modules\Destinations\Personalized_Trip_Builder(), new TNG_OS\Modules\Destinations\Live_Trip_Optimizer(), new TNG_OS\Modules\Destinations\Live_Trip_Companion(), new TNG_OS\Modules\Destinations\Multi_Stop_Route_Navigator(), new TNG_OS\Modules\Destinations\Active_Trip_Mode(), new TNG_OS\Modules\Destinations\Trip_Completion_Rewards(), new TNG_OS\Modules\Destinations\Past_Trips_Journal(), new TNG_OS\Modules\Destinations\Trip_Explorer_Integration(), new TNG_OS\Modules\Destinations\Explorer_Journal(), new TNG_OS\Modules\Destinations\Explorer_Timeline_Bridge(), new TNG_OS\Modules\Destinations\Public_Explorer_Profile(), new TNG_OS\Modules\Destinations\Explorer_Profile_Settings(), new TNG_OS\Modules\Destinations\Explorer_Showcase(), new TNG_OS\Modules\Destinations\Explorer_Discovery(), new TNG_OS\Modules\Destinations\Explorer_Network_Intelligence(), new TNG_OS\Modules\Destinations\Coordinate_Intelligence(), new TNG_OS\Modules\Destinations\Coordinate_Quality_Controls(), new TNG_OS\Modules\Destinations\Coordinate_Source_Resolver(), new TNG_OS\Modules\Destinations\Knowledge_Graph_Core(), new TNG_OS\Modules\Destinations\Knowledge_Graph_Inspector(),
    ];
    foreach ($modules as $module) { $module->register($container); $module->boot($container); }
}, 6);
