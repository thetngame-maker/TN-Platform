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
        add_action('wp_footer', [__CLASS__, 'render_status'], 180);
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
            $index = absint($raw);
            if ($index < $count) $out[] = $index;
        }
        return array_values(array_unique($out));
    }

    public static function meta_changed($meta_id, $user_id, $meta_key, $meta_value): void {
        $user_id = absint($user_id);
        if (!$user_id || !preg_match('/^_tng_game_progress_(\d+)$/', (string)$meta_key, $m)) return;
        $game_id = absint($m[1]);
        if (!$game_id) return;

        // Always re-read the canonical saved progress rather than trusting a transient hook value.
        $progress = get_user_meta($user_id, $meta_key, true);
        $checkpoints = self::checkpoints($game_id);
        if (!$checkpoints) return;
        foreach (self::progress_indexes($progress, count($checkpoints)) as $index) {
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
        update_user_meta($user_id, self::META_VISITED, array_values(array_unique(array_filter(array_map('absint', $visited)))));

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

    private static function is_top_sights_directory(): bool {
        if (class_exists('TNG_OS\\Platform\\App_Router') && TNG_OS\Platform\App_Router::current_route() === 'top-sights') return true;
        $path = trim((string)wp_parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/');
        return $path === 'top-sights';
    }

    private static function current_top_sight_id(): int {
        if (is_admin() || self::is_top_sights_directory() || !function_exists('is_singular') || !is_singular()) return 0;
        $id = get_queried_object_id();
        return self::is_top_sight($id) ? $id : 0;
    }

    public static function body_class(array $classes): array {
        $id = self::current_top_sight_id();
        if ($id && is_user_logged_in() && in_array($id, self::visited(get_current_user_id()), true)) $classes[] = 'tng-top-sight-visited';
        return $classes;
    }

    public static function render_status(): void {
        if (!is_user_logged_in()) return;
        $user_id = get_current_user_id();
        $visited = self::visited($user_id);
        if (!$visited) return;
        $single_id = self::current_top_sight_id();
        $directory = self::is_top_sights_directory();
        if (!$single_id && !$directory) return;

        $url_map = [];
        foreach ($visited as $id) {
            $url = get_permalink($id);
            if ($url) $url_map[untrailingslashit($url)] = $id;
        }
        ?>
        <style id="tng-top-sight-visited-css">
        .tng-top-sight-visited-badge{display:inline-flex;align-items:center;gap:7px;padding:7px 11px;border:1px solid #bcdac8;border-radius:999px;background:#eff8f2;color:#17613f;font-size:11px;font-weight:800;box-shadow:0 5px 18px rgba(20,72,48,.06)}
        .tng-top-sight-card-visited{position:absolute;left:14px;bottom:14px;z-index:4}.tng-top-sight-card-visited .tng-top-sight-visited-badge{background:#fff}
        </style>
        <script id="tng-top-sight-visited-js">
        (()=>{
          const single=<?php echo wp_json_encode($single_id); ?>;
          const directory=<?php echo $directory ? 'true' : 'false'; ?>;
          const urls=<?php echo wp_json_encode(array_keys($url_map)); ?>;
          const badge=()=>{const b=document.createElement('span');b.className='tng-top-sight-visited-badge';b.textContent='✓ Visited';return b};
          const addSingle=()=>{if(!single||document.querySelector('.tng-top-sight-visited-badge'))return !!single;const h=document.querySelector('.tng-place-hero h1,.tng-top-sight-hero h1,main h1');if(!h)return false;h.insertAdjacentElement('afterend',badge());return true};
          const addDirectory=()=>{if(!directory)return false;let touched=false;document.querySelectorAll('a[href]').forEach(a=>{let href='';try{href=new URL(a.href,location.href).href.replace(/\/$/,'')}catch(e){return}if(!urls.includes(href))return;const card=a.closest('article,.tng-directory-card,.tng-card,.st-list-related,.item')||a;if(card.querySelector('.tng-top-sight-card-visited'))return;const wrap=document.createElement('span');wrap.className='tng-top-sight-card-visited';wrap.appendChild(badge());const media=card.querySelector('[class*="media"],[class*="image"],img')?.parentElement||card;const cs=getComputedStyle(media);if(cs.position==='static')media.style.position='relative';media.appendChild(wrap);touched=true});return touched};
          const run=()=>{if(single)addSingle();if(directory)addDirectory()};run();let n=0,t=setInterval(()=>{run();if(++n>25)clearInterval(t)},160);
        })();
        </script>
        <?php
    }
}

TNG_Live_Top_Sight_Visits::boot();
