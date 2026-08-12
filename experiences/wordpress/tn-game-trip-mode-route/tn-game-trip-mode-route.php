<?php
/**
 * Plugin Name: TN Game Trip Mode Route
 * Description: Gives /trip-mode/ a dedicated live Trip Mode surface and prevents legacy trip pages from rendering first.
 * Version: 1.0.1
 * Author: The TN Game
 */

if (!defined('ABSPATH')) exit;

define('TNG_TRIP_MODE_ROUTE_VERSION', '1.0.1');
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
