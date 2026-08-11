<?php
/**
 * TN Game Game Session Ownership
 * Enforces one active player adventure at a time and provides explicit resume/switch decisions.
 */
if (!defined('ABSPATH')) exit;

final class TNG_Game_Session_Ownership {
    private const META = '_tng_active_game_session';
    private const SWITCH_ACTION = 'tng_replace_active_game';

    public static function boot(): void {
        add_action('template_redirect', [__CLASS__, 'guard_gameplay'], 0);
        add_action('wp_footer', [__CLASS__, 'footer'], 172);
    }

    private static function is_gameplay(): bool {
        if (is_admin()) return false;
        $path = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
        if (strpos($path, '/game-play/') !== false) return true;
        return function_exists('is_page') && is_page('game-play');
    }

    private static function active_session(): array {
        if (!is_user_logged_in()) return [];
        $raw = get_user_meta(get_current_user_id(), self::META, true);
        if (!is_array($raw)) return [];
        $game_id = absint($raw['game_id'] ?? 0);
        $status = sanitize_key((string)($raw['status'] ?? 'active'));
        if (!$game_id || !in_array($status, ['active','paused'], true)) return [];
        $post = get_post($game_id);
        if (!$post || $post->post_status !== 'publish') return [];
        return [
            'game_id' => $game_id,
            'title' => sanitize_text_field((string)($raw['title'] ?? get_the_title($game_id))),
            'status' => $status,
            'completed' => absint($raw['completed'] ?? 0),
            'total' => absint($raw['total'] ?? 0),
            'url' => esc_url_raw((string)($raw['url'] ?? add_query_arg('game', $game_id, home_url('/game-play/')))),
        ];
    }

    public static function guard_gameplay(): void {
        if (!self::is_gameplay() || !is_user_logged_in()) return;
        $requested = isset($_GET['game']) ? absint($_GET['game']) : 0;
        if (!$requested) return;
        $active = self::active_session();
        if (!$active || (int)$active['game_id'] === $requested) return;

        if (!empty($_GET['tng_replace_session'])) {
            $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
            if ($nonce && wp_verify_nonce($nonce, self::SWITCH_ACTION . '_' . $requested)) {
                delete_user_meta(get_current_user_id(), self::META);
                return;
            }
        }

        $url = add_query_arg([
            'adventure_conflict' => 1,
            'requested_game' => $requested,
            'active_game' => (int)$active['game_id'],
        ], home_url('/games/'));
        wp_safe_redirect($url);
        exit;
    }

    private static function is_games_screen(): bool {
        if (is_admin()) return false;
        $path = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
        if (strpos($path, '/games/') !== false) return true;
        return function_exists('is_page') && is_page('games');
    }

    public static function footer(): void {
        if (is_admin() || !is_user_logged_in() || !self::is_games_screen()) return;
        $active = self::active_session();
        if (!$active) return;

        $requested = !empty($_GET['adventure_conflict']) ? absint($_GET['requested_game'] ?? 0) : 0;
        $requested_post = $requested ? get_post($requested) : null;
        $switch_url = '';
        if ($requested_post && $requested_post->post_status === 'publish') {
            $switch_url = wp_nonce_url(
                add_query_arg(['game'=>$requested,'tng_replace_session'=>1], home_url('/game-play/')),
                self::SWITCH_ACTION . '_' . $requested
            );
        }
        $resume_url = $active['url'] ?: add_query_arg('game', (int)$active['game_id'], home_url('/game-play/'));
        ?>
        <style id="tng-game-ownership-css">
        .tng-active-game-chip{margin:0 0 18px;padding:15px 17px;border:1px solid #cfe1d5;border-radius:16px;background:linear-gradient(135deg,#f2faf5,#fff);display:flex;align-items:center;gap:13px;color:#153e2e;box-shadow:0 8px 24px rgba(20,62,46,.06)}
        .tng-active-game-chip__icon{width:42px;height:42px;border-radius:13px;display:grid;place-items:center;flex:none;background:#f26722;color:#fff;font-size:17px}
        .tng-active-game-chip__copy{min-width:0;flex:1}.tng-active-game-chip__copy small{display:block;color:#e45a1b;font-size:9px;font-weight:900;letter-spacing:.12em;text-transform:uppercase}.tng-active-game-chip__copy strong{display:block;margin-top:2px;font-size:16px}.tng-active-game-chip__copy span{display:block;margin-top:2px;color:#68766e;font-size:11px}.tng-active-game-chip a{display:inline-flex;align-items:center;justify-content:center;min-height:40px;padding:9px 14px;border-radius:11px;background:#f26722;color:#fff!important;text-decoration:none;font-size:12px;font-weight:900;white-space:nowrap}
        .tng-adventure-conflict{position:fixed;inset:0;z-index:1000001;display:flex;align-items:center;justify-content:center;padding:20px;background:rgba(11,31,23,.58);backdrop-filter:blur(5px)}.tng-adventure-conflict__card{width:min(520px,100%);padding:25px;border-radius:22px;background:#fff;box-shadow:0 26px 80px rgba(10,35,25,.28);color:#153e2e}.tng-adventure-conflict__eyebrow{color:#f26722;font-size:10px;font-weight:900;letter-spacing:.13em;text-transform:uppercase}.tng-adventure-conflict h2{margin:7px 0 8px;font-size:28px;line-height:1.08;color:#153e2e}.tng-adventure-conflict p{margin:0;color:#66756d;line-height:1.5}.tng-adventure-conflict__current{margin-top:17px;padding:14px;border-radius:14px;background:#f3f8f4;border:1px solid #dce8df}.tng-adventure-conflict__current strong{display:block}.tng-adventure-conflict__current span{font-size:11px;color:#6b796f}.tng-adventure-conflict__actions{display:grid;grid-template-columns:1fr 1fr;gap:9px;margin-top:18px}.tng-adventure-conflict__actions a{display:flex;align-items:center;justify-content:center;min-height:46px;padding:11px 13px;border-radius:12px;text-decoration:none;font-size:12px;font-weight:900}.tng-adventure-conflict__resume{background:#f26722;color:#fff!important}.tng-adventure-conflict__switch{border:1px solid #d7e1da;color:#153e2e!important;background:#fff}.tng-adventure-conflict__cancel{display:block;margin-top:12px;text-align:center;color:#758279;text-decoration:none;font-size:11px;font-weight:800}@media(max-width:620px){.tng-active-game-chip{align-items:flex-start;flex-wrap:wrap}.tng-active-game-chip a{width:100%}.tng-adventure-conflict__actions{grid-template-columns:1fr}.tng-adventure-conflict h2{font-size:24px}}
        </style>
        <script id="tng-game-ownership-js">
        (()=>{
          const active=<?php echo wp_json_encode($active); ?>;
          const main=document.querySelector('.tng-games-directory,.tng-app-shell');
          if(main&&!document.querySelector('[data-tng-active-game-chip]')){
            const chip=document.createElement('div');chip.className='tng-active-game-chip';chip.setAttribute('data-tng-active-game-chip','1');
            const progress=active.total?`${active.completed}/${active.total} checkpoints · `:'';
            chip.innerHTML=`<div class="tng-active-game-chip__icon">▶</div><div class="tng-active-game-chip__copy"><small>${active.status==='paused'?'Paused adventure':'Active adventure'}</small><strong></strong><span>${progress}${active.status==='paused'?'Paused':'In progress'}</span></div><a href="<?php echo esc_url($resume_url); ?>">Resume adventure</a>`;
            chip.querySelector('strong').textContent=active.title||'Current adventure';
            const hero=main.querySelector('.tng-games-hero,.tng-section__heading');if(hero)hero.insertAdjacentElement('afterend',chip);else main.prepend(chip);
          }
        })();
        </script>
        <?php if ($requested && $switch_url): ?>
        <div class="tng-adventure-conflict" data-tng-adventure-conflict>
          <div class="tng-adventure-conflict__card">
            <div class="tng-adventure-conflict__eyebrow">Adventure already in progress</div>
            <h2>Keep exploring or switch games?</h2>
            <p>TN Game keeps one active adventure at a time so checkpoint progress, GPS state, and recovery always point to the right game.</p>
            <div class="tng-adventure-conflict__current"><strong><?php echo esc_html($active['title']); ?></strong><span><?php echo esc_html(($active['total'] ? $active['completed'].'/'.$active['total'].' checkpoints · ' : '') . ($active['status']==='paused'?'Paused':'In progress')); ?></span></div>
            <div class="tng-adventure-conflict__actions"><a class="tng-adventure-conflict__resume" href="<?php echo esc_url($resume_url); ?>">Resume current</a><a class="tng-adventure-conflict__switch" href="<?php echo esc_url($switch_url); ?>">Switch to <?php echo esc_html(get_the_title($requested)); ?></a></div>
            <a class="tng-adventure-conflict__cancel" href="<?php echo esc_url(home_url('/games/')); ?>">Stay on Games</a>
          </div>
        </div>
        <?php endif;
    }
}

TNG_Game_Session_Ownership::boot();
