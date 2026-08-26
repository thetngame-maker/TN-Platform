<?php
/**
 * TN Game Progression Milestones
 * Native TN Game milestones layered on top of Explorer/GamiPress progression.
 */
if (!defined('ABSPATH')) exit;

final class TNG_Progression_Milestones {
    private const META = '_tng_native_achievements';
    private const SEEN = '_tng_native_achievements_seen';

    public static function boot(): void {
        add_action('tng_os_game_completed', [__CLASS__, 'sync_after_game'], 40, 3);
        add_action('template_redirect', [__CLASS__, 'sync_current_user'], 4);
        add_filter('tng_os_explorer_profile_stats', [__CLASS__, 'profile_stats'], 50, 2);
        add_filter('tng_os_native_achievements', [__CLASS__, 'native_achievements'], 10, 2);
        add_action('wp_footer', [__CLASS__, 'footer_feedback'], 210);
    }

    private static function completed_games(int $user_id): array {
        $games = get_user_meta($user_id, '_tng_completed_games', true);
        return is_array($games) ? array_values(array_unique(array_filter(array_map('absint', $games)))) : [];
    }

    private static function game_checkpoint_total(int $game_id): int {
        $raw = get_post_meta($game_id, 'tng_game_checkpoints', true);
        if (is_array($raw) && $raw) return count(array_filter($raw, 'is_array'));
        return max(0, min(30, absint(get_post_meta($game_id, 'checkpoint_count', true))));
    }

    private static function game_xp(int $game_id): int {
        $xp = absint(get_post_meta($game_id, 'xp_available', true));
        if ($xp) return $xp;
        $raw = get_post_meta($game_id, 'tng_game_checkpoints', true);
        if (is_array($raw)) foreach ($raw as $checkpoint) if (is_array($checkpoint)) $xp += absint($checkpoint['xp'] ?? 0);
        return $xp;
    }

    private static function explorer_xp(int $user_id): int {
        if (!$user_id) return 0;
        if (function_exists('gamipress_get_user_points') && function_exists('gamipress_get_points_types')) {
            $types = gamipress_get_points_types();
            if (is_array($types) && $types) {
                $selected = '';
                foreach ($types as $slug => $data) {
                    $name = is_array($data) ? (string)($data['plural_name'] ?? $data['singular_name'] ?? $slug) : (string)$slug;
                    $text = strtolower((string)$slug . ' ' . $name);
                    if (strpos($text, 'xp') !== false || strpos($text, 'explorer') !== false) { $selected = (string)$slug; break; }
                }
                if (!$selected && count($types) === 1) $selected = (string)array_key_first($types);
                if ($selected) return max(0, (int)gamipress_get_user_points($user_id, $selected));
            }
        }
        return 0;
    }

    private static function snapshot(int $user_id): array {
        $games = self::completed_games($user_id);
        $checkpoints = 0;
        $game_xp = 0;
        foreach ($games as $game_id) {
            $checkpoints += self::game_checkpoint_total($game_id);
            $game_xp += self::game_xp($game_id);
        }
        $stored_checkpoints = max(0, (int)get_user_meta($user_id, '_tng_game_checkpoints_total', true));
        $stored_xp = max(0, (int)get_user_meta($user_id, '_tng_game_xp_total', true));
        if ($checkpoints !== $stored_checkpoints) update_user_meta($user_id, '_tng_game_checkpoints_total', $checkpoints);
        if ($game_xp !== $stored_xp) update_user_meta($user_id, '_tng_game_xp_total', $game_xp);
        return [
            'games' => count($games),
            'checkpoints' => $checkpoints,
            'game_xp' => $game_xp,
            'explorer_xp' => self::explorer_xp($user_id),
        ];
    }

    public static function catalog(): array {
        return [
            'first-adventure' => ['title'=>'First Adventure','description'=>'Complete your first TN Game adventure.','icon'=>'🏁','metric'=>'games','target'=>1],
            'checkpoint-starter' => ['title'=>'Checkpoint Starter','description'=>'Claim 3 checkpoints across TN Game adventures.','icon'=>'📍','metric'=>'checkpoints','target'=>3],
            'five-adventures' => ['title'=>'Adventure Regular','description'=>'Complete 5 TN Game adventures.','icon'=>'🥾','metric'=>'games','target'=>5],
            'ten-adventures' => ['title'=>'Tennessee Trailblazer','description'=>'Complete 10 TN Game adventures.','icon'=>'🧭','metric'=>'games','target'=>10],
            'xp-2500' => ['title'=>'Explorer 2,500','description'=>'Earn 2,500 Explorer XP.','icon'=>'⚡','metric'=>'explorer_xp','target'=>2500],
            'xp-5000' => ['title'=>'Explorer 5,000','description'=>'Earn 5,000 Explorer XP.','icon'=>'🏆','metric'=>'explorer_xp','target'=>5000],
            'checkpoint-25' => ['title'=>'Checkpoint Hunter','description'=>'Claim 25 checkpoints across Tennessee.','icon'=>'🎯','metric'=>'checkpoints','target'=>25],
            'checkpoint-50' => ['title'=>'Checkpoint Master','description'=>'Claim 50 checkpoints across Tennessee.','icon'=>'⭐','metric'=>'checkpoints','target'=>50],
        ];
    }

    private static function stored(int $user_id): array {
        $value = get_user_meta($user_id, self::META, true);
        return is_array($value) ? $value : [];
    }

    private static function sync(int $user_id): array {
        if ($user_id < 1) return [];
        $snapshot = self::snapshot($user_id);
        $earned = self::stored($user_id);
        $new = [];
        foreach (self::catalog() as $slug => $milestone) {
            $metric = (string)$milestone['metric'];
            if (isset($earned[$slug]) || absint($snapshot[$metric] ?? 0) < absint($milestone['target'])) continue;
            $earned[$slug] = [
                'slug' => $slug,
                'title' => $milestone['title'],
                'description' => $milestone['description'],
                'icon' => $milestone['icon'],
                'earned_at' => current_time('mysql'),
            ];
            $new[] = $earned[$slug];
        }
        if ($new) {
            update_user_meta($user_id, self::META, $earned);
            do_action('tng_os_native_achievements_earned', $user_id, $new, $snapshot);
        }
        return $new;
    }

    public static function sync_after_game(int $user_id, int $game_id, array $data = []): void {
        self::sync($user_id);
    }

    public static function sync_current_user(): void {
        if (is_admin() || !is_user_logged_in()) return;
        self::sync(get_current_user_id());
    }

    public static function profile_stats($stats, $user_id) {
        $stats = is_array($stats) ? $stats : [];
        $user_id = absint($user_id);
        if (!$user_id) return $stats;
        $snapshot = self::snapshot($user_id);
        $earned = self::stored($user_id);
        $stats['game_checkpoints'] = max(absint($stats['game_checkpoints'] ?? 0), $snapshot['checkpoints']);
        $stats['checkpoints'] = max(absint($stats['checkpoints'] ?? 0), $snapshot['checkpoints']);
        $stats['completed_games'] = max(absint($stats['completed_games'] ?? 0), $snapshot['games']);
        $stats['game_xp'] = max(absint($stats['game_xp'] ?? 0), $snapshot['game_xp']);
        $stats['native_achievements'] = count($earned);
        $stats['native_achievement_items'] = array_values($earned);
        $stats['next_native_achievement'] = self::next_milestone($snapshot, $earned);
        return $stats;
    }

    private static function next_milestone(array $snapshot, array $earned): array {
        $best = [];
        $best_ratio = -1;
        foreach (self::catalog() as $slug => $milestone) {
            if (isset($earned[$slug])) continue;
            $metric = (string)$milestone['metric'];
            $value = absint($snapshot[$metric] ?? 0);
            $target = max(1, absint($milestone['target']));
            $ratio = min(1, $value / $target);
            if ($ratio <= $best_ratio) continue;
            $best_ratio = $ratio;
            $best = array_merge($milestone, ['slug'=>$slug,'current'=>$value,'remaining'=>max(0,$target-$value),'progress'=>(int)round($ratio*100)]);
        }
        return $best;
    }

    public static function native_achievements($items, $user_id) {
        $items = is_array($items) ? $items : [];
        $earned = self::stored(absint($user_id));
        foreach (self::catalog() as $slug => $milestone) {
            $items[] = array_merge($milestone, [
                'slug' => $slug,
                'earned' => isset($earned[$slug]),
                'earned_at' => sanitize_text_field($earned[$slug]['earned_at'] ?? ''),
            ]);
        }
        return $items;
    }

    public static function footer_feedback(): void {
        if (is_admin() || !is_user_logged_in()) return;
        $user_id = get_current_user_id();
        $earned = self::stored($user_id);
        if (!$earned) return;
        $seen = get_user_meta($user_id, self::SEEN, true);
        $seen = is_array($seen) ? array_values(array_unique(array_map('sanitize_key', $seen))) : [];
        $unseen = [];
        foreach ($earned as $slug => $item) if (!in_array($slug, $seen, true)) $unseen[$slug] = $item;
        if (!$unseen) return;
        $first_slug = (string)array_key_first($unseen);
        $item = $unseen[$first_slug];
        $seen[] = $first_slug;
        update_user_meta($user_id, self::SEEN, array_values(array_unique($seen)));
        ?>
        <style id="tng-native-achievement-feedback-css">
        .tng-achievement-toast{position:fixed;right:24px;bottom:104px;z-index:100050;width:min(390px,calc(100vw - 32px));padding:18px;border:1px solid rgba(255,255,255,.18);border-radius:22px;background:linear-gradient(145deg,#103e2d,#176642);color:#fff;box-shadow:0 22px 60px rgba(10,43,30,.28);display:grid;grid-template-columns:auto 1fr auto;gap:14px;align-items:center;animation:tngAchIn .28s ease-out}.tng-achievement-toast__icon{width:52px;height:52px;border-radius:16px;background:#fff;display:grid;place-items:center;font-size:27px}.tng-achievement-toast small{display:block;color:#ffc8ae;font-size:9px;font-weight:900;letter-spacing:.12em;text-transform:uppercase}.tng-achievement-toast strong{display:block;margin-top:3px;font-size:18px}.tng-achievement-toast p{margin:3px 0 0;color:rgba(255,255,255,.75);font-size:12px;line-height:1.35}.tng-achievement-toast button{border:0;background:transparent;color:#fff;font-size:22px;cursor:pointer}@keyframes tngAchIn{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:none}}@media(max-width:640px){.tng-achievement-toast{left:16px;right:16px;bottom:92px;width:auto}}
        </style>
        <script id="tng-native-achievement-feedback-js">
        (()=>{const item=<?php echo wp_json_encode($item); ?>;const el=document.createElement('aside');el.className='tng-achievement-toast';el.setAttribute('role','status');el.innerHTML=`<div class="tng-achievement-toast__icon">${item.icon||'🏆'}</div><div><small>Achievement unlocked</small><strong>${item.title||'Milestone unlocked'}</strong><p>${item.description||'Your Explorer progress has been saved.'}</p></div><button type="button" aria-label="Close">×</button>`;el.querySelector('button').addEventListener('click',()=>el.remove());document.body.appendChild(el);setTimeout(()=>{if(el.isConnected)el.remove()},9000);})();
        </script>
        <?php
    }
}

TNG_Progression_Milestones::boot();
