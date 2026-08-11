<?php
/**
 * TN Game Active Mission Navigator
 * Reliable current objective, map focus, directions and adventure progress.
 */
if (!defined('ABSPATH')) exit;

final class TNG_Active_Mission_Navigator {
    public static function boot(): void { add_action('wp_enqueue_scripts',[__CLASS__,'enqueue'],100); }

    private static function is_gameplay(): bool {
        if (is_admin()) return false;
        $uri=(string)($_SERVER['REQUEST_URI']??'');
        return strpos($uri,'/game-play/')!==false || (function_exists('is_page') && is_page('game-play'));
    }

    private static function infer_role(array $cp): string {
        $role=sanitize_key((string)($cp['role']??''));
        if(in_array($role,['trail_start','top_sight','route','bonus','trail_finish'],true)) return $role;
        $title=strtolower(trim((string)($cp['title']??'')));
        if(preg_match('/trail start|trailhead|start point/',$title)) return 'trail_start';
        if(preg_match('/trail finish|finish point|route finish/',$title)) return 'trail_finish';
        if(!empty($cp['sight_id'])||!empty($cp['top_sight_id'])) return 'top_sight';
        return 'route';
    }

    public static function enqueue(): void {
        if(!self::is_gameplay()) return;
        $game_id=absint($_GET['game']??0); if(!$game_id) return;
        $cps=get_post_meta($game_id,'tng_game_checkpoints',true); if(!is_array($cps)||!$cps) return;

        $labels=['trail_start'=>'Trail Start','top_sight'=>'Top Sight','route'=>'Route Checkpoint','bonus'=>'Bonus Stop','trail_finish'=>'Trail Finish'];
        $icons=['trail_start'=>'🥾','top_sight'=>'📍','route'=>'●','bonus'=>'⭐','trail_finish'=>'🏁'];
        $data=[];
        foreach($cps as $i=>$cp){
            if(!is_array($cp)) continue;
            $role=self::infer_role($cp);
            $data[]=[
                'index'=>$i+1,
                'title'=>(string)($cp['title']??('Checkpoint '.($i+1))),
                'role'=>$role,
                'roleLabel'=>$labels[$role]??'Checkpoint',
                'icon'=>$icons[$role]??'●',
                'xp'=>(int)($cp['xp']??$cp['points']??25),
                'radius'=>(int)($cp['radius']??30),
                'lat'=>isset($cp['latitude'])&&is_numeric($cp['latitude'])?(float)$cp['latitude']:null,
                'lng'=>isset($cp['longitude'])&&is_numeric($cp['longitude'])?(float)$cp['longitude']:null,
            ];
        }

        wp_register_style('tng-active-mission-navigator',false,[],TNG_OS_VERSION);wp_enqueue_style('tng-active-mission-navigator');
        wp_add_inline_style('tng-active-mission-navigator','
        .tng-next-distance{display:none!important}
        .tng-active-mission{position:relative;display:grid;grid-template-columns:auto minmax(0,1fr);gap:11px 12px;width:min(100%,690px);margin:12px 0 14px;padding:13px 14px 16px;border:1px solid #dfe8e2;border-radius:16px;background:#fff;box-shadow:0 8px 24px rgba(19,54,38,.06);color:#173b2a;overflow:hidden}
        .tng-active-mission__step{display:grid;place-items:center;width:42px;height:42px;border-radius:13px;background:#f26722;color:#fff;font-weight:900;font-size:15px;box-shadow:0 5px 14px rgba(242,103,34,.22)}
        .tng-active-mission__body{min-width:0}.tng-active-mission__eyebrow{display:flex;align-items:center;gap:6px;margin-bottom:2px;color:#f26722;font-size:9px;font-weight:900;letter-spacing:.09em;text-transform:uppercase}.tng-active-mission__title{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:15px;font-weight:900}.tng-active-mission__meta{display:flex;flex-wrap:wrap;gap:7px;margin-top:5px;color:#67756d;font-size:10px;font-weight:800}.tng-active-mission__meta span{display:inline-flex;align-items:center;gap:4px}
        .tng-active-mission__footer{grid-column:1/-1;display:flex;align-items:center;gap:8px;flex-wrap:wrap}.tng-active-mission__status{padding:7px 9px;border-radius:999px;background:#f3f6f3;color:#5d6d63;font-size:10px;font-weight:900;white-space:nowrap}.tng-active-mission__status.is-ready{background:#eaf5ee;color:#26724c}.tng-active-mission__action{appearance:none;border:1px solid #d9e4dc;background:#fff;color:#173b2a;border-radius:10px;padding:8px 10px;font-size:10px;font-weight:900;line-height:1;cursor:pointer;text-decoration:none;white-space:nowrap}.tng-active-mission__action:hover{border-color:#f26722;color:#d95316}.tng-active-mission__action.is-primary{margin-left:auto;border-color:#173b2a;background:#173b2a;color:#fff}.tng-active-mission__progress{position:absolute;left:0;right:0;bottom:0;height:4px;background:#e8eee9}.tng-active-mission__progress span{display:block;height:100%;background:#f26722;transition:width .25s ease}.tng-active-mission.is-complete{display:block;background:#edf7f0;border-color:#cde5d6}.tng-active-mission.is-complete .tng-active-mission__eyebrow{color:#26724c}.tng-active-mission.is-complete .tng-active-mission__title{white-space:normal}
        @media(max-width:700px){.tng-active-mission{width:100%}.tng-active-mission__footer{align-items:stretch}.tng-active-mission__action{flex:1;text-align:center;padding:10px 8px}.tng-active-mission__action.is-primary{margin-left:0;flex-basis:100%}.tng-active-mission__status{width:100%;text-align:center}.tng-active-mission__title{font-size:14px}}
        ');

        wp_register_script('tng-active-mission-navigator','',[],TNG_OS_VERSION,true);wp_enqueue_script('tng-active-mission-navigator');
        wp_localize_script('tng-active-mission-navigator','TNG_ACTIVE_MISSION',['checkpoints'=>$data]);
        wp_add_inline_script('tng-active-mission-navigator', <<<'JS'
(()=>{
 const cfg=window.TNG_ACTIVE_MISSION||{};
 const cps=Array.isArray(cfg.checkpoints)?cfg.checkpoints:[];
 if(!cps.length)return;

 const completedCount=()=>{
   const dock=document.querySelector('.tng-gameplay-dock,.tng-player-dock,.tng-game-dock');
   if(dock){const m=(dock.textContent||'').match(/(\d+)\s*\/\s*(\d+)\s*checkpoints/i);if(m)return Math.max(0,Math.min(cps.length,parseInt(m[1],10)));}
   let done=0;document.querySelectorAll('.tng-runtime-stop,.tng-game-checkpoint,.tng-checkpoint-card').forEach(card=>{const t=(card.textContent||'').toLowerCase();if(card.classList.contains('is-complete')||card.classList.contains('completed')||/completed\s*[·-]|claimed/.test(t))done++;});return Math.min(cps.length,done);
 };
 const findGameMapHeading=()=>[...document.querySelectorAll('h2,h3')].find(x=>/^game map$/i.test((x.textContent||'').trim()));
 const ensure=()=>{let el=document.querySelector('.tng-active-mission');if(el)return el;const h=findGameMapHeading();if(!h)return null;el=document.createElement('div');el.className='tng-active-mission';const copy=(h.parentElement||h).querySelector('p');(copy||h).insertAdjacentElement('afterend',el);return el;};
 const cards=()=>[...document.querySelectorAll('.tng-runtime-stop,.tng-game-checkpoint,.tng-checkpoint-card')];
 const activeCard=()=>cards().find(card=>{const t=(card.textContent||'').toLowerCase();return !/completed\s*[·-]|claimed|locked until previous/.test(t)&&(card.classList.contains('is-current')||card.classList.contains('current')||/use my location|location check-in/.test(t));})||null;
 const gameplayMap=()=>{if(window.TNG_LIVE_GAME_MAP&&window.TNG_LIVE_GAME_MAP.getContainer)return window.TNG_LIVE_GAME_MAP;const maps=Array.isArray(window.TNG_LIVE_GAME_MAPS)?window.TNG_LIVE_GAME_MAPS:[];let best=null,area=0;maps.forEach(m=>{try{const c=m.getContainer();if(!c||!c.isConnected)return;const r=c.getBoundingClientRect(),a=r.width*r.height;if(a>area){area=a;best=m;}}catch(e){}});return best;};
 const mapContainer=()=>{const m=gameplayMap();if(m)return m.getContainer();const h=findGameMapHeading();const host=h&&(h.closest('section,.tng-card,.tng-game-runtime')||h.parentElement);return host?host.querySelector('.leaflet-container'):document.querySelector('.leaflet-container');};
 const focusMap=(cp,n)=>{const map=gameplayMap(),container=mapContainer();if(container)container.scrollIntoView({behavior:'smooth',block:'center'});if(map&&Number.isFinite(Number(cp.lat))&&Number.isFinite(Number(cp.lng))){setTimeout(()=>{try{map.invalidateSize();map.setView([Number(cp.lat),Number(cp.lng)],Math.max(map.getZoom()||15,16),{animate:true});}catch(e){}},300);}setTimeout(()=>{const marker=[...document.querySelectorAll('.leaflet-marker-icon,.leaflet-div-icon')].find(x=>(x.textContent||'').trim()===String(n));if(marker){try{marker.click();}catch(e){}}},600);};
 const viewCard=()=>{const card=activeCard();if(card){card.scrollIntoView({behavior:'smooth',block:'center'});try{card.animate([{boxShadow:'0 0 0 0 rgba(242,103,34,.4)'},{boxShadow:'0 0 0 10px rgba(242,103,34,0)'}],{duration:850});}catch(e){}}};

 const render=()=>{
   const el=ensure();if(!el)return;const done=completedCount();
   if(done>=cps.length){el.className='tng-active-mission is-complete';el.innerHTML='<span class="tng-active-mission__eyebrow">✓ Adventure complete</span><strong class="tng-active-mission__title">Every checkpoint on this route is complete.</strong><div class="tng-active-mission__meta"><span>Progress saved to your Explorer profile</span></div>';return;}
   const n=done+1,cp=cps[n-1]||{},pct=Math.round(done/cps.length*100),hasCoords=Number.isFinite(Number(cp.lat))&&Number.isFinite(Number(cp.lng));
   const dir=hasCoords?'https://www.google.com/maps/dir/?api=1&destination='+encodeURIComponent(cp.lat+','+cp.lng):'#';
   el.className='tng-active-mission';
   el.innerHTML=`<div class="tng-active-mission__step">${n}</div><div class="tng-active-mission__body"><span class="tng-active-mission__eyebrow">${cp.icon||'●'} Active mission · ${cp.roleLabel||'Checkpoint'}</span><strong class="tng-active-mission__title">${cp.title||('Checkpoint '+n)}</strong><div class="tng-active-mission__meta"><span>${n} of ${cps.length}</span><span>+${Number(cp.xp||0)} XP</span><span>GPS radius ${Number(cp.radius||30)} m</span><span>${done}/${cps.length} complete</span></div></div><div class="tng-active-mission__footer"><span class="tng-active-mission__status">In progress</span><button type="button" class="tng-active-mission__action" data-act="focus">◎ Focus map</button><a class="tng-active-mission__action" data-act="directions" ${hasCoords?'href="'+dir+'" target="_blank" rel="noopener"':'aria-disabled="true"'}>↗ Directions</a><button type="button" class="tng-active-mission__action is-primary" data-act="checkpoint">View checkpoint</button></div><div class="tng-active-mission__progress"><span style="width:${pct}%"></span></div>`;
   el.querySelector('[data-act="focus"]')?.addEventListener('click',()=>focusMap(cp,n));el.querySelector('[data-act="checkpoint"]')?.addEventListener('click',viewCard);const d=el.querySelector('[data-act="directions"]');if(d&&!hasCoords){d.addEventListener('click',e=>e.preventDefault());d.style.opacity='.4';}
   const card=activeCard();if(card){const t=(card.textContent||'').toLowerCase(),s=el.querySelector('.tng-active-mission__status');if(s&&/within 30 m|ready|arrived/.test(t)){s.textContent='Ready to check in';s.classList.add('is-ready');}}
 };
 let timer=null;const queue=()=>{clearTimeout(timer);timer=setTimeout(render,100);};const start=()=>{render();new MutationObserver(queue).observe(document.body,{childList:true,subtree:true,characterData:true});setInterval(render,2500);};if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start);else start();
})();
JS
        ,'after');
    }
}
TNG_Active_Mission_Navigator::boot();
