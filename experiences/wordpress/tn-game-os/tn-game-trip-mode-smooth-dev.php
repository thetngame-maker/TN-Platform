<?php
/**
 * Plugin Name: TN Game Trip Mode Smooth + Developer
 * Description: Fast first paint for Trip Mode plus an admin-only arrival simulator.
 * Version: 0.2.0
 * Author: The TN Game
 */

if (!defined('ABSPATH')) exit;

define('TNG_TRIP_SMOOTH_DEV_VERSION', '0.2.0');
define('TNG_TRIP_SMOOTH_DEV_URL', plugin_dir_url(__FILE__));

function tng_trip_smooth_dev_is_page() {
    if (function_exists('tng_trip_mode_v1_is_page')) return tng_trip_mode_v1_is_page();
    if (is_page('trip-mode') || is_page('active-trip')) return true;
    $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    $path = (string) wp_parse_url($uri, PHP_URL_PATH);
    return (bool) preg_match('#/(?:trip-mode|active-trip)/?$#i', rtrim($path, '/') . '/');
}

/* Active Trip is now only an entry point. Send it to the dedicated Trip Mode surface
 * before the legacy page can render. Preserve query args for developer/testing use. */
add_action('template_redirect', static function () {
    if (is_admin() || wp_doing_ajax() || !is_page('active-trip')) return;
    $target = home_url('/trip-mode/');
    if (!empty($_GET)) {
        $safe = [];
        foreach ($_GET as $k => $v) {
            if (is_scalar($v)) $safe[sanitize_key((string)$k)] = sanitize_text_field(wp_unslash((string)$v));
        }
        if ($safe) $target = add_query_arg($safe, $target);
    }
    wp_safe_redirect($target, 302, 'TN Game Trip Mode');
    exit;
}, 1);

function tng_trip_smooth_dev_stop_payload($post_id) {
    $post_id = absint($post_id);
    $post = $post_id ? get_post($post_id) : null;
    if (!$post) return null;

    $category = '';
    foreach (['_tng_discovery_category','tng_discovery_category','category','activity_type'] as $key) {
        $value = get_post_meta($post_id, $key, true);
        if (is_scalar($value) && trim((string)$value) !== '') { $category = trim((string)$value); break; }
    }
    if ($category === '') {
        $terms = wp_get_post_terms($post_id, ['st_activity_type','activity_type','category'], ['fields' => 'names']);
        if (!is_wp_error($terms) && !empty($terms)) $category = (string)$terms[0];
    }
    if ($category === '') $category = 'TN Game stop';

    $address = '';
    foreach (['address','_tng_address','tng_address','location_address'] as $key) {
        $value = get_post_meta($post_id, $key, true);
        if (is_scalar($value) && trim((string)$value) !== '') { $address = trim((string)$value); break; }
    }

    $image = get_the_post_thumbnail_url($post_id, 'large');
    if (!$image) $image = '';

    return [
        'id'       => $post_id,
        'title'    => get_the_title($post_id),
        'url'      => get_permalink($post_id),
        'image'    => $image,
        'category' => $category,
        'address'  => $address,
    ];
}

function tng_trip_smooth_dev_initial_payload() {
    if (!is_user_logged_in() || !function_exists('tng_trip_mode_v1_get_state')) {
        return ['state' => null, 'stops' => []];
    }
    $state = tng_trip_mode_v1_get_state(get_current_user_id());
    $route = isset($state['route']) && is_array($state['route']) ? array_values(array_map('absint', $state['route'])) : [];
    $done = array_values(array_unique(array_merge(
        isset($state['completed']) && is_array($state['completed']) ? array_map('absint', $state['completed']) : [],
        isset($state['skipped']) && is_array($state['skipped']) ? array_map('absint', $state['skipped']) : []
    )));
    $pending = array_values(array_filter($route, static function ($id) use ($done) { return !in_array($id, $done, true); }));
    $need = array_slice($pending, 0, 3);
    $stops = [];
    foreach ($need as $id) {
        $payload = tng_trip_smooth_dev_stop_payload($id);
        if ($payload) $stops[] = $payload;
    }
    return ['state' => $state, 'stops' => $stops];
}

/* First-paint CSS: hide legacy Active Trip content, but do NOT use a fullscreen loader.
 * The fast JS shell below becomes visible as soon as DOMContentLoaded fires. */
add_action('wp_head', static function () {
    if (!tng_trip_smooth_dev_is_page()) return;
    ?>
    <style id="tng-trip-smooth-critical">
        body.tng-trip-mode-v1-page main > *:not(#tng-trip-mode-v1){visibility:hidden!important;opacity:0!important;pointer-events:none!important}
        body.tng-trip-mode-v1-page #tng-trip-mode-v1{visibility:visible!important;opacity:1!important;pointer-events:auto!important}
    </style>
    <?php
}, 1);

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
        [],
        TNG_TRIP_SMOOTH_DEV_VERSION,
        true
    );
    $initial = tng_trip_smooth_dev_initial_payload();
    wp_localize_script('tng-trip-mode-smooth-dev', 'TNGTripSmoothDev', [
        'enabled' => true,
        'isAdmin' => current_user_can('manage_options'),
        'label' => 'Trip Mode Developer',
        'initialState' => $initial['state'],
        'initialStops' => $initial['stops'],
    ]);
}, 20);
