<?php
/**
 * Plugin Name: TN Game Trip Mode Smooth + Developer
 * Description: Non-blocking admin-only Trip Mode arrival simulator.
 * Version: 0.2.1
 * Author: The TN Game
 */

if (!defined('ABSPATH')) exit;

define('TNG_TRIP_SMOOTH_DEV_VERSION', '0.2.1');
define('TNG_TRIP_SMOOTH_DEV_URL', plugin_dir_url(__FILE__));

function tng_trip_smooth_dev_is_page() {
    if (function_exists('tng_trip_mode_v1_is_page')) return tng_trip_mode_v1_is_page();
    if (is_page('trip-mode') || is_page('active-trip')) return true;
    $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    $path = (string) wp_parse_url($uri, PHP_URL_PATH);
    return (bool) preg_match('#/(?:trip-mode|active-trip)/?$#i', rtrim($path, '/') . '/');
}

/*
 * IMPORTANT:
 * This companion must never block or replace the real Trip Mode renderer.
 * No template_redirect, no critical hiding CSS, and no synthetic loading shell.
 * The primary tn-game-trip-mode-v1 controller owns page rendering.
 */
add_action('wp_enqueue_scripts', static function () {
    if (!tng_trip_smooth_dev_is_page()) return;

    wp_enqueue_style(
        'tng-trip-mode-smooth-dev',
        TNG_TRIP_SMOOTH_DEV_URL . 'assets/css/trip-mode-smooth-dev.css',
        ['tng-trip-mode-v1'],
        TNG_TRIP_SMOOTH_DEV_VERSION
    );

    wp_enqueue_script(
        'tng-trip-mode-smooth-dev',
        TNG_TRIP_SMOOTH_DEV_URL . 'assets/js/trip-mode-smooth-dev.js',
        ['tng-trip-mode-v1'],
        TNG_TRIP_SMOOTH_DEV_VERSION,
        true
    );

    wp_localize_script('tng-trip-mode-smooth-dev', 'TNGTripSmoothDev', [
        'enabled' => true,
        'isAdmin' => current_user_can('manage_options'),
        'label' => 'Trip Mode Developer',
    ]);
}, 300);
