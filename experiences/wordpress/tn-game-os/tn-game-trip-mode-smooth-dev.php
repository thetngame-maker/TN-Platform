<?php
/**
 * Plugin Name: TN Game Trip Mode Smooth + Developer
 * Description: Safe Active Trip handoff plus an admin-only Trip Mode arrival simulator.
 * Version: 0.2.4
 * Author: The TN Game
 */

if (!defined('ABSPATH')) exit;

define('TNG_TRIP_SMOOTH_DEV_VERSION', '0.2.4');
define('TNG_TRIP_SMOOTH_DEV_URL', plugin_dir_url(__FILE__));

function tng_trip_smooth_dev_is_page() {
    if (function_exists('tng_trip_mode_v1_is_page')) return tng_trip_mode_v1_is_page();
    if (is_page('trip-mode') || is_page('active-trip')) return true;
    $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    $path = (string) wp_parse_url($uri, PHP_URL_PATH);
    return (bool) preg_match('#/(?:trip-mode|active-trip)/?$#i', rtrim($path, '/') . '/');
}

/**
 * On /active-trip/ the live Trip Mode controller is the only page renderer.
 * Replace the legacy WordPress page content before it reaches the browser so
 * "Your Tennessee day" can never flash before JavaScript mounts Trip Mode.
 *
 * trip-mode-v1.js already looks for #tng-trip-mode-v1 and will reuse this
 * server-rendered root instead of creating a second one.
 */
add_filter('the_content', static function ($content) {
    if (is_admin() || !is_page('active-trip') || !in_the_loop() || !is_main_query()) {
        return $content;
    }

    return '<section id="tng-trip-mode-v1" class="tng-trip-mode tng-trip-mode--server-root" aria-live="polite">'
        . '<div class="tng-trip-mode__empty tng-trip-mode__boot">'
        . '<small>ACTIVE TRIP</small>'
        . '<h1>Trip mode</h1>'
        . '<p>Loading your active trip…</p>'
        . '</div>'
        . '</section>';
}, 1);

/*
 * Fallback first-paint protection for layouts that bypass the_content.
 * The class is applied in <head>, before page markup paints. Once the real
 * server-rendered/controller root is present, the handoff is released.
 */
add_action('wp_head', static function () {
    if (!is_page('active-trip')) return;
    ?>
    <style id="tng-active-trip-handoff-css">
        html.tng-active-trip-handoff body main,
        html.tng-active-trip-handoff body .tng-active-trip-page,
        html.tng-active-trip-handoff body [data-tng-active-trip] {
            visibility: hidden !important;
        }

        html.tng-active-trip-owned #tng-trip-mode-v1 {
            display: block !important;
            visibility: visible !important;
        }

        .tng-trip-mode--server-root .tng-trip-mode__boot {
            min-height: 46vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            gap: 8px;
        }
    </style>
    <script id="tng-active-trip-handoff-js">
    (function () {
        'use strict';
        var html = document.documentElement;
        var observer = null;
        var safetyTimer = null;

        html.classList.add('tng-active-trip-handoff');

        function rootReady() {
            return !!document.getElementById('tng-trip-mode-v1');
        }

        function release() {
            html.classList.remove('tng-active-trip-handoff');
            html.classList.add('tng-active-trip-owned');
            if (observer) observer.disconnect();
            if (safetyTimer) window.clearTimeout(safetyTimer);
        }

        function watch() {
            if (rootReady()) {
                release();
                return;
            }

            observer = new MutationObserver(function () {
                if (rootReady()) release();
            });
            observer.observe(document.documentElement, { childList: true, subtree: true });

            /* Never leave the page hidden if a custom template bypasses content. */
            safetyTimer = window.setTimeout(function () {
                html.classList.remove('tng-active-trip-handoff');
            }, 2500);
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', watch, { once: true });
        } else {
            watch();
        }
    })();
    </script>
    <?php
}, 1);

/*
 * IMPORTANT:
 * This companion never redirects and never manufactures a second Trip Mode
 * controller. The primary tn-game-trip-mode-v1 JavaScript owns live rendering.
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
