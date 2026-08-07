<?php
/**
 * Plugin Name: TN Game Runtime Map
 * Description: Live checkpoint map for active TN Game adventures.
 * Version: 0.1.0
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

    public static function enqueue(): void {
        if (!self::is_game_play()) return;
        $game_id = self::game_id();
        if (!$game_id) return;
        $checkpoints = self::checkpoint_data($game_id);
        if (!$checkpoints) return;

        wp_enqueue_style('tng-leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', [], '1.9.4');
        wp_enqueue_script('tng-leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', [], '1.9.4', true);
        wp_enqueue_style('tng-game-runtime-map', TNG_OS_URL . 'assets/css/game-runtime-map.css', ['tng-game-runtime-ui','tng-leaflet'], '0.1.0');
        wp_enqueue_script('tng-game-runtime-map', TNG_OS_URL . 'assets/js/game-runtime-map.js', ['tng-leaflet'], '0.1.0', true);
        wp_localize_script('tng-game-runtime-map', 'TNG_GAME_MAP', [
            'gameId' => $game_id,
            'checkpoints' => $checkpoints,
            'labels' => [
                'eyebrow' => 'Live adventure',
                'title' => 'Game map',
                'subtitle' => 'Follow your route and watch checkpoints unlock as you progress.',
                'locate' => 'Find me',
                'locationError' => 'We could not read your location. Check your browser location permission.',
                'current' => 'Up next',
                'completed' => 'Completed',
                'locked' => 'Locked',
            ],
        ]);
    }
}

TNG_Game_Runtime_Map::boot();
