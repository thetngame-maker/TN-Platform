<?php
/**
 * TN Game Game -> Explorer Integration
 * Persists completed games into Explorer history and augments profile stats/journal.
 */
if (!defined('ABSPATH')) exit;

final class TNG_Game_Explorer_Integration {
    private const META_COMPLETED = '_tng_completed_games';
    private const META_ACTIVITY = '_tng_game_activity';
    private const META_CHECKPOINTS = '_tng_game_checkpoints_total';
    private const META_XP = '_tng_game_xp_total';

    public static function boot(): void {
        add_action('tng_os_game_completed', [__CLASS__, 'sync_completion'], 20, 3);
        add_filter('tng_os_explorer_profile_stats', [__CLASS__, 'profile_stats'], 30, 2);
        add_filter('tng_os_adventure_journal_events', [__CLASS__, 'journal_events'], 30, 2);
        add_filter('tng_os_network_activity_items', [__CLASS__, 'network_items'], 30, 2);
        add_action('wp_footer', [__CLASS__, 'profile_footer'], 155);
    }

    private static function checkpoints(int $game_id): array {
        $raw = get_post_meta($game_id, 'tng_game_checkpoints', true);
        return is_array($raw) ? array_values(array_filter($raw, 'is_array')) : [];
    }

    private static function checkpoint_total(int $game_id): int {
        $items = self::checkpoints($game_id);
        if ($items) return count($items);
        return max(0, min(30, absint(get_post_meta($game_id, 'checkpoint_count', true))));
    }

    private static function reward_xp(int $game_id): int {
        $xp = absint(get_post_meta($game_id, 'xp_available', true));
        if ($xp) return $xp;
        foreach (self::checkpoints($game_id) as $checkpoint) $xp += absint($checkpoint['xp'] ?? 0);
        return $xp;
    }

    private static function gamipress_snapshot(int $user_id): array {
        $out = ['xp' => null, 'rank' => 'Explorer'];
        if (function_exists('gamipress_get_points_types') && function_exists('gamipress_get_user_points')) {
            $types = gamipress_get_points_types();
            if (is_array($types) && $types) {
                $selected = '';
                foreach ($types as $slug => $type) {
                    $name = is_array($type) ? (string)($type['plural_name'] ?? $type['singular_name'] ?? $slug) : (string)$slug;
                    if (stripos((string)$slug, 'xp') !== false || stripos($name, 'xp') !== false || stripos($name, 'explorer') !== false) { $selected = (string)$slug; break; }
                }
                if (!$selected) $selected = (string) array_key_first($types);
                if ($selected) $out['xp'] = (int) gamipress_get_user_points($user_id, $selected);
            }
        }
        if (function_exists('gamipress_get_rank_types') && function_exists('gamipress_get_user_rank_id')) {
            $types = gamipress_get_rank_types();
            if (is_array($types) && $types) {
                $type = (string) array_key_first($types);
                $rank_id = $type ? absint(gamipress_get_user_rank_id($user_id, $type)) : 0;
                if ($rank_id && get_post($rank_id)) $out['rank'] = get_the_title($rank_id);
            }
        }
        return $out;
    }

    public static function sync_completion(int $user_id, int $game_id, array $data = []): void {
        if ($user_id < 1 || $game_id < 1 || !get_post($game_id)) return;

        $completed = get_user_meta($user_id, self::META_COMPLETED, true);
        $completed = is_array($completed) ? array_values(array_unique(array_map('absint', $completed))) : [];
        $already = in_array($game_id, $completed, true);
        if (!$already) {
            $completed[] = $game_id;
            update_user_meta($user_id, self::META_COMPLETED, $completed);
            update_user_meta($user_id, self::META_CHECKPOINTS, max(0, (int)get_user_meta($user_id, self::META_CHECKPOINTS, true)) + self::checkpoint_total($game_id));
            update_user_meta($user_id, self::META_XP, max(0, (int)get_user_meta($user_id, self::META_XP, true)) + self::reward_xp($game_id));
        }

        $items = get_user_meta($user_id, self::META_ACTIVITY, true);
        $items = is_array($items) ? $items : [];
        $key = 'game:' . $game_id;
        foreach ($items as $item) if (($item['key'] ?? '') === $key) return;

        $title = sanitize_text_field($data['title'] ?? get_the_title($game_id) ?: 'TN Game adventure');
        $checkpoints = self::checkpoint_total($game_id);
        $xp = self::reward_xp($game_id);
        $activity = [
            'key' => $key,
            'type' => 'game_completed',
            'user_id' => $user_id,
            'game_id' => $game_id,
            'title' => $title,
            'message' => sprintf('Completed %d checkpoint%s and earned %d XP.', $checkpoints, $checkpoints === 1 ? '' : 's', $xp),
            'description' => sprintf('%d checkpoints · +%d XP', $checkpoints, $xp),
            'date' => sanitize_text_field($data['date'] ?? current_time('mysql')),
            'timestamp' => current_time('timestamp'),
            'checkpoints' => $checkpoints,
            'xp' => $xp,
            'url' => get_permalink($game_id),
            'reactions' => ['cheer'=>[], 'fire'=>[], 'amazing'=>[]],
        ];
        array_unshift($items, $activity);
        update_user_meta($user_id, self::META_ACTIVITY, array_slice($items, 0, 100));

        do_action('tng_os_explorer_activity_created', $user_id, $activity);
        do_action('tng_os_network_activity_created', $user_id, $activity);
        do_action('tng_os_journal_event', $user_id, [
            'event' => 'game_completed',
            'object' => 'game:' . $game_id,
            'title' => $title,
            'meta' => $activity,
        ]);
    }

    private static function stats(int $user_id): array {
        $completed = get_user_meta($user_id, self::META_COMPLETED, true);
        $completed = is_array($completed) ? array_values(array_unique(array_filter(array_map('absint', $completed)))) : [];
        $snapshot = self::gamipress_snapshot($user_id);
        return [
            'completed_games' => count($completed),
            'game_checkpoints' => max(0, (int)get_user_meta($user_id, self::META_CHECKPOINTS, true)),
            'game_xp' => max(0, (int)get_user_meta($user_id, self::META_XP, true)),
            'rank_name' => $snapshot['rank'],
            'gamipress_xp' => $snapshot['xp'],
        ];
    }

    public static function profile_stats($stats, $user_id) {
        $stats = is_array($stats) ? $stats : [];
        $game = self::stats(absint($user_id));
        $stats = array_merge($stats, $game);
        if (empty($stats['xp']) && $game['gamipress_xp'] !== null) $stats['xp'] = $game['gamipress_xp'];
        elseif (empty($stats['xp']) && $game['game_xp']) $stats['xp'] = $game['game_xp'];
        $stats['checkpoints'] = max(absint($stats['checkpoints'] ?? 0), $game['game_checkpoints']);
        return $stats;
    }

    public static function journal_events($events, $user_id) {
        $events = is_array($events) ? $events : [];
        $items = get_user_meta(absint($user_id), self::META_ACTIVITY, true);
        if (!is_array($items)) return $events;
        foreach ($items as $item) {
            $events[] = [
                'id' => sanitize_text_field($item['key'] ?? ''),
                'type' => 'game_completed',
                'title' => sanitize_text_field($item['title'] ?? 'Completed an adventure'),
                'description' => sanitize_text_field($item['description'] ?? $item['message'] ?? ''),
                'date' => sanitize_text_field($item['date'] ?? ''),
                'meta' => $item,
            ];
        }
        return $events;
    }

    public static function network_items($items, $user_id) {
        $items = is_array($items) ? $items : [];
        $game_items = get_user_meta(absint($user_id), self::META_ACTIVITY, true);
        return is_array($game_items) ? array_merge($items, $game_items) : $items;
    }

    private static function requested_user(): int {
        $requested = isset($_GET['explorer']) ? sanitize_user(wp_unslash($_GET['explorer'])) : '';
        if ($requested) {
            $user = get_user_by('slug', $requested);
            return $user ? (int)$user->ID : 0;
        }
        return get_current_user_id();
    }

    public static function profile_footer(): void {
        if (is_admin()) return;
        $user_id = self::requested_user();
        if (!$user_id) return;
        $stats = self::stats($user_id);
        $items = get_user_meta($user_id, self::META_ACTIVITY, true);
        $items = is_array($items) ? array_slice($items, 0, 4) : [];
        $recent = [];
        foreach ($items as $item) {
            $recent[] = [
                'title' => sanitize_text_field($item['title'] ?? 'Adventure'),
                'description' => sanitize_text_field($item['description'] ?? ''),
                'date' => sanitize_text_field($item['date'] ?? ''),
                'url' => esc_url_raw($item['url'] ?? ''),
            ];
        }
        $payload = ['stats'=>$stats, 'recent'=>$recent];
        ?>
        <style id="tng-game-explorer-profile-css">
        .tng-game-profile-progress{margin-top:18px;padding:24px;background:linear-gradient(135deg,#f7fbf8,#fff);border:1px solid #dce9df;border-radius:22px;color:#173d2f}.tng-game-profile-progress__head{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;margin-bottom:16px}.tng-game-profile-progress__eyebrow{display:block;color:#f16022;font-size:10px;font-weight:900;letter-spacing:.13em;text-transform:uppercase}.tng-game-profile-progress h2{margin:4px 0 0!important;font-size:25px!important;color:#173d2f!important}.tng-game-profile-progress__rank{padding:8px 12px;border-radius:999px;background:#e9f5ed;color:#245c43;font-size:11px;font-weight:900}.tng-game-profile-progress__stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.tng-game-profile-progress__stat{padding:16px;border:1px solid #e0e9e2;border-radius:15px;background:#fff}.tng-game-profile-progress__stat strong{display:block;font-size:23px;line-height:1;color:#173d2f}.tng-game-profile-progress__stat span{display:block;margin-top:6px;color:#728078;font-size:9px;font-weight:900;letter-spacing:.08em;text-transform:uppercase}.tng-game-profile-progress__recent{margin-top:16px;display:grid;gap:8px}.tng-game-profile-progress__recent-title{font-size:11px;font-weight:900;letter-spacing:.1em;text-transform:uppercase;color:#f16022}.tng-game-profile-progress__item{display:flex;align-items:center;gap:12px;padding:12px 13px;border:1px solid #e1e9e3;border-radius:14px;background:#fff;text-decoration:none!important}.tng-game-profile-progress__check{width:34px;height:34px;border-radius:11px;background:#e8f5ec;color:#276449;display:grid;place-items:center;font-weight:900;flex:none}.tng-game-profile-progress__item strong{display:block;color:#173d2f}.tng-game-profile-progress__item small{display:block;margin-top:2px;color:#748079}@media(max-width:720px){.tng-game-profile-progress__stats{grid-template-columns:repeat(2,1fr)}.tng-game-profile-progress__head{align-items:flex-start;flex-direction:column}}@media(max-width:420px){.tng-game-profile-progress__stats{grid-template-columns:1fr 1fr}}
        </style>
        <script id="tng-game-explorer-profile-js">
        (()=>{
          const data=<?php echo wp_json_encode($payload); ?>;
          const esc=v=>{const d=document.createElement('div');d.textContent=String(v??'');return d.innerHTML};
          const build=()=>{
            const root=document.querySelector('[data-explorer-profile]');if(!root||root.querySelector('.tng-game-profile-progress'))return !!root;
            const stats=root.querySelector('.tng-public-explorer-stats');if(!stats)return false;
            const s=data.stats||{};
            const xp=s.gamipress_xp===null||s.gamipress_xp===undefined?s.game_xp:s.gamipress_xp;
            const recent=(data.recent||[]).map(x=>`<a class="tng-game-profile-progress__item" href="${esc(x.url||'#')}"><span class="tng-game-profile-progress__check">✓</span><span><strong>${esc(x.title)}</strong><small>${esc(x.description||'Adventure complete')}</small></span></a>`).join('');
            const section=document.createElement('section');section.className='tng-game-profile-progress';section.innerHTML=`<div class="tng-game-profile-progress__head"><div><span class="tng-game-profile-progress__eyebrow">TN Game progress</span><h2>Your game journey</h2></div><span class="tng-game-profile-progress__rank">${esc(s.rank_name||'Explorer')}</span></div><div class="tng-game-profile-progress__stats"><div class="tng-game-profile-progress__stat"><strong>${Number(s.completed_games||0).toLocaleString()}</strong><span>Adventures complete</span></div><div class="tng-game-profile-progress__stat"><strong>${Number(s.game_checkpoints||0).toLocaleString()}</strong><span>Checkpoints claimed</span></div><div class="tng-game-profile-progress__stat"><strong>${Number(s.game_xp||0).toLocaleString()}</strong><span>Game XP earned</span></div><div class="tng-game-profile-progress__stat"><strong>${Number(xp||0).toLocaleString()}</strong><span>Explorer XP</span></div></div>${recent?`<div class="tng-game-profile-progress__recent"><div class="tng-game-profile-progress__recent-title">Recent completed adventures</div>${recent}</div>`:''}`;
            stats.insertAdjacentElement('afterend',section);return true;
          };
          if(!build()){let tries=0;const timer=setInterval(()=>{tries++;if(build()||tries>35)clearInterval(timer)},150)}
        })();
        </script>
        <?php
    }
}

TNG_Game_Explorer_Integration::boot();
