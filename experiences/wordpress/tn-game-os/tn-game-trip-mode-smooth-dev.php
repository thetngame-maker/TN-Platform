<?php
/**
 * Plugin Name: TN Game Trip Mode Smooth + Developer
 * Description: Safe Active Trip handoff plus an admin-only Trip Mode arrival simulator.
 * Version: 0.2.2
 * Author: The TN Game
 */

if (!defined('ABSPATH')) exit;

define('TNG_TRIP_SMOOTH_DEV_VERSION', '0.2.2');
define('TNG_TRIP_SMOOTH_DEV_URL', plugin_dir_url(__FILE__));

function tng_trip_smooth_dev_is_page() {
    if (function_exists('tng_trip_mode_v1_is_page')) return tng_trip_mode_v1_is_page();
    if (is_page('trip-mode') || is_page('active-trip')) return true;
    $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    $path = (string) wp_parse_url($uri, PHP_URL_PATH);
    return (bool) preg_match('#/(?:trip-mode|active-trip)/?$#i', rtrim($path, '/') . '/');
}

/*
 * Prevent the legacy Active Trip page from flashing before the real Trip Mode
 * controller mounts. This is deliberately fail-safe:
 * - it runs only on /active-trip/
 * - it never creates/replaces Trip Mode markup
 * - it reveals the page immediately when #tng-trip-mode-v1 appears
 * - it always gives up after 1200ms so a broken Trip Mode can never leave a blank page
 */
add_action('wp_head', static function () {
    if (!is_page('active-trip')) return;
    ?>
    <style id="tng-active-trip-handoff-css">
        html.tng-active-trip-handoff body main {
            visibility: hidden !important;
        }
        html.tng-active-trip-handoff body main #tng-trip-mode-v1 {
            visibility: hidden !important;
        }
    </style>
    <script id="tng-active-trip-handoff-js">
    (function () {
        'use strict';
        var html = document.documentElement;
        html.classList.add('tng-active-trip-handoff');
        var finished = false;
        var observer = null;

        function release() {
            if (finished) return;
            finished = true;
            html.classList.remove('tng-active-trip-handoff');
            if (observer) observer.disconnect();
        }

        function tripModeReady() {
            var root = document.getElementById('tng-trip-mode-v1');
            return !!(root && root.children && root.children.length);
        }

        function watch() {
            if (tripModeReady()) {
                release();
                return;
            }
            observer = new MutationObserver(function () {
                if (tripModeReady()) release();
            });
            observer.observe(document.documentElement, { childList: true, subtree: true });
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', watch, { once: true });
        } else {
            watch();
        }

        /* Absolute safety valve: never hide the real page indefinitely. */
        window.setTimeout(release, 1200);
    })();
    </script>
    <?php
}, 1);

/*
 * IMPORTANT:
 * This companion never redirects and never manufactures its own Trip Mode shell.
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
