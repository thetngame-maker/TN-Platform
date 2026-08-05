<?php
/**
 * Plugin Name: TN Game App Router
 * Plugin URI: https://thetngame.com
 * Description: Native TN Game application routes and full-page app shell for Explore, Play, Map, Trips, and Profile.
 * Version: 1.0.0
 * Author: The TN Game
 * Text Domain: tn-game-app-router
 */
if (!defined('ABSPATH')) exit;

add_action('plugins_loaded', static function (): void {
    if (!defined('TNG_OS_PATH') || !defined('TNG_OS_URL')) return;

    if (!class_exists('TNG_Platform_UI')) {
        require_once TNG_OS_PATH . 'tn-game-platform-ui.php';
    }
    if (!class_exists('TNG_Play_UI')) {
        require_once TNG_OS_PATH . 'tn-game-play-ui.php';
    }
    if (!class_exists('TNG_OS\\Platform\\App_Router')) {
        require_once TNG_OS_PATH . 'app/Platform/class-app-router.php';
    }
}, 20);

register_activation_hook(__FILE__, static function (): void {
    update_option('tng_os_rewrite_flush_needed', 1, false);
    flush_rewrite_rules(false);
});

register_deactivation_hook(__FILE__, static function (): void {
    flush_rewrite_rules(false);
});
