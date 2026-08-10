<?php
/**
 * TN Game Live Top Sight Visits
 * Promotes linked checkpoint claims into durable Explorer Top Sight visits.
 */
if (!defined('ABSPATH')) exit;

final class TNG_Live_Top_Sight_Visits {
    private const META_VISITED = '_tng_visited_top_sights';
    private const META_EVENTS = '_tng_top_sight_visit_events';

    public static function boot(): void {
        add_action('updated_user_meta', [__CLASS__, 'meta_changed'], 20, 4);
        add_action('added_user_meta', [__CLASS__, 'meta_changed'], 20, 4);
        add_filter('tng_os_adventure_journal_events', [__CLASS__, 'journal_events'], 45, 2);
        add_filter('tng_os_explorer_profile_stats', [__CLASS__, 'profile_stats'], 65, 2);
        add_filter('tng_os_visited_top_sights', [__CLASS__, 'visited_filter'], 10, 2);
        add_filter('body_class', [__CLASS__, 'body_class']);
        add_action('wp_footer', [__CLASS__, 'top_sight_status'], 180);
    }

    private static function is_top_sight(int $post_id): bool {
        if (!$post_id || !get_post($post_id)) return false;
        $type = strtolower((string)get_post_type($post_id));
        if (in_array($type, ['top_sight','top-sight','topsight','tng_top_sight'], true)) return true;
        return strpos($type, 'top') !== false && strpos($type, 'sight') !== false;
    }

    private static function checkpoint_sight_id(array $checkpoint): int {
        foreach (['top_sight_id','topsight_id','top_sight_post_id','linked_top_sight','sight_id','top_sight','sight','location_post_id'] as $key) {
            if (!array_key_exists($key, $checkpoint)) continue;
            $value = $checkpoint[$key];
            if (is_array($value)) {
                foreach (['ID','id','post_id','value'] as $subkey) {
                    $candidate = absint($value[$subkey] ?? 0);
                    if (self::is_top_sight($candidate)) return $candidate;
                }
            } else {
                $candidate = absint($value);
                if (self::is_top_sight($candidate)) return $candidate;
            }
        }
        foreach (['post_id','object_id'] as $key) {
            $candidate = absint($checkpoint[$key] ?? 0);
            if (self::is_top_sight($candidate)) return $candidate;
        }
        return 0;
    }

    private static function checkpoints(int $game_id): array {
        $raw = get_post_meta($game_id, 'tng_game_checkpoints', true);
        return is_array($raw) ? array_values(array_filter($raw, 'is_array')) : [];
    }

    private static function progress_indexes($value, int $count): array {
        if (!is_array($value) || !$count) return [];
        $out = [];
        foreach ($value as $raw) {
            $n = absint($raw);
            // Runtime history has used both zero-based and one-based checkpoint indexes.
            if ($n < $count) $out[] = $n;
            if ($n > 0 && ($n - 1) < $count) $out[] = $n - 1;
        }
        // Sequential games are the common case. If progress contains opaque values,
        // the count still lets us safely recognize the first N completed checkpoints.
        if (!$out && count($value) <= $count) {
            for ($i = 0; $i < count($value); $i++) $out[] = $i;
        }
        return array_values(array_unique(array_filter($out, static fn($i) => $i >= 0 && $i < $count)));
    }

    public static function meta_changed($meta_id, $user_id, $meta_key, $meta_value): void {
        $user_id = absint($user_id);
        if (!$user_id || !preg_match('/^_tng_game_progress_(\d+)$/', (string)$meta_key, $m)) return;
        $game_id = absint($m[1]);
        if (!$game_id) return;

        $checkpoints = self::checkpoints($game_id);
        if (!$checkpoints) return;
        foreach (self::progress_indexes($meta_value, count($checkpoints)) as $index) {
            $checkpoint = $checkpoints[$index] ?? [];
            if (!is_array($checkpoint)) continue;
            $sight_id = self::checkpoint_sight_id($checkpoint);
            if (!$sight_id) continue;
            self::register_visit($user_id, $sight_id, $game_id, $index, $checkpoint);
        }
    }

    private static function visited(int $user_id): array {
        $value = get_user_meta($user_id, self::META_VISITED, true);
        return is_array($value)
            ? array_values(array_unique(array_filter(array_map('absint', $value))))
            : [];
    }

    private static function register_visit(int $user_id, int $sight_id, int $game_id, int $index, array $checkpoint): void {
        $visited = self::visited($user_id);
        if (in_array($sight_id, $visited, true)) return;

        $visited[] = $sight_id;
        $visited = array_values(array_unique(array_filter(array_map('absint', $visited))));
        update_user_meta($user_id, self::META_VISITED, $visited);

        $events = get_user_meta($user_id, self::META_EVENTS, true);
        $events = is_array($events) ? $events : [];
        $events[] = [
            'id' => 'top-sight:' . $sight_id,
            'type' => 'top_sight_visited',
            'title' => get_the_title($sight_id) ?: sanitize_text_field($checkpoint['title'] ?? 'Top Sight visited'),
            'description' => 'Visited during ' . (get_the_title($game_id) ?: 'a TN Game adventure') . '.',
            'date' => current_time('mysql'),
            'meta' => [
                'post_id' => $sight_id,
                'object_id' => $sight_id,
                'game_id' => $game_id,
                'checkpoint_index' => $index,
                'object' => 'top_sight:' . $sight_id,
            ],
        ];
        if (count($events) > 250) $events = array_slice($events, -250);
        update_user_meta($user_id, self::META_EVENTS, $events);

        do_action('tng_os_top_sight_visited', $user_id, $sight_id, [
            'game_id' => $game_id,
            'checkpoint_index' => $index,
            'checkpoint' => $checkpoint,
        ]);
    }

    public static function journal_events($events, $user_id) {
        $events = is_array($events) ? $events : [];
        $stored = get_user_meta(absint($user_id), self::META_EVENTS, true);
        if (!is_array($stored)) return $events;
        $seen = [];
        foreach ($events as $event) if (is_array($event) && !empty($event['id'])) $seen[(string)$event['id']] = true;
        foreach ($stored as $event) {
            if (!is_array($event)) continue;
            $id = sanitize_text_field($event['id'] ?? '');
            if ($id && isset($seen[$id])) continue;
            $events[] = $event;
            if ($id) $seen[$id] = true;
        }
        return $events;
    }

    public static function profile_stats($stats, $user_id) {
        $stats = is_array($stats) ? $stats : [];
        $count = count(self::visited(absint($user_id)));
        $stats['top_sights'] = max(absint($stats['top_sights'] ?? 0), $count);
        $stats['places_visited'] = max(absint($stats['places_visited'] ?? 0), $count);
        return $stats;
    }

    public static function visited_filter($visited, $user_id) {
        $visited = is_array($visited) ? $visited : [];
        return array_values(array_unique(array_filter(array_merge($visited, self::visited(absint($user_id))))));
    }

    private static function current_top_sight_id(): int {
        if (is_admin() || !function_exists('is_singular') || !is_singular()) return 0;
        $id = get_queried_object_id();
        return self::is_top_sight($id) ? $id : 0;
    }

    public static function body_class(array $classes): array {
        $id = self::current_top_sight_id();
        if ($id && is_user_logged_in() && in_array($id, self::visited(get_current_user_id()), true)) {
            $classes[] = 'tng-top-sight-visited';
        }
        return $classes;
    }

    public static function top_sight_status(): void {
        $id = self::current_top_sight_id();
        if (!$id || !is_user_logged_in() || !in_array($id, self::visited(get_current_user_id()), true)) return;
        ?>
        <style id="tng-top-sight-visited-css">
        .tng-top-sight-visited-badge{display:inline-flex;align-items:center;gap:7px;padding:8px 12px;border:1px solid #bcdac8;border-radius:999px;background:#eff8f2;color:#17613f;font-size:12px;font-weight:800;box-shadow:0 5px 18px rgba(20,72,48,.06)}
        </style>
        <script id="tng-top-sight-visited-js">
        (()=>{const add=()=>{if(document.querySelector('.tng-top-sight-visited-badge'))return true;const h=document.querySelector('h1');if(!h)return false;const b=document.createElement('span');b.className='tng-top-sight-visited-badge';b.innerHTML='✓ Visited';h.insertAdjacentElement('afterend',b);return true};if(!add()){let n=0,t=setInterval(()=>{if(add()||++n>30)clearInterval(t)},120)}})();
        </script>
        <?php
    }
}

TNG_Live_Top_Sight_Visits::boot();
