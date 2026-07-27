<?php
namespace TNG_OS\Modules\Frontend;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class World_Engine implements Module_Interface {
    private const ENTITY_TYPE = 'tng_entity';
    private const QUEST_TYPE = 'tng_quest';
    private const PAGE = 'tng-world-engine';

    public function id(): string { return 'world_engine'; }

    public function register(Container $container): void {
        $container->set('world_engine', $this);
        add_action('admin_menu', [$this, 'menu'], 39);
        add_action('template_redirect', [$this, 'route']);
        add_shortcode('tng_world_map', [$this, 'shortcode']);
    }

    public function boot(Container $container): void {}

    public function menu(): void {
        add_submenu_page('tn-game-os', 'World Engine', 'World Engine', 'manage_options', self::PAGE, [$this, 'admin_page']);
    }

    public function admin_page(): void {
        if (!current_user_can('manage_options')) return;
        $entities = $this->entities();
        $quests = $this->quests();
        $types = [];
        foreach ($entities as $entity) $types[$entity['type']] = ($types[$entity['type']] ?? 0) + 1;
        arsort($types);
        ?>
        <div class="wrap tng-world-admin">
            <style>
                .tng-world-admin{max-width:1400px}.tng-world-hero{background:linear-gradient(135deg,#18213d,#4b2f68);color:#fff;border-radius:20px;padding:30px;margin:18px 0}.tng-world-hero h1{color:#fff;margin:0 0 8px}.tng-world-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}.tng-world-card{background:#fff;border:1px solid #dcdcde;border-radius:14px;padding:18px}.tng-world-card strong{display:block;font-size:28px;color:#18213d;margin-top:5px}.tng-world-grid{display:grid;grid-template-columns:1.3fr .7fr;gap:16px;margin-top:16px}.tng-world-table{width:100%;border-collapse:collapse}.tng-world-table td,.tng-world-table th{padding:10px;border-bottom:1px solid #edf0f3;text-align:left}.tng-world-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px}@media(max-width:900px){.tng-world-stats{grid-template-columns:repeat(2,1fr)}.tng-world-grid{grid-template-columns:1fr}}
            </style>
            <div class="tng-world-hero">
                <p style="color:#f6bd3b;text-transform:uppercase;letter-spacing:.13em;font-weight:800;margin:0 0 8px">TN Platform · Living World</p>
                <h1>World Engine</h1>
                <p>Turn quests, places, events, and discoveries into one location-aware player world.</p>
                <div class="tng-world-actions">
                    <a class="button button-primary" target="_blank" href="<?php echo esc_url(add_query_arg('tng_world', '1', home_url('/'))); ?>">Open World Map</a>
                    <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=tng-spatial-integrity')); ?>">Spatial Integrity</a>
                    <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=tng-quest-library')); ?>">Quest Library</a>
                </div>
            </div>
            <div class="tng-world-stats">
                <div class="tng-world-card"><span>Mapped entities</span><strong><?php echo esc_html(number_format_i18n(count($entities))); ?></strong></div>
                <div class="tng-world-card"><span>Published quests</span><strong><?php echo esc_html(number_format_i18n(count($quests))); ?></strong></div>
                <div class="tng-world-card"><span>World categories</span><strong><?php echo esc_html(number_format_i18n(count($types))); ?></strong></div>
                <div class="tng-world-card"><span>Daily challenge stops</span><strong><?php echo esc_html((string)min(3, count($entities))); ?></strong></div>
            </div>
            <div class="tng-world-grid">
                <div class="tng-world-card"><h2>World inventory</h2><table class="tng-world-table"><thead><tr><th>Category</th><th>Mapped items</th></tr></thead><tbody><?php foreach ($types as $type => $count): ?><tr><td><?php echo esc_html($this->label($type)); ?></td><td><?php echo esc_html((string)$count); ?></td></tr><?php endforeach; ?></tbody></table></div>
                <div class="tng-world-card"><h2>Foundation status</h2><p><strong style="font-size:16px;color:#067647">Ready</strong></p><p>Unified map, player location, category filters, nearby sorting, daily challenges, quest markers, and discovery reward previews are active.</p><p style="color:#667085">Dynamic XP is preview-only in this release.</p></div>
            </div>
        </div>
        <?php
    }

    public function route(): void {
        if (!isset($_GET['tng_world'])) return;
        status_header(200);
        nocache_headers();
        echo '<!doctype html><html ' . get_language_attributes() . '><head><meta charset="' . esc_attr(get_bloginfo('charset')) . '"><meta name="viewport" content="width=device-width,initial-scale=1">';
        wp_head();
        echo '</head><body class="tng-world-page">';
        echo $this->render();
        wp_footer();
        echo '</body></html>';
        exit;
    }

    public function shortcode(array $atts = []): string { return $this->render(); }

    private function render(): string {
        $entities = $this->entities();
        $quests = $this->quests();
        $payload = ['entities' => $entities, 'quests' => $quests, 'daily' => $this->daily_ids($entities)];
        ob_start(); ?>
        <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
        <main class="tng-world">
            <style>
                body.tng-world-page{margin:0;background:#eef1f5}.tng-world{--ink:#18213d;--muted:#667085;--accent:#7f56d9;max-width:1180px;margin:0 auto;padding:18px;color:var(--ink)}.tng-world *{box-sizing:border-box}.tng-world-head{background:linear-gradient(135deg,#18213d,#56316f);color:#fff;padding:24px;border-radius:24px 24px 0 0}.tng-world-head h1{color:#fff;margin:7px 0 4px}.tng-world-kicker{color:#f6bd3b;text-transform:uppercase;letter-spacing:.13em;font-size:12px;font-weight:900}.tng-world-head p{margin:0;color:rgba(255,255,255,.8)}.tng-world-bar{background:#fff;padding:14px;border:1px solid #dfe3e8;border-top:0;display:flex;gap:9px;flex-wrap:wrap;align-items:center}.tng-world-button,.tng-world-filter{border:1px solid #d0d5dd;background:#fff;border-radius:999px;padding:9px 13px;font:inherit;font-weight:800;cursor:pointer}.tng-world-button{background:var(--accent);color:#fff}.tng-world-filter.is-active{background:#f0edff;color:#53389e}.tng-world-layout{display:grid;grid-template-columns:minmax(0,1.5fr) minmax(300px,.5fr);gap:16px;margin-top:16px}.tng-world-map-card,.tng-world-panel{background:#fff;border:1px solid #dfe3e8;border-radius:20px;overflow:hidden}.tng-world-map-head{padding:13px 16px;display:flex;justify-content:space-between}.tng-world-map{height:620px;background:#dce3e9}.tng-world-side{display:grid;gap:16px;align-content:start}.tng-world-panel{padding:17px}.tng-world-panel h2{margin:0 0 11px}.tng-world-item{border:1px solid #e4e7ec;border-radius:14px;padding:12px;margin-top:9px}.tng-world-item strong{display:block}.tng-world-meta{font-size:12px;color:var(--muted);margin-top:4px}.tng-world-reward{display:inline-block;background:#ecfdf3;color:#067647;border-radius:999px;padding:4px 8px;font-size:11px;font-weight:900;margin-top:7px}.tng-world-marker{width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;border:3px solid #fff;box-shadow:0 2px 8px rgba(0,0,0,.3);font-weight:900}.tng-world-marker-place{background:#7f56d9}.tng-world-marker-event{background:#f79009}.tng-world-marker-quest{background:#2563eb}.tng-world-marker-you{width:22px;height:22px;background:#12b76a}.tng-world .leaflet-container{position:relative;overflow:hidden}.tng-world .leaflet-pane,.tng-world .leaflet-map-pane,.tng-world .leaflet-tile-pane,.tng-world .leaflet-overlay-pane,.tng-world .leaflet-shadow-pane,.tng-world .leaflet-marker-pane,.tng-world .leaflet-tooltip-pane,.tng-world .leaflet-popup-pane{position:absolute;left:0;top:0}.tng-world .leaflet-tile,.tng-world .leaflet-marker-icon,.tng-world .leaflet-marker-shadow{position:absolute;max-width:none!important}.tng-world .leaflet-tile{width:256px!important;height:256px!important}.tng-world-status{font-size:12px;color:var(--muted)}@media(max-width:850px){.tng-world{padding:0}.tng-world-head{border-radius:0}.tng-world-layout{grid-template-columns:1fr;margin:12px}.tng-world-map{height:55vh;min-height:390px}.tng-world-side{grid-row:1}.tng-world-map-card{grid-row:2}}
            </style>
            <header class="tng-world-head"><div class="tng-world-kicker">TN Game World</div><h1>Explore what is around you</h1><p>Quests, events, places, and discoveries in one living map.</p></header>
            <div class="tng-world-bar"><button class="tng-world-button" data-locate>Use my location</button><button class="tng-world-filter is-active" data-filter="all">All</button><button class="tng-world-filter" data-filter="quest">Quests</button><button class="tng-world-filter" data-filter="event">Events</button><button class="tng-world-filter" data-filter="place">Places</button><span class="tng-world-status">Waiting for location</span></div>
            <div class="tng-world-layout"><section class="tng-world-map-card"><div class="tng-world-map-head"><strong>World map</strong><span data-count></span></div><div class="tng-world-map"></div></section><aside class="tng-world-side"><section class="tng-world-panel"><h2>Nearby</h2><div data-nearby></div></section><section class="tng-world-panel"><h2>Daily challenge</h2><p class="tng-world-meta"><?php echo esc_html(wp_date('F j, Y')); ?> · Complete all discoveries</p><div data-daily></div><span class="tng-world-reward">500 XP preview</span></section></aside></div>
            <script type="application/json" class="tng-world-data"><?php echo wp_json_encode($payload); ?></script>
            <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
            <script>
            (()=>{const root=document.currentScript.closest('.tng-world'),data=JSON.parse(root.querySelector('.tng-world-data').textContent),nearby=root.querySelector('[data-nearby]'),daily=root.querySelector('[data-daily]'),status=root.querySelector('.tng-world-status'),count=root.querySelector('[data-count]');let position=null,user=null,markers=[],filter='all';const esc=v=>String(v).replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));const icon=(kind,label)=>L.divIcon({className:'',html:`<div class="tng-world-marker tng-world-marker-${kind}">${esc(label)}</div>`,iconSize:[32,32],iconAnchor:[16,16]});const distance=(a,b)=>{const R=3958.8,p=Math.PI/180,dLat=(b.lat-a.lat)*p,dLon=(b.lng-a.lng)*p,x=Math.sin(dLat/2)**2+Math.cos(a.lat*p)*Math.cos(b.lat*p)*Math.sin(dLon/2)**2;return R*2*Math.atan2(Math.sqrt(x),Math.sqrt(1-x));};const all=()=>[...data.entities.map(x=>({...x,kind:['event','concert'].includes(x.type)?'event':'place'})),...data.quests.map(x=>({...x,kind:'quest'}))];const reward=x=>x.kind==='quest'?250:x.kind==='event'?100:25;const map=L.map(root.querySelector('.tng-world-map')).setView([35.7,-85.5],7);L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,attribution:'&copy; OpenStreetMap contributors'}).addTo(map);const lists=items=>{const sorted=[...items].sort((a,b)=>position?distance(position,a)-distance(position,b):a.title.localeCompare(b.title)).slice(0,6);nearby.innerHTML=sorted.map(x=>`<div class="tng-world-item"><strong>${esc(x.title)}</strong><div class="tng-world-meta">${esc(x.label)}${position?' · '+distance(position,x).toFixed(1)+' mi':''}</div><span class="tng-world-reward">+${reward(x)} XP preview</span></div>`).join('')||'<p>No mapped discoveries.</p>';const lookup=new Map(all().map(x=>[String(x.id),x]));daily.innerHTML=data.daily.map(id=>lookup.get(String(id))).filter(Boolean).map((x,i)=>`<div class="tng-world-item"><strong>${i+1}. ${esc(x.title)}</strong><div class="tng-world-meta">${esc(x.label)}</div></div>`).join('')||'<p>Add more mapped entities.</p>';};const draw=()=>{markers.forEach(m=>m.remove());markers=[];const items=all().filter(x=>filter==='all'||x.kind===filter);items.forEach(x=>markers.push(L.marker([x.lat,x.lng],{icon:icon(x.kind,x.kind==='quest'?'Q':x.kind==='event'?'E':'•')}).addTo(map).bindPopup(`<strong>${esc(x.title)}</strong><br>${esc(x.label)}<br><b>+${reward(x)} XP discovery</b>`)));count.textContent=items.length+' visible';lists(items);};const bounds=all().map(x=>[x.lat,x.lng]);if(bounds.length===1)map.setView(bounds[0],14);else if(bounds.length>1)map.fitBounds(bounds,{padding:[35,35],maxZoom:14});draw();root.querySelectorAll('[data-filter]').forEach(b=>b.onclick=()=>{filter=b.dataset.filter;root.querySelectorAll('[data-filter]').forEach(x=>x.classList.toggle('is-active',x===b));draw();});root.querySelector('[data-locate]').onclick=()=>{if(!navigator.geolocation){status.textContent='Location unavailable';return;}status.textContent='Locating…';navigator.geolocation.watchPosition(p=>{position={lat:p.coords.latitude,lng:p.coords.longitude};status.textContent='Live · accuracy ±'+Math.round(p.coords.accuracy*3.28084)+' ft';if(!user)user=L.marker([position.lat,position.lng],{icon:icon('you','')}).addTo(map);else user.setLatLng([position.lat,position.lng]);map.setView([position.lat,position.lng],13);draw();},e=>status.textContent=e.code===1?'Location permission denied':'Location unavailable',{enableHighAccuracy:true,maximumAge:5000,timeout:15000});};setTimeout(()=>map.invalidateSize(),250);})();
            </script>
        </main>
        <?php return (string)ob_get_clean();
    }

    private function entities(): array {
        $posts = get_posts(['post_type'=>self::ENTITY_TYPE,'post_status'=>['publish','draft','private'],'posts_per_page'=>1000]);
        $items = [];
        foreach ($posts as $post) {
            $payload = (array)get_post_meta($post->ID, '_tng_entity_payload', true);
            $coords = $this->coords($payload);
            if (!$coords) continue;
            $type = sanitize_key((string)get_post_meta($post->ID, '_tng_entity_type', true)) ?: 'place';
            $items[] = ['id'=>(string)get_post_meta($post->ID, '_tng_entity_id', true), 'title'=>$post->post_title, 'type'=>$type, 'label'=>$this->label($type), 'lat'=>$coords[0], 'lng'=>$coords[1]];
        }
        return $items;
    }

    private function quests(): array {
        $posts = get_posts(['post_type'=>self::QUEST_TYPE,'post_status'=>'publish','posts_per_page'=>200]);
        $items = [];
        foreach ($posts as $post) {
            $coords = null;
            foreach ((array)get_post_meta($post->ID, '_tng_quest_entity_ids', true) as $entity_id) {
                $entity_posts = get_posts(['post_type'=>self::ENTITY_TYPE,'post_status'=>['publish','draft','private'],'meta_key'=>'_tng_entity_id','meta_value'=>(string)$entity_id,'posts_per_page'=>1]);
                if (!$entity_posts) continue;
                $coords = $this->coords((array)get_post_meta($entity_posts[0]->ID, '_tng_entity_payload', true));
                if ($coords) break;
            }
            if (!$coords) continue;
            $items[] = ['id'=>'quest-'.$post->ID,'title'=>$post->post_title,'label'=>'Quest','lat'=>$coords[0],'lng'=>$coords[1]];
        }
        return $items;
    }

    private function daily_ids(array $entities): array {
        if (!$entities) return [];
        $copy = $entities;
        mt_srand((int)wp_date('Ymd'));
        shuffle($copy);
        mt_srand();
        return array_map(static fn(array $item): string => (string)$item['id'], array_slice($copy, 0, 3));
    }

    private function coords(array $payload): ?array {
        $lat = $payload['latitude'] ?? $payload['lat'] ?? null;
        $lng = $payload['longitude'] ?? $payload['lng'] ?? $payload['lon'] ?? null;
        if ((!is_numeric($lat) || !is_numeric($lng)) && isset($payload['coordinates']) && is_array($payload['coordinates'])) {
            $lat = $payload['coordinates']['lat'] ?? $payload['coordinates']['latitude'] ?? null;
            $lng = $payload['coordinates']['lng'] ?? $payload['coordinates']['longitude'] ?? null;
        }
        if (!is_numeric($lat) || !is_numeric($lng)) return null;
        $lat = (float)$lat; $lng = (float)$lng;
        return abs($lat) <= 90 && abs($lng) <= 180 ? [$lat, $lng] : null;
    }

    private function label(string $type): string {
        return ucwords(str_replace(['_','-'], ' ', $type ?: 'place'));
    }
}
