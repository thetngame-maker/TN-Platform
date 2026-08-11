<?php
/**
 * Plugin Name: TN Game Place Discovery Integration
 * Description: Upgrades individual TN Game place pages with live discovery details and a real map.
 * Version: 0.2.3
 * Author: The TN Game
 */

if (!defined('ABSPATH')) exit;

define('TNG_PLACE_DISCOVERY_VERSION', '0.2.3');
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

function tng_place_discovery_map_config(): array {
    $token = '';
    foreach (['TNG_MAPBOX_TOKEN','MAPBOX_ACCESS_TOKEN','ST_MAPBOX_TOKEN'] as $constant) {
        if (defined($constant) && is_string(constant($constant)) && constant($constant) !== '') {
            $token = (string) constant($constant);
            break;
        }
    }
    if ($token === '') {
        foreach (['tng_mapbox_token','mapbox_access_token','st_mapbox_token','mapbox_token','st_mapbox_api_key'] as $key) {
            $value = get_option($key, '');
            if (is_string($value) && strpos($value, 'pk.') === 0) {
                $token = $value;
                break;
            }
        }
    }
    if ($token !== '') {
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
