<?php
/**
 * Plugin Name: TN Game Place Discovery Integration
 * Description: Upgrades individual TN Game place pages with live discovery details and a real map.
 * Version: 0.2.7
 * Author: The TN Game
 */

if (!defined('ABSPATH')) exit;

define('TNG_PLACE_DISCOVERY_VERSION', '0.2.7');
define('TNG_PLACE_DISCOVERY_URL', plugin_dir_url(__FILE__));

function tng_place_discovery_meta_first(int $id, array $keys) {
    foreach ($keys as $key) {
        $value = get_post_meta($id, $key, true);
        if ($value !== '' && $value !== null && !is_array($value)) return $value;
    }
    return '';
}

function tng_place_discovery_attributes(int $id): array {
    foreach (['_tng_source_attributes', '_tng_food_services', '_tng_attributes'] as $key) {
        $value = get_post_meta($id, $key, true);
        if (!is_array($value) || !$value) continue;
        return array_values(array_unique(array_filter(array_map('sanitize_key', $value))));
    }
    return [];
}

function tng_place_discovery_gallery(int $id): array {
    $ids = (array)get_post_meta($id, '_tng_discovery_gallery_attachment_ids', true);
    if (!$ids) $ids = (array)get_post_meta($id, '_tng_gallery_image_ids', true);
    if (!$ids) {
        $raw = (string)get_post_meta($id, 'gallery', true);
        if ($raw !== '') $ids = preg_split('/\s*,\s*/', $raw);
    }

    $images = [];
    foreach (array_slice(array_values(array_unique(array_filter(array_map('absint', $ids)))), 0, 9) as $attachment_id) {
        $url = wp_get_attachment_image_url($attachment_id, 'large');
        if (!$url) $url = wp_get_attachment_url($attachment_id);
        if (!$url) continue;
        $images[] = [
            'id' => $attachment_id,
            'url' => esc_url_raw($url),
            'full' => esc_url_raw(wp_get_attachment_url($attachment_id) ?: $url),
            'alt' => (string)get_post_meta($attachment_id, '_wp_attachment_image_alt', true),
        ];
    }
    return $images;
}

function tng_place_discovery_token_from_value($value): string {
    if (is_string($value)) {
        $value = trim($value);
        if (strpos($value, 'pk.') === 0 || strpos($value, 'sk.') === 0) return $value;
        return '';
    }
    if (!is_array($value)) return '';
    foreach ($value as $key => $child) {
        $key_text = strtolower((string)$key);
        if (strpos($key_text, 'mapbox') !== false || strpos($key_text, 'token') !== false || strpos($key_text, 'api') !== false) {
            $found = tng_place_discovery_token_from_value($child);
            if ($found !== '') return $found;
        }
    }
    foreach ($value as $child) {
        $found = tng_place_discovery_token_from_value($child);
        if ($found !== '') return $found;
    }
    return '';
}

function tng_place_discovery_mapbox_token(): string {
    foreach (['TNG_MAPBOX_TOKEN','MAPBOX_ACCESS_TOKEN','ST_MAPBOX_TOKEN'] as $constant) {
        if (defined($constant) && is_string(constant($constant))) {
            $found = tng_place_discovery_token_from_value((string)constant($constant));
            if ($found !== '') return $found;
        }
    }

    $keys = [
        'tng_mapbox_token','mapbox_access_token','st_mapbox_token','mapbox_token','st_mapbox_api_key',
        'mapbox_api_key','mapbox_access_key','st_mapbox_access_token','mapbox_key'
    ];
    foreach ($keys as $key) {
        $found = tng_place_discovery_token_from_value(get_option($key, ''));
        if ($found !== '') return $found;
    }

    if (function_exists('ot_get_option')) {
        foreach ($keys as $key) {
            $found = tng_place_discovery_token_from_value(ot_get_option($key, ''));
            if ($found !== '') return $found;
        }
    }

    if (function_exists('st')) {
        try {
            $traveler = st();
            if (is_object($traveler) && method_exists($traveler, 'get_option')) {
                foreach ($keys as $key) {
                    $found = tng_place_discovery_token_from_value($traveler->get_option($key, ''));
                    if ($found !== '') return $found;
                }
            }
        } catch (Throwable $e) {}
    }

    foreach (['option_tree','st_options','traveler_options','traveler_setting','traveler_settings'] as $option_name) {
        $found = tng_place_discovery_token_from_value(get_option($option_name, []));
        if ($found !== '') return $found;
    }

    return '';
}

function tng_place_discovery_map_config(): array {
    $token = tng_place_discovery_mapbox_token();
    if (strpos($token, 'pk.') === 0) {
        return [
            'tileUrl' => 'https://api.mapbox.com/styles/v1/mapbox/outdoors-v12/tiles/512/{z}/{x}/{y}@2x?access_token=' . rawurlencode($token),
            'tileSize' => 512,
            'zoomOffset' => -1,
            'maxZoom' => 20,
            'attribution' => '© Mapbox © OpenStreetMap',
        ];
    }
    return [
        'tileUrl' => 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
        'tileSize' => 256,
        'zoomOffset' => 0,
        'maxZoom' => 19,
        'attribution' => '© OpenStreetMap contributors',
    ];
}

function tng_place_discovery_is_trip_builder(): bool {
    if (is_page('trip-builder')) return true;
    $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    if ($uri === '') return false;
    $path = (string) wp_parse_url($uri, PHP_URL_PATH);
    return (bool) preg_match('#/(?:trip-builder|build-my-day)/?$#i', rtrim($path, '/') . '/');
}

add_action('rest_api_init', static function (): void {
    register_rest_route('tn-game/v1', '/trip/route-matrix', [
        'methods' => 'POST',
        'permission_callback' => static function (): bool { return is_user_logged_in(); },
        'callback' => static function (WP_REST_Request $request) {
            $coords = $request->get_param('coordinates');
            if (!is_array($coords)) {
                return new WP_Error('tng_bad_coordinates', 'Coordinates are required.', ['status' => 400]);
            }
            $coords = array_slice($coords, 0, 25);
            $clean = [];
            foreach ($coords as $point) {
                if (!is_array($point)) continue;
                $lat = isset($point['lat']) ? (float)$point['lat'] : null;
                $lng = isset($point['lng']) ? (float)$point['lng'] : null;
                if ($lat === null || $lng === null || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) continue;
                $clean[] = ['lat' => $lat, 'lng' => $lng];
            }
            if (count($clean) < 2) {
                return new WP_Error('tng_not_enough_coordinates', 'At least two valid coordinates are required.', ['status' => 400]);
            }

            $token = tng_place_discovery_mapbox_token();
            if ($token === '') {
                return new WP_Error('tng_mapbox_missing', 'No Mapbox routing token is configured.', ['status' => 503]);
            }

            $coord_string = implode(';', array_map(static function (array $point): string {
                return number_format($point['lng'], 6, '.', '') . ',' . number_format($point['lat'], 6, '.', '');
            }, $clean));
            $url = 'https://api.mapbox.com/directions-matrix/v1/mapbox/driving/' . $coord_string;
            $url = add_query_arg([
                'annotations' => 'duration,distance',
                'access_token' => $token,
            ], $url);

            $response = wp_remote_get($url, [
                'timeout' => 12,
                'redirection' => 2,
                'headers' => ['Accept' => 'application/json'],
            ]);
            if (is_wp_error($response)) {
                return new WP_Error('tng_routing_request_failed', $response->get_error_message(), ['status' => 502]);
            }
            $status = wp_remote_retrieve_response_code($response);
            $body = json_decode(wp_remote_retrieve_body($response), true);
            if ($status < 200 || $status >= 300 || !is_array($body) || !isset($body['durations']) || !is_array($body['durations'])) {
                $message = is_array($body) && !empty($body['message']) ? (string)$body['message'] : 'Mapbox routing did not return a matrix.';
                return new WP_Error('tng_routing_response_failed', $message, ['status' => 502, 'upstream_status' => $status]);
            }

            return rest_ensure_response([
                'provider' => 'mapbox',
                'durations' => $body['durations'],
                'distances' => isset($body['distances']) && is_array($body['distances']) ? $body['distances'] : null,
            ]);
        },
    ]);
});

add_action('wp_enqueue_scripts', static function (): void {
    if (!is_singular('st_activity')) return;
    $id = get_queried_object_id();
    if (!$id) return;

    $lat = tng_place_discovery_meta_first($id, ['_tng_source_latitude','_tng_food_latitude','_tng_latitude','map_lat','latitude','lat']);
    $lng = tng_place_discovery_meta_first($id, ['_tng_source_longitude','_tng_food_longitude','_tng_longitude','map_lng','longitude','lng','lon']);
    $rating = tng_place_discovery_meta_first($id, ['_tng_source_rating','_tng_food_rating','_tng_rating','rating']);
    $rating_count = tng_place_discovery_meta_first($id, ['_tng_source_rating_count','_tng_food_rating_count','_tng_rating_count','rating_count']);
    $address = tng_place_discovery_meta_first($id, ['_tng_source_address','_tng_food_address','_tng_address','address']);
    $phone = tng_place_discovery_meta_first($id, ['_tng_source_phone','_tng_food_phone','_tng_phone','phone']);
    $website = tng_place_discovery_meta_first($id, ['_tng_source_website','_tng_food_website','_tng_website','website']);
    $hours = tng_place_discovery_meta_first($id, ['_tng_source_hours','_tng_food_hours','_tng_food_hours_text','_tng_hours','hours']);
    $category = tng_place_discovery_meta_first($id, ['_tng_source_primary_type_label','_tng_food_cuisine','_tng_local_category']);
    $menu_url = tng_place_discovery_meta_first($id, ['_tng_food_menu_url','_tng_menu_url','menu_url']);
    $order_url = tng_place_discovery_meta_first($id, ['_tng_food_online_ordering_url','_tng_food_order_url','_tng_online_ordering_url','online_ordering_url','order_url']);
    $reservation_url = tng_place_discovery_meta_first($id, ['_tng_food_reservation_url','_tng_reservation_url','reservation_url']);

    $saved_ids = [];
    if (is_user_logged_in()) {
        $saved_ids = get_user_meta(get_current_user_id(), 'tng_saved_trip_items', true);
        if (!is_array($saved_ids)) $saved_ids = [];
        $saved_ids = array_values(array_unique(array_filter(array_map('absint', $saved_ids))));
    }

    wp_enqueue_style('leaflet','https://unpkg.com/leaflet@1.9.4/dist/leaflet.css',[],'1.9.4');
    wp_enqueue_script('leaflet','https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',[],'1.9.4',true);
    wp_enqueue_style('tng-place-discovery',TNG_PLACE_DISCOVERY_URL.'assets/css/place-discovery-integration.css',['leaflet'],TNG_PLACE_DISCOVERY_VERSION);
    wp_enqueue_style('tng-place-trip-actions',TNG_PLACE_DISCOVERY_URL.'assets/css/place-trip-actions.css',['tng-place-discovery'],TNG_PLACE_DISCOVERY_VERSION);
    wp_enqueue_script('tng-place-discovery',TNG_PLACE_DISCOVERY_URL.'assets/js/place-discovery-integration.js',['leaflet'],TNG_PLACE_DISCOVERY_VERSION,true);
    wp_enqueue_script('tng-place-trip-actions',TNG_PLACE_DISCOVERY_URL.'assets/js/place-trip-actions.js',['tng-place-discovery'],TNG_PLACE_DISCOVERY_VERSION,true);

    wp_localize_script('tng-place-discovery','TNGPlaceDiscovery',[
        'id' => $id,
        'title' => html_entity_decode(get_the_title($id), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        'category' => $category ?: 'Local Place',
        'rating' => is_numeric($rating) ? (float)$rating : null,
        'ratingCount' => is_numeric($rating_count) ? (int)$rating_count : 0,
        'address' => (string)$address,
        'phone' => (string)$phone,
        'website' => esc_url_raw((string)$website),
        'hours' => (string)$hours,
        'menuUrl' => esc_url_raw((string)$menu_url),
        'orderUrl' => esc_url_raw((string)$order_url),
        'reservationUrl' => esc_url_raw((string)$reservation_url),
        'attributes' => tng_place_discovery_attributes($id),
        'gallery' => tng_place_discovery_gallery($id),
        'lat' => is_numeric($lat) ? (float)$lat : null,
        'lng' => is_numeric($lng) ? (float)$lng : null,
        'mapUrl' => home_url('/map/'),
        'nearbyEndpoint' => esc_url_raw(add_query_arg('limit', 100, rest_url('tn-game/v1/explore/places'))),
        'trip' => [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('tng_trip_data'),
            'loggedIn' => is_user_logged_in(),
            'loginUrl' => wp_login_url(get_permalink($id)),
            'savedIds' => $saved_ids,
            'savedUrl' => home_url('/saved/'),
        ],
        'map' => tng_place_discovery_map_config(),
    ]);
}, 130);

add_action('wp_enqueue_scripts', static function (): void {
    if (!tng_place_discovery_is_trip_builder()) return;

    $saved_ids = [];
    if (is_user_logged_in()) {
        $saved_ids = get_user_meta(get_current_user_id(), 'tng_saved_trip_items', true);
        if (!is_array($saved_ids)) $saved_ids = [];
        $saved_ids = array_values(array_unique(array_filter(array_map('absint', $saved_ids))));
    }

    wp_enqueue_style('tng-trip-day-planner', TNG_PLACE_DISCOVERY_URL . 'assets/css/trip-day-planner.css', [], TNG_PLACE_DISCOVERY_VERSION);
    wp_enqueue_script('tng-trip-day-planner', TNG_PLACE_DISCOVERY_URL . 'assets/js/trip-day-planner.js', [], TNG_PLACE_DISCOVERY_VERSION, true);
    wp_localize_script('tng-trip-day-planner', 'TNGTripDayPlanner', [
        'enabled' => true,
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('tng_trip_data'),
        'restNonce' => wp_create_nonce('wp_rest'),
        'savedIds' => $saved_ids,
        'endpoint' => esc_url_raw(add_query_arg('limit', 100, rest_url('tn-game/v1/explore/places'))),
        'tripsUrl' => home_url('/trips/'),
        'builderUrl' => home_url('/trip-builder/'),
        'routing' => [
            'provider' => tng_place_discovery_mapbox_token() !== '' ? 'mapbox' : 'estimate',
            'matrixEndpoint' => esc_url_raw(rest_url('tn-game/v1/trip/route-matrix')),
            'maxCoordinates' => 25,
        ],
    ]);
}, 135);
