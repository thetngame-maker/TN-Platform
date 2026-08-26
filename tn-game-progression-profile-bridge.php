<?php
/**
 * TN Game Progression Profile Bridge
 * Surfaces native TN Game milestones in the native Explorer profile.
 */
if (!defined('ABSPATH')) exit;

final class TNG_Progression_Profile_Bridge {
    public static function boot(): void {
        add_action('wp_footer', [__CLASS__, 'footer'], 220);
    }

    private static function is_profile(): bool {
        if (is_admin()) return false;
        $path = isset($_SERVER['REQUEST_URI']) ? (string)wp_unslash($_SERVER['REQUEST_URI']) : '';
        return strpos($path, '/profile/') !== false || (function_exists('is_page') && is_page('profile'));
    }

    private static function gamipress_earned_count(int $user_id): int {
        if (!$user_id) return 0;
        if (function_exists('gamipress_get_user_achievements')) {
            $earned = gamipress_get_user_achievements(['user_id'=>$user_id,'limit'=>-1]);
            $ids = [];
            foreach ((array)$earned as $item) {
                if (is_object($item) && isset($item->achievement_id)) $ids[] = absint($item->achievement_id);
                elseif (is_array($item) && isset($item['achievement_id'])) $ids[] = absint($item['achievement_id']);
                elseif (is_numeric($item)) $ids[] = absint($item);
            }
            return count(array_unique(array_filter($ids)));
        }
        return 0;
    }

    public static function footer(): void {
        if (!self::is_profile() || !is_user_logged_in()) return;
        $user_id = get_current_user_id();
        $stats = apply_filters('tng_os_explorer_profile_stats', [], $user_id);
        $stats = is_array($stats) ? $stats : [];
        $native_count = absint($stats['native_achievements'] ?? 0);
        $achievement_count = $native_count + self::gamipress_earned_count($user_id);
        $checkpoints = max(absint($stats['checkpoints'] ?? 0), absint($stats['game_checkpoints'] ?? 0));
        $next = is_array($stats['next_native_achievement'] ?? null) ? $stats['next_native_achievement'] : [];
        $payload = [
            'achievements' => $achievement_count,
            'checkpoints' => $checkpoints,
            'next' => $next,
        ];
        ?>
        <style id="tng-progression-profile-bridge-css">
        .tng-profile-native-progress{margin-top:10px}.tng-profile-native-progress__meter{height:5px;margin-top:7px;overflow:hidden;border-radius:999px;background:#e5eee7}.tng-profile-native-progress__meter i{display:block;height:100%;border-radius:inherit;background:#f26422}
        </style>
        <script id="tng-progression-profile-bridge-js">
        (()=>{
          const data=<?php echo wp_json_encode($payload); ?>;
          const patch=()=>{
            const stats=document.querySelector('.tng-profile-stats');
            if(!stats)return false;
            const cards=[...stats.querySelectorAll('a')];
            const achievementCard=stats.querySelector('[data-tng-profile-stat="achievements"]')||cards[0];
            const checkpointCard=stats.querySelector('[data-tng-profile-stat="checkpoints"]')||cards[2];
            if(achievementCard){const n=achievementCard.querySelector('strong');if(n)n.textContent=Number(data.achievements||0).toLocaleString();}
            if(checkpointCard){const n=checkpointCard.querySelector('strong');if(n)n.textContent=Number(data.checkpoints||0).toLocaleString();}
            const goals=document.querySelector('.tng-profile-goals');
            if(goals&&data.next&&data.next.title){
              const links=[...goals.querySelectorAll('a')];
              const target=links[1];
              if(target){
                const strong=target.querySelector('strong'),small=target.querySelector('small'),icon=target.querySelector(':scope > span');
                if(strong)strong.textContent=data.next.title;
                if(small)small.textContent=(Number(data.next.remaining||0)).toLocaleString()+' '+String(data.next.metric||'progress').replace('_',' ')+' remaining';
                if(icon)icon.textContent=data.next.icon||'🏆';
                target.setAttribute('href','<?php echo esc_js(home_url('/achievements/')); ?>');
                const wrap=target.querySelector('div');
                if(wrap&&!wrap.querySelector('.tng-profile-native-progress__meter')){const meter=document.createElement('div');meter.className='tng-profile-native-progress__meter';meter.innerHTML='<i style="width:'+Math.max(0,Math.min(100,Number(data.next.progress||0)))+'%"></i>';wrap.appendChild(meter);}
              }
            }
            return true;
          };
          if(!patch()){let tries=0;const timer=setInterval(()=>{tries++;if(patch()||tries>30)clearInterval(timer)},120)}
        })();
        </script>
        <?php
    }
}
TNG_Progression_Profile_Bridge::boot();
