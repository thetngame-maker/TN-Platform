<?php
/**
 * TN Game Gameplay Dock Context
 * Reuses the persistent Active Trip dock as an Active Game dock while the
 * native /game-play/ runtime is open. Presentation/navigation only.
 */
if (!defined('ABSPATH')) exit;

final class TNG_Gameplay_Dock_Context {
    public static function boot(): void {
        add_action('wp_footer', array(__CLASS__, 'render'), 145);
    }

    private static function is_gameplay_request(): bool {
        if (is_admin()) return false;
        $path = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
        if (strpos($path, '/game-play/') !== false) return true;
        return function_exists('is_page') && is_page('game-play');
    }

    public static function render(): void {
        if (!self::is_gameplay_request()) return;

        $game_id = isset($_GET['game']) ? absint($_GET['game']) : 0;
        $title = $game_id ? get_the_title($game_id) : '';
        $detail_url = $game_id ? get_permalink($game_id) : home_url('/games/');
        ?>
        <style>
        .tng-atm.tng-atm-game-context{display:flex!important;background:#fff;color:#13231a;border:1px solid #e3e8e1;box-shadow:0 16px 48px rgba(18,40,27,.14)}
        .tng-atm.tng-atm-game-context .tng-atm-kicker{color:#d94e0d}
        .tng-atm.tng-atm-game-context .tng-atm-title{color:#13231a}
        .tng-atm.tng-atm-game-context .tng-atm-meta{color:#6e7a73}
        .tng-atm.tng-atm-game-context .tng-atm-progress{background:#e7eee8}
        .tng-atm.tng-atm-game-context .tng-atm-progress span{background:linear-gradient(90deg,#f26722,#ff9a62)}
        .tng-atm.tng-atm-game-context .tng-atm-start{background:#fff;color:#13231a;border:1px solid #dfe5df}
        .tng-atm.tng-atm-game-context .tng-atm-open{background:#f26722;color:#fff}
        @media(max-width:620px){.tng-atm.tng-atm-game-context .tng-atm-open{display:inline-flex}.tng-atm.tng-atm-game-context{gap:8px;padding:10px 11px}.tng-atm.tng-atm-game-context .tng-atm-start,.tng-atm.tng-atm-game-context .tng-atm-open{padding:9px 10px;font-size:11px}}
        </style>
        <script>
        (function(){
          const titleFromServer=<?php echo wp_json_encode($title ?: 'Current game'); ?>;
          const detailUrl=<?php echo wp_json_encode($detail_url); ?>;

          function cleanText(value){return String(value||'').replace(/\s+/g,' ').trim();}

          function syncGameDock(){
            const bar=document.querySelector('[data-tng-active-bar]');
            const runtime=document.querySelector('.tng-game-runtime');
            if(!bar||!runtime)return false;

            bar.classList.add('tng-atm-game-context','is-visible');
            bar.setAttribute('data-tng-game-dock','');

            const kicker=bar.querySelector('.tng-atm-kicker');
            const title=bar.querySelector('.tng-atm-title');
            const meta=bar.querySelector('.tng-atm-meta');
            const progress=bar.querySelector('.tng-atm-progress span');
            const primary=bar.querySelector('.tng-atm-start');
            const secondary=bar.querySelector('.tng-atm-open');

            const runtimeTitle=cleanText(runtime.querySelector('.tng-runtime-hero h1')?.textContent);
            const score=cleanText(runtime.querySelector('.tng-runtime-score strong')?.textContent);
            const current=cleanText(runtime.querySelector('.tng-runtime-progress h2')?.textContent);
            const runtimeBar=runtime.querySelector('.tng-runtime-progressbar span');
            const width=runtimeBar ? (runtimeBar.style.width || window.getComputedStyle(runtimeBar).width) : '';

            if(kicker)kicker.textContent='Active game';
            if(title)title.textContent=runtimeTitle||titleFromServer;
            if(meta){
              const parts=[];
              if(score)parts.push(score+' checkpoints');
              if(current)parts.push(current);
              meta.textContent=parts.join(' · ')||'Game in progress';
            }
            if(progress&&runtimeBar){
              const pct=runtimeBar.style.width;
              if(pct)progress.style.width=pct;
            }

            if(primary){
              primary.textContent='Current checkpoint';
              primary.onclick=function(event){
                event.preventDefault();
                event.stopPropagation();
                const active=runtime.querySelector('.tng-runtime-stop.is-next')||runtime.querySelector('.tng-runtime-progress');
                if(active)active.scrollIntoView({behavior:'smooth',block:'center'});
              };
            }
            if(secondary){
              secondary.textContent='Game details';
              secondary.style.display='inline-flex';
              secondary.onclick=function(event){
                event.preventDefault();
                event.stopPropagation();
                window.location.href=detailUrl;
              };
            }

            const panel=document.querySelector('[data-tng-active-panel]');
            if(panel)panel.classList.remove('is-open');
            return true;
          }

          if(!syncGameDock()){
            let tries=0;
            const timer=window.setInterval(function(){
              tries++;
              if(syncGameDock()||tries>20)window.clearInterval(timer);
            },150);
          }

          window.addEventListener('pageshow',syncGameDock);
        })();
        </script>
        <?php
    }
}

TNG_Gameplay_Dock_Context::boot();
