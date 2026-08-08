<?php
namespace TNG_OS\Modules\Destinations;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) exit;

final class Trip_Completion_Rewards implements Module_Interface {
    private const META_RECAPS = '_tng_trip_recaps';
    private const META_STREAK = '_tng_travel_day_streak';
    private const META_LAST_DAY = '_tng_last_travel_day';
    private const META_BADGES = '_tng_trip_badges';
    private const META_TOTAL = '_tng_completed_trips';

    public function id(): string { return 'trip_completion_rewards'; }

    public function register(Container $container): void {
        $container->set('trip_completion_rewards', $this);
        add_action('rest_api_init', [$this, 'routes']);
        add_action('wp_footer', [$this, 'footer'], 145);
    }

    public function boot(Container $container): void {}

    public function routes(): void {
        register_rest_route('tng-os/v1', '/trip-complete', [
            'methods' => 'POST',
            'callback' => [$this, 'complete_trip'],
            'permission_callback' => static fn() => is_user_logged_in(),
        ]);
    }

    public function complete_trip(WP_REST_Request $request): WP_REST_Response {
        $user_id = get_current_user_id();
        $raw_stops = $request->get_param('stops');
        $stops = [];
        if (is_array($raw_stops)) {
            foreach (array_slice($raw_stops, 0, 20) as $stop) {
                if (!is_array($stop)) continue;
                $id = absint($stop['id'] ?? 0);
                $title = sanitize_text_field(wp_unslash($stop['title'] ?? 'Trip stop'));
                if (!$id && !$title) continue;
                $stops[] = [
                    'id' => $id,
                    'title' => html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                    'minutes' => max(0, absint($stop['minutes'] ?? 0)),
                ];
            }
        }
        if (!$stops) return new WP_REST_Response(['message' => 'No completed stops were supplied.'], 400);

        $today = wp_date('Y-m-d');
        $last = (string) get_user_meta($user_id, self::META_LAST_DAY, true);
        $streak = max(0, (int) get_user_meta($user_id, self::META_STREAK, true));
        if ($last !== $today) {
            $yesterday = wp_date('Y-m-d', current_time('timestamp') - DAY_IN_SECONDS);
            $streak = ($last === $yesterday) ? $streak + 1 : 1;
            update_user_meta($user_id, self::META_STREAK, $streak);
            update_user_meta($user_id, self::META_LAST_DAY, $today);
        }

        $total = max(0, (int) get_user_meta($user_id, self::META_TOTAL, true)) + 1;
        update_user_meta($user_id, self::META_TOTAL, $total);

        $badges = get_user_meta($user_id, self::META_BADGES, true);
        $badges = is_array($badges) ? $badges : [];
        $new_badge = '';
        if (!in_array('day_explorer', $badges, true)) {
            $badges[] = 'day_explorer';
            $new_badge = 'Day Explorer';
        } elseif ($total >= 5 && !in_array('trip_planner', $badges, true)) {
            $badges[] = 'trip_planner';
            $new_badge = 'Trip Planner';
        }
        update_user_meta($user_id, self::META_BADGES, array_values(array_unique($badges)));

        $minutes = array_sum(array_column($stops, 'minutes'));
        $recap = [
            'id' => wp_generate_uuid4(),
            'type' => 'trip_completed',
            'title' => sanitize_text_field($request->get_param('title') ?: 'A Tennessee adventure'),
            'date' => current_time('mysql'),
            'day' => $today,
            'stops' => $stops,
            'stop_count' => count($stops),
            'minutes' => $minutes,
            'streak' => $streak,
            'badge' => $new_badge,
        ];
        $recaps = get_user_meta($user_id, self::META_RECAPS, true);
        $recaps = is_array($recaps) ? $recaps : [];
        array_unshift($recaps, $recap);
        update_user_meta($user_id, self::META_RECAPS, array_slice($recaps, 0, 100));

        do_action('tng_os_trip_completed', $user_id, $recap);
        do_action('tng_os_journal_event', $user_id, [
            'event' => 'trip_completed',
            'object' => 'trip:' . $recap['id'],
            'title' => $recap['title'],
            'meta' => $recap,
        ]);
        if ($new_badge) do_action('tng_os_badge_unlocked', $user_id, sanitize_title($new_badge), $new_badge);

        return new WP_REST_Response([
            'saved' => true,
            'recap' => $recap,
            'total_trips' => $total,
            'streak' => $streak,
            'new_badge' => $new_badge,
        ], 200);
    }

    public function footer(): void {
        if (is_admin()) return;
        $config = [
            'endpoint' => esc_url_raw(rest_url('tng-os/v1/trip-complete')),
            'nonce' => wp_create_nonce('wp_rest'),
            'loggedIn' => is_user_logged_in(),
        ];
        ?>
        <style>
        .tng-tcr{position:fixed;inset:0;z-index:1000005;background:rgba(15,23,42,.66);display:none;align-items:center;justify-content:center;padding:18px}.tng-tcr.is-open{display:flex}.tng-tcr-card{width:min(600px,100%);max-height:92vh;overflow:auto;background:#f8f9fc;border-radius:24px;box-shadow:0 26px 90px rgba(15,23,42,.4)}.tng-tcr-hero{padding:30px 26px;text-align:center;color:#fff;background:linear-gradient(135deg,#222b52,#8448c8);position:relative}.tng-tcr-close{position:absolute;right:18px;top:18px;border:0;width:42px;height:42px;border-radius:50%;background:#fff;color:#17213f;font-size:24px;cursor:pointer}.tng-tcr-check{width:82px;height:82px;border-radius:50%;display:grid;place-items:center;margin:8px auto 14px;background:#20ca78;border:10px solid rgba(255,255,255,.16);font-size:42px;font-weight:900}.tng-tcr-hero h2{color:#fff;margin:0;font-size:32px}.tng-tcr-hero p{margin:8px 0 0;color:#eee8ff}.tng-tcr-body{padding:20px}.tng-tcr-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}.tng-tcr-stat{background:#fff;border:1px solid #dfe4ee;border-radius:15px;padding:16px;text-align:center}.tng-tcr-stat strong{display:block;font-size:24px;color:#17213f}.tng-tcr-stat span{font-size:10px;letter-spacing:.12em;text-transform:uppercase;color:#667085;font-weight:900}.tng-tcr-badge{margin-top:14px;padding:16px;border-radius:16px;background:#f0e8ff;border:1px solid #d6c2ff;display:flex;gap:13px;align-items:center}.tng-tcr-badge-icon{width:46px;height:46px;border-radius:13px;background:#ffcf4d;display:grid;place-items:center;font-size:24px}.tng-tcr-badge strong{display:block;color:#17213f}.tng-tcr-badge small{color:#667085}.tng-tcr-stops{margin-top:16px}.tng-tcr-stop{display:flex;gap:10px;padding:10px 0;border-bottom:1px solid #e5e7eb;color:#17213f}.tng-tcr-stop-num{width:28px;height:28px;border-radius:50%;background:#eee7ff;color:#7042c1;display:grid;place-items:center;font-weight:900;flex:none}.tng-tcr-actions{display:flex;gap:10px;margin-top:18px}.tng-tcr-actions button{flex:1;border:0;border-radius:12px;padding:14px;font-weight:900;cursor:pointer;background:#7c4ce0;color:#fff}.tng-tcr-actions .secondary{background:#17213f}.tng-tcr-note{text-align:center;color:#667085;font-size:12px;margin-top:12px}@media(max-width:520px){.tng-tcr-stats{grid-template-columns:1fr}.tng-tcr-actions{flex-direction:column}.tng-tcr-hero h2{font-size:27px}}
        </style>
        <div class="tng-tcr" data-tng-trip-recap><div class="tng-tcr-card"><div class="tng-tcr-hero"><button class="tng-tcr-close" type="button">×</button><div class="tng-tcr-check">✓</div><div style="font-size:11px;letter-spacing:.16em;text-transform:uppercase;font-weight:900;color:#ffd85c">Adventure complete</div><h2>Trip completed</h2><p>You turned a plan into a real Tennessee adventure.</p></div><div class="tng-tcr-body"><div class="tng-tcr-stats"></div><div class="tng-tcr-badge" hidden></div><div class="tng-tcr-stops"></div><div class="tng-tcr-actions"><button type="button" data-tcr-share>Share recap</button><button type="button" class="secondary" data-tcr-done>Done</button></div><div class="tng-tcr-note"></div></div></div></div>
        <script>
        (function(){
          const cfg=<?php echo wp_json_encode($config); ?>,TRIP='tng_my_trip_v1';
          const modal=document.querySelector('[data-tng-trip-recap]');if(!modal)return;
          let recap=null;
          const legacyTrip=()=>{try{const x=JSON.parse(localStorage.getItem(TRIP)||'[]');return Array.isArray(x)?x:[]}catch(e){return[]}};
          const esc=s=>{const d=document.createElement('div');d.textContent=String(s||'');return d.innerHTML};
          const duration=m=>m>=60?(Math.round(m/6)/10)+' hr':m+' min';
          function activeTripStops(){return[...document.querySelectorAll('[data-trip-stop].is-complete')].map((node,i)=>({id:Number(node.dataset.tripStop)||0,title:node.querySelector('h3')?.textContent?.trim()||('Stop '+(i+1)),minutes:Number(node.dataset.minutes)||60}));}
          function localRecap(){const live=activeTripStops();const fallback=legacyTrip().map(x=>({id:Number(x.id)||0,title:String(x.title||'Trip stop'),minutes:Number(x.minutes)||60}));const stops=live.length?live:fallback;return{title:'My Tennessee adventure',stops,stop_count:stops.length,minutes:stops.reduce((n,x)=>n+x.minutes,0),streak:0,badge:''};}
          async function save(r){if(!cfg.loggedIn)return r;try{const res=await fetch(cfg.endpoint,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-WP-Nonce':cfg.nonce},body:JSON.stringify({title:r.title,stops:r.stops})});if(!res.ok)throw new Error('save');const data=await res.json();return data.recap||r;}catch(e){r.saveFailed=true;return r;}}
          function show(r){recap=r;modal.querySelector('.tng-tcr-stats').innerHTML='<div class="tng-tcr-stat"><strong>'+r.stop_count+'</strong><span>Stops explored</span></div><div class="tng-tcr-stat"><strong>'+duration(r.minutes||0)+'</strong><span>Adventure time</span></div><div class="tng-tcr-stat"><strong>'+(r.streak||1)+'</strong><span>Day streak</span></div>';const b=modal.querySelector('.tng-tcr-badge');if(r.badge){b.hidden=false;b.innerHTML='<div class="tng-tcr-badge-icon">★</div><div><small>Explorer badge unlocked</small><strong>'+esc(r.badge)+'</strong></div>';}else b.hidden=true;modal.querySelector('.tng-tcr-stops').innerHTML='<h3 style="margin:0 0 6px;color:#17213f">Your adventure</h3>'+r.stops.map((x,i)=>'<div class="tng-tcr-stop"><div class="tng-tcr-stop-num">'+(i+1)+'</div><div><strong>'+esc(x.title)+'</strong><div style="font-size:12px;color:#667085">Completed stop</div></div></div>').join('');modal.querySelector('.tng-tcr-note').textContent=r.saveFailed?'Your recap is available on this device, but could not be synced to your account.':cfg.loggedIn?'Saved to your Explorer story and travel-day streak.':'Sign in to save this recap to your Explorer story.';modal.classList.add('is-open');}
          async function completed(){const local=localRecap();if(!local.stops.length)return;const r=await save(local);show(r);}
          async function share(){if(!recap)return;const text='I completed '+recap.stop_count+' stops on '+recap.title+': '+recap.stops.map(x=>x.title).join(', ')+'.';if(navigator.share){try{await navigator.share({title:'My TN Game adventure',text,url:location.origin});return}catch(e){}}try{await navigator.clipboard.writeText(text+' '+location.origin);modal.querySelector('.tng-tcr-note').textContent='Trip recap copied to your clipboard.';}catch(e){}}
          const close=()=>modal.classList.remove('is-open');window.addEventListener('tng:trip-completed',completed);modal.querySelector('.tng-tcr-close').onclick=close;modal.querySelector('[data-tcr-done]').onclick=close;modal.querySelector('[data-tcr-share]').onclick=share;modal.onclick=e=>{if(e.target===modal)close();};
        })();
        </script>
        <?php
    }
}
