<?php
/**
 * TN Game Game Completion Sync
 * Retires stale active-session state as soon as the authoritative checkpoint progress is complete.
 */
if (!defined('ABSPATH')) exit;

final class TNG_Game_Completion_Sync {
    private const ACTIVE_META = '_tng_active_game_session';

    public static function boot(): void {
        add_action('template_redirect', [__CLASS__, 'retire_completed_session'], 2);
        add_action('wp_footer', [__CLASS__, 'footer'], 190);
    }

    private static function is_gameplay(): bool {
        if (is_admin()) return false;
        $path = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
        if (strpos($path, '/game-play/') !== false) return true;
        return function_exists('is_page') && is_page('game-play');
    }

    private static function game_id(): int {
        if (!self::is_gameplay()) return 0;
        return isset($_GET['game']) ? absint($_GET['game']) : 0;
    }

    private static function total(int $game_id): int {
        if (!$game_id) return 0;
        $raw = get_post_meta($game_id, 'tng_game_checkpoints', true);
        if (is_array($raw) && $raw) return count(array_filter($raw, 'is_array'));
        $count = absint(get_post_meta($game_id, 'checkpoint_count', true));
        return max(0, min(30, $count));
    }

    private static function completed(int $game_id): int {
        if (!$game_id || !is_user_logged_in()) return 0;
        $raw = get_user_meta(get_current_user_id(), '_tng_game_progress_' . $game_id, true);
        if (!is_array($raw)) return 0;
        return count(array_unique(array_map('absint', $raw)));
    }

    private static function is_complete(int $game_id): bool {
        $total = self::total($game_id);
        return $total > 0 && self::completed($game_id) >= $total;
    }

    public static function retire_completed_session(): void {
        if (!self::is_gameplay() || !is_user_logged_in()) return;
        $game_id = self::game_id();
        if (!$game_id || !self::is_complete($game_id)) return;

        $user_id = get_current_user_id();
        do_action('tng_os_game_completed', $user_id, $game_id, [
            'title' => get_the_title($game_id) ?: 'Adventure',
            'checkpoints' => self::total($game_id),
            'date' => current_time('mysql'),
        ]);

        $session = get_user_meta($user_id, self::ACTIVE_META, true);
        if (is_array($session) && absint($session['game_id'] ?? 0) === $game_id) {
            delete_user_meta($user_id, self::ACTIVE_META);
        }
    }

    public static function footer(): void {
        if (is_admin() || !self::is_gameplay() || !is_user_logged_in()) return;
        $game_id = self::game_id();
        if (!$game_id || !self::is_complete($game_id)) return;
        $title = get_the_title($game_id) ?: 'Adventure';
        ?>
        <style id="tng-game-completion-sync-css">
        body.tng-game-route-complete [data-tng-game-dock],
        body.tng-game-route-complete .tng-game-dock,
        body.tng-game-route-complete [data-tng-session-resume],
        body.tng-game-route-complete [data-tng-session-paused-banner]{display:none!important}
        </style>
        <script id="tng-game-completion-sync-js">
        (()=>{
          const gameId=<?php echo (int)$game_id; ?>;
          const title=<?php echo wp_json_encode($title); ?>;
          document.body.classList.add('tng-game-route-complete');
          try{
            const raw=localStorage.getItem('tng_game_session_v1');
            if(raw){const s=JSON.parse(raw);if(!s||Number(s.game_id||0)===gameId)localStorage.removeItem('tng_game_session_v1');}
          }catch(e){try{localStorage.removeItem('tng_game_session_v1')}catch(_) {}}
          const removeStale=()=>{
            document.querySelectorAll('[data-tng-game-dock],.tng-game-dock,[data-tng-session-resume],[data-tng-session-paused-banner]').forEach(el=>el.remove());
            document.body.classList.remove('tng-game-session-paused');
          };
          removeStale();
          let attempts=0;
          const timer=setInterval(()=>{attempts++;removeStale();if(attempts>25)clearInterval(timer)},120);
          window.dispatchEvent(new CustomEvent('tng:adventure-completed',{detail:{game_id:gameId,title}}));
          try{localStorage.setItem('tng_last_completed_game',JSON.stringify({game_id:gameId,title,completed_at:Math.floor(Date.now()/1000)}));}catch(e){}
        })();
        </script>
        <?php
    }
}

TNG_Game_Completion_Sync::boot();
