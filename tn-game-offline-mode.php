<?php
/**
 * Plugin Name: TN Game Offline Mode
 * Description: Privacy-safe PWA shell and public discovery caching for TN Game app routes.
 * Version: 1.0.0
 * Author: The TN Game
 */
if (!defined('ABSPATH')) exit;

final class TNG_Offline_Mode {
    private const QUERY_VAR = 'tng_offline_asset';
    private const SAFE_ROUTES = ['explore','play','games','map','offline','trails','events','food','top-sights','destinations'];
    private const SAFE_POST_TYPES = ['st_activity','activity','top_sight','tng_destination','st_location'];

    public static function boot(): void {
        add_action('init', [self::class, 'rewrites'], 15);
        add_filter('query_vars', [self::class, 'query_vars']);
        add_action('template_redirect', [self::class, 'serve_asset'], -100);
        add_action('send_headers', [self::class, 'public_cache_header']);
        add_action('wp_enqueue_scripts', [self::class, 'assets'], 130);
        add_action('wp_head', [self::class, 'head'], 3);
    }

    public static function rewrites(): void {
        add_rewrite_rule('^tn-game-sw\.js$', 'index.php?' . self::QUERY_VAR . '=service-worker', 'top');
        add_rewrite_rule('^tn-game\.webmanifest$', 'index.php?' . self::QUERY_VAR . '=manifest', 'top');
    }

    public static function query_vars(array $vars): array {
        $vars[] = self::QUERY_VAR;
        return $vars;
    }

    public static function serve_asset(): void {
        $asset = sanitize_key((string)get_query_var(self::QUERY_VAR));
        if ($asset === 'service-worker') self::service_worker();
        if ($asset === 'manifest') self::manifest();
    }

    public static function public_cache_header(): void {
        if (is_admin() || is_user_logged_in() || headers_sent()) return;
        $route = self::request_route();
        $public_stop = is_singular(self::SAFE_POST_TYPES) && get_post_status(get_queried_object_id()) === 'publish';
        if (in_array($route, self::SAFE_ROUTES, true) || $public_stop) header('X-TNG-Offline-Safe: 1');
    }

    public static function assets(): void {
        if (!class_exists('TNG_OS\\Platform\\App_Router') || !TNG_OS\Platform\App_Router::is_app_request()) return;
        wp_enqueue_style('tng-offline-mode', TNG_OS_URL . 'assets/css/offline-mode.css', ['tng-ui-kit'], TNG_OS_VERSION);
        wp_enqueue_script('tng-offline-mode', TNG_OS_URL . 'assets/js/offline-mode.js', [], TNG_OS_VERSION, true);
        wp_localize_script('tng-offline-mode', 'TNGOfflineMode', [
            'serviceWorkerUrl' => home_url('/tn-game-sw.js'),
            'scope' => home_url('/'),
            'version' => TNG_OS_VERSION,
            'privateRoute' => is_user_logged_in() || !in_array(TNG_OS\Platform\App_Router::current_route(), self::SAFE_ROUTES, true),
            'managerUrl' => home_url('/offline/'),
            'packs' => self::packs(),
        ]);
    }

    public static function head(): void {
        if (is_admin() || !class_exists('TNG_OS\\Platform\\App_Router') || !TNG_OS\Platform\App_Router::is_app_request()) return;
        echo '<link rel="manifest" href="' . esc_url(home_url('/tn-game.webmanifest')) . '">';
        echo '<meta name="theme-color" content="#0b3d2e">';
        echo '<meta name="apple-mobile-web-app-capable" content="yes">';
        echo '<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">';
    }

    private static function request_route(): string {
        $path = trim((string)wp_parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
        return sanitize_key(explode('/', $path)[0] ?? '');
    }

    private static function service_worker(): void {
        nocache_headers();
        header('Content-Type: application/javascript; charset=UTF-8');
        header('Service-Worker-Allowed: /');
        $version = sanitize_key(str_replace('.', '-', TNG_OS_VERSION));
        $origin = home_url('/');
        $plugin = TNG_OS_URL;
        $static = [
            $plugin . 'assets/css/ui-kit.css',
            $plugin . 'assets/css/platform-ui.css',
            $plugin . 'assets/css/app-router.css',
            $plugin . 'assets/css/offline-mode.css',
            $plugin . 'assets/js/platform-ui.js',
            $plugin . 'assets/js/offline-mode.js',
        ];
        $packs = [];
        foreach (self::packs() as $id => $pack) $packs[$id] = array_values($pack['urls']);
        $offline_html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#0b3d2e"><title>TN Game · Offline</title><style>html{background:#f6f1e7;color:#153c2c;font-family:system-ui,-apple-system,sans-serif}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:22px;box-sizing:border-box}.card{max-width:560px;padding:32px;border:1px solid #d9e2dc;border-radius:26px;background:#fff;box-shadow:0 18px 45px rgba(12,55,39,.12)}span{font-size:48px}h1{margin:12px 0 8px;font-size:42px;line-height:1}p{color:#63746b;line-height:1.6}button{min-height:48px;padding:0 18px;border:0;border-radius:999px;background:#176b45;color:#fff;font:inherit;font-weight:800}</style></head><body><main class="card"><span>◇</span><h1>Tennessee is still here.</h1><p>You are offline. Previously cached public Explore, Map, and Play screens may still open. Trips, XP, photos, and profile changes wait until you reconnect.</p><button onclick="location.reload()">Try again</button></main></body></html>';
        ?>
const VERSION=<?php echo wp_json_encode('tng-os-' . $version); ?>;
const STATIC_CACHE=VERSION+'-static';
const PAGE_CACHE=VERSION+'-public-pages';
const PLUGIN_PREFIX=<?php echo wp_json_encode((string)wp_parse_url($plugin, PHP_URL_PATH)); ?>;
const STATIC_ASSETS=<?php echo wp_json_encode($static); ?>;
const OFFLINE_HTML=<?php echo wp_json_encode($offline_html); ?>;
const PACKS=<?php echo wp_json_encode($packs); ?>;
const PACK_PREFIX=VERSION+'-pack-';
const ADVENTURE_PACK_PREFIX='tng-adventure-pack-v1-';
const ADVENTURE_META_CACHE='tng-adventure-pack-meta-v1';
const ADVENTURE_META_ORIGIN='https://tn-game.device.invalid/';
const PUBLIC_ROUTES=<?php echo wp_json_encode(self::SAFE_ROUTES); ?>;

self.addEventListener('install',event=>{
  event.waitUntil(caches.open(STATIC_CACHE).then(cache=>Promise.allSettled(STATIC_ASSETS.map(url=>cache.add(url)))).then(()=>self.skipWaiting()));
});

self.addEventListener('activate',event=>{
  event.waitUntil(caches.keys().then(async keys=>{
    for(const key of keys){const legacy=key.match(/-pack-(adventure-[a-f0-9]{20})$/);if(!legacy)continue;const source=await caches.open(key);const target=await caches.open(ADVENTURE_PACK_PREFIX+legacy[1]);for(const request of await source.keys()){if(await target.match(request))continue;const response=await source.match(request);if(response)await target.put(request,response.clone());}}
    await Promise.all(keys.filter(key=>(key.startsWith('tng-os-')&&!key.startsWith(VERSION))||(key.startsWith(ADVENTURE_PACK_PREFIX)&&key.endsWith('-staging'))).map(key=>caches.delete(key)));
  }).then(()=>self.clients.claim()));
});

self.addEventListener('fetch',event=>{
  const request=event.request;
  if(request.method!=='GET')return;
  const url=new URL(request.url);
  if(url.origin!==self.location.origin)return;
  if(url.pathname.startsWith('/wp-admin/')||url.pathname==='/wp-login.php'||url.pathname.includes('admin-ajax.php')||url.pathname.startsWith('/wp-json/'))return;
  if(request.mode==='navigate'){
    event.respondWith(fetch(request).then(response=>{
      if(response.ok&&response.headers.get('X-TNG-Offline-Safe')==='1')caches.open(PAGE_CACHE).then(cache=>cache.put(request,response.clone()));
      return response;
    }).catch(async()=>await caches.match(request)||new Response(OFFLINE_HTML,{status:200,headers:{'Content-Type':'text/html; charset=UTF-8','X-TNG-Offline-Fallback':'1'}})));
    return;
  }
  if(url.pathname.startsWith(PLUGIN_PREFIX)){
    event.respondWith(caches.match(request,{ignoreSearch:true}).then(cached=>{
      const network=fetch(request).then(response=>{if(response.ok)caches.open(STATIC_CACHE).then(cache=>cache.put(request,response.clone()));return response;});
      return cached||network;
    }));
  }
});

self.addEventListener('message',event=>{
  const data=event.data||{};
  const reply=payload=>{if(event.ports&&event.ports[0])event.ports[0].postMessage(payload);else if(event.source)event.source.postMessage(payload);};
  const safeUrl=value=>{try{const url=new URL(value,self.location.origin);const route=url.pathname.split('/').filter(Boolean)[0]||'';return url.origin===self.location.origin&&PUBLIC_ROUTES.includes(route)?url.toString():'';}catch(error){return'';}};
  const adventureId=value=>/^adventure-[a-f0-9]{20}$/.test(String(value||''))?String(value):'';
  const adventureUrl=value=>{try{const url=new URL(value,self.location.origin);url.hash='';return url.origin===self.location.origin&&!url.search&&url.pathname!=='/'?url.toString():'';}catch(error){return'';}};
  const adventureMetaUrl=id=>new URL('offline-pack/'+id,ADVENTURE_META_ORIGIN).toString();
  const adventureMeta=async id=>{try{const response=await(await caches.open(ADVENTURE_META_CACHE)).match(adventureMetaUrl(id));return response?await response.json():{};}catch(error){return{};}};
  const setAdventureMeta=async(id,value)=>{const cache=await caches.open(ADVENTURE_META_CACHE);await cache.put(adventureMetaUrl(id),new Response(JSON.stringify(value),{headers:{'Content-Type':'application/json'}}));};
  const status=async()=>{const installed={};for(const id of Object.keys(PACKS)){const cache=await caches.open(PACK_PREFIX+id);installed[id]=(await cache.keys()).length;}return installed;};
  const adventureStatus=async packs=>{const installed={};for(const value of packs.slice(0,12)){const entry=typeof value==='string'?{id:value,urls:[]}:value||{};const id=adventureId(entry.id);if(!id)continue;const expected=[...new Set((Array.isArray(entry.urls)?entry.urls:[]).map(adventureUrl).filter(Boolean))].slice(0,12);const cache=await caches.open(ADVENTURE_PACK_PREFIX+id);const saved=(await cache.keys()).map(request=>request.url);const metadata=await adventureMeta(id);installed[id]={count:saved.length,current:expected.length?expected.length===saved.length&&expected.every(url=>saved.includes(url)):saved.length===0,verifiedAt:typeof metadata.verifiedAt==='string'?metadata.verifiedAt:''};}return installed;};
  const cacheEntries=async cache=>{const entries=[];for(const request of await cache.keys()){const response=await cache.match(request);if(response)entries.push([request,response.clone()]);}return entries;};
  const writeEntries=async(name,entries)=>{const cache=await caches.open(name);for(const [request,response] of entries)await cache.put(request,response.clone());};
  const storageHeadroom=async()=>{try{if(!self.navigator.storage||!self.navigator.storage.estimate)return{ok:true,available:null,reserve:null};const estimate=await self.navigator.storage.estimate();const quota=Number(estimate.quota||0);const usage=Number(estimate.usage||0);if(!quota)return{ok:true,available:null,reserve:null};const available=Math.max(0,quota-usage);const reserve=Math.max(8388608,Math.ceil(quota*.02));return{ok:available>=reserve,available,reserve};}catch(error){return{ok:true,available:null,reserve:null};}};
  if(data.type==='TNG_OFFLINE_PACK_STATUS')event.waitUntil(status().then(installed=>reply({ok:true,installed})));
  if(data.type==='TNG_OFFLINE_PACK_REMOVE')event.waitUntil((async()=>{const id=String(data.id||'');if(!Object.prototype.hasOwnProperty.call(PACKS,id)){reply({ok:false,error:'Unknown offline pack.'});return;}await caches.delete(PACK_PREFIX+id);reply({ok:true,id,installed:await status()});})());
  if(data.type==='TNG_OFFLINE_PACK_SAVE')event.waitUntil((async()=>{const id=String(data.id||'');if(!Object.prototype.hasOwnProperty.call(PACKS,id)){reply({ok:false,error:'Unknown offline pack.'});return;}const cache=await caches.open(PACK_PREFIX+id);let saved=0;const failed=[];for(const value of PACKS[id]){const url=safeUrl(value);if(!url){failed.push(value);continue;}try{const response=await fetch(new Request(url,{credentials:'omit',cache:'reload'}));if(response.ok&&response.headers.get('X-TNG-Offline-Safe')==='1'){await cache.put(url,response.clone());saved++;}else failed.push(url);}catch(error){failed.push(url);}}reply({ok:failed.length===0,id,saved,failed:failed.length,installed:await status()});})());
  if(data.type==='TNG_ADVENTURE_PACK_STATUS')event.waitUntil((async()=>{const packs=Array.isArray(data.packs)?data.packs:(Array.isArray(data.ids)?data.ids:[]);reply({ok:true,installed:await adventureStatus(packs)});})());
  if(data.type==='TNG_ADVENTURE_PACK_REMOVE')event.waitUntil((async()=>{const id=adventureId(data.id);if(!id){reply({ok:false,error:'Unknown adventure pack.'});return;}const metadata=await caches.open(ADVENTURE_META_CACHE);await Promise.all([caches.delete(ADVENTURE_PACK_PREFIX+id),caches.delete(ADVENTURE_PACK_PREFIX+id+'-staging'),metadata.delete(adventureMetaUrl(id))]);reply({ok:true,id,installed:await adventureStatus([{id,urls:[]}])});})());
  if(data.type==='TNG_ADVENTURE_PACK_SAVE')event.waitUntil((async()=>{const id=adventureId(data.id);const values=Array.isArray(data.urls)?[...new Set(data.urls)].slice(0,12):[];if(!id||!values.length){reply({ok:false,error:'Adventure pack is not valid.'});return;}const liveName=ADVENTURE_PACK_PREFIX+id;const stagingName=liveName+'-staging';const previous=await cacheEntries(await caches.open(liveName));const storage=await storageHeadroom();if(!storage.ok){reply({ok:false,error:'low-storage',id,saved:0,failed:values.length,preserved:previous.length,storage,installed:await adventureStatus([{id,urls:values}])});return;}await caches.delete(stagingName);const staging=await caches.open(stagingName);let saved=0;const failed=[];for(const value of values){const url=adventureUrl(value);if(!url){failed.push(value);continue;}try{const response=await fetch(new Request(url,{credentials:'omit',cache:'reload',redirect:'follow'}));if(response.ok&&response.headers.get('X-TNG-Offline-Safe')==='1'){await staging.put(url,response.clone());saved++;}else failed.push(url);}catch(error){failed.push(url);}}if(failed.length||saved!==values.length){await caches.delete(stagingName);reply({ok:false,id,saved:0,failed:failed.length||values.length-saved,preserved:previous.length,installed:await adventureStatus([{id,urls:values}])});return;}const fresh=await cacheEntries(staging);try{await caches.delete(liveName);await writeEntries(liveName,fresh);await caches.delete(stagingName);try{await setAdventureMeta(id,{verifiedAt:new Date().toISOString(),count:fresh.length});}catch(error){}reply({ok:true,id,saved:fresh.length,failed:0,preserved:0,installed:await adventureStatus([{id,urls:values}])});}catch(error){await caches.delete(liveName);await writeEntries(liveName,previous);await caches.delete(stagingName);reply({ok:false,id,saved:0,failed:values.length,preserved:previous.length,installed:await adventureStatus([{id,urls:values}])});}})());
});
        <?php
        exit;
    }

    private static function manifest(): void {
        nocache_headers();
        header('Content-Type: application/manifest+json; charset=UTF-8');
        $manifest = [
            'name' => 'The TN Game',
            'short_name' => 'TN Game',
            'description' => 'Discover, play, and explore Tennessee.',
            'id' => home_url('/explore/'),
            'start_url' => home_url('/explore/?source=pwa'),
            'scope' => home_url('/'),
            'display' => 'standalone',
            'background_color' => '#f6f1e7',
            'theme_color' => '#0b3d2e',
            'orientation' => 'portrait-primary',
            'categories' => ['travel','games','lifestyle'],
        ];
        $icon = get_site_icon_url(512);
        if ($icon) $manifest['icons'] = [['src' => $icon, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable']];
        echo wp_json_encode($manifest, JSON_UNESCAPED_SLASHES);
        exit;
    }

    private static function packs(): array {
        $manager = home_url('/offline/');
        return [
            'essentials' => [
                'label' => 'TN Game Essentials',
                'icon' => '◇',
                'description' => 'Explore, Map, Play, Games, and the Offline Pack manager.',
                'urls' => [$manager,home_url('/explore/'),home_url('/map/'),home_url('/play/'),home_url('/games/')],
            ],
            'places' => [
                'label' => 'Tennessee Places',
                'icon' => '⌖',
                'description' => 'Trails, food, Top Sights, and destinations for discovery on the road.',
                'urls' => [$manager,home_url('/trails/'),home_url('/food/'),home_url('/top-sights/'),home_url('/destinations/')],
            ],
            'events' => [
                'label' => 'Events Pack',
                'icon' => '◉',
                'description' => 'The latest public Tennessee events screen for quick reference.',
                'urls' => [$manager,home_url('/events/')],
            ],
        ];
    }

    public static function render_screen(): string {
        $packs = self::packs();
        ob_start(); ?>
        <main class="tng-offline-screen tng-app-shell" data-tng-offline-manager>
            <section class="tng-offline-hero"><div><span class="tng-eyebrow">Offline packs</span><h1>Take Tennessee with you.</h1><p>Download public discovery screens before the signal disappears. Packs stay on this device and can be refreshed or removed anytime.</p></div><span class="tng-offline-hero__mark">◇</span></section>
            <section class="tng-offline-storage" aria-live="polite"><div><strong data-tng-storage-title>Checking device storage…</strong><small data-tng-storage-copy>Offline packs use your browser's private app storage.</small></div><a href="#tng-offline-packs">Manage packs</a></section>
            <section class="tng-offline-packs" id="tng-offline-packs">
                <?php foreach ($packs as $id => $pack): ?>
                    <article class="tng-offline-pack" data-tng-pack="<?php echo esc_attr($id); ?>">
                        <div class="tng-offline-pack__icon"><?php echo esc_html((string)$pack['icon']); ?></div>
                        <div class="tng-offline-pack__copy"><span data-tng-pack-state>Not downloaded</span><h2><?php echo esc_html((string)$pack['label']); ?></h2><p><?php echo esc_html((string)$pack['description']); ?></p><small><?php echo esc_html(number_format_i18n(count($pack['urls']))); ?> public screens</small></div>
                        <div class="tng-offline-pack__actions"><button type="button" class="tng-ui-button" data-tng-pack-save>Download</button><button type="button" class="tng-ui-button tng-ui-button--secondary" data-tng-pack-remove hidden>Remove</button></div>
                    </article>
                <?php endforeach; ?>
            </section>
            <section class="tng-offline-privacy"><span>🔒</span><div><h2>Private by design</h2><p>Trips, Profile, Recaps, Activity, XP, photo uploads, and account changes are never added to Offline Packs. The TN Game does not queue gameplay rewards or private writes while disconnected.</p></div></section>
        </main>
        <?php return (string)ob_get_clean();
    }
}
TNG_Offline_Mode::boot();
