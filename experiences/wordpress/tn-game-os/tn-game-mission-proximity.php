<?php
/**
 * TN Game Mission Proximity Intelligence
 * Adds live distance and proximity states to the active mission card.
 */
if (!defined('ABSPATH')) exit;

final class TNG_Mission_Proximity {
    public static function boot(): void { add_action('wp_enqueue_scripts',[__CLASS__,'enqueue'],115); }

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
            $points[]=[
                'index'=>$i+1,
                'title'=>(string)($cp['title']??('Checkpoint '.($i+1))),
                'lat'=>isset($cp['latitude'])&&is_numeric($cp['latitude'])?(float)$cp['latitude']:null,
                'lng'=>isset($cp['longitude'])&&is_numeric($cp['longitude'])?(float)$cp['longitude']:null,
                'radius'=>(int)($cp['radius']??30),
            ];
        }

        wp_register_style('tng-mission-proximity',false,[],TNG_OS_VERSION);wp_enqueue_style('tng-mission-proximity');
        wp_add_inline_style('tng-mission-proximity','
        .tng-mission-proximity{display:inline-flex;align-items:center;gap:6px;padding:7px 9px;border-radius:999px;background:#f4f7f5;color:#5d6d63;font-size:10px;font-weight:900;white-space:nowrap}.tng-mission-proximity::before{content:"●";font-size:8px;color:#8b9990}.tng-mission-proximity.is-near{background:#fff4e9;color:#b8551f}.tng-mission-proximity.is-near::before{color:#f26722}.tng-mission-proximity.is-arrived{background:#e7f5eb;color:#236a46}.tng-mission-proximity.is-arrived::before{color:#2f8b5b}.tng-active-mission__status.is-arrived{background:#dff2e5;color:#226743}.tng-active-mission__action.is-checkin{border-color:#f26722;background:#f26722;color:#fff}.tng-active-mission__action.is-checkin:hover{color:#fff;filter:brightness(.96)}
        ');

        wp_register_script('tng-mission-proximity','',[],TNG_OS_VERSION,true);wp_enqueue_script('tng-mission-proximity');
        wp_localize_script('tng-mission-proximity','TNG_MISSION_PROXIMITY',['checkpoints'=>$points]);
        wp_add_inline_script('tng-mission-proximity', <<<'JS'
(()=>{
 const cfg=window.TNG_MISSION_PROXIMITY||{};
 const cps=Array.isArray(cfg.checkpoints)?cfg.checkpoints:[];
 if(!cps.length)return;
 let livePos=null;

 const rad=d=>d*Math.PI/180;
 const distanceM=(a,b)=>{if(!a||!b)return null;const R=6371000,dLat=rad(b.lat-a.lat),dLng=rad(b.lng-a.lng),x=Math.sin(dLat/2)**2+Math.cos(rad(a.lat))*Math.cos(rad(b.lat))*Math.sin(dLng/2)**2;return 2*R*Math.atan2(Math.sqrt(x),Math.sqrt(1-x));};
 const formatDistance=m=>{if(!Number.isFinite(m))return 'Distance unavailable';const ft=m*3.28084;if(ft<1000)return Math.max(1,Math.round(ft))+' ft away';return (ft/5280).toFixed(ft/5280<10?1:0)+' mi away';};
 const isDeveloper=()=>/developer mode/i.test(document.body.innerText||'');
 const completedCount=()=>{const dock=document.querySelector('.tng-gameplay-dock,.tng-player-dock,.tng-game-dock');if(dock){const m=(dock.textContent||'').match(/(\d+)\s*\/\s*(\d+)\s*checkpoints/i);if(m)return Math.max(0,Math.min(cps.length,parseInt(m[1],10)));}let done=0;document.querySelectorAll('.tng-runtime-stop,.tng-game-checkpoint,.tng-checkpoint-card').forEach(card=>{const t=(card.textContent||'').toLowerCase();if(card.classList.contains('is-complete')||card.classList.contains('completed')||/completed\s*[·-]|claimed/.test(t))done++;});return Math.min(cps.length,done);};
 const activeCard=()=>[...document.querySelectorAll('.tng-runtime-stop,.tng-game-checkpoint,.tng-checkpoint-card')].find(card=>{const t=(card.textContent||'').toLowerCase();return !/completed\s*[·-]|claimed|locked until previous/.test(t)&&(card.classList.contains('is-current')||card.classList.contains('current')||/use my location|location check-in/.test(t));})||null;
 const simulatedPos=done=>{if(!isDeveloper()||done<1)return null;const prev=cps[Math.min(done-1,cps.length-1)];return prev&&Number.isFinite(Number(prev.lat))&&Number.isFinite(Number(prev.lng))?{lat:Number(prev.lat),lng:Number(prev.lng)}:null;};

 const update=()=>{
   const mission=document.querySelector('.tng-active-mission');if(!mission||mission.classList.contains('is-complete'))return;
   const done=completedCount(),cp=cps[Math.min(done,cps.length-1)];if(!cp)return;
   const dest=Number.isFinite(Number(cp.lat))&&Number.isFinite(Number(cp.lng))?{lat:Number(cp.lat),lng:Number(cp.lng)}:null;
   const pos=isDeveloper()?simulatedPos(done):(livePos||simulatedPos(done));
   const meters=distanceM(pos,dest),radius=Math.max(1,Number(cp.radius)||30);
   let pill=mission.querySelector('.tng-mission-proximity');
   if(!pill){pill=document.createElement('span');pill.className='tng-mission-proximity';const footer=mission.querySelector('.tng-active-mission__footer');if(footer)footer.insertBefore(pill,footer.children[1]||null);}
   if(!pill)return;
   pill.className='tng-mission-proximity';
   const status=mission.querySelector('.tng-active-mission__status');
   const card=activeCard();
   const readyFromCard=card&&/ready|arrived|within\s*30\s*m/i.test(card.textContent||'');
   if(!dest){pill.textContent='GPS unavailable';return;}
   if(!pos){pill.textContent='Locating…';return;}
   if(Number.isFinite(meters)&&meters<=radius){pill.textContent='Arrived · '+Math.round(meters*3.28084)+' ft';pill.classList.add('is-arrived');if(status){status.textContent='Ready to check in';status.classList.add('is-ready','is-arrived');}const primary=mission.querySelector('[data-act="checkpoint"]');if(primary){primary.textContent='Check in now';primary.classList.add('is-checkin');}}
   else if(readyFromCard){pill.textContent=Number.isFinite(meters)?formatDistance(meters):'Nearby';pill.classList.add('is-arrived');if(status){status.textContent='Ready to check in';status.classList.add('is-ready','is-arrived');}}
   else if(Number.isFinite(meters)&&meters<=160.934){pill.textContent=formatDistance(meters);pill.classList.add('is-near');if(status)status.textContent='Nearby';}
   else {pill.textContent=Number.isFinite(meters)?formatDistance(meters):'Locating…';if(status)status.textContent='In progress';}
 };

 const startGPS=()=>{if(!navigator.geolocation)return;navigator.geolocation.watchPosition(p=>{livePos={lat:Number(p.coords.latitude),lng:Number(p.coords.longitude)};update();},()=>{}, {enableHighAccuracy:true,maximumAge:5000,timeout:12000});};
 let timer=null;const queue=()=>{clearTimeout(timer);timer=setTimeout(update,120);};
 const start=()=>{startGPS();update();new MutationObserver(queue).observe(document.body,{childList:true,subtree:true,characterData:true});setInterval(update,2000);};
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start);else start();
})();
JS
        ,'after');
    }
}
TNG_Mission_Proximity::boot();
