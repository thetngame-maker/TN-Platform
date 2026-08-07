<?php
/**
 * Plugin Name: TN Game Developer GPS
 * Description: Admin-only GPS and route simulator for TN Game runtime testing.
 * Version: 0.3.0
 * Author: The TN Game
 */
if (!defined('ABSPATH')) exit;

final class TNG_Game_Developer_GPS {
    public static function boot(): void {
        add_action('wp_footer', [self::class, 'render'], 1200);
    }

    private static function is_game_play(): bool {
        return class_exists('TNG_OS\\Platform\\App_Router')
            && TNG_OS\Platform\App_Router::current_route() === 'game-play';
    }

    private static function game_id(): int {
        $id = absint($_GET['game'] ?? 0);
        if (!$id) return 0;
        $post = get_post($id);
        if (!$post || $post->post_status !== 'publish') return 0;
        return $id;
    }

    private static function checkpoint_data(int $game_id): array {
        $raw = get_post_meta($game_id, 'tng_game_checkpoints', true);
        if (!is_array($raw) || !$raw) return [];
        $completed = get_user_meta(get_current_user_id(), '_tng_game_progress_' . $game_id, true);
        if (!is_array($completed)) $completed = [];
        $completed = array_values(array_unique(array_map('absint', $completed)));
        $next = -1;
        foreach (array_keys($raw) as $index) {
            $index = (int) $index;
            if (!in_array($index, $completed, true)) { $next = $index; break; }
        }

        $out = [];
        foreach ($raw as $index => $item) {
            if (!is_array($item)) continue;
            $lat = isset($item['latitude']) ? (float) $item['latitude'] : 0.0;
            $lng = isset($item['longitude']) ? (float) $item['longitude'] : 0.0;
            $index = (int) $index;
            $out[] = [
                'index' => $index,
                'title' => sanitize_text_field((string) ($item['title'] ?? ('Checkpoint ' . ($index + 1)))),
                'type' => sanitize_key((string) ($item['type'] ?? 'tap')),
                'lat' => $lat,
                'lng' => $lng,
                'radius' => max(1, min(500, absint($item['radius'] ?? 30))),
                'current' => $index === $next,
                'completed' => in_array($index, $completed, true),
            ];
        }
        return $out;
    }

    public static function render(): void {
        if (is_admin() || !is_user_logged_in() || !current_user_can('manage_options') || !self::is_game_play()) return;
        $game_id = self::game_id();
        if (!$game_id) return;
        $checkpoints = self::checkpoint_data($game_id);
        if (!$checkpoints) return;
        $current = null;
        foreach ($checkpoints as $checkpoint) {
            if (!empty($checkpoint['current'])) { $current = $checkpoint; break; }
        }

        $payload = wp_json_encode([
            'gameId' => $game_id,
            'checkpoints' => $checkpoints,
            'current' => $current,
        ]);
        ?>
        <style id="tng-developer-gps-style">
            .tng-dev-gps{position:fixed;left:150px;bottom:22px;z-index:99990;width:min(410px,calc(100vw - 32px));background:#14213d;color:#fff;border:1px solid rgba(255,255,255,.16);border-radius:18px;box-shadow:0 18px 48px rgba(0,0,0,.28);padding:16px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
            .tng-dev-gps__head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:10px}.tng-dev-gps__eyebrow{font-size:10px;font-weight:900;letter-spacing:.14em;text-transform:uppercase;color:#ff8a3d}.tng-dev-gps h3{font-size:17px;line-height:1.2;margin:3px 0 0;color:#fff}.tng-dev-gps__close{border:0;background:rgba(255,255,255,.12);color:#fff;border-radius:9px;width:30px;height:30px;cursor:pointer;font-size:18px}.tng-dev-gps__coords{font-size:12px;color:#cbd5e1;line-height:1.5;margin:0 0 12px}.tng-dev-gps__buttons{display:grid;grid-template-columns:1fr 1fr;gap:8px}.tng-dev-gps button[data-dev-gps]{border:0;border-radius:11px;padding:10px 11px;font-weight:800;cursor:pointer}.tng-dev-gps button[data-mode="inside"]{background:#ef6423;color:#fff}.tng-dev-gps button[data-mode="outside"]{background:#fff;color:#14213d}.tng-dev-gps__note{display:block;margin-top:9px;color:#9fb0c5;font-size:11px;line-height:1.4}.tng-runtime-map-popup [data-tng-dev-teleport]{margin-top:8px;background:#14213d!important;color:#fff!important;border-radius:9px!important;font-weight:800!important}.tng-runtime-player.is-simulated{box-shadow:0 0 0 5px rgba(239,100,37,.2),0 0 0 9px rgba(20,33,61,.14)}
            .tng-dev-route{margin-top:14px;padding-top:13px;border-top:1px solid rgba(255,255,255,.13)}.tng-dev-route__label{display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:7px;font-size:10px;font-weight:900;letter-spacing:.12em;text-transform:uppercase;color:#ff8a3d}.tng-dev-route__count{color:#9fb0c5;letter-spacing:0;text-transform:none;font-weight:700}.tng-dev-route select{width:100%;min-height:40px;border:0;border-radius:10px;padding:0 10px;background:#fff;color:#14213d;font-weight:750}.tng-dev-route__nav{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:8px}.tng-dev-route__nav button,.tng-dev-route__go{border:0;border-radius:10px;padding:10px;font-weight:850;cursor:pointer}.tng-dev-route__nav button{background:rgba(255,255,255,.12);color:#fff}.tng-dev-route__nav button:disabled{opacity:.4;cursor:not-allowed}.tng-dev-route__go{width:100%;margin-top:8px;background:#ef6423;color:#fff}.tng-dev-route__meta{margin-top:8px;font-size:11px;color:#cbd5e1;line-height:1.45}.tng-dev-route__state{display:inline-block;margin-left:5px;padding:2px 6px;border-radius:999px;background:rgba(255,255,255,.12);font-size:10px;font-weight:800}.tng-dev-route__state.is-current{background:#ef6423;color:#fff}.tng-dev-route__state.is-complete{background:#3f8d63;color:#fff}
            @media(max-width:700px){.tng-dev-gps{left:10px;right:10px;bottom:84px;width:auto}.tng-dev-gps__buttons{grid-template-columns:1fr}}
        </style>
        <aside class="tng-dev-gps" id="tng-dev-gps" aria-label="Developer GPS simulator">
            <div class="tng-dev-gps__head"><div><span class="tng-dev-gps__eyebrow">Developer GPS</span><h3><?php echo esc_html($current ? $current['title'] : 'Route simulator'); ?></h3></div><button class="tng-dev-gps__close" type="button" aria-label="Hide developer GPS">×</button></div>
            <?php if ($current && $current['type'] === 'gps' && ($current['lat'] || $current['lng'])): ?>
                <p class="tng-dev-gps__coords"><?php echo esc_html(number_format($current['lat'], 6)); ?>, <?php echo esc_html(number_format($current['lng'], 6)); ?><br>Unlock radius: <?php echo esc_html((string) $current['radius']); ?> m</p>
                <div class="tng-dev-gps__buttons">
                    <button type="button" data-dev-gps data-mode="inside">📍 Simulate current stop</button>
                    <button type="button" data-dev-gps data-mode="outside">🧪 Test outside radius</button>
                </div>
            <?php endif; ?>

            <div class="tng-dev-route">
                <div class="tng-dev-route__label"><span>Route simulator</span><span class="tng-dev-route__count" data-dev-route-count></span></div>
                <select data-dev-route-select aria-label="Choose checkpoint to simulate"></select>
                <div class="tng-dev-route__nav"><button type="button" data-dev-route-prev>← Previous</button><button type="button" data-dev-route-next>Next →</button></div>
                <button type="button" class="tng-dev-route__go" data-dev-route-go>Go to selected stop</button>
                <div class="tng-dev-route__meta" data-dev-route-meta></div>
            </div>

            <small class="tng-dev-gps__note">Admin only. Route Simulator moves the developer marker without changing progression. If the selected stop is the active GPS checkpoint, “Go to selected stop” submits through the normal GPS validation and XP flow.</small>
        </aside>
        <script id="tng-developer-gps-script">
        (()=>{
            const cfg=<?php echo $payload; ?>;
            const panel=document.getElementById('tng-dev-gps');
            if(!panel||!cfg||!Array.isArray(cfg.checkpoints))return;
            panel.querySelector('.tng-dev-gps__close')?.addEventListener('click',()=>panel.remove());

            const byIndex=(index)=>cfg.checkpoints.find(cp=>Number(cp.index)===Number(index));
            const broadcast=(cp,lat,lng)=>{
                if(!cp)return;
                window.dispatchEvent(new CustomEvent('tng:developer-location',{detail:{index:Number(cp.index),title:cp.title||'Checkpoint',lat:Number(lat),lng:Number(lng),current:!!cp.current,type:cp.type||''}}));
            };
            const scrollToCheckpoint=(cp)=>{
                if(!cp)return;
                const stops=[...document.querySelectorAll('.tng-runtime-stop')];
                const stop=stops[Number(cp.index)];
                if(stop)setTimeout(()=>stop.scrollIntoView({behavior:'smooth',block:'center'}),180);
            };
            const submitCurrentGps=(cp,lat,lng)=>{
                if(!cp||!cp.current||cp.type!=='gps')return false;
                const form=[...document.querySelectorAll('.tng-runtime-gps-form')].find(f=>Number(f.querySelector('[name="checkpoint"]')?.value)===Number(cp.index));
                if(!form)return false;
                const latInput=form.querySelector('[name="player_lat"]');
                const lngInput=form.querySelector('[name="player_lng"]');
                if(!latInput||!lngInput)return false;
                latInput.value=String(lat);lngInput.value=String(lng);
                const status=form.querySelector('.tng-runtime-location-status');
                if(status)status.textContent='Developer location loaded — submitting through normal GPS validation…';
                setTimeout(()=>form.submit(),300);
                return true;
            };
            const simulate=(cp,mode='inside',submit=true)=>{
                if(!cp)return;
                let lat=Number(cp.lat),lng=Number(cp.lng);
                const hasLocation=Number.isFinite(lat)&&Number.isFinite(lng)&&(lat!==0||lng!==0);
                if(hasLocation){
                    if(mode==='outside')lat+=(Number(cp.radius||30)+15)/111320;
                    broadcast(cp,lat,lng);
                }
                scrollToCheckpoint(cp);
                if(submit&&hasLocation&&cp.current&&cp.type==='gps')submitCurrentGps(cp,lat,lng);
            };

            panel.querySelectorAll('[data-dev-gps]').forEach(btn=>btn.addEventListener('click',()=>{
                if(cfg.current)simulate(cfg.current,btn.dataset.mode||'inside',true);
            }));

            const wireMapButtons=()=>{
                document.querySelectorAll('[data-tng-dev-teleport]').forEach(btn=>{
                    btn.hidden=false;
                    if(btn.dataset.tngDevWired==='1')return;
                    btn.dataset.tngDevWired='1';
                    const index=Number(btn.dataset.checkpointIndex||-1);
                    const cp=byIndex(index);
                    if(!cp)return;
                    btn.textContent=cp.current&&cp.type==='gps'?'🧪 Teleport + check in':'🧪 Teleport here';
                    btn.addEventListener('click',()=>simulate(cp,'inside',true));
                });
            };
            wireMapButtons();
            new MutationObserver(wireMapButtons).observe(document.documentElement,{childList:true,subtree:true});

            const select=panel.querySelector('[data-dev-route-select]');
            const prev=panel.querySelector('[data-dev-route-prev]');
            const next=panel.querySelector('[data-dev-route-next]');
            const go=panel.querySelector('[data-dev-route-go]');
            const meta=panel.querySelector('[data-dev-route-meta]');
            const count=panel.querySelector('[data-dev-route-count]');
            let position=Math.max(0,cfg.checkpoints.findIndex(cp=>cp.current));
            if(position<0)position=0;

            const stateText=(cp)=>cp.completed?'Completed':(cp.current?'Active stop':'Locked / future');
            const renderRoute=()=>{
                if(!select)return;
                select.innerHTML='';
                cfg.checkpoints.forEach((cp,i)=>{
                    const option=document.createElement('option');
                    option.value=String(i);
                    option.textContent=`${i+1}. ${cp.title} — ${stateText(cp)}`;
                    select.appendChild(option);
                });
                select.value=String(position);
                updateRouteMeta(false);
            };
            const updateRouteMeta=(move=true)=>{
                const cp=cfg.checkpoints[position];
                if(!cp)return;
                if(select)select.value=String(position);
                if(count)count.textContent=`${position+1} of ${cfg.checkpoints.length}`;
                if(prev)prev.disabled=position<=0;
                if(next)next.disabled=position>=cfg.checkpoints.length-1;
                if(meta){
                    const stateClass=cp.completed?'is-complete':(cp.current?'is-current':'');
                    const coords=(Number(cp.lat)||Number(cp.lng))?`${Number(cp.lat).toFixed(6)}, ${Number(cp.lng).toFixed(6)}`:'No GPS coordinates';
                    meta.innerHTML=`<strong>${cp.title}</strong><span class="tng-dev-route__state ${stateClass}">${stateText(cp)}</span><br>${String(cp.type||'tap').toUpperCase()} · ${coords}`;
                }
                if(go)go.textContent=cp.current&&cp.type==='gps'?'Go to stop + check in':'Go to selected stop';
                if(move)simulate(cp,'inside',false);
            };
            select?.addEventListener('change',()=>{position=Math.max(0,Math.min(cfg.checkpoints.length-1,Number(select.value)||0));updateRouteMeta(true);});
            prev?.addEventListener('click',()=>{if(position>0){position--;updateRouteMeta(true);}});
            next?.addEventListener('click',()=>{if(position<cfg.checkpoints.length-1){position++;updateRouteMeta(true);}});
            go?.addEventListener('click',()=>simulate(cfg.checkpoints[position],'inside',true));
            renderRoute();
        })();
        </script>
        <?php
    }
}

TNG_Game_Developer_GPS::boot();
