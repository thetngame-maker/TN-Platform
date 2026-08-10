<?php
/**
 * Plugin Name: TN Game Trail UI
 * Description: Native TN Game trail-detail template and reusable trail components.
 * Version: 0.2.0
 * Author: The TN Game
 */
if (!defined('ABSPATH')) exit;

final class TNG_Trail_UI {
    public static function boot(): void {
        add_filter('template_include', [self::class, 'template'], 99998);
        add_filter('body_class', [self::class, 'body_class'], 998);
        add_action('wp_enqueue_scripts', [self::class, 'enqueue'], 110);
    }

    public static function is_trail(): bool {
        if (!is_singular(['st_activity', 'activity'])) return false;
        $id = get_queried_object_id();
        if (!$id) return false;
        foreach (['activity_types', 'st_activity_type', 'activity_type', 'category'] as $taxonomy) {
            if (!taxonomy_exists($taxonomy)) continue;
            $terms = wp_get_post_terms($id, $taxonomy, ['fields' => 'slugs']);
            if (!is_wp_error($terms) && array_intersect($terms, ['hiking-trails', 'hiking-trail', 'trails', 'trail'])) return true;
        }
        foreach (['trail_distance', 'distance', 'elevation_gain', 'trail_difficulty', 'gpx_file', 'gpx_url'] as $key) {
            if (get_post_meta($id, $key, true) !== '') return true;
        }
        return false;
    }

    public static function template(string $template): string {
        if (!self::is_trail()) return $template;
        $native = TNG_OS_PATH . 'templates/trail-shell.php';
        return is_readable($native) ? $native : $template;
    }

    public static function body_class(array $classes): array {
        if (!self::is_trail()) return $classes;
        $classes[] = 'tng-platform-ui';
        $classes[] = 'tng-native-trail-page';
        $classes[] = 'tng-hide-traveler-chrome';
        return array_values(array_unique($classes));
    }

    private static function normalize_media_url($value): string {
        if (is_numeric($value)) {
            $url = wp_get_attachment_url(absint($value));
            return $url ? esc_url_raw($url) : '';
        }
        if (is_array($value)) {
            foreach (['url','file','src','ID','id'] as $key) {
                if (!isset($value[$key])) continue;
                $url = self::normalize_media_url($value[$key]);
                if ($url !== '') return $url;
            }
            return '';
        }
        $value = trim((string)$value);
        if ($value === '') return '';
        if (strpos($value, '/') === 0) return esc_url_raw(home_url($value));
        return esc_url_raw($value);
    }

    private static function route_url(int $id): string {
        foreach (['trail_gpx_url','trail_gpx','gpx_url','gpx_file','gpx','tng_gpx_url','tng_trail_gpx','route_gpx_url','route_gpx'] as $key) {
            $url = self::normalize_media_url(get_post_meta($id, $key, true));
            if ($url !== '') return $url;
            if (function_exists('get_field')) {
                $url = self::normalize_media_url(get_field($key, $id));
                if ($url !== '') return $url;
            }
        }
        return '';
    }

    private static function linked_game_id(int $trail_id): int {
        if (!$trail_id || !post_type_exists('tng_game')) return 0;
        $ids = get_posts([
            'post_type' => 'tng_game', 'post_status' => 'publish', 'posts_per_page' => 1,
            'fields' => 'ids', 'meta_key' => 'tng_trail_id', 'meta_value' => $trail_id,
            'orderby' => 'modified', 'order' => 'DESC', 'no_found_rows' => true,
        ]);
        return $ids ? absint($ids[0]) : 0;
    }

    private static function checkpoints(int $trail_id): array {
        $game_id = self::linked_game_id($trail_id);
        if (!$game_id) return [];
        $raw = get_post_meta($game_id, 'tng_game_checkpoints', true);
        if (!is_array($raw)) return [];
        $out = [];
        foreach ($raw as $i => $cp) {
            if (!is_array($cp)) continue;
            $lat = isset($cp['latitude']) ? (float)$cp['latitude'] : 0.0;
            $lng = isset($cp['longitude']) ? (float)$cp['longitude'] : 0.0;
            if (!$lat && !$lng) continue;
            $out[] = [
                'index' => (int)$i,
                'title' => sanitize_text_field((string)($cp['title'] ?? ('Checkpoint '.((int)$i+1)))),
                'lat' => $lat, 'lng' => $lng,
                'sightId' => absint($cp['sight_id'] ?? $cp['top_sight_id'] ?? 0),
            ];
        }
        return $out;
    }

    public static function enqueue(): void {
        if (!self::is_trail()) return;
        $id = get_queried_object_id();
        wp_enqueue_style('tng-platform-ui', TNG_OS_URL . 'assets/css/platform-ui.css', [], '0.8.0');
        wp_enqueue_style('tng-app-router', TNG_OS_URL . 'assets/css/app-router.css', ['tng-platform-ui'], '1.5.0');
        wp_enqueue_style('tng-ui-kit', TNG_OS_URL . 'assets/css/ui-kit.css', ['tng-platform-ui'], '1.4.0');
        wp_enqueue_style('tng-trail-ui', TNG_OS_URL . 'assets/css/trail-ui.css', ['tng-ui-kit'], '0.2.0');
        wp_enqueue_script('tng-platform-ui', TNG_OS_URL . 'assets/js/platform-ui.js', [], '0.8.0', true);
        wp_enqueue_style('tng-trail-leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', [], '1.9.4');
        wp_enqueue_script('tng-trail-leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', [], '1.9.4', true);
        wp_add_inline_style('tng-trail-ui', '.tng-trail-live-map{height:360px;min-height:320px;border-radius:18px;overflow:hidden;background:#eef3ee}.tng-trail-map-note{margin-top:10px;color:#708078;font-size:12px}.tng-trail-marker{background:#f16022;border:3px solid #fff;border-radius:50%;width:28px!important;height:28px!important;box-shadow:0 4px 12px rgba(0,0,0,.22);display:flex!important;align-items:center;justify-content:center;color:#fff;font-size:11px;font-weight:900}.tng-trail-map-empty{display:flex;min-height:320px;align-items:center;justify-content:center;text-align:center;padding:30px;border-radius:18px;background:#f1f5f1;color:#64756b}@media(max-width:640px){.tng-trail-live-map{height:300px;min-height:280px}}');
        wp_localize_script('tng-trail-leaflet', 'TNG_TRAIL_MAP', [
            'trailId' => $id,
            'routeUrl' => self::route_url($id),
            'checkpoints' => self::checkpoints($id),
        ]);
        wp_add_inline_script('tng-trail-leaflet', "(()=>{const init=()=>{const el=document.getElementById('tng-trail-live-map');if(!el||el.dataset.ready==='1'||typeof L==='undefined'||typeof TNG_TRAIL_MAP==='undefined')return false;el.dataset.ready='1';const map=L.map(el,{scrollWheelZoom:false});L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,attribution:'© OpenStreetMap contributors'}).addTo(map);const bounds=[];(TNG_TRAIL_MAP.checkpoints||[]).forEach((cp,i)=>{if(!cp.lat&&!cp.lng)return;const icon=L.divIcon({className:'tng-trail-marker',html:String(i+1),iconSize:[28,28],iconAnchor:[14,14]});L.marker([cp.lat,cp.lng],{icon}).addTo(map).bindPopup('<strong>'+String(cp.title||('Checkpoint '+(i+1))).replace(/[<>&]/g,'')+'</strong>');bounds.push([cp.lat,cp.lng])});const fit=()=>{if(bounds.length)map.fitBounds(bounds,{padding:[28,28],maxZoom:15});else map.setView([35.5,-85.7],10)};const route=TNG_TRAIL_MAP.routeUrl||'';if(route){fetch(route,{credentials:'same-origin'}).then(r=>r.ok?r.text():Promise.reject()).then(text=>{const xml=new DOMParser().parseFromString(text,'application/xml');const pts=[...xml.querySelectorAll('trkpt,rtept')].map(n=>[parseFloat(n.getAttribute('lat')),parseFloat(n.getAttribute('lon'))]).filter(p=>Number.isFinite(p[0])&&Number.isFinite(p[1]));if(pts.length){L.polyline(pts,{color:'#f16022',weight:5,opacity:.9}).addTo(map);pts.forEach(p=>bounds.push(p))}fit()}).catch(fit)}else fit();setTimeout(()=>map.invalidateSize(),120);return true};if(!init()){let n=0,t=setInterval(()=>{if(init()||++n>40)clearInterval(t)},120)}})();", 'after');
    }

    private static function first_meta(int $id, array $keys, string $fallback = ''): string {
        foreach ($keys as $key) {
            $value = get_post_meta($id, $key, true);
            if (is_scalar($value) && trim((string) $value) !== '') return trim((string) $value);
        }
        return $fallback;
    }

    private static function clean_content(int $id): string {
        $content = strip_shortcodes((string) get_post_field('post_content', $id));
        $content = preg_replace('/\[[^\]]+\]/', ' ', $content);
        return wpautop(wp_kses_post($content));
    }

    public static function render(int $id): string {
        $title = get_the_title($id); $image = get_the_post_thumbnail_url($id, 'full');
        $distance = self::first_meta($id, ['trail_distance','distance','st_distance'], '—');
        $gain = self::first_meta($id, ['elevation_gain','trail_elevation_gain','gain'], '—');
        $time = self::first_meta($id, ['estimated_time','trail_time','duration'], '—');
        $type = self::first_meta($id, ['trail_type','route_type'], 'Trail');
        $difficulty = self::first_meta($id, ['trail_difficulty','difficulty'], 'Explore');
        $xp = self::first_meta($id, ['xp_available','xp','trail_xp'], '');
        $address = self::first_meta($id, ['address','location'], 'Tennessee South Cumberland');
        $content = self::clean_content($id); $map_url = add_query_arg(['trail' => $id], home_url('/map/'));
        $game_id = self::linked_game_id($id); $play_url = $game_id ? add_query_arg(['game'=>$game_id],home_url('/game-play/')) : add_query_arg(['trail' => $id], home_url('/play/'));
        $route = self::route_url($id); $checkpoints = self::checkpoints($id); $has_map = $route !== '' || !empty($checkpoints);
        ob_start(); ?>
        <main class="tng-trail tng-app-shell">
            <section class="tng-trail-hero<?php echo $image ? '' : ' is-placeholder'; ?>"<?php echo $image ? ' style="background-image:linear-gradient(90deg,rgba(10,30,19,.86),rgba(10,30,19,.25)),url(' . esc_url($image) . ')"' : ''; ?>><div class="tng-trail-hero__content"><span class="tng-eyebrow">Hiking trail</span><h1><?php echo esc_html($title); ?></h1><p>📍 <?php echo esc_html($address); ?></p><div class="tng-trail-badges"><span><?php echo esc_html($difficulty); ?></span><?php if ($xp): ?><span>⭐ <?php echo esc_html($xp); ?> XP available</span><?php endif; ?></div></div></section>
            <section class="tng-trail-stats" aria-label="Trail information"><div><span>↔</span><strong><?php echo esc_html($distance); ?></strong><small>Distance</small></div><div><span>↗</span><strong><?php echo esc_html($gain); ?></strong><small>Elevation gain</small></div><div><span>◷</span><strong><?php echo esc_html($time); ?></strong><small>Estimated time</small></div><div><span>◇</span><strong><?php echo esc_html($type); ?></strong><small>Trail type</small></div></section>
            <section class="tng-trail-actions"><a class="tng-ui-button tng-ui-button--secondary" href="<?php echo esc_url($map_url); ?>">⌖ Open map</a><a class="tng-ui-button" href="<?php echo esc_url($play_url); ?>">▶ <?php echo $game_id?'Start trail game':'Find a game'; ?></a></section>
            <section class="tng-trail-layout"><article class="tng-trail-main">
                <section class="tng-trail-panel"><span class="tng-eyebrow">Trail overview</span><h2>About this trail</h2><div class="tng-trail-copy"><?php echo $content ?: '<p>Trail details are being prepared.</p>'; ?></div></section>
                <section class="tng-trail-panel"><div class="tng-trail-panel__heading"><div><span class="tng-eyebrow">Route</span><h2>Map and checkpoints</h2></div><a href="<?php echo esc_url($map_url); ?>">Full screen</a></div><?php if($has_map): ?><div id="tng-trail-live-map" class="tng-trail-live-map" aria-label="Trail route and checkpoints"></div><div class="tng-trail-map-note"><?php echo esc_html(count($checkpoints).' checkpoints'.($route?' · GPX route loaded':'')); ?></div><?php else: ?><div class="tng-trail-map-empty"><div><strong>Route data is not connected yet.</strong><br><small>Add a GPX file or link this trail to a TN Game with mapped checkpoints.</small></div></div><?php endif; ?></section>
                <section class="tng-trail-panel"><span class="tng-eyebrow">Elevation</span><h2>Elevation profile</h2><div class="tng-trail-elevation"><svg viewBox="0 0 800 190" preserveAspectRatio="none" aria-hidden="true"><path d="M0 160 C90 150 120 100 210 125 S340 70 420 105 S555 40 640 80 S735 32 800 50 L800 190 L0 190 Z"/></svg><div><span>Start</span><span>Route profile</span><span>Finish</span></div></div></section>
            </article><aside class="tng-trail-side"><section class="tng-trail-panel tng-trail-ready"><span class="tng-eyebrow">Ready to explore?</span><h2>Turn this hike into an adventure.</h2><p>Follow the route, visit checkpoints, discover Top Sights, and earn XP.</p><a class="tng-ui-button" href="<?php echo esc_url($play_url); ?>"><?php echo $game_id?'Start trail game':'Find a game'; ?></a></section><section class="tng-trail-panel"><span class="tng-eyebrow">Plan ahead</span><h2>Before you go</h2><ul><li>Check weather and trail conditions.</li><li>Bring water and appropriate footwear.</li><li>Download or open the route before starting.</li></ul></section></aside></section>
        </main>
        <?php return (string) ob_get_clean();
    }
}

TNG_Trail_UI::boot();
