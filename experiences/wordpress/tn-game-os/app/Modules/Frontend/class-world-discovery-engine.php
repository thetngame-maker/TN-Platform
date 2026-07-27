<?php
namespace TNG_OS\Modules\Frontend;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class World_Discovery_Engine implements Module_Interface {
    public function id(): string { return 'world_discovery_engine'; }

    public function register(Container $container): void {
        $container->set('world_discovery_engine', $this);
        add_action('wp_footer', [$this, 'enhance_world'], 90);
    }

    public function boot(Container $container): void {}

    public function enhance_world(): void {
        if (!isset($_GET['tng_world'])) return;
        $user_id = get_current_user_id();
        $xp = $user_id ? absint(get_user_meta($user_id, '_gamipress_xp', true)) : 0;
        $level = max(1, (int)floor($xp / 500) + 1);
        $level_floor = ($level - 1) * 500;
        $level_progress = max(0, min(500, $xp - $level_floor));
        ?>
        <style>
            .tng-world-hud{position:sticky;top:0;z-index:900;display:grid;grid-template-columns:auto minmax(180px,1fr) auto;gap:14px;align-items:center;background:rgba(24,33,61,.96);color:#fff;padding:11px 16px;border-radius:0 0 18px 18px;box-shadow:0 10px 30px rgba(24,33,61,.2);backdrop-filter:blur(12px)}
            .tng-world-hud-level{width:44px;height:44px;border-radius:50%;display:grid;place-items:center;background:#7f56d9;border:3px solid rgba(255,255,255,.7);font-weight:900}.tng-world-hud-copy strong{display:block}.tng-world-hud-copy small{color:rgba(255,255,255,.72)}.tng-world-hud-progress{height:8px;border-radius:999px;background:rgba(255,255,255,.18);overflow:hidden;margin-top:6px}.tng-world-hud-progress span{display:block;height:100%;background:#4ade80}.tng-world-hud-stat{text-align:right;font-weight:900}.tng-world-hud-stat small{display:block;color:rgba(255,255,255,.68);font-weight:600}
            .tng-world-discovery-sheet{position:fixed;z-index:1200;left:50%;bottom:18px;transform:translate(-50%,calc(100% + 40px));width:min(620px,calc(100% - 24px));background:#fff;border:1px solid #dfe3e8;border-radius:24px;box-shadow:0 24px 80px rgba(24,33,61,.28);transition:transform .25s ease;overflow:hidden}.tng-world-discovery-sheet.is-open{transform:translate(-50%,0)}.tng-world-sheet-hero{min-height:130px;padding:20px;background:linear-gradient(135deg,#18213d,#633b78);color:#fff;position:relative}.tng-world-sheet-close{position:absolute;top:12px;right:12px;border:0;background:rgba(255,255,255,.16);color:#fff;width:36px;height:36px;border-radius:50%;font-size:22px;cursor:pointer}.tng-world-sheet-kicker{text-transform:uppercase;letter-spacing:.12em;color:#f6bd3b;font-size:11px;font-weight:900}.tng-world-sheet-hero h2{color:#fff;margin:8px 44px 4px 0;font-size:27px}.tng-world-sheet-body{padding:18px}.tng-world-sheet-meta{display:flex;gap:8px;flex-wrap:wrap}.tng-world-sheet-pill{background:#f4f0ff;color:#53389e;border-radius:999px;padding:6px 10px;font-size:12px;font-weight:900}.tng-world-sheet-description{color:#475467;line-height:1.55;margin:14px 0}.tng-world-sheet-actions{display:grid;grid-template-columns:1fr 1fr;gap:10px}.tng-world-sheet-actions a,.tng-world-sheet-actions button{border-radius:13px;padding:13px 14px;font:inherit;font-weight:900;text-align:center;text-decoration:none;cursor:pointer}.tng-world-sheet-primary{border:0;background:#7f56d9;color:#fff}.tng-world-sheet-secondary{border:1px solid #d0d5dd;background:#fff;color:#18213d}
            .tng-world-marker{transition:transform .2s ease,filter .2s ease}.tng-world-marker.is-near{animation:tngWorldPulse 1.5s infinite;filter:drop-shadow(0 0 8px rgba(127,86,217,.75))}@keyframes tngWorldPulse{50%{transform:scale(1.18)}}
            .tng-world-near-banner{display:none;background:#ecfdf3;border:1px solid #abefc6;color:#067647;border-radius:14px;padding:11px 13px;margin:12px 0;font-weight:800}.tng-world-near-banner.is-visible{display:block}
            @media(max-width:700px){.tng-world-hud{grid-template-columns:auto 1fr}.tng-world-hud-stat{grid-column:2;text-align:left}.tng-world-discovery-sheet{bottom:8px}.tng-world-sheet-actions{grid-template-columns:1fr}}
        </style>
        <script>
        (()=>{
            const world=document.querySelector('.tng-world');
            if(!world || world.dataset.discoveryEnhanced) return;
            world.dataset.discoveryEnhanced='1';
            const xp=<?php echo (int)$xp; ?>, level=<?php echo (int)$level; ?>, progress=<?php echo (int)$level_progress; ?>;
            const hud=document.createElement('div');
            hud.className='tng-world-hud';
            hud.innerHTML=`<div class="tng-world-hud-level">${level}</div><div class="tng-world-hud-copy"><strong>Explorer Level ${level}</strong><small>${xp.toLocaleString()} XP · ${500-progress} XP to next level</small><div class="tng-world-hud-progress"><span style="width:${(progress/500)*100}%"></span></div></div><div class="tng-world-hud-stat"><span data-hud-nearby>—</span><small>nearby discoveries</small></div>`;
            world.prepend(hud);
            const banner=document.createElement('div');
            banner.className='tng-world-near-banner';
            banner.textContent='A discovery is close enough to explore.';
            const bar=world.querySelector('.tng-world-bar');
            if(bar) bar.insertAdjacentElement('afterend',banner);
            const sheet=document.createElement('section');
            sheet.className='tng-world-discovery-sheet';
            sheet.setAttribute('aria-hidden','true');
            sheet.innerHTML='<div class="tng-world-sheet-hero"><button class="tng-world-sheet-close" aria-label="Close">×</button><div class="tng-world-sheet-kicker" data-sheet-kind></div><h2 data-sheet-title></h2></div><div class="tng-world-sheet-body"><div class="tng-world-sheet-meta" data-sheet-meta></div><p class="tng-world-sheet-description" data-sheet-description></p><div class="tng-world-sheet-actions"><a class="tng-world-sheet-primary" data-sheet-primary href="#">Explore</a><a class="tng-world-sheet-secondary" data-sheet-nav href="#" target="_blank" rel="noopener">Navigate</a></div></div>';
            document.body.append(sheet);
            const close=()=>{sheet.classList.remove('is-open');sheet.setAttribute('aria-hidden','true');};
            sheet.querySelector('.tng-world-sheet-close').addEventListener('click',close);
            const esc=v=>String(v??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
            const open=item=>{
                if(!item) return;
                const kind=item.kind||(['event','concert'].includes(item.type)?'event':'place');
                const reward=kind==='quest'?250:kind==='event'?100:25;
                sheet.querySelector('[data-sheet-kind]').textContent=(item.label||kind)+' discovery';
                sheet.querySelector('[data-sheet-title]').textContent=item.title||'Discovery';
                sheet.querySelector('[data-sheet-meta]').innerHTML=`<span class="tng-world-sheet-pill">+${reward} XP preview</span><span class="tng-world-sheet-pill">${item.distanceText||'Location based'}</span>`;
                sheet.querySelector('[data-sheet-description]').textContent=item.description||`Explore ${item.title||'this discovery'} and add it to your TN Game journey.`;
                const primary=sheet.querySelector('[data-sheet-primary]');
                primary.textContent=kind==='quest'?'Start quest':'View discovery';
                primary.href=item.url||'#';
                if(!item.url) primary.onclick=e=>e.preventDefault(); else primary.onclick=null;
                sheet.querySelector('[data-sheet-nav]').href=`https://www.google.com/maps/dir/?api=1&destination=${encodeURIComponent(item.lat+','+item.lng)}`;
                sheet.classList.add('is-open');sheet.setAttribute('aria-hidden','false');
            };
            const dataNode=world.querySelector('.tng-world-data');
            let data={entities:[],quests:[]};
            try{data=JSON.parse(dataNode?.textContent||'{}')}catch(e){}
            const items=[...(data.entities||[]).map(x=>({...x,kind:['event','concert'].includes(x.type)?'event':'place'})),...(data.quests||[]).map(x=>({...x,kind:'quest'}))];
            const byTitle=new Map(items.map(x=>[String(x.title||'').trim(),x]));
            world.addEventListener('click',e=>{
                const card=e.target.closest('.tng-world-item');
                if(card){const title=card.querySelector('strong')?.textContent.trim();if(title&&byTitle.has(title)){open(byTitle.get(title));return;}}
                const marker=e.target.closest('.tng-world-marker');
                if(marker){setTimeout(()=>{const popup=document.querySelector('.leaflet-popup-content strong');const title=popup?.textContent.trim();if(title&&byTitle.has(title))open(byTitle.get(title));},0);}
            });
            const distance=(a,b)=>{const R=3958.8,p=Math.PI/180,dLat=(b.lat-a.lat)*p,dLon=(b.lng-a.lng)*p,x=Math.sin(dLat/2)**2+Math.cos(a.lat*p)*Math.cos(b.lat*p)*Math.sin(dLon/2)**2;return R*2*Math.atan2(Math.sqrt(x),Math.sqrt(1-x));};
            if(navigator.geolocation){navigator.geolocation.watchPosition(pos=>{
                const here={lat:pos.coords.latitude,lng:pos.coords.longitude};
                const sorted=items.map(x=>({...x,miles:distance(here,x)})).sort((a,b)=>a.miles-b.miles);
                const closeItems=sorted.filter(x=>x.miles<=1);
                hud.querySelector('[data-hud-nearby]').textContent=closeItems.length;
                banner.classList.toggle('is-visible',sorted[0]&&sorted[0].miles<=0.1);
                world.querySelectorAll('.tng-world-item').forEach(card=>{const title=card.querySelector('strong')?.textContent.trim(),item=title?sorted.find(x=>x.title===title):null;if(item){card.dataset.distance=item.miles;const meta=card.querySelector('.tng-world-meta');if(meta&&!meta.textContent.includes('mi'))meta.textContent+=` · ${item.miles<0.1?Math.round(item.miles*5280)+' ft':item.miles.toFixed(1)+' mi'}`;}});
                world.querySelectorAll('.leaflet-marker-icon .tng-world-marker').forEach(marker=>marker.classList.remove('is-near'));
                if(sorted[0]&&sorted[0].miles<=0.1){const candidate=[...world.querySelectorAll('.leaflet-marker-icon .tng-world-marker')][0];if(candidate)candidate.classList.add('is-near');}
            },()=>{}, {enableHighAccuracy:true,maximumAge:5000,timeout:15000});}
        })();
        </script>
        <?php
    }
}
