<?php
/**
 * TN Game Route Order Intelligence
 * Adds GPX mileage annotations, direction-aware route-health warnings, and one-click checkpoint sorting.
 */
if (!defined('ABSPATH')) exit;

final class TNG_Route_Order_Intelligence {
    public static function boot(): void { add_action('admin_enqueue_scripts',[__CLASS__,'enqueue'],12); }

    public static function enqueue(string $hook): void {
        if(!in_array($hook,['post.php','post-new.php'],true)) return;
        $screen=get_current_screen(); if(!$screen||$screen->post_type!=='tng_game') return;
        wp_enqueue_script('tng-admin-leaflet','https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',[],'1.9.4',true);
        wp_add_inline_style('tng-admin-leaflet','
          .tng-route-health{margin:12px 0;padding:11px;border:1px solid #dfe5e1;border-radius:9px;background:#fff}.tng-route-health__head{display:flex;align-items:center;justify-content:space-between;gap:8px}.tng-route-health__head strong{color:#173b2a}.tng-route-health__status{display:inline-flex;align-items:center;gap:5px;padding:4px 7px;border-radius:999px;background:#e9f4ec;color:#17613f;font-size:10px;font-weight:800}.tng-route-health__status.is-warning{background:#fff1e8;color:#b84e1e}.tng-route-health__mode{margin-top:6px;color:#66766d;font-size:10px;font-weight:700}.tng-route-health__issues{margin:8px 0 0;padding:0;list-style:none}.tng-route-health__issues li{margin:5px 0;padding:6px 7px;border-radius:7px;background:#f7f9f7;color:#5e6d64;font-size:11px;line-height:1.35}.tng-route-health__actions{display:flex;gap:6px;flex-wrap:wrap;margin-top:9px}.tng-route-health__actions button{font-size:11px}.tng-route-order-metric{display:block;margin-top:3px;color:#66766d;font-size:9px;font-weight:700}.tng-route-order-metric.is-far{color:#b84e1e}.tng-route-item.is-order-warning{border-color:#f3a179;background:#fff8f4}.tng-route-item__title-wrap{min-width:0}.tng-route-item__title-wrap .tng-route-item__title{display:block}
        ');
        wp_add_inline_script('tng-admin-leaflet', <<<'JS'
(()=>{
 const rowWrap=()=>document.getElementById('tng-checkpoint-rows');
 const rows=()=>[...(rowWrap()?.querySelectorAll('.tng-cp-row')||[])];
 const field=(row,key)=>row?.querySelector(`[name$="[${key}]"]`);
 const num=(row,key)=>parseFloat(field(row,key)?.value||'');
 const valid=(lat,lng)=>Number.isFinite(lat)&&Number.isFinite(lng)&&Math.abs(lat)<=90&&Math.abs(lng)<=180&&(lat!==0||lng!==0);
 const hav=(a,b)=>{const R=6371000,d2r=Math.PI/180,dLat=(b[0]-a[0])*d2r,dLng=(b[1]-a[1])*d2r,x=Math.sin(dLat/2)**2+Math.cos(a[0]*d2r)*Math.cos(b[0]*d2r)*Math.sin(dLng/2)**2;return 2*R*Math.atan2(Math.sqrt(x),Math.sqrt(1-x));};
 const routeMode=()=>({direction:document.getElementById('tng-route-direction-select')?.value||window.TNG_ROUTE_DIRECTION?.direction||'forward',start:document.getElementById('tng-route-start-select')?.value||window.TNG_ROUTE_DIRECTION?.start||'gpx_start'});
 const ascending=()=>{const m=routeMode();let asc=m.start==='gpx_start';if(m.direction==='reverse')asc=!asc;return asc;};
 const modeLabel=()=>{const m=routeMode(),d=m.direction==='out_back'?'Out & Back':m.direction==='reverse'?'Reverse':'Forward',s=m.start==='gpx_end'?'GPX end':'GPX start';return `${d} · starts at ${s}`;};
 const renumber=()=>rows().forEach((r,i)=>{const n=r.querySelector('.tng-cp-num');if(n)n.textContent=i+1;r.querySelectorAll('[name]').forEach(f=>f.name=f.name.replace(/tng_cp\[\d+\]/,`tng_cp[${i}]`));});
 const forceRebuild=()=>{const r=rows()[0],f=field(r,'title');if(f)f.dispatchEvent(new Event('input',{bubbles:true}));document.dispatchEvent(new CustomEvent('tng:checkpoint-form-changed'));};
 let routePts=[],cum=[];
 const buildCum=()=>{cum=[0];for(let i=1;i<routePts.length;i++)cum[i]=cum[i-1]+hav(routePts[i-1],routePts[i]);};
 const metricFor=(lat,lng)=>{if(!routePts.length||!valid(lat,lng))return{valid:false,along:null,off:null,index:-1};let best=Infinity,idx=0;for(let i=0;i<routePts.length;i++){const d=hav([lat,lng],routePts[i]);if(d<best){best=d;idx=i;}}return{valid:true,along:(cum[idx]||0)/1609.344,off:best,index:idx};};
 const metrics=()=>rows().map((r,i)=>({row:r,i,...metricFor(num(r,'latitude'),num(r,'longitude'))}));
 const fmtOff=m=>m<1609.344?`${Math.round(m*3.28084)} ft from trail`:`${(m/1609.344).toFixed(1)} mi from trail`;
 const ensurePanel=()=>{const side=document.querySelector('.tng-route-editor__side');if(!side)return null;let p=side.querySelector('.tng-route-health');if(p)return p;p=document.createElement('section');p.className='tng-route-health';p.innerHTML='<div class="tng-route-health__head"><strong>Route health</strong><span class="tng-route-health__status">Analyzing</span></div><div class="tng-route-health__mode"></div><ul class="tng-route-health__issues"></ul><div class="tng-route-health__actions"><button type="button" class="button button-primary tng-route-sort">Sort for selected direction</button><button type="button" class="button tng-route-health-refresh">Refresh</button></div>';const auto=side.querySelector('.tng-auto-route');if(auto)auto.insertAdjacentElement('afterend',p);else side.prepend(p);p.querySelector('.tng-route-sort').onclick=sortRows;p.querySelector('.tng-route-health-refresh').onclick=render;return p;};
 const annotateList=ms=>{const list=document.getElementById('tng-route-list');if(!list)return;[...list.children].forEach((item,i)=>{const m=ms[i];if(!m)return;let title=item.querySelector('.tng-route-item__title');if(!title)return;let wrap=title.parentElement;if(!wrap.classList.contains('tng-route-item__title-wrap')){wrap=document.createElement('span');wrap.className='tng-route-item__title-wrap';title.replaceWith(wrap);wrap.appendChild(title);}let badge=wrap.querySelector('.tng-route-order-metric');if(!badge){badge=document.createElement('small');badge.className='tng-route-order-metric';wrap.appendChild(badge);}if(!m.valid){badge.textContent='Missing GPS';badge.classList.add('is-far');return;}badge.textContent=`${m.along.toFixed(1)} mi · ${fmtOff(m.off)}`;badge.classList.toggle('is-far',m.off>152.4);});};
 const analyze=ms=>{const issues=[],wrong=[];const asc=ascending();for(let i=1;i<ms.length;i++){if(!ms[i-1].valid||!ms[i].valid)continue;const bad=asc?ms[i].along+0.03<ms[i-1].along:ms[i].along-0.03>ms[i-1].along;if(bad)wrong.push(i+1);}if(wrong.length)issues.push(`Checkpoint order does not match ${modeLabel()} near stop${wrong.length>1?'s':''} ${wrong.join(', ')}.`);const invalid=ms.filter(m=>!m.valid);if(invalid.length)issues.push(`${invalid.length} checkpoint${invalid.length===1?'':'s'} ${invalid.length===1?'is':'are'} missing valid GPS coordinates.`);const far=ms.filter(m=>m.valid&&m.off>152.4);if(far.length)issues.push(`${far.length} checkpoint${far.length===1?' is':'s are'} more than 500 ft from the GPX route.`);let closePairs=[];for(let i=0;i<ms.length;i++)for(let j=i+1;j<ms.length;j++){if(!ms[i].valid||!ms[j].valid)continue;const d=hav([num(ms[i].row,'latitude'),num(ms[i].row,'longitude')],[num(ms[j].row,'latitude'),num(ms[j].row,'longitude')]);if(d<15.24)closePairs.push(`${i+1}/${j+1}`);}if(closePairs.length)issues.push(`Very close or duplicate checkpoints: ${closePairs.join(', ')}.`);return{issues,wrong};};
 function sortRows(){if(!routePts.length)return;const wrap=rowWrap();if(!wrap)return;const good=metrics().filter(m=>m.valid).sort((a,b)=>ascending()?a.along-b.along:b.along-a.along),invalid=metrics().filter(m=>!m.valid);[...good,...invalid].forEach(m=>wrap.appendChild(m.row));renumber();forceRebuild();setTimeout(render,80);}
 function render(){if(!routePts.length)return;const ms=metrics(),report=analyze(ms),panel=ensurePanel();if(!panel)return;const status=panel.querySelector('.tng-route-health__status'),list=panel.querySelector('.tng-route-health__issues'),sort=panel.querySelector('.tng-route-sort');panel.querySelector('.tng-route-health__mode').textContent=modeLabel();status.textContent=report.issues.length?`${report.issues.length} issue${report.issues.length===1?'':'s'}`:'Route looks good';status.classList.toggle('is-warning',!!report.issues.length);list.innerHTML=report.issues.map(x=>`<li>${x}</li>`).join('');sort.disabled=ms.filter(m=>m.valid).length<2;annotateList(ms);const items=[...(document.getElementById('tng-route-list')?.children||[])];items.forEach(x=>x.classList.remove('is-order-warning'));report.wrong.forEach(n=>items[n-1]?.classList.add('is-order-warning'));}
 const load=()=>{const route=(typeof TNG_ADMIN_ROUTE_EDITOR!=='undefined'&&TNG_ADMIN_ROUTE_EDITOR.routeUrl)||'';if(!route)return false;fetch(route,{credentials:'same-origin'}).then(r=>r.ok?r.text():Promise.reject()).then(text=>{const xml=new DOMParser().parseFromString(text,'application/xml');routePts=[...xml.querySelectorAll('trkpt,rtept')].map(n=>[parseFloat(n.getAttribute('lat')),parseFloat(n.getAttribute('lon'))]).filter(p=>valid(p[0],p[1]));buildCum();render();const rw=rowWrap();if(rw)new MutationObserver(()=>setTimeout(render,30)).observe(rw,{childList:true,subtree:true});const list=document.getElementById('tng-route-list');if(list)new MutationObserver(()=>setTimeout(render,30)).observe(list,{childList:true});rw?.addEventListener('change',()=>setTimeout(render,20));document.addEventListener('tng:checkpoint-form-changed',()=>setTimeout(render,20));document.addEventListener('change',e=>{if(e.target?.matches('#tng-route-direction-select,#tng-route-start-select'))setTimeout(render,20);});}).catch(()=>{});return true;};
 let tries=0,t=setInterval(()=>{if(load()||++tries>50)clearInterval(t)},120);load();
})();
JS
        ,'after');
    }
}
TNG_Route_Order_Intelligence::boot();
