<?php
/**
 * Plugin Name: TN Game Trip Mode Route
 * Description: Gives /trip-mode/ a dedicated live Trip Mode surface and prevents legacy trip pages from rendering first.
 * Version: 1.0.2
 * Author: The TN Game
 */

if (!defined('ABSPATH')) exit;

define('TNG_TRIP_MODE_ROUTE_VERSION', '1.0.2');
define('TNG_TRIP_MODE_ROUTE_PATH', plugin_dir_path(__FILE__));

function tng_trip_mode_route_path(): string {
    $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    $path = (string) wp_parse_url($uri, PHP_URL_PATH);
    return '/' . trim($path, '/') . '/';
}

function tng_trip_mode_route_is_live(): bool {
    return tng_trip_mode_route_path() === '/trip-mode/';
}

/** Redirect the former live URL before any theme/page output. */
add_action('template_redirect', static function (): void {
    if (is_admin()) return;
    if (tng_trip_mode_route_path() !== '/active-trip/') return;
    wp_safe_redirect(home_url('/trip-mode/'), 302, 'TN Game Trip Mode Route');
    exit;
}, 0);

/**
 * Replace only the final page template. This deliberately keeps the normal
 * WordPress + Traveler lifecycle intact (wp_head, enqueues, wp_footer and the
 * theme loader shutdown), while preventing the old Trips page content from
 * ever being rendered into /trip-mode/.
 */
add_filter('template_include', static function ($template) {
    if (!tng_trip_mode_route_is_live()) return $template;

    $dedicated = TNG_TRIP_MODE_ROUTE_PATH . 'templates/trip-mode.php';
    return is_readable($dedicated) ? $dedicated : $template;
}, PHP_INT_MAX);

add_filter('body_class', static function (array $classes): array {
    if (tng_trip_mode_route_is_live()) {
        $classes[] = 'tng-trip-mode-route-page';
        $classes[] = 'tng-trip-mode-v1-page';
    }
    return $classes;
});

/**
 * Critical standalone styling. This is deliberately emitted in <head> so the
 * neutral TN Game surface and boot state are present on the browser's first
 * paint, before the full Trip Mode bundle finishes loading.
 */
add_action('wp_head', static function (): void {
    if (!tng_trip_mode_route_is_live()) return;
    ?>
    <style id="tng-trip-mode-route-critical">
        html,
        body.tng-trip-mode-route-page,
        body.tng-trip-mode-route-page #page,
        body.tng-trip-mode-route-page .site,
        body.tng-trip-mode-route-page .site-content,
        body.tng-trip-mode-route-page #content,
        body.tng-trip-mode-route-page #primary,
        body.tng-trip-mode-route-page main#tng-trip-mode-page {
            background: #f7f6f1 !important;
            background-image: none !important;
        }

        body.tng-trip-mode-route-page main#tng-trip-mode-page {
            min-height: 70vh;
        }

        body.tng-trip-mode-route-page #tng-trip-mode-v1,
        body.tng-trip-mode-route-page #tng-trip-mode-v1.tng-trip-mode,
        body.tng-trip-mode-route-page #tng-trip-mode-v1.tng-trip-mode--standalone,
        body.tng-trip-mode-route-page #tng-trip-mode-v1::before,
        body.tng-trip-mode-route-page #tng-trip-mode-v1::after {
            background: transparent !important;
            background-color: transparent !important;
            background-image: none !important;
        }

        body.tng-trip-mode-route-page #tng-trip-mode-v1 {
            width: 100%;
            box-sizing: border-box;
        }

        body.tng-trip-mode-route-page .tng-trip-mode__boot {
            width: min(420px, calc(100% - 40px));
            margin: 120px auto;
            padding: 28px 30px;
            box-sizing: border-box;
            border: 1px solid rgba(22, 65, 45, .10);
            border-radius: 22px;
            background: #fff !important;
            color: #163d2d;
            box-shadow: 0 14px 34px rgba(31, 49, 40, .08);
            text-align: center;
        }

        body.tng-trip-mode-route-page .tng-trip-mode__boot small {
            display: block;
            margin-bottom: 7px;
            color: #ef5b22;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .12em;
        }

        body.tng-trip-mode-route-page .tng-trip-mode__boot h1 {
            margin: 0 0 8px;
            color: #122d22;
            font-size: clamp(30px, 5vw, 44px);
            line-height: 1.05;
        }

        body.tng-trip-mode-route-page .tng-trip-mode__boot p {
            margin: 0;
            color: #748078;
        }
    </style>
    <?php
}, 1);

/**
 * Keep unrelated trip-planner scripts off the dedicated live surface. The
 * Trip Mode controller, Leaflet, check-in, arrival and developer assets remain.
 */
add_action('wp_enqueue_scripts', static function (): void {
    if (!tng_trip_mode_route_is_live()) return;

    $legacy_handles = [
        'tng-trip-day-planner',
        'tn-trip-day-planner',
        'trip-day-planner',
        'tng-trips',
        'tng-active-trip',
        'tn-active-trip',
    ];

    foreach ($legacy_handles as $handle) {
        wp_dequeue_script($handle);
        wp_deregister_script($handle);
        wp_dequeue_style($handle);
    }
}, PHP_INT_MAX);
