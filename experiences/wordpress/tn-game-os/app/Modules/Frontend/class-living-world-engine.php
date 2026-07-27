<?php
namespace TNG_OS\Modules\Frontend;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class Living_World_Engine implements Module_Interface {
    public function id(): string { return 'living_world_engine'; }

    public function register(Container $container): void {
        $container->set('living_world_engine', $this);
        add_action('wp_footer', [$this, 'enhance_world'], 140);
    }

    public function boot(Container $container): void {}

    public function enhance_world(): void {
        if (!isset($_GET['tng_world'])) return;
        ?>
        <style>
            .tng-living-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:16px}.tng-living-card{background:#fff;border:1px solid #dfe3e8;border-radius:20px;padding:17px}.tng-living-card h2{margin:0 0 10px}.tng-feed{display:grid;gap:9px}.tng-feed-item{display:grid;grid-template-columns:38px 1fr auto;gap:10px;align-items:center;border:1px solid #e4e7ec;border-radius:14px;padding:10px}.tng-feed-icon{width:36px;height:36px;border-radius:50%;display:grid;place-items:center;background:#f4f0ff}.tng-feed-item small{color:#667085}.tng-feed-xp{font-weight:900;color:#067647}.tng-region-row{margin:12px 0}.tng-region-top{display:flex;justify-content:space-between;font-weight:800}.tng-region-bar{height:9px;background:#eaecf0;border-radius:999px;overflow:hidden;margin-top:6px}.tng-region-bar span{display:block;height:100%;background:#7f56d9}.tng-fog-badge{display:inline-flex;align-items:center;gap:7px;background:#18213d;color:#fff;border-radius:999px;padding:7px 11px;font-size:12px;font-weight:800}.tng-world-map.is-fogged:after{content:'';position:absolute;inset:0;pointer-events:none;background:radial-gradient(circle at var(--fog-x,50%) var(--fog-y,50%),transparent 0 90px,rgba(24,33,61,.58) 210px,rgba(24,33,61,.82) 100%);z-index:450}.tng-world-map{position:relative}.tng-bonus-pill{display:inline-block;margin-left:5px;background:#fff4cc;color:#7a4e00;border-radius:999px;padding:3px 7px;font-size:10px;font-weight:900}@media(max-width:850px){.tng-living-grid{grid-template-columns:1fr;margin:12px}}
        </style>
        <script>
        (()=>{
            const world=document.querySelector('.tng-world');if(!world||world.dataset.livingEnhanced)return;world.dataset.livingEnhanced='1';
            const mapEl=world.querySelector('.tng-world-map'),layout=world.querySelector('.tng-world-layout');if(!mapEl||!layout)return;
            mapEl.classList.add('is-fogged');
            const storage='tngLivingWorld:v1';let saved={visited:[],trail:[]};try{saved={...saved,...JSON.parse(localStorage.getItem(storage)||'{}')}}catch(e){}
            const grid=document.createElement('section');grid.className='tng-living-grid';grid.innerHTML='<article class="tng-living-card"><h2>Discovery feed</h2><div class="tng-fog-badge">◉ <span data-revealed>0 explored areas</span></div><div class="tng-feed" data-feed></div></article><article class="tng-living-card"><h2>Regional progress</h2><div data-regions></div></article>';layout.insertAdjacentElement('afterend',grid);
            const feed=grid.querySelector('[data-feed]'),regions=grid.querySelector('[data-regions]'),revealed=grid.querySelector('[data-revealed]');
            const dataNode=world.querySelector('.tng-world-data');let data={entities:[],quests:[]};try{data=JSON.parse(dataNode?.textContent||'{}')}catch(e){}
            const items=[...(data.entities||[]).map(x=>({...x,kind:['event','concert'].includes(x.type)?'event':'place'})),...(data.quests||[]).map(x=>({...x,kind:'quest'}))];
            const icon=x=>x.kind==='quest'?'🎯':x.kind==='event'?'🎵':'📍';
            const base=x=>x.kind==='quest'?250:x.kind==='event'?100:25;
            const bonus=x=>{let n=0,label='';const d=new Date();if(d.getDay()===0||d.getDay()===6){n+=10;label='Weekend +10';}if(!saved.visited.includes(String(x.id))){n+=15;label+=(label?' · ':'')+'First visit +15';}return{n,label};};
            const renderFeed=(position)=>{const distance=(a,b)=>{const R=3958.8,p=Math.PI/180,dLat=(b.lat-a.lat)*p,dLon=(b.lng-a.lng)*p,x=Math.sin(dLat/2)**2+Math.cos(a.lat*p)*Math.cos(b.lat*p)*Math.sin(dLon/2)**2;return R*2*Math.atan2(Math.sqrt(x),Math.sqrt(1-x));};const sorted=items.map(x=>({...x,miles:position?distance(position,x):999})).sort((a,b)=>a.miles-b.miles).slice(0,5);feed.innerHTML=sorted.map(x=>{const b=bonus(x);return `<div class="tng-feed-item"><span class="tng-feed-icon">${icon(x)}</span><div><strong>${x.title}</strong><small>${position?(x.miles<.1?Math.round(x.miles*5280)+' ft away':x.miles.toFixed(1)+' mi away'):'Enable location to sort nearby'}${b.label?`<span class="tng-bonus-pill">${b.label}</span>`:''}</small></div><span class="tng-feed-xp">+${base(x)+b.n} XP</span></div>`}).join('');};
            const renderRegions=()=>{const total=Math.max(1,items.length),visited=new Set(saved.visited);const groups={Places:items.filter(x=>x.kind==='place'),Events:items.filter(x=>x.kind==='event'),Quests:items.filter(x=>x.kind==='quest')};regions.innerHTML=Object.entries(groups).map(([name,list])=>{const done=list.filter(x=>visited.has(String(x.id))).length,pct=list.length?Math.round(done/list.length*100):0;return `<div class="tng-region-row"><div class="tng-region-top"><span>${name}</span><span>${done}/${list.length} · ${pct}%</span></div><div class="tng-region-bar"><span style="width:${pct}%"></span></div></div>`}).join('');revealed.textContent=saved.trail.length+' explored areas';};
            const persist=()=>{try{localStorage.setItem(storage,JSON.stringify(saved))}catch(e){}};
            let last=null;const onPosition=p=>{const here={lat:p.coords.latitude,lng:p.coords.longitude};renderFeed(here);if(!last||Math.abs(last.lat-here.lat)+Math.abs(last.lng-here.lng)>.00035){saved.trail.push([here.lat,here.lng,Date.now()]);saved.trail=saved.trail.slice(-300);last=here;persist();renderRegions();}mapEl.style.setProperty('--fog-x','50%');mapEl.style.setProperty('--fog-y','50%');};
            world.addEventListener('click',e=>{const card=e.target.closest('.tng-world-item');if(!card)return;const title=card.querySelector('strong')?.textContent.trim(),item=items.find(x=>x.title===title);if(item&&!saved.visited.includes(String(item.id))){saved.visited.push(String(item.id));persist();renderRegions();}});
            if(navigator.geolocation)navigator.geolocation.watchPosition(onPosition,()=>renderFeed(null),{enableHighAccuracy:true,maximumAge:10000,timeout:12000});else renderFeed(null);renderRegions();
        })();
        </script>
        <?php
    }
}
