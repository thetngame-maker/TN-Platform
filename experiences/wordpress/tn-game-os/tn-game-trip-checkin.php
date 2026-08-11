<?php
/**
 * Plugin Name: TN Game Trip Check-in
 * Description: Arrival-gated Trip Mode check-ins with optional photos, one-time XP, visit history, and route advancement.
 * Version: 0.1.0
 * Author: The TN Game
 */

if (!defined('ABSPATH')) exit;

define('TNG_TRIP_CHECKIN_VERSION', '0.1.0');
define('TNG_TRIP_CHECKIN_URL', plugin_dir_url(__FILE__));
define('TNG_TRIP_CHECKIN_VISITS_META', 'tng_trip_checkin_visits_v1');
define('TNG_TRIP_CHECKIN_HISTORY_META', 'tng_trip_checkin_history_v1');

function tng_trip_checkin_is_trip_page(): bool {
    if (function_exists('tng_trip_mode_v1_is_page')) return tng_trip_mode_v1_is_page();
    if (is_page('trip-mode')) return true;
    $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    $path = (string) wp_parse_url($uri, PHP_URL_PATH);
    return (bool) preg_match('#/(?:trip-mode|active-trip)/?$#i', rtrim($path, '/') . '/');
}

function tng_trip_checkin_current_stop(int $user_id): int {
    if (!function_exists('tng_trip_mode_v1_get_state')) return 0;
    $state = tng_trip_mode_v1_get_state($user_id);
    $route = isset($state['route']) && is_array($state['route']) ? array_map('absint', $state['route']) : [];
    $done = array_unique(array_merge(
        isset($state['completed']) && is_array($state['completed']) ? array_map('absint', $state['completed']) : [],
        isset($state['skipped']) && is_array($state['skipped']) ? array_map('absint', $state['skipped']) : []
    ));
    foreach ($route as $id) if ($id && !in_array($id, $done, true)) return $id;
    return 0;
}

function tng_trip_checkin_recursive_coords($value): ?array {
    $value = maybe_unserialize($value);
    if (!is_array($value)) return null;
    $lat = null; $lng = null;
    foreach ($value as $key => $item) {
        $k = strtolower((string) $key);
        if (in_array($k, ['lat','latitude','map_lat','location_lat'], true) && is_numeric($item)) $lat = (float) $item;
        if (in_array($k, ['lng','lon','long','longitude','map_lng','location_lng'], true) && is_numeric($item)) $lng = (float) $item;
    }
    if ($lat !== null && $lng !== null && abs($lat) <= 90 && abs($lng) <= 180) return ['lat'=>$lat,'lng'=>$lng];
    foreach ($value as $item) {
        if (is_array($item) || is_serialized($item)) {
            $found = tng_trip_checkin_recursive_coords($item);
            if ($found) return $found;
        }
    }
    return null;
}

function tng_trip_checkin_stop_coords(int $post_id): ?array {
    $pairs = [
        ['tng_lat','tng_lng'], ['_tng_lat','_tng_lng'], ['lat','lng'], ['latitude','longitude'],
        ['map_lat','map_lng'], ['_map_lat','_map_lng'], ['st_lat','st_lng'], ['_st_lat','_st_lng'],
        ['tng_discovery_lat','tng_discovery_lng'], ['_tng_discovery_lat','_tng_discovery_lng'],
        ['google_map_lat','google_map_lng'], ['location_lat','location_lng']
    ];
    foreach ($pairs as $pair) {
        $lat = get_post_meta($post_id, $pair[0], true);
        $lng = get_post_meta($post_id, $pair[1], true);
        if (is_numeric($lat) && is_numeric($lng) && abs((float)$lat) <= 90 && abs((float)$lng) <= 180) {
            return ['lat'=>(float)$lat,'lng'=>(float)$lng];
        }
    }
    $all = get_post_meta($post_id);
    foreach ($all as $values) {
        foreach ((array) $values as $value) {
            $found = tng_trip_checkin_recursive_coords($value);
            if ($found) return $found;
        }
    }
    return null;
}

function tng_trip_checkin_distance_miles(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $r = 3958.7613;
    $p1 = deg2rad($lat1); $p2 = deg2rad($lat2);
    $dp = deg2rad($lat2 - $lat1); $dl = deg2rad($lng2 - $lng1);
    $a = sin($dp/2) ** 2 + cos($p1) * cos($p2) * sin($dl/2) ** 2;
    return 2 * $r * atan2(sqrt($a), sqrt(max(0, 1-$a)));
}

function tng_trip_checkin_points_type(int $user_id, int $stop_id): string {
    $preferred = (string) apply_filters('tng_trip_checkin_points_type', 'xp', $user_id, $stop_id);
    if (!function_exists('gamipress_get_points_types')) return $preferred;
    $types = gamipress_get_points_types();
    if (!is_array($types) || !$types) return $preferred;
    if (isset($types[$preferred])) return $preferred;
    foreach (['xp','explorer-xp','explorer_xp','points'] as $candidate) if (isset($types[$candidate])) return $candidate;
    $keys = array_keys($types);
    return $keys ? (string) $keys[0] : $preferred;
}

function tng_trip_checkin_award_xp(int $user_id, int $stop_id): array {
    $amount = max(0, (int) apply_filters('tng_trip_checkin_xp_amount', 25, $user_id, $stop_id));
    if (!$amount || !function_exists('gamipress_award_points_to_user')) return ['awarded'=>0,'type'=>''];
    $type = tng_trip_checkin_points_type($user_id, $stop_id);
    gamipress_award_points_to_user($user_id, $amount, $type);
    return ['awarded'=>$amount,'type'=>$type];
}

function tng_trip_checkin_complete_stop(int $user_id, int $stop_id): array {
    if (!function_exists('tng_trip_mode_v1_get_state') || !function_exists('tng_trip_mode_v1_save_state')) return [];
    $state = tng_trip_mode_v1_get_state($user_id);
    if (!in_array($stop_id, (array)($state['route'] ?? []), true)) return $state;
    if (!in_array($stop_id, (array)($state['completed'] ?? []), true)) $state['completed'][] = $stop_id;
    $state['skipped'] = array_values(array_diff((array)($state['skipped'] ?? []), [$stop_id]));
    return tng_trip_mode_v1_save_state($user_id, $state);
}

function tng_trip_checkin_store_photo(int $stop_id): int|WP_Error {
    if (empty($_FILES['photo']) || !is_array($_FILES['photo']) || empty($_FILES['photo']['tmp_name'])) return 0;
    if (!empty($_FILES['photo']['size']) && (int)$_FILES['photo']['size'] > 10 * MB_IN_BYTES) {
        return new WP_Error('tng_checkin_photo_size', 'Photo must be 10 MB or smaller.', ['status'=>413]);
    }
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $check = wp_check_filetype_and_ext($_FILES['photo']['tmp_name'], $_FILES['photo']['name']);
    if (empty($check['type']) || strpos((string)$check['type'], 'image/') !== 0) {
        return new WP_Error('tng_checkin_photo_type', 'Please upload an image file.', ['status'=>415]);
    }
    $attachment_id = media_handle_upload('photo', $stop_id, ['post_title'=>'Trip check-in – '.get_the_title($stop_id)]);
    if (is_wp_error($attachment_id)) return $attachment_id;
    update_post_meta($attachment_id, '_tng_photo_source', 'trip_checkin');
    update_post_meta($attachment_id, '_tng_photo_status', 'pending');
    update_post_meta($attachment_id, '_tng_trip_checkin_stop_id', $stop_id);
    update_post_meta($attachment_id, '_tng_trip_checkin_user_id', get_current_user_id());
    update_post_meta($attachment_id, '_tng_trip_checkin_at', time());
    return (int) $attachment_id;
}

add_action('rest_api_init', static function (): void {
    register_rest_route('tn-game/v1', '/trip/checkin', [
        'methods' => 'POST',
        'permission_callback' => static function (): bool { return is_user_logged_in(); },
        'callback' => static function (WP_REST_Request $request) {
            $user_id = get_current_user_id();
            $stop_id = absint($request->get_param('stop_id'));
            if (!$stop_id || get_post_status($stop_id) !== 'publish') return new WP_Error('tng_checkin_stop', 'That stop is unavailable.', ['status'=>400]);
            $current = tng_trip_checkin_current_stop($user_id);
            if (!$current || $current !== $stop_id) return new WP_Error('tng_checkin_current', 'You can only check in at the current Trip Mode stop.', ['status'=>409]);

            $lat = $request->get_param('lat'); $lng = $request->get_param('lng');
            if (!is_numeric($lat) || !is_numeric($lng)) return new WP_Error('tng_checkin_location', 'Your current location is required to check in.', ['status'=>400]);
            $lat = (float)$lat; $lng = (float)$lng;
            if (abs($lat) > 90 || abs($lng) > 180) return new WP_Error('tng_checkin_location', 'Your current location is invalid.', ['status'=>400]);

            $destination = tng_trip_checkin_stop_coords($stop_id);
            if (!$destination) return new WP_Error('tng_checkin_stop_location', 'This stop does not have coordinates yet, so proximity check-in is unavailable.', ['status'=>409]);
            $distance = tng_trip_checkin_distance_miles($lat, $lng, $destination['lat'], $destination['lng']);
            $radius = max(0.02, (float) apply_filters('tng_trip_checkin_radius_miles', 0.08, $stop_id, $user_id));
            if ($distance > $radius) return new WP_Error('tng_checkin_too_far', sprintf('Move closer to check in. You are about %.2f miles away.', $distance), ['status'=>403,'distance'=>$distance,'radius'=>$radius]);

            $visits = get_user_meta($user_id, TNG_TRIP_CHECKIN_VISITS_META, true);
            if (!is_array($visits)) $visits = [];
            $key = (string)$stop_id;
            $first_visit = empty($visits[$key]);

            $photo_id = tng_trip_checkin_store_photo($stop_id);
            if (is_wp_error($photo_id)) return $photo_id;

            $xp = $first_visit ? tng_trip_checkin_award_xp($user_id, $stop_id) : ['awarded'=>0,'type'=>''];
            $entry = [
                'stopId'=>$stop_id,
                'title'=>get_the_title($stop_id),
                'checkedInAt'=>time(),
                'lat'=>round($lat,6),
                'lng'=>round($lng,6),
                'distanceMiles'=>round($distance,4),
                'photoId'=>(int)$photo_id,
                'xp'=>(int)$xp['awarded'],
                'pointsType'=>(string)$xp['type'],
            ];
            if ($first_visit) {
                $visits[$key] = $entry;
                $visits[$key]['photoIds'] = $photo_id ? [(int)$photo_id] : [];
            } else {
                if (!isset($visits[$key]['photoIds']) || !is_array($visits[$key]['photoIds'])) $visits[$key]['photoIds'] = [];
                if ($photo_id) $visits[$key]['photoIds'][] = (int)$photo_id;
                $visits[$key]['lastCheckinAt'] = time();
            }
            update_user_meta($user_id, TNG_TRIP_CHECKIN_VISITS_META, $visits);

            $history = get_user_meta($user_id, TNG_TRIP_CHECKIN_HISTORY_META, true);
            if (!is_array($history)) $history = [];
            array_unshift($history, $entry);
            $history = array_slice($history, 0, 200);
            update_user_meta($user_id, TNG_TRIP_CHECKIN_HISTORY_META, $history);

            $state = tng_trip_checkin_complete_stop($user_id, $stop_id);
            do_action('tng_trip_checkin_recorded', $user_id, $stop_id, $entry, $first_visit);
            if ($photo_id) do_action('tng_trip_checkin_photo_uploaded', $photo_id, $user_id, $stop_id, $entry);

            return rest_ensure_response([
                'success'=>true,
                'firstVisit'=>$first_visit,
                'xpAwarded'=>(int)$xp['awarded'],
                'pointsType'=>(string)$xp['type'],
                'photoId'=>(int)$photo_id,
                'photoUrl'=>$photo_id ? wp_get_attachment_image_url($photo_id, 'medium') : '',
                'distanceMiles'=>round($distance,3),
                'state'=>$state,
                'message'=>$first_visit ? (($xp['awarded'] ? '+'.$xp['awarded'].' XP · ' : '').'Check-in complete!') : 'Check-in recorded. Visit XP was already awarded for this stop.',
            ]);
        },
    ]);
});

add_action('wp_enqueue_scripts', static function (): void {
    if (!tng_trip_checkin_is_trip_page()) return;
    wp_enqueue_style('tng-trip-checkin', TNG_TRIP_CHECKIN_URL.'assets/css/trip-mode-checkin.css', [], TNG_TRIP_CHECKIN_VERSION);
    wp_enqueue_script('tng-trip-checkin', TNG_TRIP_CHECKIN_URL.'assets/js/trip-mode-checkin.js', [], TNG_TRIP_CHECKIN_VERSION, true);
    wp_localize_script('tng-trip-checkin', 'TNGTripCheckin', [
        'endpoint'=>esc_url_raw(rest_url('tn-game/v1/trip/checkin')),
        'nonce'=>wp_create_nonce('wp_rest'),
        'maxPhotoMb'=>10,
    ]);
}, 220);
