<?php
/**
 * Plugin Name: TN Game OS
 * Plugin URI: https://thetngame.com
 * Description: Modular tourism operating system for Traveler listings, trails, food, maps, Explorer progression, reusable assets, destinations, and developer tools.
 * Version: 5.10.0
 * Author: The TN Game
 * Text Domain: tn-game-os
 */
if (!defined('ABSPATH')) exit;

define('TNG_OS_VERSION', '5.10.0');
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
require_once TNG_OS_PATH . 'app/Modules/Destinations/class-coordinate-intelligence.php';
require_once TNG_OS_PATH . 'app/Modules/Destinations/class-coordinate-quality-controls.php';
require_once TNG_OS_PATH . 'app/Modules/Destinations/class-coordinate-source-resolver.php';
require_once TNG_OS_PATH . 'app/Modules/Destinations/class-knowledge-graph-core.php';
require_once TNG_OS_PATH . 'app/Modules/Destinations/class-knowledge-graph-inspector.php';

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
