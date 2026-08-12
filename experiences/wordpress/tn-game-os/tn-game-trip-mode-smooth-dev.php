<?php
/**
 * Plugin Name: TN Game Trip Mode Smooth + Developer
 * Description: Safe Active Trip handoff plus an admin-only Trip Mode arrival simulator.
 * Version: 0.2.3
 * Author: The TN Game
 */

if (!defined('ABSPATH')) exit;

define('TNG_TRIP_SMOOTH_DEV_VERSION', '0.2.3');
define('TNG_TRIP_SMOOTH_DEV_URL', plugin_dir_url(__FILE__));

function tng_trip_smooth_dev_is_page() {
    if (function_exists('tng_trip_mode_v1_is_page')) return tng_trip_mode_v1_is_page();
    if (is_page('trip-mode') || is_page('active-trip')) return true;
    $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    $path = (string) wp_parse_url($uri, PHP_URL_PATH);
    return (bool) preg_match('#/(?:trip-mode|active-trip)/?$#i', rtrim($path, '/') . '/');
}

/*
 * Active Trip has two renderers during the migration:
 * 1. the legacy "Your Tennessee day" page already present in WordPress markup
 * 2. the live #tng-trip-mode-v1 controller inserted by trip-mode-v1.js
 *
 * On /active-trip/ the live controller is authoritative. Keep the legacy markup
 * out of first paint, then permanently suppress its sibling content as soon as
 * Trip Mode mounts. The safety timeout restores the legacy page only when the
 * real Trip Mode controller genuinely fails to appear.
 */
add_action('wp_head', static function () {
    if (!is_page('active-trip')) return;
    ?>
    <style id="tng-active-trip-handoff-css">
        /* Before Trip Mode mounts, prevent the legacy page from flashing. */
        html.tng-active-trip-handoff body main {
            visibility: hidden !important;
        }

        /* Once Trip Mode owns the page, show only its root inside <main>. */
        html.tng-active-trip-owned body main {
            visibility: visible !important;
        }
        html.tng-active-trip-owned body main > :not(#tng-trip-mode-v1) {
            display: none !important;
        }
        html.tng-active-trip-owned body main > #tng-trip-mode-v1 {
            display: block !important;
            visibility: visible !important;
        }
    </style>
    <script id="tng-active-trip-handoff-js">
    (function () {
        'use strict';

        var html = document.documentElement;
        var observer = null;
        var safetyTimer = null;
        var owned = false;

        html.classList.add('tng-active-trip-handoff');

        function rootReady() {
            var root = document.getElementById('tng-trip-mode-v1');
            return !!(root && root.children && root.children.length);
        }

        function claimPage() {
            if (owned || !rootReady()) return false;
            owned = true;
            html.classList.remove('tng-active-trip-handoff');
            html.classList.add('tng-active-trip-owned');
            if (observer) observer.disconnect();
            if (safetyTimer) window.clearTimeout(safetyTimer);
            return true;
        }

        function restoreLegacy() {
            if (owned) return;
            html.classList.remove('tng-active-trip-handoff');
            html.classList.remove('tng-active-trip-owned');
            if (observer) observer.disconnect();
        }

        function watch() {
            if (claimPage()) return;

            observer = new MutationObserver(function () {
                claimPage();
            });
            observer.observe(document.documentElement, {
                childList: true,
                subtree: true
            });

            /* Fail safe only: if Trip Mode truly does not mount, show legacy UI. */
            safetyTimer = window.setTimeout(restoreLegacy, 2500);
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
