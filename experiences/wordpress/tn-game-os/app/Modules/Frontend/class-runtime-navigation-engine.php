<?php
namespace TNG_OS\Modules\Frontend;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class Runtime_Navigation_Engine implements Module_Interface {
    public function id(): string { return 'runtime_navigation_engine'; }

    public function register(Container $container): void {
        $container->set('runtime_navigation_engine', $this);
        add_action('wp_footer', [$this, 'enhance_runtime'], 120);
    }

    public function boot(Container $container): void {}

    public function enhance_runtime(): void {
        if (!isset($_GET['tng_quest_runtime_id']) && !is_singular('tng_quest')) return;
        ?>
        <style>
            .tng-nav-panel{display:none;margin:0 0 16px;background:linear-gradient(135deg,#18213d,#4b2f68);color:#fff;border-radius:18px;padding:16px;box-shadow:0 12px 30px rgba(24,33,61,.18)}
            .tng-runtime.is-started .tng-nav-panel{display:grid;grid-template-columns:74px minmax(0,1fr) auto;gap:14px;align-items:center}
            .tng-nav-compass{width:68px;height:68px;border-radius:50%;display:grid;place-items:center;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.2)}
            .tng-nav-arrow{font-size:34px;line-height:1;transform:rotate(0deg);transition:transform .35s ease}
            .tng-nav-kicker{text-transform:uppercase;letter-spacing:.1em;color:#f6bd3b;font-size:10px;font-weight:900}.tng-nav-title{font-size:17px;font-weight:900;margin-top:3px}.tng-nav-metrics{display:flex;gap:12px;flex-wrap:wrap;margin-top:7px}.tng-nav-metric{font-size:12px;color:rgba(255,255,255,.78)}.tng-nav-metric strong{display:block;color:#fff;font-size:18px}
            .tng-nav-actions{display:grid;gap:8px}.tng-nav-button{border:1px solid rgba(255,255,255,.28);background:rgba(255,255,255,.1);color:#fff;border-radius:11px;padding:9px 11px;font:inherit;font-weight:800;cursor:pointer;white-space:nowrap}.tng-nav-button.is-active{background:#7f56d9;border-color:#9e77ed}
            .tng-nav-arrival{display:none;margin-top:10px;background:#ecfdf3;color:#067647;border:1px solid #abefc6;border-radius:12px;padding:9px 11px;font-weight:900}.tng-nav-arrival.is-visible{display:block}
            .tng-marker-next{animation:tngCheckpointPulse 1.6s infinite}@keyframes tngCheckpointPulse{50%{transform:scale(1.16);filter:drop-shadow(0 0 9px rgba(127,86,217,.85))}}
            @media(max-width:650px){.tng-runtime.is-started .tng-nav-panel{grid-template-columns:58px 1fr}.tng-nav-compass{width:54px;height:54px}.tng-nav-arrow{font-size:28px}.tng-nav-actions{grid-column:1/-1;grid-template-columns:1fr 1fr}.tng-nav-button{width:100%}}
        </style>
        <script>
        (()=>{
            const root=document.querySelector('.tng-runtime');
            if(!root || root.dataset.navigationEnhanced) return;
            root.dataset.navigationEnhanced='1';
            const dataNode=root.querySelector('.tng-runtime-data');
            let data={stops:[]};try{data=JSON.parse(dataNode?.textContent||'{}')}catch(e){}
            const mapCard=root.querySelector('.tng-map-card'),mapEl=root.querySelector('.tng-live-map');
            if(!mapCard||!mapEl)return;
            const panel=document.createElement('section');
            panel.className='tng-nav-panel';
            panel.innerHTML='<div class="tng-nav-compass"><span class="tng-nav-arrow">➤</span></div><div><div class="tng-nav-kicker">Live navigation</div><div class="tng-nav-title" data-nav-title>Waiting for next checkpoint</div><div class="tng-nav-metrics"><span class="tng-nav-metric"><strong data-nav-distance>—</strong>distance</span><span class="tng-nav-metric"><strong data-nav-eta>—</strong>walk time</span><span class="tng-nav-metric"><strong data-nav-accuracy>—</strong>GPS accuracy</span></div><div class="tng-nav-arrival" data-nav-arrival>You entered the checkpoint arrival zone.</div></div><div class="tng-nav-actions"><button type="button" class="tng-nav-button is-active" data-nav-follow>Auto-follow on</button><button type="button" class="tng-nav-button" data-nav-open>Navigate</button></div>';
            mapCard.parentNode.insertBefore(panel,mapCard);
            const arrow=panel.querySelector('.tng-nav-arrow'),title=panel.querySelector('[data-nav-title]'),distanceOut=panel.querySelector('[data-nav-distance]'),etaOut=panel.querySelector('[data-nav-eta]'),accuracyOut=panel.querySelector('[data-nav-accuracy]'),arrival=panel.querySelector('[data-nav-arrival]'),followButton=panel.querySelector('[data-nav-follow]'),navigateButton=panel.querySelector('[data-nav-open]');
            let here=null,lastInside=false,follow=true,watchId=null,lastStopId='',lastFollowAt=0;
            const storage='tngQuestProgress:'+data.questId;
            const state=()=>{try{return JSON.parse(localStorage.getItem(storage)||'{}')}catch(e){return{}}};
            const currentStop=()=>{const saved=state(),done=new Set((saved.done||saved.completedStops||[]).map(String));return (data.stops||[]).find(s=>!done.has(String(s.id)))||null;};
            const meters=(a,b)=>{const R=6371000,p=Math.PI/180,dLat=(b.lat-a.lat)*p,dLon=(b.lng-a.lng)*p,x=Math.sin(dLat/2)**2+Math.cos(a.lat*p)*Math.cos(b.lat*p)*Math.sin(dLon/2)**2;return R*2*Math.atan2(Math.sqrt(x),Math.sqrt(1-x));};
            const bearing=(a,b)=>{const p=Math.PI/180,y=Math.sin((b.lng-a.lng)*p)*Math.cos(b.lat*p),x=Math.cos(a.lat*p)*Math.sin(b.lat*p)-Math.sin(a.lat*p)*Math.cos(b.lat*p)*Math.cos((b.lng-a.lng)*p);return(Math.atan2(y,x)*180/Math.PI+360)%360;};
            const formatDistance=m=>m<160.934?Math.round(m*3.28084)+' ft':(m/1609.344).toFixed(m<1609?1:0)+' mi';
            const setText=(node,value)=>{if(node.textContent!==String(value))node.textContent=String(value);};
            const refresh=()=>{
                const stop=currentStop();
                if(!stop){setText(title,'Quest complete');setText(distanceOut,'0 ft');setText(etaOut,'Done');arrival.classList.remove('is-visible');return;}
                setText(title,stop.title||'Next checkpoint');
                navigateButton.onclick=()=>window.open(`https://www.google.com/maps/dir/?api=1&destination=${encodeURIComponent(stop.lat+','+stop.lng)}`,'_blank','noopener');
                if(!here||!Number.isFinite(Number(stop.lat))||!Number.isFinite(Number(stop.lng))){setText(distanceOut,'—');setText(etaOut,'—');return;}
                const target={lat:Number(stop.lat),lng:Number(stop.lng)},m=meters(here,target),feet=m*3.28084,radius=Number(stop.radius||30);
                setText(distanceOut,formatDistance(m));setText(etaOut,m<20?'<1 min':Math.max(1,Math.round(m/1.4/60))+' min');
                arrow.style.transform=`rotate(${bearing(here,target)-90}deg)`;
                const inside=feet<=radius;arrival.classList.toggle('is-visible',inside);
                if(inside&&!lastInside&&navigator.vibrate)navigator.vibrate([120,70,180]);lastInside=inside;
                const now=Date.now();
                if(follow&&window.L&&now-lastFollowAt>2500){
                    const mapContainer=root.querySelector('.tng-live-map');
                    const mapId=mapContainer?._leaflet_id;
                    if(mapId&&L.Map&&L.Map._instances?.[mapId])L.Map._instances[mapId].setView([here.lat,here.lng],17,{animate:true});
                    lastFollowAt=now;
                }
                if(lastStopId!==String(stop.id)){lastStopId=String(stop.id);lastInside=false;}
            };
            followButton.onclick=()=>{follow=!follow;followButton.classList.toggle('is-active',follow);setText(followButton,follow?'Auto-follow on':'Auto-follow off');if(follow)mapEl.scrollIntoView({behavior:'smooth',block:'center'});};
            const startWatch=()=>{if(watchId!==null||!navigator.geolocation)return;watchId=navigator.geolocation.watchPosition(p=>{here={lat:p.coords.latitude,lng:p.coords.longitude};setText(accuracyOut,'±'+Math.round((p.coords.accuracy||0)*3.28084)+' ft');refresh();},()=>setText(accuracyOut,'Unavailable'),{enableHighAccuracy:true,maximumAge:5000,timeout:12000});};
            const tick=()=>{if(root.classList.contains('is-started'))startWatch();refresh();};
            document.addEventListener('visibilitychange',()=>{if(document.hidden&&watchId!==null){navigator.geolocation.clearWatch(watchId);watchId=null;}else tick();});
            window.addEventListener('storage',e=>{if(e.key===storage)refresh();});
            setInterval(tick,2000);tick();
        })();
        </script>
        <?php
    }
}
