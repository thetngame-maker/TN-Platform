<?php
/**
 * Plugin Name: TN Game Developer Collapse
 * Description: Compact, persistent collapse/reopen controls for the admin-only gameplay developer drawer.
 * Version: 0.1.0
 * Author: The TN Game
 */
if (!defined('ABSPATH')) exit;

final class TNG_Game_Developer_Collapse {
    public static function boot(): void {
        add_action('wp_footer', [self::class, 'render'], 1350);
    }

    private static function is_game_play(): bool {
        return class_exists('TNG_OS\\Platform\\App_Router')
            && TNG_OS\Platform\App_Router::current_route() === 'game-play';
    }

    public static function render(): void {
        if (is_admin() || !is_user_logged_in() || !current_user_can('manage_options') || !self::is_game_play()) return;
        $game_id = absint($_GET['game'] ?? 0);
        if (!$game_id) return;
        ?>
        <style id="tng-developer-collapse-style">
            #tng-dev-gps.is-collapsed{display:none!important}
            .tng-dev-launcher{position:fixed;left:150px;bottom:22px;z-index:99991;display:none;align-items:center;gap:9px;max-width:min(360px,calc(100vw - 32px));min-height:46px;padding:8px 13px 8px 10px;border:1px solid rgba(255,255,255,.18);border-radius:999px;background:#14213d;color:#fff;box-shadow:0 12px 34px rgba(0,0,0,.25);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;cursor:pointer;text-align:left}
            .tng-dev-launcher.is-visible{display:flex}.tng-dev-launcher__icon{display:grid;place-items:center;flex:0 0 30px;width:30px;height:30px;border-radius:50%;background:#ef6423;font-size:15px}.tng-dev-launcher__copy{min-width:0;display:flex;flex-direction:column;line-height:1.15}.tng-dev-launcher__copy strong{font-size:12px;color:#fff}.tng-dev-launcher__copy small{max-width:235px;margin-top:3px;color:#aebbd0;font-size:10px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.tng-dev-launcher__chevron{margin-left:auto;color:#ff8a3d;font-weight:900;font-size:16px}
            #tng-dev-gps .tng-dev-gps__close{position:relative;font-size:0}#tng-dev-gps .tng-dev-gps__close:before{content:'−';font-size:22px;line-height:1}#tng-dev-gps .tng-dev-gps__close:hover{background:rgba(255,255,255,.2)}
            #tng-dev-gps .tng-dev-gps__head:after{content:'Alt+D';align-self:center;margin-left:auto;margin-right:-4px;padding:3px 6px;border-radius:6px;background:rgba(255,255,255,.08);color:#8292aa;font-size:9px;font-weight:800;letter-spacing:.04em}
            @media(max-width:700px){.tng-dev-launcher{left:10px;bottom:84px;max-width:calc(100vw - 20px)}#tng-dev-gps .tng-dev-gps__head:after{display:none}}
        </style>
        <button type="button" class="tng-dev-launcher" id="tng-dev-launcher" aria-controls="tng-dev-gps" aria-expanded="true" title="Open Developer Mode (Alt+D)">
            <span class="tng-dev-launcher__icon">🧪</span>
            <span class="tng-dev-launcher__copy"><strong>Developer Mode</strong><small data-tng-dev-launcher-status>Gameplay tools</small></span>
            <span class="tng-dev-launcher__chevron">⌃</span>
        </button>
        <script id="tng-developer-collapse-script">
        (()=>{
            const gameId=<?php echo (int) $game_id; ?>;
            const init=()=>{
                const panel=document.getElementById('tng-dev-gps');
                const launcher=document.getElementById('tng-dev-launcher');
                if(!panel||!launcher)return false;
                if(panel.dataset.tngCollapseWired==='1')return true;
                panel.dataset.tngCollapseWired='1';

                const key=`tng_dev_panel_collapsed_${gameId}`;
                const close=panel.querySelector('.tng-dev-gps__close');
                const status=launcher.querySelector('[data-tng-dev-launcher-status]');
                if(close){close.setAttribute('aria-label','Collapse developer tools');close.setAttribute('title','Collapse Developer Mode (Alt+D)');}

                const updateStatus=()=>{
                    const title=(panel.querySelector('.tng-dev-gps__head h3')?.textContent||'Gameplay tools').trim();
                    const routeCount=(panel.querySelector('[data-dev-route-count]')?.textContent||'').trim();
                    const guided=panel.querySelector('[data-dev-guided-toggle]')?.classList.contains('is-active');
                    const bits=[title];
                    if(routeCount)bits.push(routeCount);
                    if(guided)bits.push('guided run');
                    if(status)status.textContent=bits.join(' · ');
                };

                const setCollapsed=(collapsed,remember=true)=>{
                    panel.classList.toggle('is-collapsed',collapsed);
                    launcher.classList.toggle('is-visible',collapsed);
                    launcher.setAttribute('aria-expanded',collapsed?'false':'true');
                    updateStatus();
                    if(remember){
                        try{sessionStorage.setItem(key,collapsed?'1':'0');}catch(e){}
                    }
                };

                // Capture the original X control before the legacy listener removes the panel.
                if(close){
                    close.addEventListener('click',(event)=>{
                        event.preventDefault();
                        event.stopPropagation();
                        event.stopImmediatePropagation();
                        setCollapsed(true,true);
                    },true);
                }
                launcher.addEventListener('click',()=>setCollapsed(false,true));
                document.addEventListener('keydown',(event)=>{
                    if(event.altKey && String(event.key).toLowerCase()==='d'){
                        event.preventDefault();
                        setCollapsed(!panel.classList.contains('is-collapsed'),true);
                    }
                });

                let saved=false;
                try{saved=sessionStorage.getItem(key)==='1';}catch(e){}
                setCollapsed(saved,false);
                updateStatus();
                setTimeout(updateStatus,500);
                setTimeout(updateStatus,1200);
                return true;
            };

            if(init())return;
            let tries=0;
            const timer=setInterval(()=>{tries++;if(init()||tries>20)clearInterval(timer);},150);
        })();
        </script>
        <?php
    }
}

TNG_Game_Developer_Collapse::boot();
