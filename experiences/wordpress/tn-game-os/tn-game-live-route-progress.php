<?php
/**
 * TN Game Live Route Progress
 * Visual route-state overlay + next-checkpoint distance for gameplay.
 */
if (!defined('ABSPATH')) exit;

final class TNG_Live_Route_Progress {
    public static function boot(): void {
        add_action('wp_enqueue_scripts',[__CLASS__,'enqueue'],99);
    }

    private static function is_gameplay(): bool {
        if (is_admin()) return false;
        $uri=(string)($_SERVER['REQUEST_URI']??'');
        return strpos($uri,'/game-play/')!==false || (function_exists('is_page') && is_page('game-play'));
    }

    private static function route_url(int $trail_id): string {
        foreach(['trail_gpx_url','trail_gpx','gpx_url','gpx_file','gpx','tng_gpx_url','tng_trail_gpx','route_gpx_url','route_gpx'] as $key){
            $value=get_post_meta($trail_id,$key,true);
            if(function_exists('get_field') && !$value) $value=get_field($key,$trail_id);
            if(is_numeric($value)){
                $url=wp_get_attachment_url(absint($value));
                if($url) return esc_url_raw($url);
            }
            if(is_array($value)) $value=$value['url']??$value['file']??'';
            if(is_string($value) && trim($value)!=='') return esc_url_raw(strpos($value,'/')===0?home_url($value):$value);
        }
        return '';
    }

    public static function enqueue(): void {
        if(!self::is_gameplay()) return;
        $game_id=absint($_GET['game']??0);
        if(!$game_id) return;
        $trail_id=absint(get_post_meta($game_id,'tng_trail_id',true));
        if(!$trail_id) return;
        $gpx=self::route_url($trail_id);
        if(!$gpx) return;

        $cps=get_post_meta($game_id,'tng_game_checkpoints',true);
        if(!is_array($cps)) $cps=[];
        $checkpoint_data=[];
        foreach($cps as $i=>$cp){
            if(!is_array($cp)) continue;
            $checkpoint_data[]=[
                'index'=>$i+1,
                'lat'=>(float)($cp['latitude']??$cp['lat']??0),
                'lng'=>(float)($cp['longitude']??$cp['lng']??0),
                'title'=>(string)($cp['title']??('Checkpoint '.($i+1))),
                'role'=>(string)($cp['role']??''),
            ];
        }

        wp_register_style('tng-live-route-progress',false,[],TNG_OS_VERSION);
        wp_enqueue_style('tng-live-route-progress');
        wp_add_inline_style('tng-live-route-progress','
        .tng-journey-key{margin:0!important}
        .tng-game-runtime .tng-journey-key{grid-column:auto!important}
        .tng-runtime-list-wrap,.tng-runtime-checkpoints,.tng-game-checkpoints{align-self:start!important}
        .tng-route-progress-key{display:flex;flex-wrap:wrap;gap:8px;margin:7px 0 0}.tng-route-progress-key span{display:inline-flex;align-items:center;gap:5px;color:#6c7971;font-size:10px;font-weight:800}.tng-route-progress-key i{display:block;width:18px;height:4px;border-radius:999px}.tng-route-progress-key .is-done i{background:#26724c}.tng-route-progress-key .is-current i{background:#f26722}.tng-route-progress-key .is-ahead i{background:#edb49a}
        .tng-next-distance{display:flex;align-items:center;justify-content:space-between;gap:14px;margin:10px 0 0;padding:10px 13px;border:1px solid #dfe8e2;border-radius:14px;background:#f8faf8;color:#173b2a;max-width:420px}.tng-next-distance__main{min-width:0}.tng-next-distance__eyebrow{display:block;margin-bottom:2px;color:#f26722;font-size:9px;font-weight:900;letter-spacing:.09em;text-transform:uppercase}.tng-next-distance__title{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:13px;font-weight:900}.tng-next-distance__metric{flex:0 0 auto;padding:7px 10px;border-radius:999px;background:#fff;border:1px solid #e1e8e3;color:#173b2a;font-size:12px;font-weight:900}.tng-next-distance.is-near .tng-next-distance__metric{background:#eaf5ee;border-color:#cce5d5;color:#26724c}.tng-next-distance.is-arrived .tng-next-distance__metric{background:#26724c;border-color:#26724c;color:#fff}.tng-next-distance.is-developer .tng-next-distance__eyebrow:after{content:" · Developer GPS";color:#53695d}
        .tng-game-runtime .leaflet-marker-icon.tng-next-checkpoint{z-index:1000!important;filter:drop-shadow(0 0 7px rgba(242,103,34,.55))}.tng-game-runtime .leaflet-marker-icon.tng-next-checkpoint::before{content:"";position:absolute;inset:-7px;border:2px solid rgba(242,103,34,.55);border-radius:999px;animation:tngNextPulse 1.6s ease-out infinite;pointer-events:none}@keyframes tngNextPulse{0%{transform:scale(.85);opacity:1}100%{transform:scale(1.45);opacity:0}}
        @media(min-width:900px){.tng-game-runtime .tng-runtime-layout,.tng-game-runtime .tng-game-body,.tng-game-runtime .tng-play-grid{align-items:start!important}.tng-game-runtime .tng-journey-key{position:relative!important;top:auto!important;left:auto!important;right:auto!important;bottom:auto!important}}
        @media(max-width:640px){.tng-next-distance{align-items:flex-start;max-width:none}.tng-next-distance__metric{font-size:11px}}
        ');

        /* Capture every Leaflet map at creation time. */
        wp_add_inline_script('leaflet', <<<'JS'
(()=>{
 if(!window.L||!L.map||L.map.__tngCaptured)return;
 window.TNG_LIVE_GAME_MAPS=window.TNG_LIVE_GAME_MAPS||[];
 const original=L.map;
 const wrapped=function(){
   const map=original.apply(this,arguments);
   window.TNG_LIVE_GAME_MAPS.push(map);
   try{
     const c=map.getContainer&&map.getContainer();
     if(c&&c.closest&&c.closest('.tng-game-runtime'))window.TNG_LIVE_GAME_MAP=map;
   }catch(e){}
   return map;
 };
 Object.keys(original).forEach(k=>{try{wrapped[k]=original[k]}catch(e){}});
 wrapped.__tngCaptured=true;
 L.map=wrapped;
})();
JS
        ,'after');

        wp_register_script('tng-live-route-progress','',[],TNG_OS_VERSION,true);
        wp_enqueue_script('tng-live-route-progress');
        wp_localize_script('tng-live-route-progress','TNG_LIVE_ROUTE_PROGRESS',[
            'gpx'=>$gpx,
            'checkpoints'=>$checkpoint_data,
        ]);

        wp_add_inline_script('tng-live-route-progress', <<<'JS'
(()=>{
 const cfg=window.TNG_LIVE_ROUTE_PROGRESS||{};
 const cps=Array.isArray(cfg.checkpoints)?cfg.checkpoints:[];
 const hav=(a,b)=>{const R=6371000,p1=a[0]*Math.PI/180,p2=b[0]*Math.PI/180,dp=(b[0]-a[0])*Math.PI/180,dl=(b[1]-a[1])*Math.PI/180;const h=Math.sin(dp/2)**2+Math.cos(p1)*Math.cos(p2)*Math.sin(dl/2)**2;return 2*R*Math.asin(Math.sqrt(h));};
 const nearestIndex=(pts,lat,lng)=>{let best=0,dist=Infinity;pts.forEach((p,i)=>{const d=hav(p,[lat,lng]);if(d<dist){dist=d;best=i;}});return best;};

 const completedCount=()=>{
   const dock=document.querySelector('.tng-gameplay-dock,.tng-player-dock,.tng-game-dock');
   if(dock){const m=(dock.textContent||'').match(/(\d+)\s*\/\s*(\d+)\s*checkpoints/i);if(m)return Math.max(0,Math.min(cps.length,parseInt(m[1],10)));}
   const cards=[...document.querySelectorAll('.tng-runtime-stop,.tng-game-checkpoint,.tng-checkpoint-card')];
   let done=0;
   cards.forEach(card=>{const tx=(card.textContent||'').toLowerCase();if(card.classList.contains('is-complete')||card.classList.contains('completed')||/completed\s*·|completed\s*-|completed\s*25|claimed/.test(tx))done++;});
   return Math.min(cps.length,done);
 };
 const currentIndex=()=>Math.min(cps.length,completedCount()+1);

 const developerActive=()=>/developer mode/i.test(document.body.textContent||'') && !!document.querySelector('.tng-developer-dock,.tng-dev-dock,[class*="developer"]');

 const chooseGameMap=()=>{
   const all=[];
   if(window.TNG_LIVE_GAME_MAP)all.push(window.TNG_LIVE_GAME_MAP);
   (window.TNG_LIVE_GAME_MAPS||[]).forEach(m=>{if(!all.includes(m))all.push(m);});
   let best=null,bestScore=-1;
   all.forEach(map=>{
     try{
       const c=map.getContainer();if(!c||!c.isConnected)return;
       const r=c.getBoundingClientRect();if(r.width<250||r.height<220)return;
       let score=r.width*r.height;
       if(c.closest('.tng-game-runtime'))score+=1000000;
       const heading=[...document.querySelectorAll('h2,h3')].find(h=>/^game map$/i.test((h.textContent||'').trim()));
       if(heading){const section=heading.closest('section,article,.tng-runtime-panel,.tng-game-panel,.tng-trail-panel,div');if(section&&section.contains(c))score+=3000000;}
       if(score>bestScore){bestScore=score;best=map;}
     }catch(e){}
   });
   if(best)window.TNG_LIVE_GAME_MAP=best;
   return best;
 };

 const compactLayout=()=>{
   const key=document.querySelector('.tng-journey-key');if(!key)return;
   const heading=[...document.querySelectorAll('h2,h3')].find(h=>/^checkpoints$/i.test((h.textContent||'').trim()));
   if(!heading)return;
   const panel=heading.closest('.tng-trail-panel,.tng-runtime-panel,.tng-game-panel,section,article,div');
   if(panel&&!panel.contains(key))heading.parentElement.appendChild(key);
 };

 const addKey=()=>{
   const heading=[...document.querySelectorAll('h2,h3')].find(h=>/^checkpoints$/i.test((h.textContent||'').trim()));
   if(!heading||document.querySelector('.tng-route-progress-key'))return;
   const el=document.createElement('div');el.className='tng-route-progress-key';el.innerHTML='<span class="is-done"><i></i>Completed</span><span class="is-current"><i></i>Current leg</span><span class="is-ahead"><i></i>Route ahead</span>';
   heading.insertAdjacentElement('afterend',el);
 };

 const ensureDistanceCard=()=>{
   let el=document.querySelector('.tng-next-distance');if(el)return el;
   const heading=[...document.querySelectorAll('h2,h3')].find(h=>/^game map$/i.test((h.textContent||'').trim()));if(!heading)return null;
   const copy=heading.parentElement&&heading.parentElement.querySelector('p');
   el=document.createElement('div');el.className='tng-next-distance';el.innerHTML='<div class="tng-next-distance__main"><span class="tng-next-distance__eyebrow">Up next</span><span class="tng-next-distance__title">Finding next checkpoint…</span></div><span class="tng-next-distance__metric">Locating…</span>';
   (copy||heading).insertAdjacentElement('afterend',el);return el;
 };
 const fmtDistance=m=>{if(!Number.isFinite(m))return '—';const ft=m*3.28084;if(ft<1000)return Math.max(0,Math.round(ft))+' ft away';return (m/1609.344).toFixed(m<1609?2:1)+' mi away';};

 let lastPos=null;
 let nativePositionReceived=false;
 const developerPosition=()=>{
   if(!developerActive())return null;
   const done=completedCount();
   if(done>0&&cps[done-1])return [Number(cps[done-1].lat),Number(cps[done-1].lng)];
   if(cps[0])return [Number(cps[0].lat),Number(cps[0].lng)];
   return null;
 };
 const updateDistance=()=>{
   const el=ensureDistanceCard();if(!el)return;
   const done=completedCount();
   const n=Math.min(cps.length,done+1);
   const cp=cps[n-1];
   if(!cp){el.querySelector('.tng-next-distance__title').textContent='Adventure complete';el.querySelector('.tng-next-distance__metric').textContent='Complete';el.classList.add('is-arrived');return;}
   el.querySelector('.tng-next-distance__title').textContent=cp.title||('Checkpoint '+n);
   let pos=lastPos;
   if(!nativePositionReceived){const dev=developerPosition();if(dev){pos=dev;el.classList.add('is-developer');}else el.classList.remove('is-developer');}
   if(!pos){el.querySelector('.tng-next-distance__metric').textContent='Locating…';return;}
   const m=hav(pos,[Number(cp.lat),Number(cp.lng)]);
   el.querySelector('.tng-next-distance__metric').textContent=fmtDistance(m);
   el.classList.toggle('is-near',m<=100);
   el.classList.toggle('is-arrived',m<=35);
 };
 const watchLocation=()=>{
   if(!navigator.geolocation)return;
   try{navigator.geolocation.watchPosition(pos=>{nativePositionReceived=true;lastPos=[pos.coords.latitude,pos.coords.longitude];updateDistance();},()=>{updateDistance();},{enableHighAccuracy:true,maximumAge:3000,timeout:12000});}catch(e){updateDistance();}
 };

 const decorateNext=()=>{
   const n=currentIndex();
   document.querySelectorAll('.tng-game-runtime .leaflet-marker-icon').forEach(m=>{
     m.classList.remove('tng-next-checkpoint');
     const raw=(m.textContent||'').trim();
     if(/^\d+$/.test(raw)&&Number(raw)===n)m.classList.add('tng-next-checkpoint');
   });
 };

 const renderRoute=async()=>{
   if(!cfg.gpx||!window.L)return;
   let map=chooseGameMap();
   for(let i=0;i<60&&!map;i++){await new Promise(r=>setTimeout(r,100));map=chooseGameMap();}
   if(!map)return;

   let text;try{text=await fetch(cfg.gpx,{credentials:'same-origin'}).then(r=>{if(!r.ok)throw new Error('GPX '+r.status);return r.text();});}catch(e){return;}
   const xml=new DOMParser().parseFromString(text,'application/xml');
   const nodes=[...xml.querySelectorAll('trkpt,rtept')];
   let pts=nodes.map(n=>[parseFloat(n.getAttribute('lat')),parseFloat(n.getAttribute('lon'))]).filter(p=>Number.isFinite(p[0])&&Number.isFinite(p[1]));
   if(pts.length<2)return;

   let cpRoute=cps.map(cp=>nearestIndex(pts,Number(cp.lat),Number(cp.lng)));
   const first=cpRoute.find(Number.isFinite),last=[...cpRoute].reverse().find(Number.isFinite);
   if(Number.isFinite(first)&&Number.isFinite(last)&&first>last){pts=pts.slice().reverse();cpRoute=cps.map(cp=>nearestIndex(pts,Number(cp.lat),Number(cp.lng)));}

   let progressPane='tngRouteProgress';
   try{
     if(!map.getPane(progressPane)){
       const pane=map.createPane(progressPane);
       pane.style.zIndex='450';
       pane.style.pointerEvents='none';
     }
   }catch(e){progressPane='overlayPane';}

   let layers=[];
   const clear=()=>{layers.forEach(l=>{try{map.removeLayer(l)}catch(e){}});layers=[];};
   const add=(segment,opts)=>{
     if(segment.length<2)return;
     const l=L.polyline(segment,{...opts,pane:progressPane,interactive:false}).addTo(map);
     try{l.bringToFront();}catch(e){}
     layers.push(l);
   };

   const redraw=()=>{
     clear();
     const done=completedCount();
     const next=Math.min(cps.length,done+1);
     let completedIdx=done>0?(cpRoute[Math.min(done-1,cpRoute.length-1)]??0):0;
     let nextIdx=next>0?(cpRoute[Math.min(next-1,cpRoute.length-1)]??pts.length-1):pts.length-1;
     completedIdx=Math.max(0,Math.min(pts.length-1,completedIdx));
     nextIdx=Math.max(0,Math.min(pts.length-1,nextIdx));

     // If a route folds back on itself, preserve visible progression rather than swapping states.
     if(nextIdx<completedIdx)nextIdx=completedIdx;

     // Cover the original GPX first, then paint progress states above it.
     add(pts,{color:'#edb49a',weight:10,opacity:.9,lineCap:'round',lineJoin:'round'});
     if(completedIdx>0)add(pts.slice(0,completedIdx+1),{color:'#26724c',weight:10,opacity:1,lineCap:'round',lineJoin:'round'});
     if(nextIdx>completedIdx)add(pts.slice(completedIdx,nextIdx+1),{color:'#f26722',weight:10,opacity:1,lineCap:'round',lineJoin:'round'});

     decorateNext();
     updateDistance();
   };

   redraw();
   let timer=null;
   new MutationObserver(()=>{clearTimeout(timer);timer=setTimeout(redraw,120);}).observe(document.body,{childList:true,subtree:true,characterData:true,attributes:true,attributeFilter:['class']});
 };

 const start=()=>{
   compactLayout();addKey();ensureDistanceCard();decorateNext();watchLocation();renderRoute();updateDistance();
   let timer=null;
   new MutationObserver(()=>{clearTimeout(timer);timer=setTimeout(()=>{compactLayout();addKey();decorateNext();updateDistance();},120);}).observe(document.body,{childList:true,subtree:true,characterData:true});
   setInterval(()=>{decorateNext();updateDistance();},1500);
 };
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start);else start();
})();
JS
        ,'after');
    }
}
TNG_Live_Route_Progress::boot();
