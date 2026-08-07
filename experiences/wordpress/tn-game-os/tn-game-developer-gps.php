<?php
/**
 * Plugin Name: TN Game Developer GPS
 * Description: Admin-only GPS checkpoint simulator for TN Game runtime testing.
 * Version: 0.1.0
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

    private static function current_checkpoint(int $game_id): ?array {
        $raw = get_post_meta($game_id, 'tng_game_checkpoints', true);
        if (!is_array($raw) || !$raw) return null;

        $completed = get_user_meta(get_current_user_id(), '_tng_game_progress_' . $game_id, true);
        if (!is_array($completed)) $completed = [];
        $completed = array_values(array_unique(array_map('absint', $completed)));

        foreach ($raw as $index => $item) {
            $index = (int) $index;
            if (in_array($index, $completed, true)) continue;
            if (!is_array($item)) return null;
            $type = sanitize_key((string) ($item['type'] ?? 'tap'));
            if ($type !== 'gps') return null;
            $lat = isset($item['latitude']) ? (float) $item['latitude'] : 0.0;
            $lng = isset($item['longitude']) ? (float) $item['longitude'] : 0.0;
            if (!$lat && !$lng) return null;
            return [
                'index' => $index,
                'title' => sanitize_text_field((string) ($item['title'] ?? ('Checkpoint ' . ($index + 1)))),
                'lat' => $lat,
                'lng' => $lng,
                'radius' => max(1, min(500, absint($item['radius'] ?? 30))),
            ];
        }
        return null;
    }

    public static function render(): void {
        if (is_admin() || !is_user_logged_in() || !current_user_can('manage_options') || !self::is_game_play()) return;
        $game_id = self::game_id();
        if (!$game_id) return;
        $checkpoint = self::current_checkpoint($game_id);
        if (!$checkpoint) return;

        $payload = wp_json_encode([
            'index' => $checkpoint['index'],
            'title' => $checkpoint['title'],
            'lat' => $checkpoint['lat'],
            'lng' => $checkpoint['lng'],
            'radius' => $checkpoint['radius'],
        ]);
        ?>
        <style id="tng-developer-gps-style">
            .tng-dev-gps{position:fixed;left:150px;bottom:22px;z-index:99990;width:min(360px,calc(100vw - 32px));background:#14213d;color:#fff;border:1px solid rgba(255,255,255,.16);border-radius:18px;box-shadow:0 18px 48px rgba(0,0,0,.28);padding:16px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
            .tng-dev-gps__head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:10px}.tng-dev-gps__eyebrow{font-size:10px;font-weight:900;letter-spacing:.14em;text-transform:uppercase;color:#ff8a3d}.tng-dev-gps h3{font-size:17px;line-height:1.2;margin:3px 0 0;color:#fff}.tng-dev-gps__close{border:0;background:rgba(255,255,255,.12);color:#fff;border-radius:9px;width:30px;height:30px;cursor:pointer;font-size:18px}.tng-dev-gps__coords{font-size:12px;color:#cbd5e1;line-height:1.5;margin:0 0 12px}.tng-dev-gps__buttons{display:grid;grid-template-columns:1fr 1fr;gap:8px}.tng-dev-gps button[data-dev-gps]{border:0;border-radius:11px;padding:10px 11px;font-weight:800;cursor:pointer}.tng-dev-gps button[data-mode="inside"]{background:#ef6423;color:#fff}.tng-dev-gps button[data-mode="outside"]{background:#fff;color:#14213d}.tng-dev-gps__note{display:block;margin-top:9px;color:#9fb0c5;font-size:11px;line-height:1.4}
            @media(max-width:700px){.tng-dev-gps{left:10px;right:10px;bottom:84px;width:auto}.tng-dev-gps__buttons{grid-template-columns:1fr}}
        </style>
        <aside class="tng-dev-gps" id="tng-dev-gps" aria-label="Developer GPS simulator">
            <div class="tng-dev-gps__head"><div><span class="tng-dev-gps__eyebrow">Developer GPS</span><h3><?php echo esc_html($checkpoint['title']); ?></h3></div><button class="tng-dev-gps__close" type="button" aria-label="Hide developer GPS">×</button></div>
            <p class="tng-dev-gps__coords"><?php echo esc_html(number_format($checkpoint['lat'], 6)); ?>, <?php echo esc_html(number_format($checkpoint['lng'], 6)); ?><br>Unlock radius: <?php echo esc_html((string) $checkpoint['radius']); ?> m</p>
            <div class="tng-dev-gps__buttons">
                <button type="button" data-dev-gps data-mode="inside">📍 Simulate at checkpoint</button>
                <button type="button" data-dev-gps data-mode="outside">🧪 Test outside radius</button>
            </div>
            <small class="tng-dev-gps__note">Admin only. Uses the normal server-side GPS validation and completion flow.</small>
        </aside>
        <script id="tng-developer-gps-script">
        (()=>{
            const cfg=<?php echo $payload; ?>;
            const panel=document.getElementById('tng-dev-gps');
            if(!panel||!cfg)return;
            panel.querySelector('.tng-dev-gps__close')?.addEventListener('click',()=>panel.remove());
            const run=(mode)=>{
                const form=[...document.querySelectorAll('.tng-runtime-gps-form')].find(f=>Number(f.querySelector('[name="checkpoint"]')?.value)===Number(cfg.index));
                if(!form){alert('The active GPS checkpoint form was not found. Refresh the page and try again.');return;}
                let lat=Number(cfg.lat),lng=Number(cfg.lng);
                if(mode==='outside'){
                    const meters=Number(cfg.radius)+15;
                    lat += meters/111320;
                }
                const latInput=form.querySelector('[name="player_lat"]');
                const lngInput=form.querySelector('[name="player_lng"]');
                if(!latInput||!lngInput){alert('GPS form fields are missing.');return;}
                latInput.value=String(lat); lngInput.value=String(lng);
                const status=form.querySelector('.tng-runtime-location-status');
                if(status)status.textContent=mode==='inside'?'Developer location loaded — submitting…':'Developer outside-radius location loaded — submitting…';
                form.submit();
            };
            panel.querySelectorAll('[data-dev-gps]').forEach(btn=>btn.addEventListener('click',()=>run(btn.dataset.mode||'inside')));
        })();
        </script>
        <?php
    }
}

TNG_Game_Developer_GPS::boot();
