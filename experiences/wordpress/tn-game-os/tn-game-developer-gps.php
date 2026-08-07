<?php
/**
 * Plugin Name: TN Game Developer GPS
 * Description: Admin-only GPS checkpoint simulator for TN Game runtime testing.
 * Version: 0.2.0
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
            if (!$lat && !$lng) continue;
            $index = (int) $index;
            $out[] = [
                'index' => $index,
                'title' => sanitize_text_field((string) ($item['title'] ?? ('Checkpoint ' . ($index + 1)))),
                'type' => sanitize_key((string) ($item['type'] ?? 'gps')),
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
            .tng-dev-gps{position:fixed;left:150px;bottom:22px;z-index:99990;width:min(380px,calc(100vw - 32px));background:#14213d;color:#fff;border:1px solid rgba(255,255,255,.16);border-radius:18px;box-shadow:0 18px 48px rgba(0,0,0,.28);padding:16px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
            .tng-dev-gps__head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:10px}.tng-dev-gps__eyebrow{font-size:10px;font-weight:900;letter-spacing:.14em;text-transform:uppercase;color:#ff8a3d}.tng-dev-gps h3{font-size:17px;line-height:1.2;margin:3px 0 0;color:#fff}.tng-dev-gps__close{border:0;background:rgba(255,255,255,.12);color:#fff;border-radius:9px;width:30px;height:30px;cursor:pointer;font-size:18px}.tng-dev-gps__coords{font-size:12px;color:#cbd5e1;line-height:1.5;margin:0 0 12px}.tng-dev-gps__buttons{display:grid;grid-template-columns:1fr 1fr;gap:8px}.tng-dev-gps button[data-dev-gps]{border:0;border-radius:11px;padding:10px 11px;font-weight:800;cursor:pointer}.tng-dev-gps button[data-mode="inside"]{background:#ef6423;color:#fff}.tng-dev-gps button[data-mode="outside"]{background:#fff;color:#14213d}.tng-dev-gps__note{display:block;margin-top:9px;color:#9fb0c5;font-size:11px;line-height:1.4}.tng-runtime-map-popup [data-tng-dev-teleport]{margin-top:8px;background:#14213d!important;color:#fff!important;border-radius:9px!important;font-weight:800!important}.tng-runtime-player.is-simulated{box-shadow:0 0 0 5px rgba(239,100,37,.2),0 0 0 9px rgba(20,33,61,.14)}
            @media(max-width:700px){.tng-dev-gps{left:10px;right:10px;bottom:84px;width:auto}.tng-dev-gps__buttons{grid-template-columns:1fr}}
        </style>
        <aside class="tng-dev-gps" id="tng-dev-gps" aria-label="Developer GPS simulator">
            <div class="tng-dev-gps__head"><div><span class="tng-dev-gps__eyebrow">Developer GPS</span><h3><?php echo esc_html($current ? $current['title'] : 'Route simulator'); ?></h3></div><button class="tng-dev-gps__close" type="button" aria-label="Hide developer GPS">×</button></div>
            <?php if ($current): ?>
                <p class="tng-dev-gps__coords"><?php echo esc_html(number_format($current['lat'], 6)); ?>, <?php echo esc_html(number_format($current['lng'], 6)); ?><br>Unlock radius: <?php echo esc_html((string) $current['radius']); ?> m</p>
                <?php if ($current['type'] === 'gps'): ?>
                    <div class="tng-dev-gps__buttons">
                        <button type="button" data-dev-gps data-mode="inside">📍 Simulate current stop</button>
                        <button type="button" data-dev-gps data-mode="outside">🧪 Test outside radius</button>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
            <small class="tng-dev-gps__note">Admin only. Open any checkpoint marker on the map and use <strong>Teleport here</strong>. Future stops can be previewed, but only the active GPS stop can complete.</small>
        </aside>
        <script id="tng-developer-gps-script">
        (()=>{
            const cfg=<?php echo $payload; ?>;
            const panel=document.getElementById('tng-dev-gps');
            if(!panel||!cfg)return;
            panel.querySelector('.tng-dev-gps__close')?.addEventListener('click',()=>panel.remove());

            const byIndex=(index)=>Array.isArray(cfg.checkpoints)?cfg.checkpoints.find(cp=>Number(cp.index)===Number(index)):null;
            const broadcast=(cp,lat,lng)=>{
                window.dispatchEvent(new CustomEvent('tng:developer-location',{detail:{index:Number(cp.index),title:cp.title||'Checkpoint',lat:Number(lat),lng:Number(lng),current:!!cp.current,type:cp.type||''}}));
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
                setTimeout(()=>form.submit(),250);
                return true;
            };
            const simulate=(cp,mode='inside',submit=true)=>{
                if(!cp)return;
                let lat=Number(cp.lat),lng=Number(cp.lng);
                if(mode==='outside')lat+=(Number(cp.radius||30)+15)/111320;
                broadcast(cp,lat,lng);
                if(submit&&cp.current&&cp.type==='gps')submitCurrentGps(cp,lat,lng);
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
        })();
        </script>
        <?php
    }
}

TNG_Game_Developer_GPS::boot();
