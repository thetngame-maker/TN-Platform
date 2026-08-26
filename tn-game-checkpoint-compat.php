<?php
/**
 * TN Game Checkpoint Compatibility
 * Keeps legacy and current checkpoint records in one canonical runtime schema.
 */
if (!defined('ABSPATH')) exit;

final class TNG_Game_Checkpoint_Compat {
    private static bool $running = false;

    public static function boot(): void {
        add_action('init', [self::class, 'repair_games'], 40);
    }

    private static function default_xp(string $type): int {
        $defaults = ['tap' => 10, 'gps' => 25, 'question' => 50, 'photo' => 40];
        return $defaults[$type] ?? 25;
    }

    private static function valid_coords($lat, $lng): bool {
        if (!is_numeric($lat) || !is_numeric($lng)) return false;
        $lat = (float) $lat;
        $lng = (float) $lng;
        return $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180 && !($lat === 0.0 && $lng === 0.0);
    }

    private static function sight_coordinates(int $post_id): array {
        if (!$post_id || !get_post($post_id)) return [0.0, 0.0];

        $pairs = [
            ['_sight_latitude','_sight_longitude'],
            ['sight_latitude','sight_longitude'],
            ['latitude','longitude'],
            ['lat','lng'],
            ['trail_latitude','trail_longitude'],
            ['st_latitude','st_longitude'],
            ['map_lat','map_lng'],
            ['location_lat','location_lng'],
            ['_latitude','_longitude'],
            ['tng_latitude','tng_longitude'],
        ];

        foreach ($pairs as $pair) {
            $lat = get_post_meta($post_id, $pair[0], true);
            $lng = get_post_meta($post_id, $pair[1], true);
            if (self::valid_coords($lat, $lng)) return [(float) $lat, (float) $lng];
        }

        foreach (['location','top_sight_location','coordinates','map_location'] as $key) {
            $values = [get_post_meta($post_id, $key, true)];
            if (function_exists('get_field')) $values[] = get_field($key, $post_id);
            foreach ($values as $value) {
                if (!is_array($value)) continue;
                $lat = $value['lat'] ?? $value['latitude'] ?? null;
                $lng = $value['lng'] ?? $value['longitude'] ?? null;
                if (self::valid_coords($lat, $lng)) return [(float) $lat, (float) $lng];
            }
        }

        return [0.0, 0.0];
    }

    private static function normalize_checkpoint(array $item, int $index): array {
        $out = $item;

        $title = sanitize_text_field((string) ($item['title'] ?? $item['name'] ?? ''));
        if ($title === '') $title = 'Checkpoint ' . ($index + 1);
        $out['title'] = $title;

        if (!isset($out['instructions'])) {
            $out['instructions'] = sanitize_textarea_field((string) ($item['description'] ?? $item['instruction'] ?? ''));
        }

        $type = sanitize_key((string) ($item['type'] ?? $item['mode'] ?? 'tap'));
        if ($type === 'location' || $type === 'checkin' || $type === 'check-in') $type = 'gps';
        if (!in_array($type, ['tap','question','gps','photo'], true)) $type = 'tap';
        $out['type'] = $type;

        $sight_id = absint($item['sight_id'] ?? $item['sightId'] ?? $item['top_sight_id'] ?? $item['topSightId'] ?? 0);
        if ($sight_id) $out['sight_id'] = $sight_id;

        $lat = $item['latitude'] ?? $item['lat'] ?? $item['checkpoint_latitude'] ?? $item['checkpoint_lat'] ?? null;
        $lng = $item['longitude'] ?? $item['lng'] ?? $item['lon'] ?? $item['checkpoint_longitude'] ?? $item['checkpoint_lng'] ?? null;

        if ($type === 'gps' && !self::valid_coords($lat, $lng) && $sight_id) {
            [$lat, $lng] = self::sight_coordinates($sight_id);
        }

        if ($type === 'gps') {
            $out['latitude'] = is_numeric($lat) ? (float) $lat : 0.0;
            $out['longitude'] = is_numeric($lng) ? (float) $lng : 0.0;
            $radius = absint($item['radius'] ?? $item['radius_meters'] ?? $item['unlock_radius'] ?? 30);
            $out['radius'] = max(1, min(500, $radius ?: 30));
        }

        if ($type === 'question' && !isset($out['answer'])) {
            $out['answer'] = sanitize_text_field((string) ($item['correct_answer'] ?? $item['correctAnswer'] ?? ''));
        }

        $xp = absint($item['xp'] ?? $item['reward_xp'] ?? $item['reward'] ?? $item['points'] ?? 0);
        if ($xp < 1) $xp = self::default_xp($type);
        $out['xp'] = min(5000, $xp);

        return $out;
    }

    public static function repair_game(int $game_id): bool {
        if (self::$running || !$game_id) return false;
        $post = get_post($game_id);
        if (!$post || $post->post_type !== 'tng_game') return false;

        $raw = get_post_meta($game_id, 'tng_game_checkpoints', true);
        if (!is_array($raw) || !$raw) return false;

        self::$running = true;
        $normalized = [];
        foreach ($raw as $index => $item) {
            if (is_array($item)) $normalized[] = self::normalize_checkpoint($item, (int) $index);
            else $normalized[] = self::normalize_checkpoint(['title' => (string) $item], (int) $index);
        }

        $changed = maybe_serialize($normalized) !== maybe_serialize(array_values($raw));
        if ($changed) update_post_meta($game_id, 'tng_game_checkpoints', $normalized);

        $xp_total = 0;
        foreach ($normalized as $checkpoint) $xp_total += absint($checkpoint['xp'] ?? 0);
        if (absint(get_post_meta($game_id, 'xp_available', true)) !== $xp_total) {
            update_post_meta($game_id, 'xp_available', $xp_total);
            $changed = true;
        }
        if (absint(get_post_meta($game_id, 'checkpoint_count', true)) !== count($normalized)) {
            update_post_meta($game_id, 'checkpoint_count', count($normalized));
            $changed = true;
        }

        if ($changed) update_post_meta($game_id, '_tng_checkpoint_schema_repaired_at', current_time('mysql'));
        self::$running = false;
        return $changed;
    }

    public static function repair_games(): void {
        if (self::$running || !post_type_exists('tng_game')) return;
        $ids = get_posts([
            'post_type' => 'tng_game',
            'post_status' => ['publish','draft','pending','private'],
            'posts_per_page' => 500,
            'fields' => 'ids',
            'orderby' => 'ID',
            'order' => 'ASC',
            'no_found_rows' => true,
            'suppress_filters' => false,
        ]);
        foreach ($ids as $id) self::repair_game((int) $id);
    }
}

TNG_Game_Checkpoint_Compat::boot();
