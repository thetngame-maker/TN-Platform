<?php
/**
 * TN Game Trail Top Sights
 * Adds linked Top Sights and Explorer visit state to native GPX trail maps.
 */
if (!defined('ABSPATH')) exit;

final class TNG_Trail_Top_Sights {
    public static function boot(): void {
        // Register Leaflet just before Trail UI so our map-capture shim runs first.
        add_action('wp_enqueue_scripts', [__CLASS__, 'prepare_map_capture'], 109);
        // Add Top Sight data and renderer after Trail UI has attached its GPX renderer.
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_overlay'], 111);
    }

    private static function is_trail(): bool {
        return class_exists('TNG_Trail_UI') && TNG_Trail_UI::is_trail();
    }

    private static function top_sight_types(): array {
        $types = [];
        foreach (get_post_types(['public' => true], 'names') as $type) {
            $key = strtolower(str_replace(['-', '_'], '', (string)$type));
            if ($key === 'topsight' || (strpos($key, 'top') !== false && strpos($key, 'sight') !== false)) $types[] = $type;
        }
        foreach (['top_sight','top-sight','topsight','top-sights','tng_top_sight'] as $type) {
            if (post_type_exists($type)) $types[] = $type;
        }
        return array_values(array_unique($types));
    }

    private static function is_top_sight(int $id): bool {
        if (!$id || !get_post($id)) return false;
        return in_array(get_post_type($id), self::top_sight_types(), true);
    }

    private static function ids_from_value($value): array {
        $ids = [];
        if (is_object($value) && isset($value->ID)) $value = $value->ID;
        if (is_numeric($value)) return [absint($value)];
        if (is_string($value)) {
            $trim = trim($value);
            if ($trim === '') return [];
            $maybe = maybe_unserialize($value);
            if ($maybe !== $value) return self::ids_from_value($maybe);
            if (preg_match_all('/\b\d+\b/', $trim, $m)) foreach ($m[0] as $raw) $ids[] = absint($raw);
            return array_values(array_unique(array_filter($ids)));
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (in_array((string)$key, ['ID','id','post_id','value'], true) && is_numeric($item)) $ids[] = absint($item);
                else $ids = array_merge($ids, self::ids_from_value($item));
            }
        }
        return array_values(array_unique(array_filter(array_map('absint', $ids))));
    }

    private static function linked_game_id(int $trail_id): int {
        if (!post_type_exists('tng_game')) return 0;
        $ids = get_posts([
            'post_type' => 'tng_game', 'post_status' => 'publish', 'posts_per_page' => 1,
            'fields' => 'ids', 'meta_key' => 'tng_trail_id', 'meta_value' => $trail_id,
            'orderby' => 'modified', 'order' => 'DESC', 'no_found_rows' => true,
        ]);
        return $ids ? absint($ids[0]) : 0;
    }

    private static function checkpoint_sights(int $trail_id): array {
        $game_id = self::linked_game_id($trail_id);
        if (!$game_id) return [];
        $raw = get_post_meta($game_id, 'tng_game_checkpoints', true);
        if (!is_array($raw)) return [];
        $out = [];
        foreach ($raw as $cp) {
            if (!is_array($cp)) continue;
            $sight_id = 0;
            foreach (['top_sight_id','topsight_id','top_sight_post_id','linked_top_sight','sight_id','top_sight','sight','location_post_id'] as $key) {
                if (!array_key_exists($key, $cp)) continue;
                foreach (self::ids_from_value($cp[$key]) as $candidate) {
                    if (self::is_top_sight($candidate)) { $sight_id = $candidate; break 2; }
                }
            }
            if (!$sight_id) continue;
            $lat = isset($cp['latitude']) && is_numeric($cp['latitude']) ? (float)$cp['latitude'] : 0.0;
            $lng = isset($cp['longitude']) && is_numeric($cp['longitude']) ? (float)$cp['longitude'] : 0.0;
            $out[$sight_id] = ['lat' => $lat, 'lng' => $lng];
        }
        return $out;
    }

    private static function direct_relationship_ids(int $trail_id): array {
        $ids = [];
        // related_top_sights is the canonical legacy TN Game / ACF relationship field.
        foreach (['related_top_sights','top_sights','top_sight_ids','trail_top_sights','tng_top_sights','linked_top_sights','trail_sights'] as $key) {
            $ids = array_merge($ids, self::ids_from_value(get_post_meta($trail_id, $key, true)));
            if (function_exists('get_field')) $ids = array_merge($ids, self::ids_from_value(get_field($key, $trail_id)));
        }
        return array_values(array_unique(array_filter($ids, [__CLASS__, 'is_top_sight'])));
    }

    private static function reverse_relationship_ids(int $trail_id): array {
        $types = self::top_sight_types();
        if (!$types) return [];
        $posts = get_posts([
            'post_type' => $types, 'post_status' => 'publish', 'posts_per_page' => 250,
            'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC', 'no_found_rows' => true,
        ]);
        $keys = ['trail_id','trails','trail_ids','related_trails','linked_trails','activity_id','activities','related_activities','linked_activity','parent_activity_id'];
        $found = [];
        foreach ($posts as $sight_id) {
            foreach ($keys as $key) {
                $values = self::ids_from_value(get_post_meta($sight_id, $key, true));
                if (function_exists('get_field')) $values = array_merge($values, self::ids_from_value(get_field($key, $sight_id)));
                if (in_array($trail_id, array_map('absint', $values), true)) { $found[] = absint($sight_id); break; }
            }
        }
        return array_values(array_unique($found));
    }

    private static function numeric_meta(int $id, array $keys): ?float {
        foreach ($keys as $key) {
            $value = get_post_meta($id, $key, true);
            if (is_numeric($value)) return (float)$value;
            if (function_exists('get_field')) {
                $value = get_field($key, $id);
                if (is_numeric($value)) return (float)$value;
            }
        }
        return null;
    }

    private static function coordinates(int $sight_id, array $fallback = []): array {
        // IMPORTANT: sight_latitude / sight_longitude are the original TN Game Core
        // coordinate fields used by the proven Mapbox Top Sight implementation.
        $lat = self::numeric_meta($sight_id, ['sight_latitude','latitude','lat','top_sight_latitude','map_latitude','location_latitude','location_lat']);
        $lng = self::numeric_meta($sight_id, ['sight_longitude','longitude','lng','lon','top_sight_longitude','map_longitude','location_longitude','location_lng']);
        if ($lat === null || $lng === null) {
            foreach (['location','map','coordinates','top_sight_location','google_map'] as $key) {
                $value = get_post_meta($sight_id, $key, true);
                if (function_exists('get_field')) {
                    $acf = get_field($key, $sight_id);
                    if ($acf) $value = $acf;
                }
                if (!is_array($value)) continue;
                if ($lat === null && isset($value['lat']) && is_numeric($value['lat'])) $lat = (float)$value['lat'];
                if ($lat === null && isset($value['latitude']) && is_numeric($value['latitude'])) $lat = (float)$value['latitude'];
                if ($lng === null && isset($value['lng']) && is_numeric($value['lng'])) $lng = (float)$value['lng'];
                if ($lng === null && isset($value['lon']) && is_numeric($value['lon'])) $lng = (float)$value['lon'];
                if ($lng === null && isset($value['longitude']) && is_numeric($value['longitude'])) $lng = (float)$value['longitude'];
            }
        }
        if (($lat === null || $lng === null) && !empty($fallback)) {
            if ($lat === null && isset($fallback['lat']) && is_numeric($fallback['lat'])) $lat = (float)$fallback['lat'];
            if ($lng === null && isset($fallback['lng']) && is_numeric($fallback['lng'])) $lng = (float)$fallback['lng'];
        }
        if ($lat === null || $lng === null || abs($lat) > 90 || abs($lng) > 180 || ($lat == 0.0 && $lng == 0.0)) return [];
        return ['lat' => $lat, 'lng' => $lng];
    }

    private static function sights(int $trail_id): array {
        $checkpoint = self::checkpoint_sights($trail_id);
        $ids = array_keys($checkpoint);
        $ids = array_merge($ids, self::direct_relationship_ids($trail_id), self::reverse_relationship_ids($trail_id));
        $ids = array_values(array_unique(array_filter(array_map('absint', $ids))));
        $visited = [];
        if (is_user_logged_in()) $visited = apply_filters('tng_os_visited_top_sights', [], get_current_user_id());
        $visited = array_map('absint', is_array($visited) ? $visited : []);
        $out = [];
        foreach ($ids as $sight_id) {
            if (!self::is_top_sight($sight_id)) continue;
            $coords = self::coordinates($sight_id, $checkpoint[$sight_id] ?? []);
            if (!$coords) continue;
            $out[] = [
                'id' => $sight_id,
                'title' => get_the_title($sight_id) ?: 'Top Sight',
                'lat' => $coords['lat'], 'lng' => $coords['lng'],
                'url' => get_permalink($sight_id) ?: '',
                'image' => get_the_post_thumbnail_url($sight_id, 'thumbnail') ?: '',
                'visited' => in_array($sight_id, $visited, true),
            ];
        }
        return $out;
    }

    public static function prepare_map_capture(): void {
        if (!self::is_trail()) return;
        wp_register_script('tng-trail-leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', [], '1.9.4', true);
        wp_enqueue_script('tng-trail-leaflet');
        wp_add_inline_script('tng-trail-leaflet', "(()=>{if(typeof L==='undefined'||L.__tngTrailCapture)return;L.__tngTrailCapture=1;const original=L.map;L.map=function(){const map=original.apply(this,arguments);const el=arguments[0];if(el==='tng-trail-live-map'||(el&&el.id==='tng-trail-live-map'))window.TNG_TRAIL_LEAFLET_MAP=map;return map};})();", 'after');
    }

    public static function enqueue_overlay(): void {
        if (!self::is_trail()) return;
        $trail_id = get_queried_object_id();
        $sights = self::sights($trail_id);
        if (!$sights) return;
        wp_add_inline_style('tng-trail-ui', '.tng-trail-sight-marker{width:34px!important;height:34px!important;border-radius:12px;background:#e54851;border:3px solid #fff;box-shadow:0 5px 16px rgba(0,0,0,.24);display:flex!important;align-items:center;justify-content:center;font-size:17px}.tng-trail-sight-marker.is-visited{background:#17613f}.tng-trail-sight-popup{min-width:180px}.tng-trail-sight-popup__status{display:inline-block;margin:5px 0 7px;padding:3px 7px;border-radius:999px;background:#eef4ef;color:#17613f;font-size:10px;font-weight:800}.tng-trail-sight-popup a{color:#e85d24;font-weight:800;text-decoration:none}.tng-trail-map-legend{display:flex;gap:14px;align-items:center;flex-wrap:wrap;margin-top:10px;color:#68776e;font-size:11px}.tng-trail-map-legend span{display:inline-flex;align-items:center;gap:5px}.tng-trail-map-legend i{width:10px;height:10px;border-radius:50%;background:#e54851}.tng-trail-map-legend .visited i{background:#17613f}');
        wp_localize_script('tng-trail-leaflet', 'TNG_TRAIL_TOP_SIGHTS', ['items' => $sights]);
        wp_add_inline_script('tng-trail-leaflet', "(()=>{const esc=s=>String(s||'').replace(/[&<>'\"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',\"'\":'&#039;','\"':'&quot;'}[c]||c));const render=()=>{const map=window.TNG_TRAIL_LEAFLET_MAP;if(!map||typeof L==='undefined'||typeof TNG_TRAIL_TOP_SIGHTS==='undefined')return false;if(map.__tngTopSightsReady)return true;map.__tngTopSightsReady=1;(TNG_TRAIL_TOP_SIGHTS.items||[]).forEach(s=>{if(!Number.isFinite(Number(s.lat))||!Number.isFinite(Number(s.lng)))return;const visited=!!s.visited;const icon=L.divIcon({className:'tng-trail-sight-marker'+(visited?' is-visited':''),html:'📍',iconSize:[34,34],iconAnchor:[17,30],popupAnchor:[0,-27]});let html='<div class=\"tng-trail-sight-popup\"><strong>'+esc(s.title)+'</strong>';if(visited)html+='<br><span class=\"tng-trail-sight-popup__status\">✓ Visited</span>';if(s.url)html+='<br><a href=\"'+esc(s.url)+'\">View Top Sight →</a>';html+='</div>';L.marker([Number(s.lat),Number(s.lng)],{icon,zIndexOffset:600}).addTo(map).bindPopup(html)});const note=document.querySelector('.tng-trail-map-note');if(note&&!document.querySelector('.tng-trail-map-legend')){const legend=document.createElement('div');legend.className='tng-trail-map-legend';legend.innerHTML='<span><i></i> Top Sight</span><span class=\"visited\"><i></i> Visited Top Sight</span>';note.insertAdjacentElement('afterend',legend)}return true};let n=0,t=setInterval(()=>{if(render()||++n>45)clearInterval(t)},120);render()})();", 'after');
    }
}

TNG_Trail_Top_Sights::boot();
