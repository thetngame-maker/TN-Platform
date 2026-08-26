<?php
/**
 * TN Game Start / Finish Intelligence
 * Adds trailhead/finish diagnostics and optional zero-XP orientation checkpoints.
 */
if (!defined('ABSPATH')) exit;

final class TNG_Start_Finish_Intelligence {
    public static function boot(): void {
        add_action('admin_enqueue_scripts',[__CLASS__,'enqueue'],14);
    }

    public static function enqueue(string $hook): void {
        if(!in_array($hook,['post.php','post-new.php'],true)) return;
        $screen=get_current_screen(); if(!$screen||$screen->post_type!=='tng_game') return;

        wp_enqueue_script('tng-admin-leaflet','https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',[],'1.9.4',true);
        wp_add_inline_style('tng-admin-leaflet','
          .tng-start-finish{margin:12px 0;padding:11px;border:1px solid #dfe5e1;border-radius:9px;background:#fff}.tng-start-finish__head{display:flex;align-items:center;justify-content:space-between;gap:8px}.tng-start-finish__head strong{color:#173b2a}.tng-start-finish__status{padding:4px 7px;border-radius:999px;background:#e9f4ec;color:#17613f;font-size:10px;font-weight:800}.tng-start-finish__status.is-warning{background:#fff1e8;color:#b84e1e}.tng-start-finish__grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:9px}.tng-start-finish__card{padding:9px;border:1px solid #e4eae6;border-radius:9px;background:#f8faf8}.tng-start-finish__label{display:block;color:#e85d24;font-size:9px;font-weight:900;text-transform:uppercase;letter-spacing:.06em}.tng-start-finish__card strong{display:block;margin-top:3px;color:#173b2a;font-size:12px}.tng-start-finish__card small{display:block;margin-top:3px;color:#69776f;font-size:10px;line-height:1.35}.tng-start-finish__route{margin-top:8px;padding:8px;border-radius:8px;background:#f7f9f7;color:#5f6f66;font-size:11px;line-height:1.4}.tng-start-finish__warning{display:none;margin-top:8px;padding:7px;border-radius:8px;background:#fff4ec;color:#a64b22;font-size:10px;font-weight:700}.tng-start-finish__warning.is-visible{display:block}.tng-start-finish__actions{display:flex;gap:6px;flex-wrap:wrap;margin-top:9px}.tng-start-finish__actions button{font-size:11px}@media(max-width:1100px){.tng-start-finish__grid{grid-template-columns:1fr}}
        ');

        wp_add_inline_script('tng-admin-leaflet', <<<'JS'
(()=>{
 const wrap=()=>document.getElementById('tng-checkpoint-rows');
 const rows=()=>[...(wrap()?.querySelectorAll('.tng-cp-row')||[])];
 const field=(row,key)=>row?.querySelector(`[name$="[${key}]"]`);
 const coord=row=>[parseFloat(field(row,'latitude')?.value||''),parseFloat(field(row,'longitude')?.value||'')];
 const valid=p=>Number.isFinite(p[0])&&Number.isFinite(p[1])&&Math.abs(p[0])<=90&&Math.abs(p[1])<=180&&(p[0]!==0||p[1]!==0);
 const hav=(a,b)=>{const R=6371000,d2r=Math.PI/180,dLat=(b[0]-a[0])*d2r,dLng=(b[1]-a[1])*d2r,x=Math.sin(dLat/2)**2+Math.cos(a[0]*d2r)*Math.cos(b[0]*d2r)*Math.sin(dLng/2)**2;return 2*R*Math.atan2(Math.sqrt(x),Math.sqrt(1-x));};
 const fmtDist=m=>m<1609.344?`${Math.round(m*3.28084)} ft`:`${(m/1609.344).toFixed(1)} mi`;
 const currentStart=()=>document.getElementById('tng-route-start-select')?.value||((typeof TNG_ROUTE_DIRECTION!=='undefined'&&TNG_ROUTE_DIRECTION.start)||'gpx_start');
 const currentDirection=()=>document.getElementById('tng-route-direction-select')?.value||((typeof TNG_ROUTE_DIRECTION!=='undefined'&&TNG_ROUTE_DIRECTION.direction)||'forward');
 const renumber=()=>rows().forEach((r,i)=>{const n=r.querySelector('.tng-cp-num');if(n)n.textContent=i+1;r.querySelectorAll('[name]').forEach(f=>f.name=f.name.replace(/tng_cp\[\d+\]/,`tng_cp[${i}]`));});
 const changed=()=>{const f=field(rows()[0],'title');if(f)f.dispatchEvent(new Event('input',{bubbles:true}));document.dispatchEvent(new CustomEvent('tng:checkpoint-form-changed'));};
 let route=[];
 const endpoints=()=>{if(!route.length)return{start:null,finish:null};const a=route[0],b=route[route.length-1],startIsA=currentStart()==='gpx_start';return{start:startIsA?a:b,finish:startIsA?b:a};};
 const routeLength=()=>{let total=0;for(let i=1;i<route.length;i++)total+=hav(route[i-1],route[i]);return total;};
 const nearestCheckpoint=p=>{let best=Infinity,index=-1;rows().forEach((r,i)=>{const c=coord(r);if(!valid(c))return;const d=hav(p,c);if(d<best){best=d;index=i;}});return{distance:best,index};};
 const findOrientation=(kind,p)=>rows().find(r=>{const title=(field(r,'title')?.value||'').trim().toLowerCase();const xp=parseFloat(field(r,'xp')?.value||'0');const c=coord(r);if(!valid(c)||xp!==0)return false;const named=kind==='start'?/trail start|start point|trailhead/.test(title):/trail finish|finish point|route finish/.test(title);return named&&hav(p,c)<60;});
 const addOrientation=(kind)=>{
   const ep=endpoints(),p=kind==='start'?ep.start:ep.finish;if(!p)return;
   const existing=findOrientation(kind,p);if(existing){existing.scrollIntoView({behavior:'smooth',block:'center'});existing.style.outline='2px solid #e85d24';setTimeout(()=>existing.style.outline='',1200);return;}
   const add=document.getElementById('tng-add-checkpoint');if(!add)return;add.click();setTimeout(()=>{
     const arr=rows(),row=arr[arr.length-1];if(!row)return;
     const set=(key,val)=>{const f=field(row,key);if(f){f.value=val;f.dispatchEvent(new Event('input',{bubbles:true}));f.dispatchEvent(new Event('change',{bubbles:true}));}};
     set('title',kind==='start'?'Trail Start':'Trail Finish');
     set('instructions',kind==='start'?'Begin the trail here. Confirm your location before heading to the first adventure checkpoint.':'You reached the end of the route. Confirm your location to finish the trail.');
     set('type','gps');set('latitude',Number(p[0]).toFixed(6));set('longitude',Number(p[1]).toFixed(6));set('radius','30');set('xp','0');
     const w=wrap();if(kind==='start'&&w)w.insertBefore(row,w.firstChild);else if(kind==='finish'&&w)w.appendChild(row);
     renumber();changed();setTimeout(render,80);
   },60);
 };
 const ensurePanel=()=>{
   const side=document.querySelector('.tng-route-editor__side');if(!side)return null;let p=side.querySelector('.tng-start-finish');if(p)return p;
   p=document.createElement('section');p.className='tng-start-finish';p.innerHTML=`<div class="tng-start-finish__head"><strong>Start & finish</strong><span class="tng-start-finish__status">Analyzing</span></div><div class="tng-start-finish__grid"><div class="tng-start-finish__card"><span class="tng-start-finish__label">Trailhead</span><strong class="tng-start-finish__start-coord">—</strong><small class="tng-start-finish__start-nearest">—</small></div><div class="tng-start-finish__card"><span class="tng-start-finish__label">Finish</span><strong class="tng-start-finish__finish-coord">—</strong><small class="tng-start-finish__finish-nearest">—</small></div></div><div class="tng-start-finish__route">—</div><div class="tng-start-finish__warning"></div><div class="tng-start-finish__actions"><button type="button" class="button button-primary tng-add-start-point">Add 0 XP Start Point</button><button type="button" class="button tng-add-finish-point">Add 0 XP Finish Point</button></div>`;
   const direction=side.querySelector('.tng-route-direction');if(direction)direction.insertAdjacentElement('afterend',p);else side.prepend(p);
   p.querySelector('.tng-add-start-point').onclick=()=>addOrientation('start');p.querySelector('.tng-add-finish-point').onclick=()=>addOrientation('finish');return p;
 };
 function render(){if(!route.length)return;const panel=ensurePanel();if(!panel)return;const ep=endpoints();if(!ep.start||!ep.finish)return;const length=routeLength(),first=nearestCheckpoint(ep.start),last=nearestCheckpoint(ep.finish);panel.querySelector('.tng-start-finish__start-coord').textContent=`${ep.start[0].toFixed(5)}, ${ep.start[1].toFixed(5)}`;panel.querySelector('.tng-start-finish__finish-coord').textContent=`${ep.finish[0].toFixed(5)}, ${ep.finish[1].toFixed(5)}`;panel.querySelector('.tng-start-finish__start-nearest').textContent=first.index>=0?`Nearest checkpoint: #${first.index+1} · ${fmtDist(first.distance)} away`:'No checkpoint near the trailhead yet';panel.querySelector('.tng-start-finish__finish-nearest').textContent=last.index>=0?`Nearest checkpoint: #${last.index+1} · ${fmtDist(last.distance)} away`:'No checkpoint near the finish yet';panel.querySelector('.tng-start-finish__route').textContent=`${(length/1609.344).toFixed(1)} mi GPX route · ${currentDirection()==='out_back'?'Out & Back':currentDirection()==='reverse'?'Reverse':'Forward'} · starts at ${currentStart()==='gpx_start'?'GPX start':'GPX end'}.`;
   const warn=panel.querySelector('.tng-start-finish__warning'),status=panel.querySelector('.tng-start-finish__status');const warnings=[];if(first.index<0||first.distance>91.44)warnings.push(first.index<0?'No GPS checkpoint identifies the trailhead.':`First nearby checkpoint is ${fmtDist(first.distance)} from the selected trailhead.`);if(last.index<0||last.distance>91.44)warnings.push(last.index<0?'No GPS checkpoint identifies the finish.':`Nearest finish checkpoint is ${fmtDist(last.distance)} from the selected finish.`);warn.textContent=warnings.join(' ');warn.classList.toggle('is-visible',!!warnings.length);status.textContent=warnings.length?`${warnings.length} note${warnings.length===1?'':'s'}`:'Endpoints ready';status.classList.toggle('is-warning',!!warnings.length);
   const startBtn=panel.querySelector('.tng-add-start-point'),finishBtn=panel.querySelector('.tng-add-finish-point');const hasStart=!!findOrientation('start',ep.start),hasFinish=!!findOrientation('finish',ep.finish);startBtn.textContent=hasStart?'Start Point Added ✓':'Add 0 XP Start Point';startBtn.disabled=hasStart;finishBtn.textContent=hasFinish?'Finish Point Added ✓':'Add 0 XP Finish Point';finishBtn.disabled=hasFinish;
 }
 const load=()=>{const url=(typeof TNG_ADMIN_ROUTE_EDITOR!=='undefined'&&TNG_ADMIN_ROUTE_EDITOR.routeUrl)||'';if(!url)return false;fetch(url,{credentials:'same-origin'}).then(r=>r.ok?r.text():Promise.reject()).then(text=>{const xml=new DOMParser().parseFromString(text,'application/xml');route=[...xml.querySelectorAll('trkpt,rtept')].map(n=>[parseFloat(n.getAttribute('lat')),parseFloat(n.getAttribute('lon'))]).filter(valid);ensurePanel();render();wrap()?.addEventListener('change',()=>setTimeout(render,25));document.addEventListener('tng:checkpoint-form-changed',()=>setTimeout(render,25));document.addEventListener('change',e=>{if(e.target?.id==='tng-route-direction-select'||e.target?.id==='tng-route-start-select')setTimeout(render,25);});}).catch(()=>{});return true;};
 let tries=0,t=setInterval(()=>{if(load()||++tries>50)clearInterval(t)},120);load();
})();
JS
        ,'after');
    }
}
TNG_Start_Finish_Intelligence::boot();
