<?php
/**
 * TN Game Live Route Progress
 * Live route coloring, user position, follow mode, and distance guidance.
 */
if (!defined('ABSPATH')) exit;

final class TNG_Live_Route_Progress {
    public static function boot(): void { add_action('wp_enqueue_scripts',[__CLASS__,'enqueue'],99); }

    private static function is_gameplay(): bool {
        if (is_admin()) return false;
        $uri=(string)($_SERVER['REQUEST_URI']??'');
        return strpos($uri,'/game-play/')!==false || (function_exists('is_page') && is_page('game-play'));
    }

    public static function enqueue(): void {
        if(!self::is_gameplay()) return;
        $game_id=absint($_GET['game']??0); if(!$game_id) return;
        $cps=get_post_meta($game_id,'tng_game_checkpoints',true); if(!is_array($cps)) $cps=[];
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

        wp_register_style('tng-live-route-progress',false,[],TNG_OS_VERSION);wp_enqueue_style('tng-live-route-progress');
        wp_add_inline_style('tng-live-route-progress','
        .tng-journey-key{margin:0!important}.tng-game-runtime .tng-journey-key{grid-column:auto!important}.tng-runtime-list-wrap,.tng-runtime-checkpoints,.tng-game-checkpoints{align-self:start!important}
        .tng-route-progress-key{display:flex;flex-wrap:wrap;gap:8px;margin:7px 0 0}.tng-route-progress-key span{display:inline-flex;align-items:center;gap:5px;color:#6c7971;font-size:10px;font-weight:800}.tng-route-progress-key i{display:block;width:18px;height:4px;border-radius:999px}.tng-route-progress-key .is-done i{background:#26724c}.tng-route-progress-key .is-current i{background:#f26722}.tng-route-progress-key .is-ahead i{background:#edb49a}
        .tng-next-distance{display:flex;align-items:center;justify-content:space-between;gap:14px;margin:10px 0 0;padding:10px 13px;border:1px solid #dfe8e2;border-radius:14px;background:#f8faf8;color:#173b2a;max-width:420px}.tng-next-distance__main{min-width:0}.tng-next-distance__eyebrow{display:block;margin-bottom:2px;color:#f26722;font-size:9px;font-weight:900;letter-spacing:.09em;text-transform:uppercase}.tng-next-distance__title{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:13px;font-weight:900}.tng-next-distance__metric{flex:0 0 auto;padding:7px 10px;border-radius:999px;background:#fff;border:1px solid #e1e8e3;color:#173b2a;font-size:12px;font-weight:900}.tng-next-distance.is-near .tng-next-distance__metric{background:#eaf5ee;border-color:#cce5d5;color:#26724c}.tng-next-distance.is-arrived .tng-next-distance__metric{background:#26724c;border-color:#26724c;color:#fff}.tng-next-distance.is-developer .tng-next-distance__eyebrow:after{content:" · Developer GPS";color:#53695d}
        .tng-game-runtime .leaflet-marker-icon.tng-next-checkpoint{z-index:1000!important;filter:drop-shadow(0 0 7px rgba(242,103,34,.55))}.tng-game-runtime .leaflet-marker-icon.tng-next-checkpoint::before{content:"";position:absolute;inset:-7px;border:2px solid rgba(242,103,34,.55);border-radius:999px;animation:tngNextPulse 1.6s ease-out infinite;pointer-events:none}@keyframes tngNextPulse{0%{transform:scale(.85);opacity:1}100%{transform:scale(1.45);opacity:0}}
        .tng-live-location-dot{width:18px;height:18px;border:3px solid #fff;border-radius:999px;background:#1677ff;box-shadow:0 2px 8px rgba(18,55,88,.28),0 0 0 5px rgba(22,119,255,.16)}
        .tng-follow-control{display:flex!important;align-items:center!important;gap:6px!important;width:auto!important;height:34px!important;padding:0 11px!important;border:0!important;border-radius:999px!important;background:#fff!important;color:#173b2a!important;font:800 11px/34px inherit!important;box-shadow:0 3px 12px rgba(21,55,39,.18)!important;text-decoration:none!important}.tng-follow-control:before{content:"◎";font-size:14px}.tng-follow-control.is-following{background:#173b2a!important;color:#fff!important}.tng-follow-control.is-following:before{content:"●";color:#62c78c}.tng-follow-control:hover{background:#f7faf8!important}
        @media(min-width:900px){.tng-game-runtime .tng-runtime-layout,.tng-game-runtime .tng-game-body,.tng-game-runtime .tng-play-grid{align-items:start!important}.tng-game-runtime .tng-journey-key{position:relative!important;top:auto!important;left:auto!important;right:auto!important;bottom:auto!important}}
        @media(max-width:640px){.tng-next-distance{align-items:flex-start;max-width:none}.tng-next-distance__metric{font-size:11px}}
        ');

        wp_add_inline_script('leaflet', <<<'JS'
(()=>{
 if(!window.L||!L.map||L.map.__tngCaptured)return;
 window.TNG_LIVE_GAME_MAPS=window.TNG_LIVE_GAME_MAPS||[];
 const original=L.map;
 const wrapped=function(){const map=original.apply(this,arguments);window.TNG_LIVE_GAME_MAPS.push(map);try{const c=map.getContainer&&map.getContainer();if(c&&c.closest&&c.closest('.tng-game-runtime'))window.TNG_LIVE_GAME_MAP=map;}catch(e){}return map;};
 Object.keys(original).forEach(k=>{try{wrapped[k]=original[k]}catch(e){}});wrapped.__tngCaptured=true;L.map=wrapped;
})();
JS
        ,'after');

        wp_register_script('tng-live-route-progress','',[],TNG_OS_VERSION,true);wp_enqueue_script('tng-live-route-progress');
        wp_localize_script('tng-live-route-progress','TNG_LIVE_ROUTE_PROGRESS',['checkpoints'=>$checkpoint_data]);
        wp_add_inline_script('tng-live-route-progress', <<<'JS'
(()=>{
 const cfg=window.TNG_LIVE_ROUTE_PROGRESS||{};
 const cps=Array.isArray(cfg.checkpoints)?cfg.checkpoints:[];
 const hav=(a,b)=>{const R=6371000,p1=a[0]*Math.PI/180,p2=b[0]*Math.PI/180,dp=(b[0]-a[0])*Math.PI/180,dl=(b[1]-a[1])*Math.PI/180,h=Math.sin(dp/2)**2+Math.cos(p1)*Math.cos(p2)*Math.sin(dl/2)**2;return 2*R*Math.asin(Math.sqrt(h));};
 const nearestIndex=(pts,p)=>{let bi=0,bd=Infinity;pts.forEach((x,i)=>{const d=hav(x,p);if(d<bd){bd=d;bi=i;}});return bi;};
 const flattenLatLngs=v=>{const out=[];(function walk(x){if(!x)return;if(Array.isArray(x)){x.forEach(walk);return;}if(Number.isFinite(x.lat)&&Number.isFinite(x.lng))out.push([Number(x.lat),Number(x.lng)]);})(v);return out;};

 const completedCount=()=>{
   const dock=document.querySelector('.tng-gameplay-dock,.tng-player-dock,.tng-game-dock');
   if(dock){const m=(dock.textContent||'').match(/(\d+)\s*\/\s*(\d+)\s*checkpoints/i);if(m)return Math.max(0,Math.min(cps.length,parseInt(m[1],10)));}
   let done=0;document.querySelectorAll('.tng-runtime-stop,.tng-game-checkpoint,.tng-checkpoint-card').forEach(card=>{const t=(card.textContent||'').toLowerCase();if(card.classList.contains('is-complete')||card.classList.contains('completed')||/completed\s*[·-]|claimed/.test(t))done++;});return Math.min(cps.length,done);
 };
 const currentIndex=()=>Math.min(cps.length,completedCount()+1);
 const developerActive=()=>!!document.querySelector('.tng-developer-dock,.tng-dev-dock,[class*="developer"]')&&/developer mode/i.test(document.body.textContent||'');

 const chooseGameMap=()=>{
   const all=[];if(window.TNG_LIVE_GAME_MAP)all.push(window.TNG_LIVE_GAME_MAP);(window.TNG_LIVE_GAME_MAPS||[]).forEach(m=>{if(!all.includes(m))all.push(m);});
   let best=null,score=-1;all.forEach(map=>{try{const c=map.getContainer();if(!c||!c.isConnected)return;const r=c.getBoundingClientRect();if(r.width<250||r.height<220)return;let s=r.width*r.height;if(c.closest('.tng-game-runtime'))s+=1000000;const h=[...document.querySelectorAll('h2,h3')].find(x=>/^game map$/i.test((x.textContent||'').trim()));if(h){const p=h.closest('section,article,.tng-runtime-panel,.tng-game-panel,.tng-trail-panel,div');if(p&&p.contains(c))s+=3000000;}if(s>score){score=s;best=map;}}catch(e){}});if(best)window.TNG_LIVE_GAME_MAP=best;return best;
 };

 const checkpointMarker=(map,n)=>{let found=null;try{map.eachLayer(layer=>{if(found||!layer.getLatLng||!layer._icon)return;const raw=(layer._icon.textContent||'').trim();if(/^\d+$/.test(raw)&&Number(raw)===Number(n))found=layer;});}catch(e){}return found;};
 const checkpointPosition=(map,n)=>{const marker=checkpointMarker(map,n);if(marker){const p=marker.getLatLng();return [Number(p.lat),Number(p.lng)];}const cp=cps[n-1];return cp&&Number.isFinite(Number(cp.lat))&&Number.isFinite(Number(cp.lng))?[Number(cp.lat),Number(cp.lng)]:null;};

 const compactLayout=()=>{const key=document.querySelector('.tng-journey-key'),h=[...document.querySelectorAll('h2,h3')].find(x=>/^checkpoints$/i.test((x.textContent||'').trim()));if(key&&h){const panel=h.closest('.tng-trail-panel,.tng-runtime-panel,.tng-game-panel,section,article,div');if(panel&&!panel.contains(key))h.parentElement.appendChild(key);}};
 const addKey=()=>{const h=[...document.querySelectorAll('h2,h3')].find(x=>/^checkpoints$/i.test((x.textContent||'').trim()));if(!h||document.querySelector('.tng-route-progress-key'))return;const el=document.createElement('div');el.className='tng-route-progress-key';el.innerHTML='<span class="is-done"><i></i>Completed</span><span class="is-current"><i></i>Current leg</span><span class="is-ahead"><i></i>Route ahead</span>';h.insertAdjacentElement('afterend',el);};
 const ensureDistanceCard=()=>{let el=document.querySelector('.tng-next-distance');if(el)return el;const h=[...document.querySelectorAll('h2,h3')].find(x=>/^game map$/i.test((x.textContent||'').trim()));if(!h)return null;const copy=h.parentElement&&h.parentElement.querySelector('p');el=document.createElement('div');el.className='tng-next-distance';el.innerHTML='<div class="tng-next-distance__main"><span class="tng-next-distance__eyebrow">Up next</span><span class="tng-next-distance__title">Finding next checkpoint…</span></div><span class="tng-next-distance__metric">Locating…</span>';(copy||h).insertAdjacentElement('afterend',el);return el;};
 const fmtDistance=m=>{if(!Number.isFinite(m))return '—';const ft=m*3.28084;if(ft<1000)return Math.round(ft)+' ft away';return (m/1609.344).toFixed(m<1609?2:1)+' mi away';};

 let nativePos=null,nativeAccuracy=0,locationMarker=null,accuracyCircle=null,followMode=true,programmaticMove=false,routeRedraw=null;
 const updateFollowButton=()=>{const b=document.querySelector('.tng-follow-control');if(!b)return;b.classList.toggle('is-following',followMode);b.textContent=followMode?'Following':'Follow me';};
 const ensureFollowControl=map=>{if(!window.L||!map||document.querySelector('.tng-follow-control'))return;const Control=L.Control.extend({options:{position:'bottomright'},onAdd:function(){const a=L.DomUtil.create('a','tng-follow-control is-following');a.href='#';a.setAttribute('role','button');a.textContent='Following';L.DomEvent.disableClickPropagation(a);L.DomEvent.on(a,'click',e=>{L.DomEvent.preventDefault(e);followMode=true;updateFollowButton();if(nativePos){programmaticMove=true;map.setView(nativePos,Math.max(map.getZoom()||15,16),{animate:true});setTimeout(()=>programmaticMove=false,500);}});return a;}});new Control().addTo(map);updateFollowButton();
   map.on('dragstart',()=>{if(!programmaticMove){followMode=false;updateFollowButton();}});map.on('zoomstart',()=>{if(!programmaticMove){followMode=false;updateFollowButton();}});
 };
 const drawUserLocation=map=>{if(!map||!nativePos||!window.L)return;window.TNG_LIVE_USER_POSITION={lat:nativePos[0],lng:nativePos[1],accuracy:nativeAccuracy};if(!accuracyCircle)accuracyCircle=L.circle(nativePos,{radius:Math.max(4,nativeAccuracy||8),color:'#1677ff',weight:1,opacity:.35,fillColor:'#1677ff',fillOpacity:.08,interactive:false}).addTo(map);else{accuracyCircle.setLatLng(nativePos);accuracyCircle.setRadius(Math.max(4,nativeAccuracy||8));}if(!locationMarker){const icon=L.divIcon({className:'',html:'<div class="tng-live-location-dot"></div>',iconSize:[18,18],iconAnchor:[9,9]});locationMarker=L.marker(nativePos,{icon,zIndexOffset:1800,interactive:false}).addTo(map);}else locationMarker.setLatLng(nativePos);if(followMode){programmaticMove=true;map.panTo(nativePos,{animate:true,duration:.35});setTimeout(()=>programmaticMove=false,450);}};

 const updateDistance=()=>{const el=ensureDistanceCard(),map=chooseGameMap();if(!el||!map)return;const done=completedCount(),n=Math.min(cps.length,done+1),target=checkpointPosition(map,n);if(!target){el.querySelector('.tng-next-distance__title').textContent='Adventure complete';el.querySelector('.tng-next-distance__metric').textContent='Complete';return;}el.querySelector('.tng-next-distance__title').textContent=(cps[n-1]&&cps[n-1].title)||('Checkpoint '+n);let pos=null;if(developerActive()){pos=done>0?checkpointPosition(map,done):checkpointPosition(map,1);el.classList.add('is-developer');}else{pos=nativePos;el.classList.remove('is-developer');}if(!pos){el.querySelector('.tng-next-distance__metric').textContent='Locating…';return;}const m=hav(pos,target);el.querySelector('.tng-next-distance__metric').textContent=m<=35?'You’ve arrived':fmtDistance(m);el.classList.toggle('is-near',m<=100);el.classList.toggle('is-arrived',m<=35);};
 const watchLocation=()=>{if(!navigator.geolocation)return;try{navigator.geolocation.watchPosition(p=>{nativePos=[Number(p.coords.latitude),Number(p.coords.longitude)];nativeAccuracy=Number(p.coords.accuracy)||0;const map=chooseGameMap();if(map&&!developerActive()){ensureFollowControl(map);drawUserLocation(map);updateDistance();if(routeRedraw)routeRedraw();}},()=>updateDistance(),{enableHighAccuracy:true,maximumAge:2500,timeout:12000});}catch(e){}};

 const decorateNext=()=>{const n=currentIndex();document.querySelectorAll('.tng-game-runtime .leaflet-marker-icon').forEach(m=>{m.classList.remove('tng-next-checkpoint');const raw=(m.textContent||'').trim();if(/^\d+$/.test(raw)&&Number(raw)===n)m.classList.add('tng-next-checkpoint');});};
 const findVisibleRoute=map=>{let best=null,bestPts=[];try{map.eachLayer(layer=>{if(!layer||layer.__tngProgress||!layer.getLatLngs||!layer.setStyle)return;const pts=flattenLatLngs(layer.getLatLngs());if(pts.length<12)return;const opts=layer.options||{},color=String(opts.color||'').toLowerCase();let score=pts.length;if(color==='#f26722'||color.includes('f26722')||color==='orange')score+=100000;if((opts.weight||0)>=4)score+=1000;if(!best||score>(best.__tngScore||0)){best=layer;bestPts=pts;best.__tngScore=score;}});}catch(e){}return best?{layer:best,pts:bestPts}:null;};

 const renderRoute=async()=>{let map=chooseGameMap();for(let i=0;i<70&&!map;i++){await new Promise(r=>setTimeout(r,100));map=chooseGameMap();}if(!map)return;ensureFollowControl(map);let route=null;for(let i=0;i<50&&!route;i++){route=findVisibleRoute(map);if(!route)await new Promise(r=>setTimeout(r,100));}if(!route)return;let pts=route.pts.slice();const first=checkpointPosition(map,1),last=checkpointPosition(map,cps.length);if(first&&last&&nearestIndex(pts,first)>nearestIndex(pts,last))pts.reverse();try{route.layer.__tngOriginalStyle={color:route.layer.options.color,opacity:route.layer.options.opacity,weight:route.layer.options.weight};route.layer.setStyle({opacity:0});}catch(e){}let pane='tngRouteProgress';try{if(!map.getPane(pane)){const p=map.createPane(pane);p.style.zIndex='450';p.style.pointerEvents='none';}}catch(e){pane='overlayPane';}let layers=[];const clear=()=>{layers.forEach(l=>{try{map.removeLayer(l)}catch(e){}});layers=[];};const add=(seg,opt)=>{if(seg.length<2)return;const l=L.polyline(seg,{...opt,pane,interactive:false}).addTo(map);l.__tngProgress=true;layers.push(l);};
   const redraw=()=>{clear();const done=completedCount(),next=Math.min(cps.length,done+1),lastDonePos=done>0?checkpointPosition(map,done):pts[0],nextPos=checkpointPosition(map,next)||pts[pts.length-1];let a=nearestIndex(pts,lastDonePos),b=nearestIndex(pts,nextPos);if(b<a)b=a;let progressIndex=a;const live=!developerActive()&&nativePos?nearestIndex(pts,nativePos):a;if(live>=a&&live<=b)progressIndex=live;add(pts.slice(Math.max(0,b),pts.length),{color:'#edb49a',weight:9,opacity:.62,lineCap:'round',lineJoin:'round'});if(progressIndex>0)add(pts.slice(0,progressIndex+1),{color:'#26724c',weight:10,opacity:1,lineCap:'round',lineJoin:'round'});if(b>progressIndex)add(pts.slice(progressIndex,b+1),{color:'#f26722',weight:10,opacity:1,lineCap:'round',lineJoin:'round'});decorateNext();updateDistance();};routeRedraw=redraw;redraw();let timer=null;new MutationObserver(()=>{clearTimeout(timer);timer=setTimeout(redraw,120);}).observe(document.body,{childList:true,subtree:true,characterData:true,attributes:true,attributeFilter:['class']});};

 const start=()=>{compactLayout();addKey();ensureDistanceCard();decorateNext();watchLocation();renderRoute();updateDistance();let timer=null;new MutationObserver(()=>{clearTimeout(timer);timer=setTimeout(()=>{compactLayout();addKey();decorateNext();updateDistance();},120);}).observe(document.body,{childList:true,subtree:true,characterData:true});setInterval(()=>{decorateNext();updateDistance();},1200);};
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start);else start();
})();
JS
        ,'after');
    }
}
TNG_Live_Route_Progress::boot();
