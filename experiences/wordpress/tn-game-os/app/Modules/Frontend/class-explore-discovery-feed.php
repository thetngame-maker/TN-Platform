<?php
namespace TNG_OS\Modules\Frontend;

if (!defined('ABSPATH')) exit;

final class Explore_Discovery_Feed {
    private const REST_NAMESPACE = 'tn-game/v1';
    private const REST_ROUTE = '/explore/places';

    public static function boot(): void {
        add_action('rest_api_init', [self::class, 'register_rest_route']);
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_assets'], 120);
    }

    public static function register_rest_route(): void {
        register_rest_route(self::REST_NAMESPACE, self::REST_ROUTE, [
            'methods' => 'GET',
            'callback' => [self::class, 'rest_places'],
            'permission_callback' => '__return_true',
            'args' => [
                'limit' => ['default' => 60, 'sanitize_callback' => 'absint'],
            ],
        ]);
    }

    public static function enqueue_assets(): void {
        if (is_admin()) return;
        $path = trim((string) wp_parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
        $is_map = $path === 'map';
        $is_explore = $path === '' || $path === 'explore' || is_front_page();
        if (!$is_map && !$is_explore) return;

        if ($is_map) {
            wp_enqueue_style(
                'leaflet',
                'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css',
                [],
                '1.9.4'
            );
            wp_enqueue_script(
                'leaflet',
                'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',
                [],
                '1.9.4',
                true
            );
        }

        wp_enqueue_style(
            'tng-explore-discovery-feed',
            TNG_EXPLORE_DISCOVERY_URL . 'assets/css/explore-discovery-feed.css',
            $is_map ? ['leaflet'] : [],
            TNG_EXPLORE_DISCOVERY_VERSION
        );
        wp_enqueue_script(
            'tng-explore-discovery-feed',
            TNG_EXPLORE_DISCOVERY_URL . 'assets/js/explore-discovery-feed.js',
            $is_map ? ['leaflet'] : [],
            TNG_EXPLORE_DISCOVERY_VERSION,
            true
        );
        wp_localize_script('tng-explore-discovery-feed', 'TNGExploreDiscovery', [
            'endpoint' => esc_url_raw(rest_url(self::REST_NAMESPACE . self::REST_ROUTE)),
            'isMap' => $is_map,
            'isExplore' => $is_explore,
            'mapUrl' => home_url('/map/'),
            'map' => self::map_config(),
        ]);
    }

    public static function rest_places(\WP_REST_Request $request): \WP_REST_Response {
        $limit = max(1, min(100, absint($request->get_param('limit') ?: 60)));
        $types = array_values(array_filter(['st_activity','activity','top_sight','tng_destination','st_location'], 'post_type_exists'));
        if (!$types) return rest_ensure_response(['places' => [], 'count' => 0]);

        $query = new \WP_Query([
            'post_type' => $types,
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'ignore_sticky_posts' => true,
            'orderby' => 'modified',
            'order' => 'DESC',
            'no_found_rows' => true,
        ]);

        $places = [];
        foreach ($query->posts as $post) {
            $item = self::normalize_post((int) $post->ID);
            if ($item) $places[] = $item;
        }
        return rest_ensure_response(['places' => $places, 'count' => count($places), 'generatedAt' => current_time('mysql')]);
    }

    private static function normalize_post(int $id): array {
        $post_type = get_post_type($id);
        if (!$post_type || get_post_status($id) !== 'publish') return [];

        $lat = self::meta_first($id, ['_tng_source_latitude','_tng_food_latitude','_tng_latitude','map_lat','latitude','lat']);
        $lng = self::meta_first($id, ['_tng_source_longitude','_tng_food_longitude','_tng_longitude','map_lng','longitude','lng','lon']);
        $rating = self::meta_first($id, ['_tng_source_rating','_tng_food_rating','_tng_rating','rating']);
        $rating_count = self::meta_first($id, ['_tng_source_rating_count','_tng_food_rating_count','_tng_rating_count','rating_count']);
        $address = self::meta_first($id, ['_tng_source_address','_tng_food_address','_tng_address','address']);
        $hours = self::meta_first($id, ['_tng_source_hours','_tng_food_hours','_tng_food_hours_text','_tng_hours','hours']);
        $place_id = self::meta_first($id, ['_tng_google_place_id','_tng_food_google_place_id','_tng_local_place_id']);
        $bucket = self::bucket($id, $post_type);
        $image = get_the_post_thumbnail_url($id, 'medium_large');
        if (!$image) {
            $gallery = get_post_meta($id, '_tng_gallery_ids', true);
            if (is_array($gallery) && !empty($gallery[0])) $image = wp_get_attachment_image_url(absint($gallery[0]), 'medium_large');
        }
        $type_object = get_post_type_object($post_type);
        $type_label = $type_object && !empty($type_object->labels->singular_name) ? (string) $type_object->labels->singular_name : 'Place';
        $excerpt_source = (string) get_post_field('post_excerpt', $id);
        if ($excerpt_source === '') $excerpt_source = (string) get_post_field('post_content', $id);

        return [
            'id' => $id,
            'title' => html_entity_decode(get_the_title($id), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'url' => get_permalink($id),
            'image' => $image ?: '',
            'excerpt' => wp_trim_words(wp_strip_all_tags($excerpt_source), 18),
            'type' => $type_label,
            'postType' => $post_type,
            'bucket' => $bucket,
            'category' => self::category_label($id, $bucket),
            'rating' => is_numeric($rating) ? (float) $rating : null,
            'ratingCount' => is_numeric($rating_count) ? (int) $rating_count : 0,
            'address' => (string) $address,
            'hours' => is_scalar($hours) ? (string) $hours : '',
            'lat' => is_numeric($lat) ? (float) $lat : null,
            'lng' => is_numeric($lng) ? (float) $lng : null,
            'googlePlaceId' => (string) $place_id,
            'discoveryImported' => $place_id !== '',
        ];
    }

    private static function map_config(): array {
        $token = '';
        foreach (['TNG_MAPBOX_TOKEN', 'MAPBOX_ACCESS_TOKEN', 'ST_MAPBOX_TOKEN'] as $constant) {
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
        $token = (string) apply_filters('tng_explore_mapbox_token', $token);

        if ($token !== '') {
            return [
                'provider' => 'mapbox',
                'tileUrl' => 'https://api.mapbox.com/styles/v1/mapbox/outdoors-v12/tiles/512/{z}/{x}/{y}@2x?access_token=' . rawurlencode($token),
                'tileSize' => 512,
                'zoomOffset' => -1,
                'maxZoom' => 20,
                'attribution' => '© Mapbox © OpenStreetMap',
            ];
        }

        return [
            'provider' => 'openstreetmap',
            'tileUrl' => 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
            'tileSize' => 256,
            'zoomOffset' => 0,
            'maxZoom' => 19,
            'attribution' => '© OpenStreetMap contributors',
        ];
    }

    private static function meta_first(int $id, array $keys) {
        foreach ($keys as $key) {
            $value = get_post_meta($id, $key, true);
            if ($value !== '' && $value !== null && !is_array($value)) return $value;
        }
        return '';
    }

    private static function bucket(int $id, string $post_type): string {
        if ($post_type === 'top_sight') return 'sights';
        if ($post_type === 'tng_destination' || $post_type === 'st_location') return 'destinations';
        $terms = [];
        foreach ((array) get_object_taxonomies($post_type) as $taxonomy) {
            $post_terms = wp_get_post_terms($id, $taxonomy, ['fields' => 'all']);
            if (!is_wp_error($post_terms)) $terms = array_merge($terms, $post_terms);
        }
        $text = strtolower(implode(' ', array_map(static fn($term) => ($term->name ?? '') . ' ' . ($term->slug ?? ''), $terms)) . ' ' . get_the_title($id));
        if (preg_match('/trail|hiking|hike/', $text)) return 'trails';
        if (preg_match('/game|quest|scavenger|challenge/', $text)) return 'games';
        if (preg_match('/concert|event|music|festival|show/', $text)) return 'events';
        if (preg_match('/restaurant|food|coffee|cafe|café|bakery|brewery|pizza|barbecue|bbq|sandwich|grill|diner/', $text)) return 'food';
        if (preg_match('/shop|store|boutique|market|retail|gift/', $text)) return 'shops';
        if (preg_match('/hotel|motel|lodging|cabin|resort|inn|campground|camping|rv/', $text)) return 'lodging';
        return 'places';
    }

    private static function category_label(int $id, string $bucket): string {
        $source = self::meta_first($id, ['_tng_source_primary_type_label','_tng_food_cuisine','_tng_local_category']);
        if ($source) return (string) $source;
        $labels = ['trails'=>'Trail','games'=>'Game','sights'=>'Top Sight','events'=>'Event','food'=>'Food & Drink','shops'=>'Shop','lodging'=>'Stay','destinations'=>'Destination','places'=>'Local Place'];
        return $labels[$bucket] ?? 'Local Place';
    }
}