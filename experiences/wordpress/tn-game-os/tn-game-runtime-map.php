<?php
/**
 * Plugin Name: TN Game Runtime Map
 * Description: Live checkpoint map and trail route for active TN Game adventures.
 * Version: 0.2.0
 * Author: The TN Game
 */
if (!defined('ABSPATH')) exit;

final class TNG_Game_Runtime_Map {
    public static function boot(): void {
        add_action('wp_enqueue_scripts', [self::class, 'enqueue'], 120);
    }

    private static function is_game_play(): bool {
        return class_exists('TNG_OS\\Platform\\App_Router')
            && TNG_OS\Platform\App_Router::current_route() === 'game-play';
    }

    private static function game_id(): int {
        $id = absint($_GET['game'] ?? 0);
        if (!$id) return 0;
        $post = get_post($id);
        if (!$post || $post->post_status !== 'publish') return 0;
        return $id;
    }

    private static function completed(int $game_id): array {
        if (!is_user_logged_in()) return [];
        $value = get_user_meta(get_current_user_id(), '_tng_game_progress_' . $game_id, true);
        if (!is_array($value)) return [];
        return array_values(array_unique(array_map('absint', $value)));
    }

    private static function checkpoint_data(int $game_id): array {
        $raw = get_post_meta($game_id, 'tng_game_checkpoints', true);
        if (!is_array($raw)) return [];
        $completed = self::completed($game_id);
        $next = -1;
        foreach (array_keys($raw) as $i) {
            if (!in_array((int) $i, $completed, true)) { $next = (int) $i; break; }
        }

        $out = [];
        foreach ($raw as $index => $item) {
            if (!is_array($item)) continue;
            $lat = isset($item['latitude']) ? (float) $item['latitude'] : 0.0;
            $lng = isset($item['longitude']) ? (float) $item['longitude'] : 0.0;
            if (!$lat && !$lng) continue;
            $index = (int) $index;
            $out[] = [
                'index' => $index,
                'title' => sanitize_text_field((string) ($item['title'] ?? ('Checkpoint ' . ($index + 1)))),
                'instructions' => sanitize_text_field((string) ($item['instructions'] ?? '')),
                'type' => sanitize_key((string) ($item['type'] ?? 'gps')),
                'lat' => $lat,
                'lng' => $lng,
                'radius' => max(1, min(500, absint($item['radius'] ?? 30))),
                'completed' => in_array($index, $completed, true),
                'current' => $index === $next,
                'locked' => $next >= 0 && $index > $next,
            ];
        }
        return $out;
    }

    private static function normalize_media_url($value): string {
        if (is_numeric($value)) {
            $url = wp_get_attachment_url(absint($value));
            return $url ? esc_url_raw($url) : '';
        }
        if (is_array($value)) {
            foreach (['url','file','src','ID','id'] as $key) {
                if (isset($value[$key])) {
                    $url = self::normalize_media_url($value[$key]);
                    if ($url !== '') return $url;
                }
            }
            return '';
        }
        $value = trim((string) $value);
        if ($value === '') return '';
        if (strpos($value, '/') === 0) return esc_url_raw(home_url($value));
        return esc_url_raw($value);
    }

    private static function route_url_from_post(int $post_id): string {
        if (!$post_id) return '';
        $keys = [
            'trail_gpx_url', 'trail_gpx', 'gpx_url', 'gpx_file', 'gpx',
            'tng_gpx_url', 'tng_trail_gpx', 'route_gpx_url', 'route_gpx'
        ];
        foreach ($keys as $key) {
            $value = get_post_meta($post_id, $key, true);
            $url = self::normalize_media_url($value);
            if ($url !== '') return $url;
            if (function_exists('get_field')) {
                $url = self::normalize_media_url(get_field($key, $post_id));
                if ($url !== '') return $url;
            }
        }
        return '';
    }

    private static function linked_trail_id(int $game_id): int {
        $keys = [
            'trail_id', 'tng_trail_id', 'source_trail_id', 'source_activity_id',
            'linked_activity_id', 'activity_id', 'parent_activity_id', 'game_trail_id'
        ];
        foreach ($keys as $key) {
            $id = absint(get_post_meta($game_id, $key, true));
            if ($id && get_post($id)) return $id;
            if (function_exists('get_field')) {
                $value = get_field($key, $game_id);
                if (is_object($value) && !empty($value->ID)) $id = absint($value->ID);
                elseif (is_array($value) && isset($value['ID'])) $id = absint($value['ID']);
                else $id = absint($value);
                if ($id && get_post($id)) return $id;
            }
        }
        return 0;
    }

    private static function route_url(int $game_id): string {
        $url = self::route_url_from_post($game_id);
        if ($url !== '') return $url;
        $trail_id = self::linked_trail_id($game_id);
        return $trail_id ? self::route_url_from_post($trail_id) : '';
    }

    public static function enqueue(): void {
        if (!self::is_game_play()) return;
        $game_id = self::game_id();
        if (!$game_id) return;
        $checkpoints = self::checkpoint_data($game_id);
        if (!$checkpoints) return;

        wp_enqueue_style('tng-leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', [], '1.9.4');
        wp_enqueue_script('tng-leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', [], '1.9.4', true);
        wp_enqueue_style('tng-game-runtime-map', TNG_OS_URL . 'assets/css/game-runtime-map.css', ['tng-game-runtime-ui','tng-leaflet'], '0.2.0');
        wp_enqueue_script('tng-game-runtime-map', TNG_OS_URL . 'assets/js/game-runtime-map.js', ['tng-leaflet'], '0.2.0', true);
        wp_localize_script('tng-game-runtime-map', 'TNG_GAME_MAP', [
            'gameId' => $game_id,
            'checkpoints' => $checkpoints,
            'routeUrl' => self::route_url($game_id),
            'labels' => [
                'eyebrow' => 'Live adventure',
                'title' => 'Game map',
                'subtitle' => 'Follow your route and watch checkpoints unlock as you progress.',
                'locate' => 'Find me',
                'locationError' => 'We could not read your location. Check your browser location permission.',
                'routeReady' => 'Trail route loaded',
                'routeUnavailable' => 'Checkpoint map',
                'current' => 'Up next',
                'completed' => 'Completed',
                'locked' => 'Locked',
            ],
        ]);
    }
}

TNG_Game_Runtime_Map::boot();
