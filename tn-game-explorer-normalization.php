<?php
/**
 * TN Game Explorer Progression Normalization
 * Keeps checkpoint, Top Sight and photo progression on shared canonical counters.
 */
if (!defined('ABSPATH')) exit;

final class TNG_Explorer_Normalization {
    private const META_SIGHTS = '_tng_visited_top_sights';
    private const META_CHECKPOINTS = '_tng_game_checkpoints_total';
    private const META_PHOTOS = '_tng_photo_count';

    public static function boot(): void {
        add_action('tng_os_game_completed', [__CLASS__, 'sync_completed_game'], 22, 3);
        add_action('template_redirect', [__CLASS__, 'backfill_current_user'], 3);
        add_filter('tng_os_explorer_profile_stats', [__CLASS__, 'normalize_profile_stats'], 60, 2);
    }

    private static function completed_games(int $user_id): array {
        $value = get_user_meta($user_id, '_tng_completed_games', true);
        return is_array($value)
            ? array_values(array_unique(array_filter(array_map('absint', $value))))
            : [];
    }

    private static function checkpoints(int $game_id): array {
        $raw = get_post_meta($game_id, 'tng_game_checkpoints', true);
        return is_array($raw) ? array_values(array_filter($raw, 'is_array')) : [];
    }

    private static function checkpoint_total(int $game_id): int {
        $checkpoints = self::checkpoints($game_id);
        if ($checkpoints) return count($checkpoints);
        return max(0, min(100, absint(get_post_meta($game_id, 'checkpoint_count', true))));
    }

    private static function is_top_sight(int $post_id): bool {
        if (!$post_id || !get_post($post_id)) return false;
        $type = strtolower((string)get_post_type($post_id));
        if (in_array($type, ['top_sight', 'top-sight', 'topsight', 'tng_top_sight'], true)) return true;
        return strpos($type, 'top') !== false && strpos($type, 'sight') !== false;
    }

    private static function possible_sight_id(array $checkpoint): int {
        $keys = [
            'top_sight_id', 'topsight_id', 'top_sight_post_id', 'linked_top_sight',
            'sight_id', 'top_sight', 'sight', 'location_post_id'
        ];
        foreach ($keys as $key) {
            if (!array_key_exists($key, $checkpoint)) continue;
            $value = $checkpoint[$key];
            if (is_array($value)) {
                foreach (['ID', 'id', 'post_id', 'value'] as $subkey) {
                    if (isset($value[$subkey])) {
                        $candidate = absint($value[$subkey]);
                        if (self::is_top_sight($candidate)) return $candidate;
                    }
                }
                continue;
            }
            $candidate = absint($value);
            if (self::is_top_sight($candidate)) return $candidate;
        }

        // Some older checkpoint records use a generic post_id. Only accept it when
        // the referenced post is actually a Top Sight so checkpoint indexes cannot leak in.
        foreach (['post_id', 'object_id'] as $key) {
            $candidate = absint($checkpoint[$key] ?? 0);
            if (self::is_top_sight($candidate)) return $candidate;
        }
        return 0;
    }

    private static function sights_from_game(int $game_id): array {
        $ids = [];
        foreach (self::checkpoints($game_id) as $checkpoint) {
            $id = self::possible_sight_id($checkpoint);
            if ($id) $ids[] = $id;
        }
        return array_values(array_unique(array_filter(array_map('absint', $ids))));
    }

    private static function stored_sights(int $user_id): array {
        $value = get_user_meta($user_id, self::META_SIGHTS, true);
        return is_array($value)
            ? array_values(array_unique(array_filter(array_map('absint', $value))))
            : [];
    }

    private static function merge_sights(int $user_id, array $ids): array {
        $merged = array_values(array_unique(array_filter(array_merge(self::stored_sights($user_id), array_map('absint', $ids)))));
        update_user_meta($user_id, self::META_SIGHTS, $merged);
        return $merged;
    }

    private static function rebuild_game_counters(int $user_id): array {
        $games = self::completed_games($user_id);
        $checkpoints = 0;
        $sights = self::stored_sights($user_id);
        foreach ($games as $game_id) {
            $checkpoints += self::checkpoint_total($game_id);
            $sights = array_merge($sights, self::sights_from_game($game_id));
        }
        $sights = array_values(array_unique(array_filter(array_map('absint', $sights))));
        update_user_meta($user_id, self::META_CHECKPOINTS, max(0, $checkpoints));
        update_user_meta($user_id, self::META_SIGHTS, $sights);
        return ['checkpoints' => max(0, $checkpoints), 'top_sights' => count($sights)];
    }

    public static function sync_completed_game(int $user_id, int $game_id, array $data = []): void {
        if ($user_id < 1 || $game_id < 1) return;
        self::merge_sights($user_id, self::sights_from_game($game_id));
        self::rebuild_game_counters($user_id);
    }

    public static function backfill_current_user(): void {
        if (is_admin() || !is_user_logged_in()) return;
        $user_id = get_current_user_id();
        $stamp = (string)get_user_meta($user_id, '_tng_progression_normalized_v1', true);
        if ($stamp === '1') return;
        self::rebuild_game_counters($user_id);
        update_user_meta($user_id, '_tng_progression_normalized_v1', '1');
    }

    public static function normalize_profile_stats($stats, $user_id) {
        $stats = is_array($stats) ? $stats : [];
        $user_id = absint($user_id);
        if (!$user_id) return $stats;

        $rebuilt = self::rebuild_game_counters($user_id);
        $stats['game_checkpoints'] = max(absint($stats['game_checkpoints'] ?? 0), $rebuilt['checkpoints']);
        $stats['checkpoints'] = max(absint($stats['checkpoints'] ?? 0), $rebuilt['checkpoints']);
        $stats['top_sights'] = max(absint($stats['top_sights'] ?? 0), $rebuilt['top_sights']);

        // The native Profile may resolve photos from attachments/journal data rather than
        // legacy user meta. Once it has done so, persist that resolved total canonically
        // so every progression surface reads the same number on subsequent passes.
        $resolved_photos = max(
            absint($stats['photos'] ?? 0),
            absint($stats['photo_count'] ?? 0),
            absint(get_user_meta($user_id, self::META_PHOTOS, true))
        );
        if ($resolved_photos > 0) {
            update_user_meta($user_id, self::META_PHOTOS, $resolved_photos);
            $stats['photos'] = $resolved_photos;
            $stats['photo_count'] = $resolved_photos;
        }

        $stats['progression_normalized'] = true;
        return $stats;
    }
}

TNG_Explorer_Normalization::boot();
