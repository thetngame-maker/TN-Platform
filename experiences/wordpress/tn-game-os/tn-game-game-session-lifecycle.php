<?php
/**
 * TN Game Game Session Lifecycle
 * Persists active gameplay sessions across page changes/browser returns and adds pause/resume recovery.
 */
if (!defined('ABSPATH')) exit;

final class TNG_Game_Session_Lifecycle {
    private const ACTIVE_META = '_tng_active_game_session';
    private const NONCE = 'tng_game_session_lifecycle';

    public static function boot(): void {
        add_action('wp_ajax_tng_game_session_save', [__CLASS__, 'ajax_save']);
        add_action('wp_ajax_tng_game_session_clear', [__CLASS__, 'ajax_clear']);
        add_action('wp_footer', [__CLASS__, 'footer'], 165);
    }

    private static function is_gameplay(): bool {
        if (is_admin()) return false;
        $path = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
        if (strpos($path, '/game-play/') !== false) return true;
        return function_exists('is_page') && is_page('game-play');
    }

    private static function current_game_id(): int {
        if (!self::is_gameplay()) return 0;
        return isset($_GET['game']) ? absint($_GET['game']) : 0;
    }

    private static function checkpoint_total(int $game_id): int {
        if (!$game_id) return 0;
        $saved = get_post_meta($game_id, 'tng_game_checkpoints', true);
        if (is_array($saved) && $saved) return count($saved);
        $count = absint(get_post_meta($game_id, 'checkpoint_count', true));
        return max(1, min(30, $count ?: 1));
    }

    private static function completed_count(int $game_id): int {
        if (!$game_id || !is_user_logged_in()) return 0;
        $value = get_user_meta(get_current_user_id(), '_tng_game_progress_' . $game_id, true);
        if (!is_array($value)) return 0;
        return count(array_unique(array_map('absint', $value)));
    }

    private static function normalize_session(array $raw): array {
        $game_id = absint($raw['game_id'] ?? 0);
        $status = sanitize_key((string)($raw['status'] ?? 'active'));
        if (!in_array($status, ['active','paused','completed'], true)) $status = 'active';
        return [
            'game_id' => $game_id,
            'title' => sanitize_text_field((string)($raw['title'] ?? ($game_id ? get_the_title($game_id) : 'Adventure'))),
            'status' => $status,
            'started_at' => absint($raw['started_at'] ?? time()),
            'updated_at' => absint($raw['updated_at'] ?? time()),
            'paused_at' => absint($raw['paused_at'] ?? 0),
            'paused_seconds' => absint($raw['paused_seconds'] ?? 0),
            'completed' => absint($raw['completed'] ?? 0),
            'total' => absint($raw['total'] ?? 0),
            'current_index' => absint($raw['current_index'] ?? 0),
            'url' => esc_url_raw((string)($raw['url'] ?? ($game_id ? add_query_arg('game', $game_id, home_url('/game-play/')) : ''))),
        ];
    }

    private static function saved_session(): array {
        if (!is_user_logged_in()) return [];
        $raw = get_user_meta(get_current_user_id(), self::ACTIVE_META, true);
        return is_array($raw) ? self::normalize_session($raw) : [];
    }

    public static function ajax_save(): void {
        if (!is_user_logged_in()) wp_send_json_error(['message'=>'Sign in required.'], 401);
        check_ajax_referer(self::NONCE, 'nonce');
        $game_id = absint($_POST['game_id'] ?? 0);
        if (!$game_id || !get_post($game_id)) wp_send_json_error(['message'=>'Invalid game.'], 400);

        $existing = self::saved_session();
        $now = time();
        $status = sanitize_key(wp_unslash($_POST['status'] ?? 'active'));
        if (!in_array($status, ['active','paused','completed'], true)) $status = 'active';
        $started = absint($_POST['started_at'] ?? ($existing['started_at'] ?? $now));
        if (!$started) $started = $now;
        $paused_seconds = absint($_POST['paused_seconds'] ?? ($existing['paused_seconds'] ?? 0));
        $paused_at = absint($_POST['paused_at'] ?? ($existing['paused_at'] ?? 0));

        $total = self::checkpoint_total($game_id);
        $completed = self::completed_count($game_id);
        if ($total > 0 && $completed >= $total) $status = 'completed';

        $session = self::normalize_session([
            'game_id'=>$game_id,
            'title'=>get_the_title($game_id),
            'status'=>$status,
            'started_at'=>$started,
            'updated_at'=>$now,
            'paused_at'=>$paused_at,
            'paused_seconds'=>$paused_seconds,
            'completed'=>$completed,
            'total'=>$total,
            'current_index'=>min($completed, max(0, $total - 1)),
            'url'=>add_query_arg('game', $game_id, home_url('/game-play/')),
        ]);
        update_user_meta(get_current_user_id(), self::ACTIVE_META, $session);
        wp_send_json_success($session);
    }

    public static function ajax_clear(): void {
        if (!is_user_logged_in()) wp_send_json_error([], 401);
        check_ajax_referer(self::NONCE, 'nonce');
        delete_user_meta(get_current_user_id(), self::ACTIVE_META);
        wp_send_json_success();
    }

    public static function footer(): void {
        if (is_admin() || !is_user_logged_in()) return;
        $game_id = self::current_game_id();
        $saved = self::saved_session();
        $bootstrap = null;
        if ($game_id) {
            $total = self::checkpoint_total($game_id);
            $done = self::completed_count($game_id);
            $existing_for_game = ($saved && (int)($saved['game_id'] ?? 0) === $game_id) ? $saved : [];
            $bootstrap = self::normalize_session(array_merge($existing_for_game, [
                'game_id'=>$game_id,
                'title'=>get_the_title($game_id),
                'completed'=>$done,
                'total'=>$total,
                'current_index'=>min($done, max(0, $total - 1)),
                'url'=>add_query_arg('game', $game_id, home_url('/game-play/')),
            ]));
            if ($total > 0 && $done >= $total) $bootstrap['status'] = 'completed';
        }
        $nonce = wp_create_nonce(self::NONCE);
        ?>
        <style id="tng-game-session-css">
        .tng-session-resume{position:fixed;left:50%;bottom:88px;transform:translateX(-50%);z-index:999996;width:min(680px,calc(100% - 24px));background:#fff;border:1px solid #dfe7e1;border-radius:18px;box-shadow:0 18px 50px rgba(10,45,31,.18);padding:11px 12px;display:flex;align-items:center;gap:12px;color:#153e2e}.tng-session-resume[hidden]{display:none}.tng-session-resume__icon{width:42px;height:42px;border-radius:13px;background:#f16022;color:#fff;display:grid;place-items:center;font-size:18px;flex:none}.tng-session-resume__copy{min-width:0;flex:1}.tng-session-resume__kicker{font-size:9px;font-weight:900;letter-spacing:.12em;color:#f16022;text-transform:uppercase}.tng-session-resume__title{font-size:14px;font-weight:900;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.tng-session-resume__meta{font-size:11px;color:#718077;margin-top:2px}.tng-session-resume__btn{background:#f16022;color:#fff!important;border-radius:11px;padding:10px 14px;text-decoration:none;font-size:12px;font-weight:900;white-space:nowrap}.tng-game-session-pause{border:1px solid #dfe7e1;background:#fff;color:#153e2e;border-radius:10px;padding:9px 11px;font-size:12px;font-weight:900;cursor:pointer}.tng-game-session-pause.is-paused{background:#f16022;color:#fff;border-color:#f16022}.tng-game-session-paused .tng-game-runtime .tng-runtime-stop form,.tng-game-session-paused .tng-game-runtime .tng-runtime-location-button{pointer-events:none;opacity:.48}.tng-session-paused-banner{display:none;position:sticky;top:10px;z-index:50;margin:0 auto 14px;width:min(900px,calc(100% - 24px));background:#fff7ed;border:1px solid #fed7aa;border-radius:14px;padding:12px 14px;color:#9a3412;font-weight:800;box-shadow:0 8px 28px rgba(124,45,18,.08)}.tng-game-session-paused .tng-session-paused-banner{display:flex;align-items:center;justify-content:space-between;gap:10px}.tng-session-paused-banner button{background:#f16022;color:#fff;border:0;border-radius:9px;padding:8px 12px;font-weight:900;cursor:pointer}@media(max-width:620px){.tng-session-resume{bottom:92px}.tng-session-resume__meta{display:none}.tng-session-resume__btn{padding:9px 11px}}
        </style>
        <div class="tng-session-paused-banner" data-tng-session-paused-banner><span>Adventure paused. Resume when you're ready to keep playing.</span><button type="button">Resume</button></div>
        <div class="tng-session-resume" data-tng-session-resume hidden>
          <div class="tng-session-resume__icon">▶</div>
          <div class="tng-session-resume__copy"><div class="tng-session-resume__kicker">Resume adventure</div><div class="tng-session-resume__title"></div><div class="tng-session-resume__meta"></div></div>
          <a class="tng-session-resume__btn" href="#">Resume</a>
        </div>
        <script id="tng-game-session-js">
        (()=>{
          const CFG={ajax:<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>,nonce:<?php echo wp_json_encode($nonce); ?>,gameplay:<?php echo $game_id ? 'true':'false'; ?>,bootstrap:<?php echo wp_json_encode($bootstrap); ?>,saved:<?php echo wp_json_encode($saved); ?>};
          const KEY='tng_game_session_v1';
          const readLocal=()=>{try{return JSON.parse(localStorage.getItem(KEY)||'null')}catch(e){return null}};
          const writeLocal=s=>{try{localStorage.setItem(KEY,JSON.stringify(s))}catch(e){}};
          const clearLocal=()=>{try{localStorage.removeItem(KEY)}catch(e){}};
          const now=()=>Math.floor(Date.now()/1000);
          let state=CFG.bootstrap||readLocal()||CFG.saved||null;
          if(CFG.bootstrap){state=Object.assign({status:'active',started_at:now(),paused_seconds:0,paused_at:0},state);if(!state.started_at)state.started_at=now();writeLocal(state);}
          const post=(action,payload={},beacon=false)=>{const fd=new FormData();fd.append('action',action);fd.append('nonce',CFG.nonce);Object.entries(payload).forEach(([k,v])=>fd.append(k,String(v??'')));if(beacon&&navigator.sendBeacon){navigator.sendBeacon(CFG.ajax,fd);return Promise.resolve();}return fetch(CFG.ajax,{method:'POST',body:fd,credentials:'same-origin'}).then(r=>r.json()).catch(()=>null)};
          const elapsed=()=>{if(!state||!state.started_at)return 0;let end=now(),paused=Number(state.paused_seconds||0);if(state.status==='paused'&&state.paused_at)paused+=Math.max(0,end-Number(state.paused_at));return Math.max(0,end-Number(state.started_at)-paused)};
          const human=secs=>{secs=Math.max(0,Math.floor(secs));const h=Math.floor(secs/3600),m=Math.floor((secs%3600)/60);return h?`${h} hr ${m} min`:`${m} min`};
          const syncServer=(beacon=false)=>{if(!state||!state.game_id||state.status==='completed')return;state.updated_at=now();writeLocal(state);return post('tng_game_session_save',state,beacon)};
          const setPaused=paused=>{if(!state)return;if(paused){if(state.status!=='paused'){state.status='paused';state.paused_at=now();}}else{if(state.status==='paused'){state.paused_seconds=Number(state.paused_seconds||0)+Math.max(0,now()-Number(state.paused_at||now()));state.paused_at=0;}state.status='active';}writeLocal(state);applyState();syncServer(false)};
          const applyState=()=>{if(!state)return;document.body.classList.toggle('tng-game-session-paused',CFG.gameplay&&state.status==='paused');const pause=document.querySelector('[data-tng-game-session-pause]');if(pause){pause.textContent=state.status==='paused'?'Resume':'Pause';pause.classList.toggle('is-paused',state.status==='paused');}const dock=document.querySelector('[data-tng-game-dock] .tng-game-dock__meta');if(dock){const base=dock.dataset.sessionBase||dock.textContent||'Game in progress';dock.dataset.sessionBase=base.replace(/ · Session .*/, '');dock.textContent=dock.dataset.sessionBase+' · Session '+human(elapsed())+(state.status==='paused'?' · Paused':'');}};
          const enhanceDock=()=>{if(!CFG.gameplay)return;const dock=document.querySelector('[data-tng-game-dock]');if(!dock||dock.querySelector('[data-tng-game-session-pause]'))return;const details=dock.querySelector('.tng-game-dock__details');const btn=document.createElement('button');btn.type='button';btn.className='tng-game-session-pause';btn.setAttribute('data-tng-game-session-pause','1');btn.onclick=()=>setPaused(state?.status!=='paused');if(details)details.before(btn);else dock.append(btn);applyState();};
          const recovery=()=>{const box=document.querySelector('[data-tng-session-resume]');if(!box)return;const s=CFG.gameplay?null:(readLocal()||CFG.saved);if(!s||!s.game_id||s.status==='completed'){box.hidden=true;return;}box.hidden=false;box.querySelector('.tng-session-resume__title').textContent=s.title||'TN Game adventure';const done=Number(s.completed||0),total=Number(s.total||0);box.querySelector('.tng-session-resume__meta').textContent=(total?`${done}/${total} checkpoints · `:'')+(s.status==='paused'?'Paused':'In progress');box.querySelector('a').href=s.url||('/game-play/?game='+s.game_id);};
          document.querySelector('[data-tng-session-paused-banner] button')?.addEventListener('click',()=>setPaused(false));
          if(CFG.gameplay&&state){
            if(Number(state.total||0)>0&&Number(state.completed||0)>=Number(state.total||0)){state.status='completed';writeLocal(state);post('tng_game_session_save',state,false);setTimeout(()=>{clearLocal()},1800);}else{syncServer(false);}
            enhanceDock();let tries=0;const dockTimer=setInterval(()=>{tries++;enhanceDock();applyState();if(tries>30||document.querySelector('[data-tng-game-session-pause]'))clearInterval(dockTimer)},160);
            setInterval(()=>{applyState();if(state&&state.status==='active')syncServer(false)},30000);
            document.addEventListener('visibilitychange',()=>{if(document.hidden)syncServer(true);else applyState()});
            window.addEventListener('pagehide',()=>syncServer(true));
          }else recovery();
          window.addEventListener('storage',recovery);setInterval(()=>{if(CFG.gameplay)applyState();},15000);
        })();
        </script>
        <?php
    }
}

TNG_Game_Session_Lifecycle::boot();
