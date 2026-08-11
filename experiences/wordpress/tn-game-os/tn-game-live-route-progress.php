<?php
/**
 * TN Game Live Route Progress
 * Tightens gameplay layout and overlays completed/current/ahead GPX route segments.
 */
if (!defined('ABSPATH')) exit;

final class TNG_Live_Route_Progress {
    public static function boot(): void { add_action('wp_enqueue_scripts',[__CLASS__,'enqueue'],99); }
    private static function is_gameplay(): bool {
        if(is_admin()) return false;
        $uri=(string)($_SERVER['REQUEST_URI']??'');
        return strpos($uri,'/game-play/')!==false || (function_exists('is_page')&&is_page('game-play'));
    }
    private static function route_url(int $trail_id): string {
        foreach(['trail_gpx_url','trail_gpx','gpx_url','gpx_file','gpx','tng_gpx_url','tng_trail_gpx','route_gpx_url','route_gpx'] as $key){
            $value=get_post_meta($trail_id,$key,true);
            if(function_exists('get_field')&&!$value)$value=get_field($key,$trail_id);
            if(is_numeric($value)){$url=wp_get_attachment_url(absint($value));if($url)return esc_url_raw($url);}
            if(is_array($value))$value=$value['url']??$value['file']??'';
            if(is_string($value)&&trim($value)!=='')return esc_url_raw(strpos($value,'/')===0?home_url($value):$value);
        }
        return '';
    }
    public static function enqueue(): void {
        if(!self::is_gameplay()) return;
        $game_id=absint($_GET['game']??0); if(!$game_id) return;
        $trail_id=absint(get_post_meta($game_id,'tng_trail_id',true)); if(!$trail_id) return;
        $gpx=self::route_url($trail_id); if(!$gpx) return;
        $cps=get_post_meta($game_id,'tng_game_checkpoints',true); if(!is_array($cps))$cps=[];
        $checkpoint_data=[];
        foreach($cps as $i=>$cp){
            if(!is_array($cp))continue;
            $checkpoint_data[]=[
                'index'=>$i+1,
                'lat'=>(float)($cp['latitude']??$cp['lat']??0),
                'lng'=>(float)($cp['longitude']??$cp['lng']??0),
                'title'=>(string)($cp['title']??('Checkpoint '.($i+1)))
            ];
        }

        wp_register_style('tng-live-route-progress',false,[],TNG_OS_VERSION);wp_enqueue_style('tng-live-route-progress');
        wp_add_inline_style('tng-live-route-progress','
        /* Keep the checkpoint content attached to the map instead of creating an empty grid row. */
        .tng-journey-key{margin:0!important}
        .tng-game-runtime .tng-journey-key{grid-column:auto!important}
        .tng-runtime-list-wrap,.tng-runtime-checkpoints,.tng-game-checkpoints{align-self:start!important}
        .tng-route-progress-key{display:flex;flex-wrap:wrap;gap:7px;margin:7px 0 0}.tng-route-progress-key span{display:inline-flex;align-items:center;gap:5px;color:#6c7971;font-size:10px;font-weight:800}.tng-route-progress-key i{display:block;width:18px;height:4px;border-radius:999px;background:#d8ddd9}.tng-route-progress-key .is-done i{background:#26724c}.tng-route-progress-key .is-current i{background:#f26722}.tng-route-progress-key .is-ahead i{background:#edb49a}
        .tng-game-runtime .leaflet-marker-icon.tng-next-checkpoint{z-index:1000!important;filter:drop-shadow(0 0 7px rgba(242,103,34,.55))}.tng-game-runtime .leaflet-marker-icon.tng-next-checkpoint::before{content:"";position:absolute;inset:-7px;border:2px solid rgba(242,103,34,.55);border-radius:999px;animation:tngNextPulse 1.6s ease-out infinite;pointer-events:none}@keyframes tngNextPulse{0%{transform:scale(.85);opacity:1}100%{transform:scale(1.45);opacity:0}}
        @media(min-width:900px){.tng-game-runtime .tng-runtime-layout,.tng-game-runtime .tng-game-body,.tng-game-runtime .tng-play-grid{align-items:start!important}.tng-game-runtime .tng-journey-key{position:relative!important;top:auto!important;left:auto!important;right:auto!important;bottom:auto!important}}
        ');

        /* Capture the Leaflet map at creation time without changing the runtime map code. */
        wp_add_inline_script('leaflet', <<<'JS'
(()=>{
 if(!window.L||!L.map||L.map.__tngCaptured)return;
 const original=L.map;
 const wrapped=function(){const map=original.apply(this,arguments);window.TNG_LIVE_GAME_MAP=map;return map;};
 Object.keys(original).forEach(k=>{try{wrapped[k]=original[k]}catch(e){}});
 wrapped.__tngCaptured=true;L.map=wrapped;
})();
JS
        ,'after');

        wp_register_script('tng-live-route-progress','',[],TNG_OS_VERSION,true);wp_enqueue_script('tng-live-route-progress');
        wp_localize_script('tng-live-route-progress','TNG_LIVE_ROUTE_PROGRESS',['gpx'=>$gpx,'checkpoints'=>$checkpoint_data]);
        wp_add_inline_script('tng-live-route-progress', <<<'JS'
(()=>{
 const cfg=window.TNG_LIVE_ROUTE_PROGRESS||{};
 const cps=Array.isArray(cfg.checkpoints)?cfg.checkpoints:[];
 const hav=(a,b)=>{const R=6371000,p1=a[0]*Math.PI/180,p2=b[0]*Math.PI/180,dp=(b[0]-a[0])*Math.PI/180,dl=(b[1]-a[1])*Math.PI/180;const h=Math.sin(dp/2)**2+Math.cos(p1)*Math.cos(p2)*Math.sin(dl/2)**2;return 2*R*Math.asin(Math.sqrt(h));};
 const nearestIndex=(pts,lat,lng)=>{let best=0,dist=Infinity;pts.forEach((p,i)=>{const d=hav(p,[lat,lng]);if(d<dist){dist=d;best=i;}});return best;};
 const currentIndex=()=>{
   const dock=document.querySelector('.tng-gameplay-dock,.tng-player-dock,.tng-game-dock');
   if(dock){const t=dock.textContent||'';let m=t.match(/(\d+)\s*\/\s*(\d+)\s*checkpoints/i);if(m)return Math.min(cps.length,parseInt(m[1],10)+1);}
   const stops=[...document.querySelectorAll('.tng-runtime-stop')];
   let completed=0;stops.forEach(s=>{const tx=(s.textContent||'').toLowerCase();if(s.classList.contains('is-complete')||s.classList.contains('completed')||/completed|claimed/.test(tx))completed++;});
   return Math.min(cps.length,completed+1);
 };
 const compactLayout=()=>{
   const key=document.querySelector('.tng-journey-key');if(!key)return;
   const checkpointHeading=[...document.querySelectorAll('h2,h3')].find(h=>/^checkpoints$/i.test((h.textContent||'').trim()));
   const panel=checkpointHeading&&checkpointHeading.closest('.tng-trail-panel,.tng-runtime-panel,.tng-game-panel,section,article,div');
   if(panel&&!panel.contains(key)){
      const intro=checkpointHeading.parentElement;
      if(intro) intro.appendChild(key); else checkpointHeading.insertAdjacentElement('afterend',key);
   }
 };
 const decorateNext=()=>{
   const n=currentIndex();document.querySelectorAll('.tng-game-runtime .leaflet-marker-icon').forEach(m=>{m.classList.remove('tng-next-checkpoint');const raw=(m.textContent||'').trim();if(/^\d+$/.test(raw)&&Number(raw)===n)m.classList.add('tng-next-checkpoint');});
 };
 const addKey=()=>{
   const heading=[...document.querySelectorAll('h2,h3')].find(h=>/^checkpoints$/i.test((h.textContent||'').trim()));if(!heading||document.querySelector('.tng-route-progress-key'))return;
   const el=document.createElement('div');el.className='tng-route-progress-key';el.innerHTML='<span class="is-done"><i></i>Completed</span><span class="is-current"><i></i>Current leg</span><span class="is-ahead"><i></i>Route ahead</span>';heading.insertAdjacentElement('afterend',el);
 };
 const renderRoute=async()=>{
   if(!cfg.gpx||!window.L)return;
   let map=window.TNG_LIVE_GAME_MAP;
   for(let i=0;i<40&&!map;i++){await new Promise(r=>setTimeout(r,125));map=window.TNG_LIVE_GAME_MAP;}
   if(!map)return;
   let text;try{text=await fetch(cfg.gpx,{credentials:'same-origin'}).then(r=>r.text());}catch(e){return;}
   const xml=new DOMParser().parseFromString(text,'application/xml');const nodes=[...xml.querySelectorAll('trkpt,rtept')];
   const pts=nodes.map(n=>[parseFloat(n.getAttribute('lat')),parseFloat(n.getAttribute('lon'))]).filter(p=>Number.isFinite(p[0])&&Number.isFinite(p[1]));if(pts.length<2)return;
   const cpRoute=cps.map(cp=>nearestIndex(pts,Number(cp.lat),Number(cp.lng)));
   const direction=(cpRoute.length>1&&cpRoute[0]>cpRoute[cpRoute.length-1])?-1:1;if(direction<0){pts.reverse();cpRoute.splice(0,cpRoute.length,...cps.map(cp=>nearestIndex(pts,Number(cp.lat),Number(cp.lng))));}
   const layers=[];
   const redraw=()=>{
      layers.forEach(l=>{try{map.removeLayer(l)}catch(e){}});layers.length=0;
      const n=currentIndex(),doneCp=Math.max(0,n-1),prevIdx=doneCp>0?(cpRoute[doneCp-1]??0):0,nextIdx=cpRoute[Math.min(n-1,cpRoute.length-1)]??pts.length-1;
      const add=(segment,opts)=>{if(segment.length<2)return;const l=L.polyline(segment,opts).addTo(map);layers.push(l);};
      add(pts.slice(0,Math.max(1,prevIdx+1)),{color:'#26724c',weight:7,opacity:.95,lineCap:'round'});
      add(pts.slice(Math.max(0,prevIdx),Math.max(prevIdx+2,nextIdx+1)),{color:'#f26722',weight:8,opacity:1,lineCap:'round'});
      add(pts.slice(Math.max(0,nextIdx),pts.length),{color:'#edb49a',weight:6,opacity:.75,lineCap:'round'});
      decorateNext();
   };
   redraw();
   new MutationObserver(()=>requestAnimationFrame(redraw)).observe(document.body,{childList:true,subtree:true,characterData:true});
 };
 const start=()=>{compactLayout();addKey();decorateNext();renderRoute();new MutationObserver(()=>requestAnimationFrame(()=>{compactLayout();addKey();decorateNext();})).observe(document.body,{childList:true,subtree:true});};
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start);else start();
})();
JS
        ,'after');
    }
}
TNG_Live_Route_Progress::boot();
