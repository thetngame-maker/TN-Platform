<?php
namespace TNG_OS\Modules\Destinations;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class Multi_Stop_Route_Navigator implements Module_Interface {
    public function id(): string { return 'multi_stop_route_navigator'; }

    public function register(Container $container): void {
        $container->set('multi_stop_route_navigator', $this);
        add_action('wp_ajax_tng_multi_stop_route_data', [$this, 'ajax_data']);
        add_action('wp_ajax_nopriv_tng_multi_stop_route_data', [$this, 'ajax_data']);
        add_action('wp_footer', [$this, 'footer'], 130);
    }

    public function boot(Container $container): void {}

    public function ajax_data(): void {
        check_ajax_referer('tng_multi_stop_route', 'nonce');
        $ids = array_values(array_unique(array_filter(array_map('absint', (array)($_POST['ids'] ?? [])))));
        $items = [];
        foreach ($ids as $id) {
            if (get_post_status($id) !== 'publish') continue;
            $coords = $this->coordinates($id);
            $items[] = [
                'id' => $id,
                'title' => html_entity_decode(get_the_title($id), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'lat' => $coords ? $coords[0] : null,
                'lng' => $coords ? $coords[1] : null,
            ];
        }
        wp_send_json_success(['items' => $items]);
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
                $found = $this->from_value(maybe_unserialize($raw));
                if ($found) return $found;
            }
        }
        return null;
    }

    private function from_value($value): ?array {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) return $this->from_value($decoded);
            if (preg_match('/(-?\d{1,3}\.\d+)\s*[,|]\s*(-?\d{1,3}\.\d+)/', $value, $m)) {
                $lat = (float)$m[1]; $lng = (float)$m[2];
                return $this->valid($lat, $lng) ? [$lat, $lng] : null;
            }
            return null;
        }
        if (!is_array($value)) return null;
        $lat = $value['lat'] ?? $value['latitude'] ?? null;
        $lng = $value['lng'] ?? $value['lon'] ?? $value['longitude'] ?? null;
        if (is_numeric($lat) && is_numeric($lng) && $this->valid((float)$lat, (float)$lng)) return [(float)$lat, (float)$lng];
        foreach ($value as $child) {
            $found = $this->from_value($child);
            if ($found) return $found;
        }
        return null;
    }

    private function valid(float $lat, float $lng): bool {
        return $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180 && !($lat == 0.0 && $lng == 0.0);
    }

    public function footer(): void {
        if (is_admin()) return;
        $nonce = wp_create_nonce('tng_multi_stop_route');
        ?>
        <script>
        (function(){
            const KEY='tng_my_trip_v1';
            const ajax=<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            const nonce=<?php echo wp_json_encode($nonce); ?>;
            let lastGeo=null;

            const read=()=>{try{const value=JSON.parse(localStorage.getItem(KEY)||'[]');return Array.isArray(value)?value:[]}catch(e){return[]}};
            const statuses=()=>document.querySelectorAll('.tng-tc-status');
            const setStatus=text=>statuses().forEach(node=>node.textContent=text);

            async function hydrate(items){
                const ids=items.map(item=>Number(item.id||0)).filter(Boolean);
                if(!ids.length)return [];
                const body=new FormData();
                body.append('action','tng_multi_stop_route_data');
                body.append('nonce',nonce);
                ids.forEach(id=>body.append('ids[]',String(id)));
                const response=await fetch(ajax,{method:'POST',credentials:'same-origin',body});
                const json=await response.json();
                if(!json.success)throw new Error('route-data');
                const byId=new Map((json.data.items||[]).map(item=>[Number(item.id),item]));
                return items.map(item=>{
                    const data=byId.get(Number(item.id));
                    if(!data||data.lat===null||data.lng===null)return null;
                    return {id:Number(item.id),title:String(data.title||item.title||'Stop'),lat:Number(data.lat),lng:Number(data.lng)};
                }).filter(Boolean);
            }

            function locate(){
                return new Promise(resolve=>{
                    if(lastGeo)return resolve(lastGeo);
                    if(!navigator.geolocation)return resolve(null);
                    navigator.geolocation.getCurrentPosition(position=>{
                        lastGeo={lat:position.coords.latitude,lng:position.coords.longitude};
                        resolve(lastGeo);
                    },()=>resolve(null),{enableHighAccuracy:true,timeout:10000,maximumAge:30000});
                });
            }

            function routeUrl(stops,geo){
                const points=[];
                if(geo)points.push(geo.lat+','+geo.lng);
                stops.forEach(stop=>points.push(stop.lat+','+stop.lng));
                return 'https://www.google.com/maps/dir/'+points.map(point=>encodeURIComponent(point)).join('/')+'/?travelmode=driving';
            }

            async function openFullRoute(event){
                event.preventDefault();
                event.stopImmediatePropagation();
                const trip=read();
                if(!trip.length){setStatus('Add stops to My Trip first.');return;}
                setStatus('Preparing all route stops…');
                try{
                    const stops=await hydrate(trip);
                    if(!stops.length){setStatus('None of the saved stops has usable coordinates.');return;}
                    const missing=trip.length-stops.length;
                    const geo=await locate();
                    setStatus('Opening '+stops.length+' route stop'+(stops.length===1?'':'s')+(missing?' · '+missing+' missing coordinates':'')+'.');
                    window.open(routeUrl(stops.slice(0,9),geo),'_blank','noopener');
                }catch(error){
                    setStatus('The full route could not be prepared. Please try again.');
                }
            }

            document.addEventListener('click',event=>{
                const button=event.target.closest('.tng-tc-route');
                if(button)openFullRoute(event);
            },true);
        })();
        </script>
        <?php
    }
}
