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

register_activation_hook(__FILE__, ['TNG_OS\\Core\\Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['TNG_OS\\Core\\Plugin', 'deactivate']);

add_action('plugins_loaded', static function () {
    TNG_OS\Core\Plugin::instance()->boot();
}, 5);

add_action('plugins_loaded', static function () {
    $container = new TNG_OS\Core\Container();

    $notifications = new TNG_OS\Modules\Frontend\Gameplay_Notifications();
    $notifications->register($container);
    $notifications->boot($container);

    $recovery = new TNG_OS\Modules\Frontend\Mobile_Session_Recovery();
    $recovery->register($container);
    $recovery->boot($container);

    $destination_intelligence = new TNG_OS\Modules\Destinations\Destination_Intelligence_Center();
    $destination_intelligence->register($container);
    $destination_intelligence->boot($container);

    $destination_health = new TNG_OS\Modules\Destinations\Destination_Health_Dashboard();
    $destination_health->register($container);
    $destination_health->boot($container);

    $destination_profiles = new TNG_OS\Modules\Destinations\Destination_AI_Profiles();
    $destination_profiles->register($container);
    $destination_profiles->boot($container);

    $recommendations = new TNG_OS\Modules\Destinations\Smart_Recommendation_Engine();
    $recommendations->register($container);
    $recommendations->boot($container);

    $explore_nearby = new TNG_OS\Modules\Destinations\Explore_Nearby_Widget();
    $explore_nearby->register($container);
    $explore_nearby->boot($container);

    $day_planner = new TNG_OS\Modules\Destinations\Smart_Day_Planner();
    $day_planner->register($container);
    $day_planner->boot($container);

    $my_trip = new TNG_OS\Modules\Destinations\My_Trip_Workspace();
    $my_trip->register($container);
    $my_trip->boot($container);

    $trip_builder = new TNG_OS\Modules\Destinations\Personalized_Trip_Builder();
    $trip_builder->register($container);
    $trip_builder->boot($container);

    $trip_optimizer = new TNG_OS\Modules\Destinations\Live_Trip_Optimizer();
    $trip_optimizer->register($container);
    $trip_optimizer->boot($container);

    $trip_companion = new TNG_OS\Modules\Destinations\Live_Trip_Companion();
    $trip_companion->register($container);
    $trip_companion->boot($container);

    $route_navigator = new TNG_OS\Modules\Destinations\Multi_Stop_Route_Navigator();
    $route_navigator->register($container);
    $route_navigator->boot($container);

    $active_trip = new TNG_OS\Modules\Destinations\Active_Trip_Mode();
    $active_trip->register($container);
    $active_trip->boot($container);

    $trip_rewards = new TNG_OS\Modules\Destinations\Trip_Completion_Rewards();
    $trip_rewards->register($container);
    $trip_rewards->boot($container);

    $past_trips = new TNG_OS\Modules\Destinations\Past_Trips_Journal();
    $past_trips->register($container);
    $past_trips->boot($container);

    $trip_explorer = new TNG_OS\Modules\Destinations\Trip_Explorer_Integration();
    $trip_explorer->register($container);
    $trip_explorer->boot($container);

    $explorer_journal = new TNG_OS\Modules\Destinations\Explorer_Journal();
    $explorer_journal->register($container);
    $explorer_journal->boot($container);

    $timeline_bridge = new TNG_OS\Modules\Destinations\Explorer_Timeline_Bridge();
    $timeline_bridge->register($container);
    $timeline_bridge->boot($container);

    $public_profile = new TNG_OS\Modules\Destinations\Public_Explorer_Profile();
    $public_profile->register($container);
    $public_profile->boot($container);

    $profile_settings = new TNG_OS\Modules\Destinations\Explorer_Profile_Settings();
    $profile_settings->register($container);
    $profile_settings->boot($container);

    $explorer_showcase = new TNG_OS\Modules\Destinations\Explorer_Showcase();
    $explorer_showcase->register($container);
    $explorer_showcase->boot($container);

    $explorer_discovery = new TNG_OS\Modules\Destinations\Explorer_Discovery();
    $explorer_discovery->register($container);
    $explorer_discovery->boot($container);

    $network_intelligence = new TNG_OS\Modules\Destinations\Explorer_Network_Intelligence();
    $network_intelligence->register($container);
    $network_intelligence->boot($container);

    $coordinate_intelligence = new TNG_OS\Modules\Destinations\Coordinate_Intelligence();
    $coordinate_intelligence->register($container);
    $coordinate_intelligence->boot($container);

    $coordinate_quality = new TNG_OS\Modules\Destinations\Coordinate_Quality_Controls();
    $coordinate_quality->register($container);
    $coordinate_quality->boot($container);

    $coordinate_sources = new TNG_OS\Modules\Destinations\Coordinate_Source_Resolver();
    $coordinate_sources->register($container);
    $coordinate_sources->boot($container);

    $knowledge_graph = new TNG_OS\Modules\Destinations\Knowledge_Graph_Core();
    $knowledge_graph->register($container);
    $knowledge_graph->boot($container);

    $knowledge_inspector = new TNG_OS\Modules\Destinations\Knowledge_Graph_Inspector();
    $knowledge_inspector->register($container);
    $knowledge_inspector->boot($container);
}, 6);