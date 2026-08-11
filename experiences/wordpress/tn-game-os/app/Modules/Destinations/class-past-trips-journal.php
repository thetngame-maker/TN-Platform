<?php
namespace TNG_OS\Modules\Destinations;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;
use WP_REST_Response;

if (!defined('ABSPATH')) exit;

final class Past_Trips_Journal implements Module_Interface {
    private const META_RECAPS = '_tng_trip_recaps';

    public function id(): string { return 'past_trips_journal'; }

    public function register(Container $container): void {
        $container->set('past_trips_journal', $this);
        add_action('rest_api_init', [$this, 'routes']);
        add_shortcode('tng_past_trips', [$this, 'shortcode']);
        add_action('wp_footer', [$this, 'footer'], 150);
    }

    public function boot(Container $container): void {}

    public function routes(): void {
        register_rest_route('tng-os/v1', '/past-trips', [
            'methods' => 'GET',
            'callback' => [$this, 'get_trips'],
            'permission_callback' => static fn() => is_user_logged_in(),
        ]);
    }

    public function get_trips(): WP_REST_Response {
        $recaps = get_user_meta(get_current_user_id(), self::META_RECAPS, true);
        $recaps = is_array($recaps) ? array_values($recaps) : [];
        return new WP_REST_Response(['trips' => array_slice($recaps, 0, 100)], 200);
    }

    public function shortcode($atts = []): string {
        $atts = shortcode_atts(['title' => 'Past Trips'], $atts, 'tng_past_trips');
        return '<div class="tng-ptj-page" data-tng-past-trips-page><div class="tng-ptj-page-head"><div class="tng-ptj-kicker">Explorer Journal</div><h2>'.esc_html($atts['title']).'</h2><p>Revisit completed itineraries, stops, streaks, and Explorer milestones.</p></div><div class="tng-ptj-list" data-tng-ptj-page-list></div></div>';
    }

    public function footer(): void {
        if (is_admin()) return;
        $config = [
            'endpoint' => esc_url_raw(rest_url('tng-os/v1/past-trips')),
            'nonce' => wp_create_nonce('wp_rest'),
            'loggedIn' => is_user_logged_in(),
            'loginUrl' => wp_login_url(home_url('/')),
        ];
        ?>
        <style>
        .tng-atm-actions{display:grid!important;grid-template-columns:repeat(2,minmax(0,1fr))!important;gap:10px!important}.tng-atm-actions button{width:100%!important;min-height:46px!important;border-radius:12px!important;padding:12px 14px!important;line-height:1.2!important;white-space:normal!important}.tng-atm-actions .danger{grid-column:1/-1}.tng-ptj-trigger{position:fixed;right:22px;bottom:88px;z-index:999996;border:0;border-radius:999px;background:#17213f;color:#fff;padding:11px 15px;font-weight:900;box-shadow:0 12px 32px rgba(15,23,42,.25);cursor:pointer}.tng-ptj{position:fixed;inset:0;z-index:1000006;background:rgba(15,23,42,.62);display:none;justify-content:flex-end}.tng-ptj.is-open{display:flex}.tng-ptj-panel{width:min(620px,100%);height:100%;overflow:auto;background:#f7f8fc;padding:24px}.tng-ptj-head{position:relative;background:linear-gradient(135deg,#20294d,#7440a0);color:#fff;border-radius:22px;padding:26px;margin-bottom:16px}.tng-ptj-head h2{color:#fff;margin:4px 50px 5px 0}.tng-ptj-head p{margin:0;color:#eee8ff}.tng-ptj-kicker{font-size:10px;letter-spacing:.16em;text-transform:uppercase;font-weight:900;color:#d8c9ff}.tng-ptj-close{position:absolute;right:16px;top:16px;width:42px;height:42px;border:0;border-radius:50%;background:#fff;color:#17213f;font-size:24px;cursor:pointer}.tng-ptj-trip{background:#fff;border:1px solid #dfe4ee;border-radius:17px;padding:17px;margin-bottom:12px}.tng-ptj-trip-top{display:flex;justify-content:space-between;gap:12px}.tng-ptj-trip h3{margin:0;color:#17213f;font-size:19px}.tng-ptj-date{font-size:12px;color:#667085;white-space:nowrap}.tng-ptj-meta{display:flex;gap:8px;flex-wrap:wrap;margin:10px 0}.tng-ptj-pill{background:#f0e8ff;color:#6d3fc0;border-radius:999px;padding:6px 9px;font-size:11px;font-weight:900}.tng-ptj-stops{margin-top:10px;border-top:1px solid #edf0f5;padding-top:9px}.tng-ptj-stop{font-size:13px;color:#344054;padding:4px 0}.tng-ptj-empty{background:#fff;border:1px dashed #cfd6e3;border-radius:16px;padding:28px;text-align:center;color:#667085}.tng-ptj-page{max-width:1000px;margin:28px auto;padding:0 16px}.tng-ptj-page-head{background:linear-gradient(135deg,#20294d,#7440a0);color:#fff;border-radius:24px;padding:30px;margin-bottom:18px}.tng-ptj-page-head h2{color:#fff;margin:4px 0}.tng-ptj-page-head p{margin:4px 0 0;color:#eee8ff}@media(max-width:620px){.tng-atm-actions{grid-template-columns:1fr!important}.tng-atm-actions .danger{grid-column:auto}.tng-ptj-trigger{right:14px;bottom:78px}.tng-ptj-panel{padding:14px}.tng-ptj-trip-top{display:block}.tng-ptj-date{margin-top:4px}}
        </style>
        <button type="button" class="tng-ptj-trigger" data-tng-past-trips-open>Past trips</button>
        <div class="tng-ptj" data-tng-past-trips><div class="tng-ptj-panel"><div class="tng-ptj-head"><div class="tng-ptj-kicker">Explorer Journal</div><h2>Past Trips</h2><p>Your completed Tennessee adventures, saved in one place.</p><button class="tng-ptj-close" type="button">×</button></div><div class="tng-ptj-list" data-tng-ptj-list></div></div></div>
        <script>
        (function(){
          const cfg=<?php echo wp_json_encode($config); ?>,modal=document.querySelector('[data-tng-past-trips]');if(!modal)return;
          const esc=s=>{const d=document.createElement('div');d.textContent=String(s||'');return d.innerHTML};
          const dur=m=>Number(m)>=60?(Math.round(Number(m)/6)/10)+' hr':Number(m||0)+' min';
          const fmt=d=>{try{return new Date(String(d).replace(' ','T')).toLocaleDateString(undefined,{year:'numeric',month:'short',day:'numeric'})}catch(e){return String(d||'')}};
          function cards(trips){if(!cfg.loggedIn)return '<div class="tng-ptj-empty"><strong>Sign in to save and revisit completed trips.</strong><br><a href="'+esc(cfg.loginUrl)+'">Sign in</a></div>';if(!trips.length)return '<div class="tng-ptj-empty"><strong>No completed trips yet.</strong><br>Finish an Active Trip and it will appear here.</div>';return trips.map(t=>'<article class="tng-ptj-trip"><div class="tng-ptj-trip-top"><h3>'+esc(t.title||'Tennessee adventure')+'</h3><div class="tng-ptj-date">'+esc(fmt(t.date||t.day))+'</div></div><div class="tng-ptj-meta"><span class="tng-ptj-pill">'+Number(t.stop_count||0)+' stops</span><span class="tng-ptj-pill">'+esc(dur(t.minutes||0))+'</span><span class="tng-ptj-pill">'+Number(t.streak||1)+' day streak</span>'+(t.badge?'<span class="tng-ptj-pill">★ '+esc(t.badge)+'</span>':'')+'</div><div class="tng-ptj-stops">'+(Array.isArray(t.stops)?t.stops.map((s,i)=>'<div class="tng-ptj-stop">'+(i+1)+'. '+esc(s.title||'Trip stop')+'</div>').join(''):'')+'</div></article>').join('');}
          async function load(){let trips=[];if(cfg.loggedIn){try{const r=await fetch(cfg.endpoint,{credentials:'same-origin',headers:{'X-WP-Nonce':cfg.nonce}});if(r.ok){const j=await r.json();trips=Array.isArray(j.trips)?j.trips:[];}}catch(e){}}document.querySelectorAll('[data-tng-ptj-list],[data-tng-ptj-page-list]').forEach(el=>el.innerHTML=cards(trips));}
          const open=()=>{modal.classList.add('is-open');load();},close=()=>modal.classList.remove('is-open');
          document.querySelector('[data-tng-past-trips-open]').onclick=open;modal.querySelector('.tng-ptj-close').onclick=close;modal.onclick=e=>{if(e.target===modal)close();};window.addEventListener('tng:trip-completed',()=>setTimeout(load,900));load();
        })();
        </script>
        <?php
    }
}
