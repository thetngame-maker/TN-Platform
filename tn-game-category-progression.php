<?php
/**
 * TN Game Category Progression
 * Adds category-level Explorer progression to Profile and completed-game handoff.
 */
if (!defined('ABSPATH')) exit;

final class TNG_Category_Progression {
    public static function boot(): void {
        add_filter('tng_os_explorer_profile_stats', [__CLASS__, 'profile_stats'], 70, 2);
        add_action('wp_footer', [__CLASS__, 'footer'], 220);
    }

    private static function count_meta_array(int $user_id, string $key): int {
        $value = get_user_meta($user_id, $key, true);
        return is_array($value) ? count(array_unique(array_filter(array_map('absint', $value)))) : max(0, (int)$value);
    }

    private static function raw_stats(int $user_id): array {
        $games = self::count_meta_array($user_id, '_tng_completed_games');
        $checkpoints = max(0, (int)get_user_meta($user_id, '_tng_game_checkpoints_total', true));
        $sights = self::count_meta_array($user_id, '_tng_visited_top_sights');

        $trips = 0;
        foreach (['_tng_completed_trips','tng_completed_trips','tng_trip_history','_tng_trip_history'] as $key) {
            $value = get_user_meta($user_id, $key, true);
            if (is_array($value)) $trips = max($trips, count($value));
            elseif (is_numeric($value)) $trips = max($trips, (int)$value);
        }

        $photos = 0;
        foreach (['tng_photo_count','_tng_photo_count','tng_approved_photos','photo_count'] as $key) {
            $value = get_user_meta($user_id, $key, true);
            if (is_array($value)) $photos = max($photos, count($value));
            elseif (is_numeric($value)) $photos = max($photos, (int)$value);
        }

        return [
            'games' => $games,
            'checkpoints' => $checkpoints,
            'top_sights' => $sights,
            'trips' => $trips,
            'photos' => $photos,
        ];
    }

    private static function target(array $tiers, int $value): array {
        $previous = 0;
        foreach ($tiers as $target) {
            $target = absint($target);
            if ($value < $target) {
                $span = max(1, $target - $previous);
                $progress = (int)round((max(0, $value - $previous) / $span) * 100);
                return ['target'=>$target,'previous'=>$previous,'remaining'=>max(0,$target-$value),'progress'=>max(0,min(100,$progress))];
            }
            $previous = $target;
        }
        return ['target'=>$previous,'previous'=>$previous,'remaining'=>0,'progress'=>100];
    }

    public static function categories(int $user_id): array {
        $stats = self::raw_stats($user_id);
        $defs = [
            'games' => ['label'=>'Games','icon'=>'🎮','tiers'=>[1,5,10,25,50]],
            'checkpoints' => ['label'=>'Checkpoints','icon'=>'📍','tiers'=>[3,10,25,50,100]],
            'top_sights' => ['label'=>'Top Sights','icon'=>'📌','tiers'=>[1,5,10,25,50]],
            'trips' => ['label'=>'Trips','icon'=>'🗺️','tiers'=>[1,3,5,10,25]],
            'photos' => ['label'=>'Photos','icon'=>'📸','tiers'=>[1,5,10,25,50,100,250]],
        ];
        $out = [];
        foreach ($defs as $key => $def) {
            $value = max(0, absint($stats[$key] ?? 0));
            $goal = self::target($def['tiers'], $value);
            $out[$key] = array_merge($def, $goal, ['key'=>$key,'value'=>$value]);
        }
        return $out;
    }

    public static function profile_stats($stats, $user_id) {
        $stats = is_array($stats) ? $stats : [];
        $user_id = absint($user_id);
        if (!$user_id) return $stats;
        $categories = self::categories($user_id);
        $stats['category_progression'] = $categories;
        if (isset($categories['top_sights'])) $stats['top_sights'] = max(absint($stats['top_sights'] ?? 0), $categories['top_sights']['value']);
        if (isset($categories['trips'])) $stats['completed_trips'] = max(absint($stats['completed_trips'] ?? 0), $categories['trips']['value']);
        return $stats;
    }

    private static function is_profile(): bool {
        if (is_admin()) return false;
        $path = isset($_SERVER['REQUEST_URI']) ? (string)wp_unslash($_SERVER['REQUEST_URI']) : '';
        return strpos($path, '/profile/') !== false || (function_exists('is_page') && is_page('profile'));
    }

    private static function is_gameplay(): bool {
        if (is_admin()) return false;
        $path = isset($_SERVER['REQUEST_URI']) ? (string)wp_unslash($_SERVER['REQUEST_URI']) : '';
        return strpos($path, '/game-play/') !== false || (function_exists('is_page') && is_page('game-play'));
    }

    private static function current_game_id(): int {
        return self::is_gameplay() && isset($_GET['game']) ? absint($_GET['game']) : 0;
    }

    private static function game_complete(int $user_id, int $game_id): bool {
        if (!$user_id || !$game_id) return false;
        $raw = get_post_meta($game_id, 'tng_game_checkpoints', true);
        $total = is_array($raw) ? count(array_filter($raw, 'is_array')) : absint(get_post_meta($game_id, 'checkpoint_count', true));
        if (!$total) return false;
        $done = get_user_meta($user_id, '_tng_game_progress_' . $game_id, true);
        $done = is_array($done) ? count(array_unique(array_map('absint', $done))) : 0;
        return $done >= $total;
    }

    public static function footer(): void {
        if (is_admin() || !is_user_logged_in()) return;
        $user_id = get_current_user_id();
        $categories = self::categories($user_id);
        if (!$categories) return;

        $profile = self::is_profile();
        $game_id = self::current_game_id();
        $complete = $game_id && self::game_complete($user_id, $game_id);
        if (!$profile && !$complete) return;

        $payload = array_values($categories);
        ?>
        <style id="tng-category-progression-css">
        .tng-category-progression{margin:18px 0 0;padding:22px;border:1px solid #e2e9e3;border-radius:22px;background:#fff}.tng-category-progression__head{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;margin-bottom:16px}.tng-category-progression__head small{display:block;color:#f16022;font-size:10px;font-weight:900;letter-spacing:.13em;text-transform:uppercase}.tng-category-progression__head h2{margin:4px 0 0;color:#173d2f;font-size:26px;line-height:1.05}.tng-category-progression__head p{margin:0;color:#718078;font-size:12px}.tng-category-progression__grid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px}.tng-category-card{padding:15px;border:1px solid #e5ebe6;border-radius:16px;background:#fbfcfa;min-width:0}.tng-category-card__top{display:flex;align-items:center;justify-content:space-between;gap:8px}.tng-category-card__icon{font-size:21px}.tng-category-card__value{font-size:21px;font-weight:900;color:#173d2f}.tng-category-card strong{display:block;margin-top:7px;color:#173d2f;font-size:13px}.tng-category-card small{display:block;margin-top:4px;color:#79867f;font-size:10px;line-height:1.3}.tng-category-card__bar{height:6px;margin-top:10px;border-radius:999px;background:#e7efe9;overflow:hidden}.tng-category-card__bar i{display:block;height:100%;border-radius:999px;background:#f16022}.tng-category-completion{margin-top:12px;padding:14px;border:1px solid #e0e9e2;border-radius:14px;background:#fff}.tng-category-completion__head{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:10px}.tng-category-completion__head small{color:#e85618;font-size:8px;font-weight:900;letter-spacing:.11em;text-transform:uppercase}.tng-category-completion__head strong{color:#173d2f;font-size:14px}.tng-category-completion__rows{display:grid;grid-template-columns:1fr 1fr;gap:9px}.tng-category-completion__row{padding:10px;border-radius:11px;background:#f6faf7}.tng-category-completion__row div{display:flex;align-items:center;justify-content:space-between;gap:8px}.tng-category-completion__row strong{font-size:12px;color:#173d2f}.tng-category-completion__row span{font-size:10px;color:#6f7f76}.tng-category-completion__row .tng-category-card__bar{margin-top:7px;height:5px}@media(max-width:900px){.tng-category-progression__grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:540px){.tng-category-progression{padding:16px}.tng-category-progression__head{align-items:flex-start;flex-direction:column}.tng-category-progression__grid{grid-template-columns:1fr}.tng-category-completion__rows{grid-template-columns:1fr}}
        </style>
        <script id="tng-category-progression-js">
        (()=>{
          const categories=<?php echo wp_json_encode($payload); ?>;
          const esc=v=>{const d=document.createElement('div');d.textContent=String(v??'');return d.innerHTML};
          const card=c=>`<article class="tng-category-card"><div class="tng-category-card__top"><span class="tng-category-card__icon">${esc(c.icon)}</span><span class="tng-category-card__value">${Number(c.value||0).toLocaleString()}</span></div><strong>${esc(c.label)}</strong><small>${Number(c.remaining||0)>0?`${Number(c.remaining).toLocaleString()} to ${Number(c.target).toLocaleString()}`:'Top tier reached'}</small><div class="tng-category-card__bar"><i style="width:${Number(c.progress||0)}%"></i></div></article>`;
          const addProfile=()=>{
            const stats=document.querySelector('.tng-profile-stats');
            if(!stats||document.querySelector('.tng-category-progression')) return !!stats;
            const section=document.createElement('section');section.className='tng-category-progression';section.innerHTML=`<div class="tng-category-progression__head"><div><small>Category progression</small><h2>Your Explorer path</h2></div><p>Every kind of adventure moves a different part of your profile forward.</p></div><div class="tng-category-progression__grid">${categories.map(card).join('')}</div>`;
            stats.insertAdjacentElement('afterend',section);return true;
          };
          const addCompletion=()=>{
            const handoff=document.querySelector('.tng-completion-handoff');
            if(!handoff||handoff.querySelector('.tng-category-completion')) return !!handoff;
            const relevant=categories.filter(c=>['games','checkpoints'].includes(c.key));
            const rows=relevant.map(c=>`<div class="tng-category-completion__row"><div><strong>${esc(c.icon)} ${esc(c.label)}</strong><span>${Number(c.value||0).toLocaleString()} / ${Number(c.target||0).toLocaleString()}</span></div><div class="tng-category-card__bar"><i style="width:${Number(c.progress||0)}%"></i></div></div>`).join('');
            const box=document.createElement('div');box.className='tng-category-completion';box.innerHTML=`<div class="tng-category-completion__head"><div><small>Category progress</small><strong>This adventure moved your Explorer path forward.</strong></div><a href="<?php echo esc_url(home_url('/profile/')); ?>" style="font-size:10px;font-weight:900;color:#e85618;text-decoration:none">View profile →</a></div><div class="tng-category-completion__rows">${rows}</div>`;
            handoff.appendChild(box);return true;
          };
          <?php if ($profile): ?>if(!addProfile()){let n=0,t=setInterval(()=>{if(addProfile()||++n>40)clearInterval(t)},120)}<?php endif; ?>
          <?php if ($complete): ?>if(!addCompletion()){let n=0,t=setInterval(()=>{if(addCompletion()||++n>50)clearInterval(t)},120)}<?php endif; ?>
        })();
        </script>
        <?php
    }
}

TNG_Category_Progression::boot();
