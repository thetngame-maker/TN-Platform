<?php
/**
 * TN Game Gameplay Dock Context
 * Owns the persistent dock while the native /game-play/ runtime is open.
 * The normal Active Trip dock is hidden on gameplay pages so trip state can
 * never overwrite the current game context.
 */
if (!defined('ABSPATH')) exit;

final class TNG_Gameplay_Dock_Context {
    public static function boot(): void {
        add_action('wp_footer', array(__CLASS__, 'render'), 150);
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
        $title = $game_id ? get_the_title($game_id) : 'Current game';
        $detail_url = $game_id ? get_permalink($game_id) : home_url('/games/');
        ?>
        <div class="tng-game-dock" data-tng-game-dock>
            <div class="tng-game-dock__icon" aria-hidden="true">▶</div>
            <div class="tng-game-dock__copy">
                <div class="tng-game-dock__kicker">Active game</div>
                <div class="tng-game-dock__title"><?php echo esc_html($title); ?></div>
                <div class="tng-game-dock__meta">Loading checkpoint progress…</div>
            </div>
            <div class="tng-game-dock__progress" aria-hidden="true"><span></span></div>
            <button type="button" class="tng-game-dock__checkpoint">Current checkpoint</button>
            <a class="tng-game-dock__details" href="<?php echo esc_url($detail_url); ?>">Game details</a>
        </div>
        <script>
        (function(){
          const dock=document.querySelector('[data-tng-game-dock]');
          if(!dock)return;

          const clean=value=>String(value||'').replace(/\s+/g,' ').trim();
          const pctFromScore=score=>{
            const match=clean(score).match(/(\d+)\s*\/\s*(\d+)/);
            if(!match)return 0;
            const done=Number(match[1]),total=Number(match[2]);
            return total?Math.max(0,Math.min(100,Math.round(done/total*100))):0;
          };

          function sync(){
            const runtime=document.querySelector('.tng-game-runtime');
            if(!runtime)return false;

            const title=clean(runtime.querySelector('.tng-runtime-hero h1')?.textContent);
            const score=clean(runtime.querySelector('.tng-runtime-score strong')?.textContent);
            const current=clean(runtime.querySelector('.tng-runtime-progress h2')?.textContent);
            const runtimeBar=runtime.querySelector('.tng-runtime-progressbar span');
            const pct=runtimeBar?.style.width || (pctFromScore(score)+'%');

            if(title)dock.querySelector('.tng-game-dock__title').textContent=title;
            const meta=[];
            if(score)meta.push(score+' checkpoints');
            if(current)meta.push(current.replace(/^Next:\s*/i,'Next: '));
            dock.querySelector('.tng-game-dock__meta').textContent=meta.join(' · ')||'Game in progress';
            dock.querySelector('.tng-game-dock__progress span').style.width=pct;
            return true;
          }

          dock.querySelector('.tng-game-dock__checkpoint').addEventListener('click',function(){
            const runtime=document.querySelector('.tng-game-runtime');
            const target=runtime?.querySelector('.tng-runtime-stop.is-next')||runtime?.querySelector('.tng-runtime-progress');
            if(target)target.scrollIntoView({behavior:'smooth',block:'center'});
          });

          let tries=0;
          const timer=window.setInterval(function(){
            tries++;
            if(sync()||tries>25)window.clearInterval(timer);
          },120);
          sync();
          window.addEventListener('pageshow',sync);
          window.addEventListener('load',sync);
        })();
        </script>
        <?php
    }
}

TNG_Gameplay_Dock_Context::boot();
