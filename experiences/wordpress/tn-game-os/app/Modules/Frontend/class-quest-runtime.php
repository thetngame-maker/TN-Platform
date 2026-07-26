<?php
namespace TNG_OS\Modules\Frontend;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class Quest_Runtime implements Module_Interface {
    private const QUEST_TYPE = 'tng_quest';
    private Container $container;

    public function id(): string { return 'quest_runtime'; }

    public function register(Container $container): void {
        $this->container = $container;
        $container->set('quest_runtime', $this);
        add_shortcode('tng_quest_runtime', [$this, 'shortcode']);
        add_filter('the_content', [$this, 'append_runtime']);
    }

    public function boot(Container $container): void {}

    public function append_runtime(string $content): string {
        if (!is_singular(self::QUEST_TYPE) || !in_the_loop() || !is_main_query()) return $content;
        return $content . $this->render((int)get_the_ID());
    }

    public function shortcode(array $atts = []): string {
        $atts = shortcode_atts(['id' => 0], $atts, 'tng_quest_runtime');
        $id = absint($atts['id']);
        if (!$id && is_singular(self::QUEST_TYPE)) $id = (int)get_the_ID();
        return $this->render($id);
    }

    private function render(int $quest_id): string {
        $quest = $quest_id ? get_post($quest_id) : null;
        if (!$quest || $quest->post_type !== self::QUEST_TYPE) return '';
        if ($quest->post_status !== 'publish' && !current_user_can('edit_post', $quest_id)) return '';

        $entities = $this->entities();
        $ids = (array)get_post_meta($quest_id, '_tng_quest_entity_ids', true);
        $notes = (array)get_post_meta($quest_id, '_tng_quest_checkpoint_instructions', true);
        $mechanics = (array)get_post_meta($quest_id, '_tng_game_checkpoint_mechanics', true);
        $xp = absint(get_post_meta($quest_id, '_tng_quest_xp', true) ?: get_post_meta($quest_id, '_tng_quest_estimated_xp', true));
        $minutes = absint(get_post_meta($quest_id, '_tng_quest_estimated_minutes', true));
        $summary = (string)get_post_meta($quest_id, '_tng_quest_summary', true);
        $mode = sanitize_key((string)get_post_meta($quest_id, '_tng_game_completion_mode', true)) ?: 'all';
        $configured = absint(get_post_meta($quest_id, '_tng_game_completion_count', true));
        $stops = [];

        foreach ($ids as $entity_id) {
            $key = (string)$entity_id;
            if (!isset($entities[$key])) continue;
            $entity = $entities[$key];
            $m = is_array($mechanics[$key] ?? null) ? $mechanics[$key] : [];
            $coordinates = $this->coordinates((array)($entity['payload'] ?? []));
            $stops[] = [
                'id' => $key,
                'title' => (string)($entity['title'] ?? 'Checkpoint'),
                'type' => sanitize_key((string)($m['type'] ?? 'manual')),
                'instruction' => (string)($m['challenge'] ?? $notes[$key] ?? ''),
                'arrival' => (string)($m['arrival_message'] ?? ''),
                'hint' => (string)($m['hint'] ?? ''),
                'xp' => absint($m['xp'] ?? 25),
                'radius' => max(5, absint($m['radius'] ?? 30)),
                'lat' => $coordinates[0] ?? null,
                'lng' => $coordinates[1] ?? null,
            ];
        }

        $required = $mode === 'count' ? min(count($stops), max(1, $configured)) : count($stops);
        $payload = [
            'questId' => $quest_id,
            'title' => get_the_title($quest_id),
            'required' => $required,
            'rewardXp' => $xp,
            'stops' => $stops,
            'loggedIn' => is_user_logged_in(),
            'adminOverride' => current_user_can('manage_options'),
            'progressUrl' => rest_url('tng-game/v1/quest-progress/' . $quest_id),
            'restNonce' => is_user_logged_in() ? wp_create_nonce('wp_rest') : '',
        ];

        ob_start(); ?>
        <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIINfQ3ynA+zW0R6xMZ3eYFJt8XbF0xQ8oM=" crossorigin="anonymous">
        <section class="tng-runtime" data-quest-id="<?php echo esc_attr((string)$quest_id); ?>">
            <style>
                .tng-runtime{--ink:#18213d;--muted:#667085;--accent:#7f56d9;--success:#12b76a;--soft:#f4f0ff;max-width:920px;margin:28px auto;font-family:inherit;color:var(--ink)}
                .tng-runtime *{box-sizing:border-box}.tng-runtime button{font:inherit}.tng-runtime-hero{background:linear-gradient(135deg,#18213d,#633b78);color:#fff;border-radius:26px;padding:32px;box-shadow:0 18px 45px rgba(24,33,61,.18)}
                .tng-runtime-kicker{text-transform:uppercase;letter-spacing:.13em;color:#f6bd3b;font-weight:800;font-size:12px}.tng-runtime h2{color:#fff;font-size:32px;margin:9px 0}.tng-runtime-meta,.tng-next-meta{display:flex;gap:8px;flex-wrap:wrap;margin:16px 0}.tng-runtime-pill{background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.2);border-radius:999px;padding:7px 11px;font-weight:700;font-size:13px}
                .tng-runtime-start,.tng-next-claim,.tng-location-button{border:0;border-radius:13px;background:#fff;color:var(--ink);font-weight:800;padding:14px 20px;cursor:pointer}.tng-runtime-sync{font-size:12px;margin-top:11px;color:rgba(255,255,255,.78)}.tng-runtime-error{display:none;background:#fff1f0;color:#b42318;border:1px solid #fecdca;border-radius:12px;padding:11px;margin-top:12px}.tng-runtime-error.is-visible{display:block}
                .tng-adventure{display:none;margin-top:16px;background:#eef1f5;border-radius:24px;overflow:hidden;border:1px solid #dfe3e8}.tng-runtime.is-started .tng-runtime-hero{display:none}.tng-runtime.is-started .tng-adventure{display:block}.tng-adventure-head{background:linear-gradient(135deg,#18213d,#4a2d68);color:#fff;padding:20px}.tng-adventure-top,.tng-adventure-stats{display:flex;justify-content:space-between;gap:12px}.tng-adventure-title{font-weight:800;font-size:20px}.tng-adventure-exit{border:1px solid rgba(255,255,255,.26);background:rgba(255,255,255,.1);color:#fff;border-radius:10px;padding:8px 10px;cursor:pointer}.tng-adventure-stats{margin-top:16px;font-weight:700;font-size:13px}
                .tng-runtime-progress{height:10px;background:rgba(255,255,255,.18);border-radius:999px;overflow:hidden;margin-top:10px}.tng-runtime-progress span{display:block;height:100%;width:0;background:#4ade80;transition:.25s}.tng-runtime-dots{display:flex;gap:7px;margin-top:13px}.tng-runtime-dot{width:10px;height:10px;border-radius:50%;background:rgba(255,255,255,.28)}.tng-runtime-dot.is-done{background:#4ade80}.tng-runtime-dot.is-current{background:#fff;box-shadow:0 0 0 4px rgba(255,255,255,.16)}
                .tng-adventure-body{padding:18px}.tng-next-card{background:#fff;border:1px solid #e4e7ec;border-radius:20px;padding:20px;box-shadow:0 10px 28px rgba(24,33,61,.08)}.tng-next-label{text-transform:uppercase;letter-spacing:.1em;color:var(--accent);font-size:11px;font-weight:900}.tng-next-card h3{font-size:24px;margin:6px 0 8px}.tng-next-card p{color:#475467}.tng-next-chip{background:var(--soft);color:#53389e;border-radius:999px;padding:6px 9px;font-size:12px;font-weight:800}.tng-next-actions{display:flex;gap:9px;flex-wrap:wrap;margin-top:16px}.tng-next-claim{background:var(--accent);color:#fff;padding:12px 15px}.tng-next-claim:disabled{background:#98a2b3;cursor:not-allowed}.tng-next-secondary{border:1px solid #d0d5dd;background:#fff;border-radius:12px;padding:12px 15px;font-weight:800;cursor:pointer}
                .tng-location{margin:14px 0;background:#f8fafc;border:1px solid #e4e7ec;border-radius:14px;padding:13px}.tng-location-row{display:flex;justify-content:space-between;align-items:center;gap:12px}.tng-location-status{font-weight:800}.tng-location-detail{font-size:13px;color:var(--muted);margin-top:4px}.tng-location-button{background:var(--accent);color:#fff;padding:10px 12px}.tng-location-ready{color:#067647}.tng-location-far{color:#b54708}
                .tng-map-card{margin-top:16px;background:#fff;border:1px solid #e4e7ec;border-radius:20px;overflow:hidden}.tng-map-head{display:flex;justify-content:space-between;gap:12px;align-items:center;padding:14px 16px}.tng-map-head h3{margin:0}.tng-map-status{font-size:12px;color:var(--muted)}.tng-live-map{height:360px;background:#dce3e9}.tng-map-legend{display:flex;gap:14px;flex-wrap:wrap;padding:11px 16px;font-size:12px;color:var(--muted)}.tng-map-key{display:inline-flex;align-items:center;gap:6px}.tng-map-key i{width:10px;height:10px;border-radius:50%;display:inline-block}.tng-key-you{background:#2563eb}.tng-key-next{background:#7f56d9}.tng-key-done{background:#12b76a}.tng-key-future{background:#98a2b3}
                .tng-journey{margin-top:16px}.tng-runtime-list{display:grid;gap:10px}.tng-runtime-stop{border:1px solid #e5e7eb;border-radius:15px;padding:13px;display:grid;grid-template-columns:38px minmax(0,1fr) auto;gap:11px;align-items:center;background:#fff}.tng-runtime-stop.is-locked{opacity:.55}.tng-runtime-stop.is-done{background:#ecfdf3;border-color:#abefc6}.tng-runtime-stop.is-current{border-color:#b9a7ef;box-shadow:0 0 0 3px #f0edff}.tng-runtime-num{width:34px;height:34px;border-radius:50%;background:var(--accent);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:900}.tng-runtime-stop h4{margin:0 0 3px;font-size:15px}.tng-runtime-stop small{color:var(--muted)}.tng-runtime-state{font-size:12px;font-weight:800;color:var(--muted)}
                .tng-runtime-complete{display:none;text-align:center;background:#fff;border:1px solid #d9d0ff;border-radius:20px;padding:28px;margin-top:16px}.tng-runtime-complete.is-visible{display:block}.tng-runtime-reset{border:1px solid #d0d5dd;background:#fff;border-radius:10px;padding:10px 12px;cursor:pointer}
                .tng-map-marker{width:30px;height:30px;border-radius:50%;border:3px solid #fff;box-shadow:0 2px 8px rgba(0,0,0,.32);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:900;font-size:12px}.tng-marker-next{background:#7f56d9}.tng-marker-done{background:#12b76a}.tng-marker-future{background:#98a2b3}.tng-marker-you{background:#2563eb;width:22px;height:22px}
                @media(max-width:650px){.tng-runtime{margin:14px 0}.tng-runtime-hero{border-radius:20px;padding:24px}.tng-runtime h2{font-size:27px}.tng-adventure{border-radius:0;margin-left:-12px;margin-right:-12px}.tng-adventure-body{padding:14px}.tng-next-card h3{font-size:21px}.tng-runtime-stop{grid-template-columns:34px 1fr}.tng-runtime-state{grid-column:2}.tng-next-actions>*{width:100%}.tng-location-row{align-items:flex-start;flex-direction:column}.tng-location-button{width:100%}.tng-live-map{height:310px}}
            </style>
            <div class="tng-runtime-hero"><div class="tng-runtime-kicker">TN Game Quest</div><h2><?php echo esc_html(get_the_title($quest_id)); ?></h2><p><?php echo esc_html($summary ?: wp_strip_all_tags($quest->post_content)); ?></p><div class="tng-runtime-meta"><span class="tng-runtime-pill"><?php echo esc_html((string)count($stops)); ?> checkpoints</span><span class="tng-runtime-pill"><?php echo esc_html(number_format_i18n($xp)); ?> XP</span><span class="tng-runtime-pill"><?php echo esc_html($this->duration_label($minutes)); ?></span></div><button type="button" class="tng-runtime-start">Start Quest</button><div class="tng-runtime-sync"><?php echo is_user_logged_in() ? 'Progress syncs to your TN Game account.' : 'Guest progress is saved on this device.'; ?></div></div>
            <div class="tng-runtime-error" role="alert"></div>
            <div class="tng-adventure"><header class="tng-adventure-head"><div class="tng-adventure-top"><div><div class="tng-runtime-kicker">Adventure in progress</div><div class="tng-adventure-title"><?php echo esc_html(get_the_title($quest_id)); ?></div></div><button type="button" class="tng-adventure-exit">Exit</button></div><div class="tng-adventure-stats"><span><b data-complete>0</b> / <b><?php echo esc_html((string)$required); ?></b> complete</span><span><b data-earned>0</b> XP</span></div><div class="tng-runtime-progress"><span></span></div><div class="tng-runtime-dots"></div></header><div class="tng-adventure-body"><section class="tng-next-card"></section><section class="tng-map-card"><div class="tng-map-head"><h3>Live adventure map</h3><span class="tng-map-status">Waiting for location</span></div><div class="tng-live-map" aria-label="Quest checkpoint map"></div><div class="tng-map-legend"><span class="tng-map-key"><i class="tng-key-you"></i>You</span><span class="tng-map-key"><i class="tng-key-next"></i>Next</span><span class="tng-map-key"><i class="tng-key-done"></i>Completed</span><span class="tng-map-key"><i class="tng-key-future"></i>Future</span></div></section><section class="tng-journey"><h3>Checkpoint journey</h3><div class="tng-runtime-list"></div></section><div class="tng-runtime-complete"><div style="font-size:42px">🎉</div><h3>Quest complete!</h3><p>You earned <strong><span data-final-xp>0</span> XP</strong>.</p><button type="button" class="tng-runtime-reset">Replay quest</button></div></div></div>
            <script type="application/json" class="tng-runtime-data"><?php echo wp_json_encode($payload); ?></script>
            <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin="anonymous"></script>
            <script>
            (()=>{
                const root=document.currentScript.closest('.tng-runtime'); if(!root)return;
                const data=JSON.parse(root.querySelector('.tng-runtime-data').textContent);
                const list=root.querySelector('.tng-runtime-list'),next=root.querySelector('.tng-next-card'),bar=root.querySelector('.tng-runtime-progress span'),dots=root.querySelector('.tng-runtime-dots'),error=root.querySelector('.tng-runtime-error'),mapStatus=root.querySelector('.tng-map-status'),storage='tngQuestProgress:'+data.questId;
                let state={started:false,done:[],status:'not_started'},saving=false,watchId=null,position=null,geoError='',map=null,userMarker=null,accuracyCircle=null,radiusCircle=null,checkpointMarkers=[];
                const esc=v=>String(v).replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
                const load=()=>{try{return JSON.parse(localStorage.getItem(storage)||'{}')}catch(e){return{}}};
                const saveLocal=()=>{try{localStorage.setItem(storage,JSON.stringify(state))}catch(e){}};
                const showError=m=>{error.textContent=m||'';error.classList.toggle('is-visible',Boolean(m));};
                const api=async(method,body)=>{const r=await fetch(data.progressUrl,{method,credentials:'same-origin',headers:{'Content-Type':'application/json','X-WP-Nonce':data.restNonce},body:body?JSON.stringify(body):undefined});if(!r.ok)throw new Error();return r.json();};
                const apply=r=>{state.started=Boolean(r.started);state.done=Array.isArray(r.completedStops)?r.completedStops.map(String):[];state.status=r.status||'not_started';};
                const persist=async()=>{saveLocal();if(!data.loggedIn||saving)return;saving=true;try{apply(await api('POST',{started:state.started,completedStops:state.done}));saveLocal();}catch(e){showError('Progress is saved on this device, but account sync is unavailable.');}finally{saving=false;render();}};
                const typeLabel=t=>({gps:'GPS arrival',trivia:'Trivia',photo:'Photo challenge',qr:'QR code',manual:'Manual claim'}[t]||'Checkpoint');
                const feet=m=>Math.round(m*3.28084);
                const distance=(a,b)=>{const R=6371000,p=Math.PI/180,dLat=(b.lat-a.lat)*p,dLon=(b.lng-a.lng)*p,x=Math.sin(dLat/2)**2+Math.cos(a.lat*p)*Math.cos(b.lat*p)*Math.sin(dLon/2)**2;return R*2*Math.atan2(Math.sqrt(x),Math.sqrt(1-x));};
                const hasCoords=s=>Number.isFinite(Number(s.lat))&&Number.isFinite(Number(s.lng));
                const currentInfo=stop=>{if(stop.type!=='gps')return{claimable:true,text:'This checkpoint does not require GPS.'};if(!hasCoords(stop))return{claimable:data.adminOverride,text:'Location coordinates have not been added for this checkpoint.'};if(!position)return{claimable:data.adminOverride,text:geoError||'Turn on location to measure your distance.'};const meters=distance(position,{lat:Number(stop.lat),lng:Number(stop.lng)}),radius=Number(stop.radius||30),d=feet(meters);return{claimable:d<=radius,distanceFeet:d,radius,text:d<=radius?'You are inside the arrival zone.':d+' ft away · get within '+radius+' ft to claim.'};};
                const markerIcon=(kind,label)=>L.divIcon({className:'',html:`<div class="tng-map-marker tng-marker-${kind}">${esc(label)}</div>`,iconSize:[30,30],iconAnchor:[15,15]});
                const initMap=()=>{if(map||!window.L)return;map=L.map(root.querySelector('.tng-live-map'),{zoomControl:true,attributionControl:true});L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,attribution:'&copy; OpenStreetMap contributors'}).addTo(map);setTimeout(()=>map.invalidateSize(),50);};
                const renderMap=(done,currentIndex)=>{initMap();if(!map)return;checkpointMarkers.forEach(m=>m.remove());checkpointMarkers=[];if(radiusCircle){radiusCircle.remove();radiusCircle=null;}const bounds=[];data.stops.forEach((s,i)=>{if(!hasCoords(s))return;const id=String(s.id),kind=done.has(id)?'done':i===currentIndex?'next':'future',label=done.has(id)?'✓':String(i+1),ll=[Number(s.lat),Number(s.lng)];const marker=L.marker(ll,{icon:markerIcon(kind,label)}).addTo(map).bindPopup(`<strong>${esc(s.title)}</strong><br>${esc(typeLabel(s.type))}`);checkpointMarkers.push(marker);bounds.push(ll);if(i===currentIndex&&s.type==='gps'){radiusCircle=L.circle(ll,{radius:Number(s.radius||30)/3.28084,color:'#7f56d9',weight:2,fillColor:'#7f56d9',fillOpacity:.12}).addTo(map);}});if(position){const ll=[position.lat,position.lng];if(!userMarker)userMarker=L.marker(ll,{icon:markerIcon('you','')}).addTo(map).bindPopup('Your location');else userMarker.setLatLng(ll);if(!accuracyCircle)accuracyCircle=L.circle(ll,{radius:position.accuracy,color:'#2563eb',weight:1,fillColor:'#2563eb',fillOpacity:.08}).addTo(map);else accuracyCircle.setLatLng(ll).setRadius(position.accuracy);bounds.push(ll);mapStatus.textContent='Live · accuracy ±'+feet(position.accuracy)+' ft';}else mapStatus.textContent=geoError||'Waiting for location';if(bounds.length===1)map.setView(bounds[0],16);else if(bounds.length>1)map.fitBounds(bounds,{padding:[35,35],maxZoom:17});};
                const startLocation=()=>{if(!navigator.geolocation){geoError='Location is not supported by this browser.';render();return;}if(watchId!==null)return;geoError='Locating…';render();watchId=navigator.geolocation.watchPosition(p=>{position={lat:p.coords.latitude,lng:p.coords.longitude,accuracy:p.coords.accuracy};geoError='';render();},e=>{geoError=e.code===1?'Location permission was denied.':'Your location could not be determined.';render();},{enableHighAccuracy:true,maximumAge:3000,timeout:15000});};
                const render=()=>{root.classList.toggle('is-started',state.started);root.querySelector('.tng-runtime-start').textContent=state.started?'Resume Quest':'Start Quest';const done=new Set(state.done.map(String));let earned=0;data.stops.forEach(s=>{if(done.has(String(s.id)))earned+=Number(s.xp||0)});root.querySelector('[data-complete]').textContent=done.size;root.querySelector('[data-earned]').textContent=earned;root.querySelector('[data-final-xp]').textContent=earned;bar.style.width=(data.required?Math.min(100,done.size/data.required*100):0)+'%';const ci=data.stops.findIndex(s=>!done.has(String(s.id)));dots.innerHTML=data.stops.map((s,i)=>`<span class="tng-runtime-dot ${done.has(String(s.id))?'is-done':''} ${i===ci?'is-current':''}"></span>`).join('');const cur=ci>=0?data.stops[ci]:null;if(cur){const info=currentInfo(cur),statusClass=info.claimable?'tng-location-ready':'tng-location-far';next.innerHTML=`<div class="tng-next-label">Next checkpoint · ${ci+1} of ${data.stops.length}</div><h3>${esc(cur.title)}</h3><p>${esc(cur.instruction||cur.arrival||'Reach this checkpoint and complete the activity to continue.')}</p><div class="tng-next-meta"><span class="tng-next-chip">${esc(typeLabel(cur.type))}</span><span class="tng-next-chip">${Number(cur.xp||0)} XP</span>${cur.type==='gps'?`<span class="tng-next-chip">${Number(cur.radius||30)} ft radius</span>`:''}</div>${cur.hint?`<p><strong>Hint:</strong> ${esc(cur.hint)}</p>`:''}<div class="tng-location"><div class="tng-location-row"><div><div class="tng-location-status ${statusClass}">${info.claimable?'Ready to claim':'Location required'}</div><div class="tng-location-detail">${esc(info.text)}${position&&cur.type==='gps'?` · GPS accuracy ±${feet(position.accuracy)} ft`:''}</div></div>${cur.type==='gps'?'<button type="button" class="tng-location-button" data-location>Use my location</button>':''}</div></div><div class="tng-next-actions"><button type="button" class="tng-next-claim" data-claim-current="${esc(String(cur.id))}" ${info.claimable?'':'disabled'}>Claim checkpoint</button><button type="button" class="tng-next-secondary" data-scroll-journey>View full journey</button></div>`;}else next.innerHTML='<div class="tng-next-label">Journey complete</div><h3>Every required checkpoint is complete.</h3><p>Your adventure has been saved.</p>';list.innerHTML=data.stops.map((s,i)=>{const id=String(s.id),complete=done.has(id),isCurrent=i===ci,locked=!complete&&!isCurrent;return `<article class="tng-runtime-stop ${complete?'is-done':''} ${isCurrent?'is-current':''} ${locked?'is-locked':''}"><span class="tng-runtime-num">${complete?'✓':i+1}</span><div><h4>${esc(s.title)}</h4><small>${esc(typeLabel(s.type))} · ${Number(s.xp||0)} XP</small></div><span class="tng-runtime-state">${complete?'Completed':isCurrent?'Next':'Locked'}</span></article>`}).join('');root.querySelector('.tng-runtime-complete').classList.toggle('is-visible',done.size>=data.required&&data.required>0);renderMap(done,ci);};
                root.querySelector('.tng-runtime-start').addEventListener('click',()=>{state.started=true;persist();startLocation();render();root.scrollIntoView({behavior:'smooth',block:'start'});setTimeout(()=>map&&map.invalidateSize(),250);});
                root.querySelector('.tng-adventure-exit').addEventListener('click',()=>root.classList.remove('is-started'));
                next.addEventListener('click',e=>{if(e.target.closest('[data-location]'))startLocation();const claim=e.target.closest('[data-claim-current]');if(claim&&!claim.disabled){state.done=Array.from(new Set([...state.done.map(String),String(claim.dataset.claimCurrent)]));persist();if(navigator.vibrate)navigator.vibrate([80,50,120]);}if(e.target.closest('[data-scroll-journey]'))root.querySelector('.tng-journey').scrollIntoView({behavior:'smooth'});});
                root.querySelector('.tng-runtime-reset').addEventListener('click',()=>{state={started:true,done:[],status:'in_progress'};persist();});
                const init=async()=>{const l=load();apply({started:Boolean(l.started),completedStops:l.done||l.completedStops||[],status:l.status||'not_started'});if(data.loggedIn)try{apply(await api('GET'));saveLocal();}catch(e){showError('Account progress could not be loaded. Using device progress.');}render();if(state.started){startLocation();setTimeout(()=>map&&map.invalidateSize(),250);}};init();
            })();
            </script>
        </section>
        <?php return (string)ob_get_clean();
    }

    private function entities(): array {
        $engine = $this->container->get('recommendation_engine');
        return $engine && is_callable([$engine, 'entities']) ? $engine->entities() : [];
    }

    private function coordinates(array $payload): ?array {
        $lat = $payload['latitude'] ?? $payload['lat'] ?? null;
        $lng = $payload['longitude'] ?? $payload['lng'] ?? $payload['lon'] ?? null;
        if ((!is_numeric($lat) || !is_numeric($lng)) && isset($payload['coordinates']) && is_array($payload['coordinates'])) {
            $lat = $payload['coordinates']['lat'] ?? $payload['coordinates']['latitude'] ?? null;
            $lng = $payload['coordinates']['lng'] ?? $payload['coordinates']['longitude'] ?? null;
        }
        return is_numeric($lat) && is_numeric($lng) ? [(float)$lat, (float)$lng] : null;
    }

    private function duration_label(int $minutes): string {
        if ($minutes < 60) return $minutes . ' min';
        $hours = round($minutes / 60, 1);
        return rtrim(rtrim(number_format($hours, 1, '.', ''), '0'), '.') . ' hr';
    }
}
