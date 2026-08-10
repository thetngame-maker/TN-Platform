<?php
/**
 * TN Game Auto Route Builder
 * Adds one-click route-aware checkpoint generation to the TN Game visual editor.
 */
if (!defined('ABSPATH')) exit;

final class TNG_Auto_Route_Builder {
    public static function boot(): void { add_action('admin_enqueue_scripts',[__CLASS__,'enqueue'],11); }

    public static function enqueue(string $hook): void {
        if(!in_array($hook,['post.php','post-new.php'],true))return;
        $screen=get_current_screen();if(!$screen||$screen->post_type!=='tng_game')return;
        wp_add_inline_style('tng-admin-leaflet','
          .tng-auto-route{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:9px 0 0;padding:10px;border:1px solid #dfe7e1;border-radius:10px;background:#f8faf8}.tng-auto-route strong{color:#173b2a;font-size:12px}.tng-auto-route label{display:flex;align-items:center;gap:5px;color:#66746c;font-size:11px}.tng-auto-route select{min-height:30px;font-size:11px}.tng-auto-route__status{color:#66746c;font-size:11px;margin-left:auto}.tng-auto-route__preview{width:100%;padding-top:8px;border-top:1px solid #e3e9e5;color:#526159;font-size:11px}.tng-auto-route__preview b{color:#173b2a}
        ');
        wp_add_inline_script('tng-admin-leaflet', <<<'JS'
(()=>{
 const rows=()=>[...document.querySelectorAll('#tng-checkpoint-rows .tng-cp-row')];
 const field=(row,key)=>row?.querySelector(`[name$="[${key}]"]`);
 const usedIds=()=>new Set(rows().map(r=>parseInt(field(r,'sight_id')?.value||'0',10)).filter(Boolean));
 const hav=(a,b)=>{const R=6371000,d2r=Math.PI/180,dLat=(b[0]-a[0])*d2r,dLng=(b[1]-a[1])*d2r,x=Math.sin(dLat/2)**2+Math.cos(a[0]*d2r)*Math.cos(b[0]*d2r)*Math.sin(dLng/2)**2;return 2*R*Math.atan2(Math.sqrt(x),Math.sqrt(1-x));};
 const addCheckpoint=(s)=>new Promise(resolve=>{
   const add=document.getElementById('tng-add-checkpoint');if(!add){resolve(false);return;}add.click();setTimeout(()=>{const arr=rows(),row=arr[arr.length-1];if(!row){resolve(false);return;}
     const set=(key,val)=>{let f=field(row,key);if(!f&&key==='sight_id'){f=document.createElement('input');f.type='hidden';f.name=`tng_cp[${arr.length-1}][sight_id]`;row.querySelector('span:nth-child(2)')?.appendChild(f);}if(f){f.value=val;f.dispatchEvent(new Event('input',{bubbles:true}));f.dispatchEvent(new Event('change',{bubbles:true}));}};
     set('title',s.title);set('instructions',`Visit ${s.title}.`);set('latitude',Number(s.lat).toFixed(6));set('longitude',Number(s.lng).toFixed(6));set('radius','30');set('xp',String(s.xp||25));set('sight_id',String(s.id));
     document.dispatchEvent(new CustomEvent('tng:checkpoint-form-changed'));resolve(true);
   },45);
 });
 const enrich=(items,pts)=>{if(!pts.length)return[];const cum=[0];for(let i=1;i<pts.length;i++)cum[i]=cum[i-1]+hav(pts[i-1],pts[i]);return items.map(s=>{let best=Infinity,idx=0;for(let i=0;i<pts.length;i++){const d=hav([Number(s.lat),Number(s.lng)],pts[i]);if(d<best){best=d;idx=i;}}return{...s,routeDistance:best,alongMiles:cum[idx]/1609.344};});};
 const parseRoute=async()=>{const route=(typeof TNG_ADMIN_ROUTE_EDITOR!=='undefined'&&TNG_ADMIN_ROUTE_EDITOR.routeUrl)||'';if(!route)return[];try{const r=await fetch(route,{credentials:'same-origin'});if(!r.ok)return[];const text=await r.text(),xml=new DOMParser().parseFromString(text,'application/xml');return[...xml.querySelectorAll('trkpt,rtept')].map(n=>[parseFloat(n.getAttribute('lat')),parseFloat(n.getAttribute('lon'))]).filter(p=>Number.isFinite(p[0])&&Number.isFinite(p[1]));}catch(e){return[];}};
 const boot=async()=>{
   if(typeof TNG_ADMIN_TOP_SIGHTS==='undefined')return false;
   const side=document.querySelector('.tng-route-editor__side');if(!side||side.querySelector('.tng-auto-route'))return !!side;
   const pts=await parseRoute();if(!pts.length)return false;
   const all=enrich(TNG_ADMIN_TOP_SIGHTS.items||[],pts);
   const box=document.createElement('section');box.className='tng-auto-route';box.innerHTML=`<strong>Auto Build Route</strong><label>Max distance <select class="tng-auto-route__distance"><option value="30">100 ft</option><option value="76">250 ft</option><option value="152" selected>500 ft</option><option value="305">1,000 ft</option></select></label><label>Max sights <select class="tng-auto-route__max"><option>3</option><option selected>5</option><option>8</option><option>12</option></select></label><button type="button" class="button button-primary tng-auto-route__build">Build route</button><span class="tng-auto-route__status"></span><div class="tng-auto-route__preview"></div>`;
   side.insertBefore(box,side.firstChild);
   const distSel=box.querySelector('.tng-auto-route__distance'),maxSel=box.querySelector('.tng-auto-route__max'),preview=box.querySelector('.tng-auto-route__preview'),status=box.querySelector('.tng-auto-route__status'),build=box.querySelector('.tng-auto-route__build');
   const candidates=()=>{const used=usedIds(),threshold=Number(distSel.value),max=Number(maxSel.value);return all.filter(s=>!used.has(Number(s.id))&&Number(s.routeDistance)<=threshold).sort((a,b)=>a.alongMiles-b.alongMiles).slice(0,max);};
   const render=()=>{const picks=candidates();preview.innerHTML=picks.length?`<b>${picks.length} checkpoint${picks.length===1?'':'s'} ready:</b> ${picks.map(s=>`${s.title} (${s.alongMiles.toFixed(1)} mi)`).join(' → ')}`:'No unused Top Sights match this distance.';status.textContent=picks.length?`${picks.reduce((n,s)=>n+(Number(s.xp)||25),0)} XP`:'0 XP';build.disabled=!picks.length;};
   distSel.onchange=render;maxSel.onchange=render;
   build.onclick=async()=>{const picks=candidates();if(!picks.length)return;build.disabled=true;build.textContent='Building…';for(const s of picks)await addCheckpoint(s);build.textContent='Route added ✓';status.textContent=`${picks.length} added · ${picks.reduce((n,s)=>n+(Number(s.xp)||25),0)} XP`;setTimeout(()=>{build.textContent='Build route';render();},1000);};
   const obs=new MutationObserver(()=>render());const rowWrap=document.getElementById('tng-checkpoint-rows');if(rowWrap)obs.observe(rowWrap,{childList:true,subtree:true});
   render();return true;
 };
 let n=0,t=setInterval(()=>{boot().then(ok=>{if(ok||++n>40)clearInterval(t)});},150);boot();
})();
JS
        ,'after');
    }
}
TNG_Auto_Route_Builder::boot();
