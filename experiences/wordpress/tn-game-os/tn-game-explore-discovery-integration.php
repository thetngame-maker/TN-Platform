<?php
/**
 * Plugin Name: TN Game Explore Discovery Integration
 * Description: Connects published TN Game places to the native Explore and Map surfaces.
 * Version: 0.2.0
 * Author: The TN Game
 */

if (!defined('ABSPATH')) exit;

define('TNG_EXPLORE_DISCOVERY_VERSION', '0.2.0');
define('TNG_EXPLORE_DISCOVERY_PATH', plugin_dir_path(__FILE__));
define('TNG_EXPLORE_DISCOVERY_URL', plugin_dir_url(__FILE__));

require_once TNG_EXPLORE_DISCOVERY_PATH . 'app/Modules/Frontend/class-explore-discovery-feed.php';

add_action('plugins_loaded', static function (): void {
    \TNG_OS\Modules\Frontend\Explore_Discovery_Feed::boot();
}, 70);
