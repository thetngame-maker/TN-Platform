<?php
/**
 * Plugin Name: TN Game Place Discovery Integration
 * Description: Upgrades individual TN Game place pages with live discovery details and a real map.
 * Version: 0.1.0
 * Author: The TN Game
 */

if (!defined('ABSPATH')) exit;

define('TNG_PLACE_DISCOVERY_VERSION', '0.1.0');
define('TNG_PLACE_DISCOVERY_URL', plugin_dir_url(__FILE__));

function tng_place_discovery_meta_first(int $id, array $keys) {
    foreach ($keys as $key) {
        $value = get_post_meta($id, $key, true);
        if ($value !== '' && $value !== null && !is_array($value)) return $value;
    }
    return '';
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
    $hours = tng_place_discovery_meta_first($id, ['_tng_source_hours','_tng_food_hours','_tng_hours','hours']);
    $category = tng_place_discovery_meta_first($id, ['_tng_source_primary_type_label','_tng_food_cuisine','_tng_local_category']);

    wp_enqueue_style('leaflet','https://unpkg.com/leaflet@1.9.4/dist/leaflet.css',[],'1.9.4');
    wp_enqueue_script('leaflet','https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',[],'1.9.4',true);
    wp_enqueue_style('tng-place-discovery',TNG_PLACE_DISCOVERY_URL.'assets/css/place-discovery-integration.css',['leaflet'],TNG_PLACE_DISCOVERY_VERSION);
    wp_enqueue_script('tng-place-discovery',TNG_PLACE_DISCOVERY_URL.'assets/js/place-discovery-integration.js',['leaflet'],TNG_PLACE_DISCOVERY_VERSION,true);

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
        'lat' => is_numeric($lat) ? (float)$lat : null,
        'lng' => is_numeric($lng) ? (float)$lng : null,
        'mapUrl' => home_url('/map/'),
        'map' => tng_place_discovery_map_config(),
    ]);
}, 130);
