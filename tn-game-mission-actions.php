<?php
/**
 * TN Game Mission Actions
 * Adds map focus, directions, and progress context to the Active Mission card.
 */
if (!defined('ABSPATH')) exit;

final class TNG_Mission_Actions {
    public static function boot(): void { add_action('wp_enqueue_scripts',[__CLASS__,'enqueue'],110); }

    private static function is_gameplay(): bool {
        if (is_admin()) return false;
        $uri=(string)($_SERVER['REQUEST_URI']??'');
        return strpos($uri,'/game-play/')!==false || (function_exists('is_page') && is_page('game-play'));
    }

    public static function enqueue(): void {
        if(!self::is_gameplay()) return;
        $game_id=absint($_GET['game']??0); if(!$game_id) return;
        $raw=get_post_meta($game_id,'tng_game_checkpoints',true); if(!is_array($raw)||!$raw) return;

        $points=[];
        foreach($raw as $i=>$cp){
            if(!is_array($cp)) continue;
            $lat=isset($cp['latitude'])&&is_numeric($cp['latitude'])?(float)$cp['latitude']:null;
            $lng=isset($cp['longitude'])&&is_numeric($cp['longitude'])?(float)$cp['longitude']:null;
            $points[]=[
                'index'=>$i+1,
                'title'=>(string)($cp['title']??('Checkpoint '.($i+1))),
                'lat'=>$lat,
                'lng'=>$lng,
            ];
        }

        wp_register_style('tng-mission-actions',false,[],TNG_OS_VERSION);wp_enqueue_style('tng-mission-actions');
        wp_add_inline_style('tng-mission-actions','
        .tng-active-mission{position:relative;overflow:hidden}.tng-mission-progress{position:absolute;left:0;right:0;bottom:0;height:3px;background:#e8eee9}.tng-mission-progress__fill{display:block;height:100%;background:#f26722;border-radius:0 3px 3px 0;transition:width .28s ease}.tng-mission-actions{display:flex;align-items:center;gap:7px;flex-wrap:wrap}.tng-mission-action{appearance:none;border:1px solid #d9e4dc;background:#fff;color:#173b2a;border-radius:10px;padding:8px 10px;font-size:10px;font-weight:900;line-height:1;cursor:pointer;text-decoration:none;white-space:nowrap}.tng-mission-action:hover{border-color:#f26722;color:#d95316}.tng-mission-action.is-primary{border-color:#173b2a;background:#173b2a;color:#fff}.tng-mission-action.is-primary:hover{color:#fff;filter:brightness(1.08)}.tng-mission-action[disabled]{opacity:.42;cursor:not-allowed}.tng-active-mission__actions{flex-wrap:wrap;justify-content:flex-end}.tng-active-mission__actions>.tng-active-mission__button{display:none}.tng-mission-count{font-weight:900;color:#173b2a}
        @media(max-width:700px){.tng-active-mission__actions{grid-column:1/-1;justify-content:flex-start}.tng-mission-actions{width:100%}.tng-mission-action{flex:1;text-align:center;padding:10px 9px}.tng-active-mission__status{order:-1}.tng-mission-progress{height:4px}}
        ');

        wp_register_script('tng-mission-actions','',[],TNG_OS_VERSION,true);wp_enqueue_script('tng-mission-actions');
        wp_localize_script('tng-mission-actions','TNG_MISSION_ACTIONS',['checkpoints'=>$points]);
        wp_add_inline_script('tng-mission-actions', <<<'JS'
(()=>{
 const cfg=window.TNG_MISSION_ACTIONS||{};
 const cps=Array.isArray(cfg.checkpoints)?cfg.checkpoints:[];
 if(!cps.length)return;

 const completedCount=()=>{
   const dock=document.querySelector('.tng-gameplay-dock,.tng-player-dock,.tng-game-dock');
   if(dock){const m=(dock.textContent||'').match(/(\d+)\s*\/\s*(\d+)\s*checkpoints/i);if(m)return Math.max(0,Math.min(cps.length,parseInt(m[1],10)));}
   let done=0;
   document.querySelectorAll('.tng-runtime-stop,.tng-game-checkpoint,.tng-checkpoint-card').forEach(card=>{
     const t=(card.textContent||'').toLowerCase();
     if(card.classList.contains('is-complete')||card.classList.contains('completed')||/completed\s*[·-]|claimed/.test(t))done++;
   });
   return Math.min(cps.length,done);
 };

 const activeCard=()=>{
   const cards=[...document.querySelectorAll('.tng-runtime-stop,.tng-game-checkpoint,.tng-checkpoint-card')];
   return cards.find(card=>{
     const t=(card.textContent||'').toLowerCase();
     return !/completed\s*[·-]|claimed|locked until previous/.test(t) && (card.classList.contains('is-current')||card.classList.contains('current')||/use my location|location check-in/.test(t));
   })||null;
 };

 const gameplayMap=()=>{
   if(window.TNG_LIVE_GAME_MAP&&window.TNG_LIVE_GAME_MAP.getContainer)return window.TNG_LIVE_GAME_MAP;
   const maps=Array.isArray(window.TNG_LIVE_GAME_MAPS)?window.TNG_LIVE_GAME_MAPS:[];
   let best=null,bestArea=0;
   maps.forEach(map=>{try{const c=map.getContainer&&map.getContainer();if(!c||!c.isConnected)return;const r=c.getBoundingClientRect();const area=Math.max(0,r.width)*Math.max(0,r.height);if(area>bestArea){best=map;bestArea=area;}}catch(e){}});
   return best;
 };

 const mapContainer=()=>{
   const map=gameplayMap();if(map&&map.getContainer)return map.getContainer();
   const heading=[...document.querySelectorAll('h2,h3')].find(x=>/^game map$/i.test((x.textContent||'').trim()));
   if(!heading)return null;
   const section=heading.closest('section,.tng-card,.tng-game-runtime')||heading.parentElement;
   return section?section.querySelector('.leaflet-container'):document.querySelector('.leaflet-container');
 };

 const focusMap=(cp,n)=>{
   const map=gameplayMap();const container=mapContainer();
   if(container)container.scrollIntoView({behavior:'smooth',block:'center'});
   if(map&&Number.isFinite(Number(cp.lat))&&Number.isFinite(Number(cp.lng))){
     setTimeout(()=>{try{map.invalidateSize&&map.invalidateSize();const z=Math.max(Number(map.getZoom?map.getZoom():15)||15,16);map.setView([Number(cp.lat),Number(cp.lng)],z,{animate:true});}catch(e){}},350);
   }
   setTimeout(()=>{
     const icons=[...document.querySelectorAll('.leaflet-marker-icon,.leaflet-div-icon')];
     const marker=icons.find(el=>(el.textContent||'').trim()===String(n));
     if(marker){try{marker.click();}catch(e){} marker.animate([{transform:(marker.style.transform||'')+' scale(1)'},{filter:'drop-shadow(0 0 0 rgba(242,103,34,0))'},{filter:'drop-shadow(0 0 10px rgba(242,103,34,.8))'},{filter:'drop-shadow(0 0 0 rgba(242,103,34,0))'}],{duration:950});}
   },650);
 };

 const viewCard=()=>{const card=activeCard();if(card){card.scrollIntoView({behavior:'smooth',block:'center'});try{card.animate([{boxShadow:'0 0 0 0 rgba(242,103,34,.4)'},{boxShadow:'0 0 0 10px rgba(242,103,34,0)'}],{duration:850});}catch(e){}}};

 const enhance=()=>{
   const el=document.querySelector('.tng-active-mission');if(!el||el.classList.contains('is-complete'))return;
   const done=completedCount(),n=Math.min(cps.length,done+1),cp=cps[n-1]||{};
   let actions=el.querySelector('.tng-mission-actions');
   if(!actions){actions=document.createElement('div');actions.className='tng-mission-actions';const host=el.querySelector('.tng-active-mission__actions')||el;host.appendChild(actions);}
   const hasCoords=Number.isFinite(Number(cp.lat))&&Number.isFinite(Number(cp.lng));
   const dir=hasCoords?'https://www.google.com/maps/dir/?api=1&destination='+encodeURIComponent(cp.lat+','+cp.lng):'#';
   actions.innerHTML=`<button type="button" class="tng-mission-action" data-action="focus">◎ Focus map</button><a class="tng-mission-action" data-action="directions" ${hasCoords?'href="'+dir+'" target="_blank" rel="noopener"':'aria-disabled="true"'}>↗ Directions</a><button type="button" class="tng-mission-action is-primary" data-action="checkpoint">View checkpoint</button>`;
   const d=actions.querySelector('[data-action="directions"]');if(d&&!hasCoords){d.addEventListener('click',e=>e.preventDefault());d.style.opacity='.42';}
   actions.querySelector('[data-action="focus"]')?.addEventListener('click',()=>focusMap(cp,n));
   actions.querySelector('[data-action="checkpoint"]')?.addEventListener('click',viewCard);
   let bar=el.querySelector('.tng-mission-progress');if(!bar){bar=document.createElement('div');bar.className='tng-mission-progress';bar.innerHTML='<span class="tng-mission-progress__fill"></span>';el.appendChild(bar);}
   const pct=cps.length?Math.max(0,Math.min(100,(done/cps.length)*100)):0;bar.querySelector('.tng-mission-progress__fill').style.width=pct+'%';
   const meta=el.querySelector('.tng-active-mission__meta');if(meta){let count=meta.querySelector('.tng-mission-count');if(!count){count=document.createElement('span');count.className='tng-mission-count';meta.prepend(count);}count.textContent='Adventure '+done+'/'+cps.length+' complete';}
 };

 let timer=null;const queue=()=>{clearTimeout(timer);timer=setTimeout(enhance,120);};
 const start=()=>{enhance();new MutationObserver(queue).observe(document.body,{childList:true,subtree:true,characterData:true});setInterval(enhance,2500);};
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start);else start();
})();
JS
        ,'after');
    }
}
TNG_Mission_Actions::boot();
