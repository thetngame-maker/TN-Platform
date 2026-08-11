<?php
/**
 * Plugin Name: TN Game Trip Mode Smooth + Developer
 * Description: Prevents legacy Active Trip flash and adds an admin-only Trip Mode arrival simulator.
 * Version: 0.1.0
 * Author: The TN Game
 */

if (!defined('ABSPATH')) exit;

define('TNG_TRIP_SMOOTH_DEV_VERSION', '0.1.0');
define('TNG_TRIP_SMOOTH_DEV_URL', plugin_dir_url(__FILE__));

function tng_trip_smooth_dev_is_page() {
    if (function_exists('tng_trip_mode_v1_is_page')) return tng_trip_mode_v1_is_page();
    if (is_page('trip-mode') || is_page('active-trip')) return true;
    $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    $path = (string) wp_parse_url($uri, PHP_URL_PATH);
    return (bool) preg_match('#/(?:trip-mode|active-trip)/?$#i', rtrim($path, '/') . '/');
}

/* Critical first-paint CSS. This intentionally runs in wp_head before normal styles. */
add_action('wp_head', static function () {
    if (!tng_trip_smooth_dev_is_page()) return;
    ?>
    <style id="tng-trip-smooth-critical">
        body.tng-trip-mode-v1-page main > *:not(#tng-trip-mode-v1){visibility:hidden!important;opacity:0!important;pointer-events:none!important}
        body.tng-trip-mode-v1-page #tng-trip-mode-v1{visibility:visible!important;opacity:1!important;pointer-events:auto!important}
        body.tng-trip-mode-v1-page:not(.tng-trip-mode-hydrated)::after{
            content:"Loading Trip Mode…";position:fixed;left:50%;top:48%;z-index:99999;
            transform:translate(-50%,-50%);padding:14px 18px;border:1px solid #dfe8e1;
            border-radius:16px;background:#fff;color:#17231b;font:700 14px/1.2 system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
            box-shadow:0 12px 36px rgba(18,61,41,.10)
        }
        body.tng-trip-mode-v1-page.tng-trip-mode-hydrated::after{display:none!important}
    </style>
    <?php
}, 1);

add_action('wp_enqueue_scripts', static function () {
    if (!tng_trip_smooth_dev_is_page()) return;

    wp_enqueue_style(
        'tng-trip-mode-smooth-dev',
        TNG_TRIP_SMOOTH_DEV_URL . 'assets/css/trip-mode-smooth-dev.css',
        [],
        TNG_TRIP_SMOOTH_DEV_VERSION
    );
    wp_enqueue_script(
        'tng-trip-mode-smooth-dev',
        TNG_TRIP_SMOOTH_DEV_URL . 'assets/js/trip-mode-smooth-dev.js',
        [],
        TNG_TRIP_SMOOTH_DEV_VERSION,
        true
    );
    wp_localize_script('tng-trip-mode-smooth-dev', 'TNGTripSmoothDev', [
        'enabled' => true,
        'isAdmin' => current_user_can('manage_options'),
        'label' => 'Trip Mode Developer',
    ]);
}, 300);
