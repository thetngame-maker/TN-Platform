<?php
/**
 * TN Game Player State Polish
 * Makes New / Active / Paused / Completed adventure states explicit across Games and game detail screens.
 */
if (!defined('ABSPATH')) exit;

final class TNG_Game_Player_State_Polish {
    private const ACTIVE_META = '_tng_active_game_session';

    public static function boot(): void {
        add_action('wp_footer', [__CLASS__, 'footer'], 176);
        add_filter('body_class', [__CLASS__, 'body_class'], 1200);
    }

    private static function active_session(): array {
        if (!is_user_logged_in()) return [];
        $raw = get_user_meta(get_current_user_id(), self::ACTIVE_META, true);
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

    private static function current_game_id(): int {
        if (!is_singular()) return 0;
        $id = get_queried_object_id();
        if (!$id) return 0;
        if (class_exists('TNG_Games_UI') && TNG_Games_UI::is_game($id)) return $id;
        return 0;
    }

    private static function progress(int $game_id): array {
        $raw = get_post_meta($game_id, 'tng_game_checkpoints', true);
        $total = is_array($raw) ? count(array_filter($raw, 'is_array')) : 0;
        if ($total < 1) $total = absint(get_post_meta($game_id, 'checkpoint_count', true));
        $done = 0;
        $complete = false;
        if (is_user_logged_in()) {
            $saved = get_user_meta(get_current_user_id(), '_tng_game_progress_' . $game_id, true);
            if (is_array($saved)) $done = count(array_unique(array_map('absint', $saved)));
            $games = get_user_meta(get_current_user_id(), '_tng_completed_games', true);
            if (is_array($games)) $complete = in_array($game_id, array_map('absint', $games), true);
        }
        if ($total > 0 && $done > $total) $done = $total;
        if ($total > 0 && $done >= $total) $complete = true;
        return ['done'=>$done,'total'=>$total,'complete'=>$complete];
    }

    public static function body_class(array $classes): array {
        $id = self::current_game_id();
        if (!$id) return $classes;
        $active = self::active_session();
        $progress = self::progress($id);
        if ($progress['complete']) $classes[] = 'tng-player-state-complete';
        elseif ($active && (int)$active['game_id'] === $id) $classes[] = $active['status'] === 'paused' ? 'tng-player-state-paused' : 'tng-player-state-active';
        else $classes[] = $progress['done'] > 0 ? 'tng-player-state-progress' : 'tng-player-state-new';
        return array_values(array_unique($classes));
    }

    private static function is_games_screen(): bool {
        if (is_admin()) return false;
        $path = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
        if (strpos($path, '/games/') !== false) return true;
        return function_exists('is_page') && is_page('games');
    }

    public static function footer(): void {
        if (is_admin()) return;
        $active = self::active_session();
        $detail_id = self::current_game_id();
        if (!$active && !$detail_id && !self::is_games_screen()) return;
        $detail_progress = $detail_id ? self::progress($detail_id) : [];
        $detail_state = 'new';
        if ($detail_id) {
            if (!empty($detail_progress['complete'])) $detail_state = 'complete';
            elseif ($active && (int)$active['game_id'] === $detail_id) $detail_state = $active['status'] === 'paused' ? 'paused' : 'active';
            elseif (!empty($detail_progress['done'])) $detail_state = 'progress';
        }
        ?>
        <style id="tng-player-state-polish-css">
        .tng-games-card.is-session-active{position:relative;border-color:#f26722!important;box-shadow:0 14px 34px rgba(242,103,34,.14)!important}
        .tng-games-card.is-session-active::after{content:"ACTIVE ADVENTURE";position:absolute;top:12px;right:12px;z-index:4;padding:6px 8px;border-radius:999px;background:#f26722;color:#fff;font-size:8px;font-weight:900;letter-spacing:.1em}
        .tng-games-card.is-session-paused::after{content:"PAUSED ADVENTURE";background:#163e2e}
        .tng-games-card.is-session-active .tng-games-card__actions .tng-ui-button{background:#f26722!important;color:#fff!important}
        .tng-player-state-banner{margin:0 0 18px;padding:14px 16px;border:1px solid #cfe2d5;border-radius:16px;background:linear-gradient(135deg,#eef8f1,#fff);display:flex;align-items:center;gap:12px;color:#153e2e}
        .tng-player-state-banner__icon{display:grid;place-items:center;width:42px;height:42px;flex:none;border-radius:13px;background:#f26722;color:#fff;font-size:18px;font-weight:900}
        .tng-player-state-banner__copy{min-width:0;flex:1}.tng-player-state-banner__copy small{display:block;color:#e45618;font-size:9px;font-weight:900;letter-spacing:.12em;text-transform:uppercase}.tng-player-state-banner__copy strong{display:block;margin-top:2px;font-size:15px}.tng-player-state-banner__copy span{display:block;margin-top:2px;color:#6b786f;font-size:11px}
        .tng-player-state-banner.is-complete{border-color:#b9dcc5;background:linear-gradient(135deg,#eaf8ef,#fff)}.tng-player-state-banner.is-complete .tng-player-state-banner__icon{background:#257047}
        .tng-player-state-banner.is-paused{border-color:#ead9c5;background:linear-gradient(135deg,#fff8ef,#fff)}.tng-player-state-banner.is-paused .tng-player-state-banner__icon{background:#163e2e}
        .tng-game-detail.is-state-active .tng-game-detail-actions .tng-ui-button:first-child,
        .tng-game-detail.is-state-paused .tng-game-detail-actions .tng-ui-button:first-child{box-shadow:0 10px 24px rgba(242,103,34,.22)}
        .tng-game-detail.is-state-complete .tng-game-detail-actions .tng-ui-button:first-child{background:#257047!important}
        @media(max-width:620px){.tng-player-state-banner{align-items:flex-start}.tng-games-card.is-session-active::after{top:9px;right:9px;font-size:7px}}
        </style>
        <script id="tng-player-state-polish-js">
        (()=>{
          const active=<?php echo wp_json_encode($active ?: null); ?>;
          const detail={id:<?php echo (int)$detail_id; ?>,state:<?php echo wp_json_encode($detail_state); ?>,done:<?php echo (int)($detail_progress['done'] ?? 0); ?>,total:<?php echo (int)($detail_progress['total'] ?? 0); ?>};
          const clean=v=>String(v||'').replace(/\s+/g,' ').trim();

          const labelFor=state=>state==='complete'?'Review Adventure':((state==='active'||state==='paused'||state==='progress')?'Resume Adventure':'Start Adventure');
          const kickerFor=state=>state==='complete'?'Adventure complete':(state==='paused'?'Adventure paused':((state==='active'||state==='progress')?'Adventure in progress':'Ready to explore'));

          function polishDetail(){
            const main=document.querySelector('.tng-game-detail');
            if(!main||!detail.id)return;
            main.classList.add('is-state-'+detail.state);
            main.querySelectorAll('.tng-game-detail-actions .tng-ui-button:first-child,.tng-game-start-card > .tng-ui-button:first-of-type').forEach(a=>{
              const prefix=a.closest('.tng-game-detail-actions')?'▶ ':'';
              a.textContent=prefix+labelFor(detail.state);
              if((detail.state==='active'||detail.state==='paused')&&active?.url)a.href=active.url;
            });
            const eyebrow=main.querySelector('.tng-game-start-card .tng-eyebrow');
            const title=main.querySelector('.tng-game-start-card h2');
            const copy=main.querySelector('.tng-game-start-card p');
            if(eyebrow)eyebrow.textContent=kickerFor(detail.state);
            if(title)title.textContent=detail.state==='complete'?'Adventure complete.':(detail.state==='paused'?'Ready when you are.':((detail.state==='active'||detail.state==='progress')?'Pick up where you left off.':'Start the adventure.'));
            if(copy){
              if(detail.state==='complete')copy.textContent='Your completion and Explorer XP are saved to your profile.';
              else if(detail.state==='paused')copy.textContent='Your checkpoint progress is safe. Resume whenever you are ready.';
              else if(detail.state==='active'||detail.state==='progress')copy.textContent='Your progress is saved. Resume from your next checkpoint.';
            }
            const hero=main.querySelector('.tng-game-detail-hero');
            if(hero&&!main.querySelector('[data-tng-player-state-banner]')&&detail.state!=='new'){
              const box=document.createElement('div');box.className='tng-player-state-banner is-'+detail.state;box.setAttribute('data-tng-player-state-banner','1');
              const icon=detail.state==='complete'?'✓':(detail.state==='paused'?'Ⅱ':'▶');
              const progress=detail.total?`${detail.done}/${detail.total} checkpoints`:'Progress saved';
              box.innerHTML=`<div class="tng-player-state-banner__icon">${icon}</div><div class="tng-player-state-banner__copy"><small>${kickerFor(detail.state)}</small><strong>${labelFor(detail.state)}</strong><span>${progress}</span></div>`;
              hero.insertAdjacentElement('afterend',box);
            }
          }

          function polishDirectory(){
            const directory=document.querySelector('.tng-games-directory');if(!directory)return;
            directory.querySelectorAll('.tng-games-card').forEach(card=>{
              const title=clean(card.querySelector('h3')?.textContent);
              const primary=card.querySelector('.tng-games-card__actions .tng-ui-button');
              if(card.classList.contains('is-new')&&primary)primary.textContent='Start Adventure';
              if(card.classList.contains('is-complete')&&primary)primary.textContent='Review Adventure';
              if((card.classList.contains('is-progress')||card.classList.contains('is-continue'))&&primary)primary.textContent='Resume Adventure';
              if(active&&title&&title===clean(active.title)){
                card.classList.add('is-session-active');
                if(active.status==='paused')card.classList.add('is-session-paused');
                if(primary){primary.textContent='Resume Adventure';primary.href=active.url||primary.href;}
                const mediaStatus=card.querySelector('.tng-games-card__media > span');if(mediaStatus)mediaStatus.textContent=active.status==='paused'?'Paused':'In progress';
              }
            });
          }
          polishDetail();polishDirectory();
        })();
        </script>
        <?php
    }
}

TNG_Game_Player_State_Polish::boot();
