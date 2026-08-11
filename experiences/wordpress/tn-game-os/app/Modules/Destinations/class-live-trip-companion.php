<?php
namespace TNG_OS\Modules\Destinations;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class Live_Trip_Companion implements Module_Interface {
    public function id(): string { return 'live_trip_companion'; }

    public function register(Container $container): void {
        $container->set('live_trip_companion', $this);
        add_action('wp_ajax_tng_trip_companion_data', [$this, 'ajax_data']);
        add_action('wp_ajax_nopriv_tng_trip_companion_data', [$this, 'ajax_data']);
        add_action('wp_footer', [$this, 'footer'], 110);
    }

    public function boot(Container $container): void {}

    public function ajax_data(): void {
        check_ajax_referer('tng_trip_companion', 'nonce');
        $ids = array_values(array_unique(array_filter(array_map('absint', (array)($_POST['ids'] ?? [])))));
        $items = [];
        foreach ($ids as $id) {
            if (get_post_status($id) !== 'publish') continue;
            $coords = $this->coordinates($id);
            $items[] = [
                'id' => $id,
                'title' => html_entity_decode(get_the_title($id), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'url' => get_permalink($id),
                'lat' => $coords ? $coords[0] : null,
                'lng' => $coords ? $coords[1] : null,
                'directions' => $coords ? $this->directions_url($coords[0], $coords[1], get_the_title($id)) : '',
                'replacement' => $this->replacement($id, $ids),
            ];
        }
        wp_send_json_success(['items' => $items]);
    }

    private function replacement(int $id, array $exclude): ?array {
        if (!class_exists(Smart_Recommendation_Engine::class)) return null;
        foreach (Smart_Recommendation_Engine::recommend($id, 'similar', 8) as $rec) {
            $candidate = (int)($rec['id'] ?? 0);
            if (!$candidate || in_array($candidate, $exclude, true)) continue;
            $coords = $this->coordinates($candidate);
            return [
                'id' => $candidate,
                'title' => html_entity_decode(get_the_title($candidate), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'url' => get_permalink($candidate),
                'image' => get_the_post_thumbnail_url($candidate, 'medium') ?: '',
                'minutes' => 60,
                'detail' => 'Suggested replacement',
                'reason' => (string)($rec['reason'] ?? 'Similar nearby experience'),
                'distance' => isset($rec['distance']) ? round((float)$rec['distance'], 1) : null,
                'lat' => $coords ? $coords[0] : null,
                'lng' => $coords ? $coords[1] : null,
            ];
        }
        return null;
    }

    private function coordinates(int $id): ?array {
        $pairs = [
            ['_tng_precise_lat', '_tng_precise_lng'],
            ['_tng_resolved_lat', '_tng_resolved_lng'],
            ['_tng_latitude', '_tng_longitude'],
            ['latitude', 'longitude'], ['lat', 'lng'], ['map_lat', 'map_lng'],
            ['st_google_map_lat', 'st_google_map_lng'],
        ];
        foreach ($pairs as [$lat_key, $lng_key]) {
            $lat = get_post_meta($id, $lat_key, true);
            $lng = get_post_meta($id, $lng_key, true);
            if (is_numeric($lat) && is_numeric($lng) && $this->valid((float)$lat, (float)$lng)) return [(float)$lat, (float)$lng];
        }
        foreach (get_post_meta($id) as $values) {
            foreach ((array)$values as $raw) {
                $value = maybe_unserialize($raw);
                $found = $this->coordinates_from_value($value);
                if ($found) return $found;
            }
        }
        return null;
    }

    private function coordinates_from_value($value): ?array {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) return $this->coordinates_from_value($decoded);
            if (preg_match('/(-?\d{1,3}\.\d+)\s*[,|]\s*(-?\d{1,3}\.\d+)/', $value, $m)) {
                $lat = (float)$m[1]; $lng = (float)$m[2];
                if ($this->valid($lat, $lng)) return [$lat, $lng];
            }
            return null;
        }
        if (!is_array($value)) return null;
        $lat = $value['lat'] ?? $value['latitude'] ?? null;
        $lng = $value['lng'] ?? $value['lon'] ?? $value['longitude'] ?? null;
        if (is_numeric($lat) && is_numeric($lng) && $this->valid((float)$lat, (float)$lng)) return [(float)$lat, (float)$lng];
        foreach ($value as $child) {
            $found = $this->coordinates_from_value($child);
            if ($found) return $found;
        }
        return null;
    }

    private function valid(float $lat, float $lng): bool {
        return $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180 && !($lat == 0.0 && $lng == 0.0);
    }

    private function directions_url(float $lat, float $lng, string $title): string {
        return 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode($lat . ',' . $lng) . '&destination_place_id=&travelmode=driving';
    }

    public function footer(): void {
        if (is_admin()) return;
        $nonce = wp_create_nonce('tng_trip_companion');
        ?>
        <style>
        .tng-tc-tools{display:flex;gap:8px;flex-wrap:wrap;margin:12px 0 4px}.tng-tc-tools button,.tng-tc-tools a{border:0;border-radius:10px;padding:10px 12px;font-weight:800;font-size:12px;cursor:pointer;text-decoration:none}.tng-tc-locate,.tng-tc-optimize{background:#7c4ce0;color:#fff}.tng-tc-route{background:#17213f;color:#fff}.tng-tc-status{font-size:12px;color:#667085;margin:8px 0}.tng-tc-distance{color:#087a45;font-weight:800}.tng-tc-replace{margin-top:6px;border:0;background:#f0eaff;color:#6336ae;border-radius:8px;padding:6px 8px;font-size:11px;font-weight:800;cursor:pointer}.tng-tc-directions{display:inline-block;margin-top:6px;color:#6336ae!important;font-size:11px;font-weight:800;text-decoration:none!important}
        </style>
        <script>
        (function(){
            const KEY='tng_my_trip_v1', ajax=<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>, nonce=<?php echo wp_json_encode($nonce); ?>;
            let geo=null, server=new Map();
            const read=()=>{try{const x=JSON.parse(localStorage.getItem(KEY)||'[]');return Array.isArray(x)?x:[]}catch(e){return[]}};
            const write=a=>{localStorage.setItem(KEY,JSON.stringify(a));window.dispatchEvent(new CustomEvent('tng:trip-updated',{detail:{count:a.length}}));};
            const rad=x=>x*Math.PI/180;
            const miles=(a,b,c,d)=>{const R=3958.8,dl=rad(c-a),dn=rad(d-b),q=Math.sin(dl/2)**2+Math.cos(rad(a))*Math.cos(rad(c))*Math.sin(dn/2)**2;return 2*R*Math.asin(Math.sqrt(q));};
            async function load(){const ids=read().map(x=>Number(x.id||0)).filter(Boolean);if(!ids.length)return;const f=new FormData();f.append('action','tng_trip_companion_data');f.append('nonce',nonce);ids.forEach(id=>f.append('ids[]',id));try{const r=await fetch(ajax,{method:'POST',credentials:'same-origin',body:f}),j=await r.json();if(j.success)(j.data.items||[]).forEach(x=>server.set(Number(x.id),x));decorate();}catch(e){}}
            function tools(root){if(root.querySelector('.tng-tc-tools'))return;const box=document.createElement('div');box.className='tng-tc-tools';box.innerHTML='<button type="button" class="tng-tc-locate">Use my location</button><button type="button" class="tng-tc-optimize">Optimize route</button><a class="tng-tc-route" href="#">Open full route</a><div class="tng-tc-status"></div>';root.prepend(box);box.querySelector('.tng-tc-locate').onclick=locate;box.querySelector('.tng-tc-optimize').onclick=optimize;box.querySelector('.tng-tc-route').onclick=e=>{e.preventDefault();openRoute();};}
            function locate(){const statuses=document.querySelectorAll('.tng-tc-status');statuses.forEach(x=>x.textContent='Finding your location…');if(!navigator.geolocation){statuses.forEach(x=>x.textContent='Location is not supported on this device.');return;}navigator.geolocation.getCurrentPosition(p=>{geo={lat:p.coords.latitude,lng:p.coords.longitude};statuses.forEach(x=>x.textContent='Location active · accuracy about '+Math.round(p.coords.accuracy)+' m');decorate();},()=>statuses.forEach(x=>x.textContent='Location permission was not granted.'),{enableHighAccuracy:true,timeout:12000,maximumAge:30000});}
            function optimize(){const a=read();if(a.length<2)return;let current=geo,remaining=a.slice(),out=[];while(remaining.length){let best=0,score=Infinity;remaining.forEach((s,i)=>{const d=server.get(Number(s.id));if(!d||d.lat===null){if(!current&&i===0){best=i;score=0;}return;}const n=current?miles(current.lat,current.lng,Number(d.lat),Number(d.lng)):i;if(n<score){score=n;best=i;}});const chosen=remaining.splice(best,1)[0];out.push(chosen);const d=server.get(Number(chosen.id));if(d&&d.lat!==null)current={lat:Number(d.lat),lng:Number(d.lng)};}write(out);if(window.TNGTrip&&window.TNGTrip.open)window.TNGTrip.open();setTimeout(()=>{load();decorate();},50);document.querySelectorAll('.tng-tc-status').forEach(x=>x.textContent='Route optimized from '+(geo?'your location.':'the first stop.'));}
            function openRoute(){const stops=read().map(s=>server.get(Number(s.id))).filter(x=>x&&x.lat!==null);if(!stops.length)return;const destination=stops[stops.length-1],waypoints=stops.slice(0,-1).map(x=>x.lat+','+x.lng).join('|');let u='https://www.google.com/maps/dir/?api=1&travelmode=driving&destination='+encodeURIComponent(destination.lat+','+destination.lng);if(geo)u+='&origin='+encodeURIComponent(geo.lat+','+geo.lng);if(waypoints)u+='&waypoints='+encodeURIComponent(waypoints);window.open(u,'_blank','noopener');}
            function replace(id){const data=server.get(Number(id));if(!data||!data.replacement)return;const a=read(),i=a.findIndex(x=>Number(x.id)===Number(id));if(i<0)return;const r=data.replacement;a[i]={id:Number(r.id),title:String(r.title),url:String(r.url),image:String(r.image||''),minutes:Number(r.minutes||60),detail:String(r.detail||'Suggested replacement')};write(a);load();if(window.TNGTrip&&window.TNGTrip.open)window.TNGTrip.open();}
            function decorate(){document.querySelectorAll('[data-tng-trip-drawer] .tng-mt-summary,[data-tng-trip-full] .tng-mt-full-head').forEach(tools);document.querySelectorAll('.tng-mt-item').forEach(row=>{const link=row.querySelector('h3 a');if(!link)return;const item=read().find(x=>x.url===link.href||link.href.endsWith(new URL(x.url,location.href).pathname));if(!item)return;const data=server.get(Number(item.id));const meta=row.querySelector('.tng-mt-meta');if(!meta||meta.dataset.companion)return;meta.dataset.companion='1';if(data&&data.directions){const a=document.createElement('a');a.className='tng-tc-directions';a.target='_blank';a.rel='noopener';a.href=data.directions;a.textContent='Directions';meta.appendChild(document.createElement('br'));meta.appendChild(a);}if(geo&&data&&data.lat!==null){const span=document.createElement('span');span.className='tng-tc-distance';span.textContent=' · '+miles(geo.lat,geo.lng,Number(data.lat),Number(data.lng)).toFixed(1)+' mi from you';meta.appendChild(span);}if(data&&data.replacement){const b=document.createElement('button');b.type='button';b.className='tng-tc-replace';b.textContent='Replace with nearby option';b.onclick=()=>replace(item.id);meta.appendChild(document.createElement('br'));meta.appendChild(b);}});}
            window.addEventListener('tng:trip-updated',()=>{setTimeout(load,30)});new MutationObserver(decorate).observe(document.body,{childList:true,subtree:true});load();decorate();
        })();
        </script>
        <?php
    }
}
