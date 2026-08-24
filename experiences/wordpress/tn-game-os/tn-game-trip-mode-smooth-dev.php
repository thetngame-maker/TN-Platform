<?php
/**
 * Plugin Name: TN Game Trip Mode Smooth + Developer
 * Description: Dedicated Trip Mode page routing plus an admin-only arrival simulator.
 * Version: 0.3.0
 * Author: The TN Game
 */

if (!defined('ABSPATH')) exit;

define('TNG_TRIP_SMOOTH_DEV_VERSION', '0.3.0');
define('TNG_TRIP_SMOOTH_DEV_URL', plugin_dir_url(__FILE__));
define('TNG_TRIP_SMOOTH_DEV_PATH', plugin_dir_path(__FILE__));

/**
 * Return the normalized request path with a trailing slash.
 */
function tng_trip_smooth_dev_request_path(): string {
    $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    $path = (string) wp_parse_url($uri, PHP_URL_PATH);
    return '/' . trim($path, '/') . '/';
}

function tng_trip_smooth_dev_is_trip_mode_request(): bool {
    return tng_trip_smooth_dev_request_path() === '/trip-mode/';
}

/**
 * Ensure Trip Mode has its own published WordPress URL. Its post content is
 * intentionally blank because the plugin template owns the entire page body.
 */
add_action('init', static function (): void {
    if (wp_installing()) return;

    $page = get_page_by_path('trip-mode', OBJECT, 'page');
    if ($page instanceof WP_Post) {
        if ($page->post_status !== 'publish') {
            wp_update_post([
                'ID' => $page->ID,
                'post_status' => 'publish',
            ]);
        }
        return;
    }

    wp_insert_post([
        'post_type' => 'page',
        'post_status' => 'publish',
        'post_title' => 'Trip Mode',
        'post_name' => 'trip-mode',
        'post_content' => '',
        'comment_status' => 'closed',
        'ping_status' => 'closed',
    ]);
}, 20);

/**
 * The old /active-trip/ screen remains useful as a planning/status surface,
 * but any direct Active Trip navigation should enter the dedicated live page.
 * Redirect before the theme emits any HTML, eliminating the legacy-page flash.
 */
add_action('template_redirect', static function (): void {
    if (is_admin()) return;
    if (tng_trip_smooth_dev_request_path() !== '/active-trip/') return;

    wp_safe_redirect(home_url('/trip-mode/'), 302, 'TN Game Trip Mode');
    exit;
}, 0);

/**
 * Give /trip-mode/ a plugin-owned template. WordPress page content is never
 * rendered on this surface, so "Your Tennessee day" cannot appear underneath
 * or before the live controller.
 */
add_filter('template_include', static function ($template) {
    if (!tng_trip_smooth_dev_is_trip_mode_request()) return $template;

    $standalone = TNG_TRIP_SMOOTH_DEV_PATH . 'templates/trip-mode-standalone.php';
    return is_readable($standalone) ? $standalone : $template;
}, 9999);

add_filter('body_class', static function (array $classes): array {
    if (tng_trip_smooth_dev_is_trip_mode_request()) {
        $classes[] = 'tng-trip-mode-standalone-page';
    }
    return $classes;
});

/**
 * Minimal first-paint styling for the dedicated root. This is only a boot
 * surface; the primary tn-game-trip-mode-v1 controller replaces its contents.
 */
add_action('wp_head', static function (): void {
    if (!tng_trip_smooth_dev_is_trip_mode_request()) return;
    ?>
    <style id="tng-trip-mode-standalone-boot-css">
        #tng-trip-mode-page {
            width: 100%;
            min-height: 70vh;
        }
        #tng-trip-mode-v1.tng-trip-mode--standalone .tng-trip-mode__boot {
            min-height: 58vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            gap: 8px;
        }
    </style>
    <?php
}, 1);

/**
 * Developer simulator is now limited to the dedicated Trip Mode surface.
 */
add_action('wp_enqueue_scripts', static function (): void {
    if (!tng_trip_smooth_dev_is_trip_mode_request()) return;

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
