<?php
namespace TNG_OS\Platform;

if (!defined('ABSPATH')) exit;

/**
 * Canonical Explorer snapshot shared by Profile, completion, Nearby XP, and recaps.
 */
final class Explorer_Profile_V2 {
    private const META_DISCOVERIES = '_tng_discovered_objects';
    private const META_SYNCED = '_tng_explorer_profile_v2_synced';

    public static function boot(): void {
        add_action('tng_os_top_sight_visited', [self::class, 'top_sight_visited'], 30, 2);
        add_action('tng_os_game_completed', [self::class, 'game_completed'], 35, 3);
        add_action('tng_os_trip_completed', [self::class, 'trip_completed'], 35, 2);
        add_action('template_redirect', [self::class, 'sync_current_user'], 5);
        add_filter('tng_os_explorer_profile_stats', [self::class, 'profile_stats'], 90, 2);
    }

    public static function top_sight_visited(int $user_id, int $sight_id): void {
        self::record($user_id, [$sight_id]);
    }

    public static function game_completed(int $user_id, int $game_id, array $data = []): void {
        $ids = [$game_id];
        $checkpoints = get_post_meta($game_id, 'tng_game_checkpoints', true);
        if (is_array($checkpoints)) foreach ($checkpoints as $checkpoint) {
            if (!is_array($checkpoint)) continue;
            foreach (['sight_id','top_sight_id','post_id','object_id'] as $key) {
                $id = absint($checkpoint[$key] ?? 0);
                if ($id && get_post($id)) $ids[] = $id;
            }
        }
        self::record($user_id, $ids);
    }

    public static function trip_completed(int $user_id, array $recap): void {
        self::record($user_id, self::stop_ids($recap['stops'] ?? []));
    }

    public static function sync_current_user(): void {
        if (is_admin() || !is_user_logged_in()) return;
        $user_id = get_current_user_id();
        if ((string) get_user_meta($user_id, self::META_SYNCED, true) === '1') return;
        self::record($user_id, self::legacy_ids($user_id));
        update_user_meta($user_id, self::META_SYNCED, '1');
    }

    public static function record(int $user_id, array $ids): array {
        if ($user_id < 1) return [];
        $stored = get_user_meta($user_id, self::META_DISCOVERIES, true);
        $stored = is_array($stored) ? $stored : [];
        $valid = [];
        foreach (array_merge($stored, $ids) as $id) {
            $id = absint($id);
            if ($id && get_post_status($id) === 'publish') $valid[] = $id;
        }
        $valid = array_values(array_unique($valid));
        sort($valid, SORT_NUMERIC);
        update_user_meta($user_id, self::META_DISCOVERIES, $valid);
        return $valid;
    }

    public static function discovery_ids(int $user_id): array {
        if ($user_id < 1) return [];
        $stored = get_user_meta($user_id, self::META_DISCOVERIES, true);
        $stored = is_array($stored) ? $stored : [];
        $ids = array_merge($stored, self::legacy_ids($user_id));
        $ids = array_values(array_unique(array_filter(array_map('absint', $ids))));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    private static function legacy_ids(int $user_id): array {
        $ids = [];
        foreach (['_tng_visited_top_sights','_tng_completed_games','tng_completed_trails','_tng_completed_trails','tng_completed_adventures','_tng_completed_adventures'] as $key) {
            $value = get_user_meta($user_id, $key, true);
            if (is_array($value)) $ids = array_merge($ids, array_map('absint', $value));
        }
        foreach (['_tng_trip_recaps','_tng_os_trip_activity','tng_trip_history','_tng_trip_history'] as $key) {
            $value = get_user_meta($user_id, $key, true);
            if (!is_array($value)) continue;
            foreach ($value as $entry) if (is_array($entry)) $ids = array_merge($ids, self::stop_ids($entry['stops'] ?? []));
        }
        $events = apply_filters('tng_os_adventure_journal_events', [], $user_id);
        if (is_array($events)) foreach ($events as $event) {
            if (!is_array($event)) continue;
            $meta = is_array($event['meta'] ?? null) ? $event['meta'] : [];
            foreach (['post_id','object_id','sight_id','game_id'] as $key) {
                $id = absint($meta[$key] ?? 0);
                if ($id) $ids[] = $id;
            }
        }
        return array_values(array_unique(array_filter(array_map('absint', $ids))));
    }

    private static function stop_ids($stops): array {
        if (!is_array($stops)) return [];
        $ids = [];
        foreach ($stops as $stop) {
            if (!is_array($stop)) continue;
            foreach (['id','post_id','object_id','sight_id'] as $key) {
                $id = absint($stop[$key] ?? 0);
                if ($id && get_post($id)) { $ids[] = $id; break; }
            }
        }
        return $ids;
    }

    public static function snapshot(int $user_id): array {
        $ids = self::discovery_ids($user_id);
        $dataset = class_exists(Universal_Map_Registry::class)
            ? Universal_Map_Registry::dataset()
            : ['items' => [], 'categories' => []];
        $discovered = array_fill_keys($ids, true);
        $category_rows = [];
        foreach ((array) ($dataset['categories'] ?? []) as $kind => $category) {
            $category_rows[$kind] = [
                'key' => $kind,
                'label' => sanitize_text_field($category['label'] ?? ucfirst($kind)),
                'icon' => sanitize_text_field($category['icon'] ?? '•'),
                'discovered' => 0,
                'total' => absint($category['count'] ?? 0),
                'percent' => 0,
            ];
        }
        $mapped_discovered = [];
        foreach ((array) ($dataset['items'] ?? []) as $item) {
            $id = absint($item['id'] ?? 0);
            $kind = sanitize_key($item['kind'] ?? 'place');
            if (!$id || !isset($discovered[$id])) continue;
            $mapped_discovered[$id] = true;
            if (isset($category_rows[$kind])) $category_rows[$kind]['discovered']++;
        }
        foreach ($category_rows as &$row) {
            $row['percent'] = $row['total'] > 0 ? round(($row['discovered'] / $row['total']) * 100, 1) : 0;
        }
        unset($row);
        $total = count((array) ($dataset['items'] ?? []));
        $mapped_count = count($mapped_discovered);
        return [
            'discovery_ids' => $ids,
            'discoveries' => count($ids),
            'mapped_discoveries' => $mapped_count,
            'tennessee_total' => $total,
            'tennessee_remaining' => max(0, $total - $mapped_count),
            'tennessee_percent' => $total > 0 ? round(($mapped_count / $total) * 100, 1) : 0,
            'categories' => array_values($category_rows),
        ];
    }

    public static function profile_stats($stats, $user_id) {
        $stats = is_array($stats) ? $stats : [];
        $snapshot = self::snapshot(absint($user_id));
        $stats['discoveries'] = max(absint($stats['discoveries'] ?? 0), $snapshot['discoveries']);
        $stats['mapped_discoveries'] = $snapshot['mapped_discoveries'];
        $stats['tennessee_completion'] = $snapshot['tennessee_percent'];
        $stats['tennessee_completion_total'] = $snapshot['tennessee_total'];
        $stats['tennessee_completion_remaining'] = $snapshot['tennessee_remaining'];
        $stats['discovery_categories'] = $snapshot['categories'];
        return $stats;
    }
}

Explorer_Profile_V2::boot();
