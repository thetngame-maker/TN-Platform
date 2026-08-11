<?php
/**
 * Plugin Name: TN Game Trip Mode V1
 * Description: Live active-trip controller for TN Game saved routes.
 * Version: 0.1.1
 * Author: The TN Game
 */

if (!defined('ABSPATH')) exit;

define('TNG_TRIP_MODE_V1_VERSION', '0.1.1');
define('TNG_TRIP_MODE_V1_URL', plugin_dir_url(__FILE__));
define('TNG_TRIP_MODE_V1_META', 'tng_active_trip_state_v1');

function tng_trip_mode_v1_saved_ids(int $user_id): array {
    $ids = get_user_meta($user_id, 'tng_saved_trip_items', true);
    if (!is_array($ids)) $ids = [];
    return array_values(array_unique(array_filter(array_map('absint', $ids))));
}

function tng_trip_mode_v1_is_page(): bool {
    if (is_page('trip-mode')) return true;
    $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    if ($uri === '') return false;
    $path = (string) wp_parse_url($uri, PHP_URL_PATH);
    return (bool) preg_match('#/(?:trip-mode|active-trip)/?$#i', rtrim($path, '/') . '/');
}

add_filter('body_class', static function (array $classes): array {
    if (tng_trip_mode_v1_is_page()) $classes[] = 'tng-trip-mode-v1-page';
    return $classes;
});

function tng_trip_mode_v1_clean_state($raw): array {
    if (!is_array($raw)) $raw = [];
    $route = isset($raw['route']) && is_array($raw['route']) ? array_values(array_unique(array_filter(array_map('absint', $raw['route'])))) : [];
    $completed = isset($raw['completed']) && is_array($raw['completed']) ? array_values(array_intersect($route, array_map('absint', $raw['completed']))) : [];
    $skipped = isset($raw['skipped']) && is_array($raw['skipped']) ? array_values(array_intersect($route, array_map('absint', $raw['skipped']))) : [];
    $done = array_values(array_unique(array_merge($completed, $skipped)));
    $current_index = count($route);
    foreach ($route as $index => $id) {
        if (!in_array($id, $done, true)) {
            $current_index = $index;
            break;
        }
    }
    return [
        'route' => $route,
        'completed' => $completed,
        'skipped' => $skipped,
        'currentIndex' => $current_index,
        'startedAt' => isset($raw['startedAt']) ? absint($raw['startedAt']) : 0,
        'updatedAt' => isset($raw['updatedAt']) ? absint($raw['updatedAt']) : 0,
        'finished' => $route ? $current_index >= count($route) : false,
    ];
}

function tng_trip_mode_v1_get_state(int $user_id): array {
    return tng_trip_mode_v1_clean_state(get_user_meta($user_id, TNG_TRIP_MODE_V1_META, true));
}

function tng_trip_mode_v1_save_state(int $user_id, array $state): array {
    $state['updatedAt'] = time();
    $state = tng_trip_mode_v1_clean_state($state);
    update_user_meta($user_id, TNG_TRIP_MODE_V1_META, $state);
    return $state;
}

add_action('rest_api_init', static function (): void {
    register_rest_route('tn-game/v1', '/trip/state', [
        [
            'methods' => 'GET',
            'permission_callback' => static function (): bool { return is_user_logged_in(); },
            'callback' => static function () {
                $user_id = get_current_user_id();
                return rest_ensure_response([
                    'state' => tng_trip_mode_v1_get_state($user_id),
                    'savedIds' => tng_trip_mode_v1_saved_ids($user_id),
                ]);
            },
        ],
        [
            'methods' => 'POST',
            'permission_callback' => static function (): bool { return is_user_logged_in(); },
            'callback' => static function (WP_REST_Request $request) {
                $user_id = get_current_user_id();
                $action = sanitize_key((string) $request->get_param('action'));
                $state = tng_trip_mode_v1_get_state($user_id);

                if ($action === 'start' || $action === 'restart') {
                    $route = $request->get_param('route');
                    if (!is_array($route)) $route = tng_trip_mode_v1_saved_ids($user_id);
                    $route = array_values(array_unique(array_filter(array_map('absint', $route))));
                    $saved = tng_trip_mode_v1_saved_ids($user_id);
                    $route = array_values(array_intersect($route, $saved));
                    $state = [
                        'route' => $route,
                        'completed' => [],
                        'skipped' => [],
                        'currentIndex' => 0,
                        'startedAt' => time(),
                        'updatedAt' => time(),
                    ];
                } elseif ($action === 'complete' || $action === 'skip') {
                    if (!$state['route']) {
                        return new WP_Error('tng_trip_not_started', 'Start a trip first.', ['status' => 409]);
                    }
                    $index = min(max(0, (int)$state['currentIndex']), max(0, count($state['route']) - 1));
                    $id = absint($request->get_param('id'));
                    if (!$id) $id = isset($state['route'][$index]) ? absint($state['route'][$index]) : 0;
                    if (!$id || !in_array($id, $state['route'], true)) {
                        return new WP_Error('tng_trip_bad_stop', 'That stop is not in the active trip.', ['status' => 400]);
                    }
                    $key = $action === 'complete' ? 'completed' : 'skipped';
                    if (!in_array($id, $state[$key], true)) $state[$key][] = $id;
                    $other = $action === 'complete' ? 'skipped' : 'completed';
                    $state[$other] = array_values(array_diff($state[$other], [$id]));
                } elseif ($action === 'reset') {
                    $state = [
                        'route' => [],
                        'completed' => [],
                        'skipped' => [],
                        'currentIndex' => 0,
                        'startedAt' => 0,
                        'updatedAt' => time(),
                    ];
                } else {
                    return new WP_Error('tng_trip_bad_action', 'Unsupported trip action.', ['status' => 400]);
                }

                $state = tng_trip_mode_v1_save_state($user_id, $state);
                return rest_ensure_response(['state' => $state]);
            },
        ],
    ]);
});

add_action('wp_enqueue_scripts', static function (): void {
    if (!tng_trip_mode_v1_is_page()) return;

    $saved_ids = is_user_logged_in() ? tng_trip_mode_v1_saved_ids(get_current_user_id()) : [];

    wp_enqueue_style('tng-trip-mode-v1', TNG_TRIP_MODE_V1_URL . 'assets/css/trip-mode-v1.css', [], TNG_TRIP_MODE_V1_VERSION);
    wp_enqueue_style('tng-trip-mode-v1-compat', TNG_TRIP_MODE_V1_URL . 'assets/css/trip-mode-v1-compat.css', ['tng-trip-mode-v1'], TNG_TRIP_MODE_V1_VERSION);
    wp_enqueue_script('tng-trip-mode-v1', TNG_TRIP_MODE_V1_URL . 'assets/js/trip-mode-v1.js', [], TNG_TRIP_MODE_V1_VERSION, true);
    wp_enqueue_script('tng-trip-mode-v1-compat', TNG_TRIP_MODE_V1_URL . 'assets/js/trip-mode-v1-compat.js', ['tng-trip-mode-v1'], TNG_TRIP_MODE_V1_VERSION, true);
    wp_localize_script('tng-trip-mode-v1', 'TNGTripModeV1', [
        'enabled' => true,
        'loggedIn' => is_user_logged_in(),
        'loginUrl' => wp_login_url(home_url('/trip-mode/')),
        'restNonce' => wp_create_nonce('wp_rest'),
        'stateEndpoint' => esc_url_raw(rest_url('tn-game/v1/trip/state')),
        'placesEndpoint' => esc_url_raw(add_query_arg('limit', 100, rest_url('tn-game/v1/explore/places'))),
        'matrixEndpoint' => esc_url_raw(rest_url('tn-game/v1/trip/route-matrix')),
        'savedIds' => $saved_ids,
        'builderUrl' => home_url('/trip-builder/'),
        'tripsUrl' => home_url('/trips/'),
        'mapUrl' => home_url('/map/'),
    ]);
}, 150);
