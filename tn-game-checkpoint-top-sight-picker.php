<?php
/**
 * TN Game Checkpoint Top Sight Picker
 * Adds route-aware Top Sight discovery/attachment to the visual checkpoint editor.
 */
if (!defined('ABSPATH')) exit;

final class TNG_Checkpoint_Top_Sight_Picker {
    public static function boot(): void { add_action('admin_enqueue_scripts',[__CLASS__,'enqueue'],9); }

    private static function top_sight_types(): array {
        $types=[];
        foreach(get_post_types(['public'=>true],'names') as $type){
            $key=strtolower(str_replace(['-','_'],'',(string)$type));
            if($key==='topsight'||(strpos($key,'top')!==false&&strpos($key,'sight')!==false))$types[]=$type;
        }
        foreach(['top_sight','top-sight','topsight','top-sights','tng_top_sight'] as $type)if(post_type_exists($type))$types[]=$type;
        return array_values(array_unique($types));
    }

    private static function numeric_meta(int $id,array $keys): ?float {
        foreach($keys as $key){
            $value=get_post_meta($id,$key,true); if(is_numeric($value))return(float)$value;
            if(function_exists('get_field')){$value=get_field($key,$id);if(is_numeric($value))return(float)$value;}
        }
        return null;
    }

    private static function coordinates(int $id): array {
        $lat=self::numeric_meta($id,['sight_latitude','latitude','lat','top_sight_latitude','map_latitude','location_latitude','location_lat']);
        $lng=self::numeric_meta($id,['sight_longitude','longitude','lng','lon','top_sight_longitude','map_longitude','location_longitude','location_lng']);
        if($lat===null||$lng===null)return[];
        if(abs($lat)>90||abs($lng)>180||($lat==0.0&&$lng==0.0))return[];
        return['lat'=>$lat,'lng'=>$lng];
    }

    private static function xp(int $id): int {
        foreach(['xp_available','xp','checkpoint_xp','top_sight_xp','tng_xp'] as $key){$v=get_post_meta($id,$key,true);if(is_numeric($v))return max(0,absint($v));}
        return 25;
    }

    private static function items(): array {
        $types=self::top_sight_types(); if(!$types)return[];
        $ids=get_posts(['post_type'=>$types,'post_status'=>'publish','posts_per_page'=>500,'fields'=>'ids','orderby'=>'title','order'=>'ASC','no_found_rows'=>true]);
        $out=[];
        foreach($ids as $id){$coords=self::coordinates((int)$id);if(!$coords)continue;$out[]=['id'=>(int)$id,'title'=>get_the_title($id)?:('Top Sight #'.$id),'lat'=>$coords['lat'],'lng'=>$coords['lng'],'xp'=>self::xp((int)$id),'url'=>get_permalink($id)?:''];}
        return$out;
    }

    public static function enqueue(string $hook): void {
        if(!in_array($hook,['post.php','post-new.php'],true))return;
        $screen=get_current_screen();if(!$screen||$screen->post_type!=='tng_game')return;
        wp_enqueue_style('tng-admin-leaflet','https://unpkg.com/leaflet@1.9.4/dist/leaflet.css',[],'1.9.4');
        wp_enqueue_script('tng-admin-leaflet','https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',[],'1.9.4',true);
        wp_add_inline_style('tng-admin-leaflet','
          .tng-admin-sight-marker{display:flex!important;align-items:center;justify-content:center;width:27px!important;height:27px!important;border-radius:50%;background:#17613f;color:#fff;border:3px solid #fff;box-shadow:0 3px 10px rgba(0,0,0,.22);font-size:12px}.tng-admin-sight-marker.is-used{background:#7d8b83;opacity:.78}.tng-admin-sight-popup{min-width:230px}.tng-admin-sight-popup strong{color:#173b2a}.tng-admin-sight-popup small{display:block;color:#718078;margin:3px 0 8px}.tng-admin-sight-popup__metrics{display:flex;gap:5px;flex-wrap:wrap;margin:7px 0}.tng-admin-sight-popup__metrics span,.tng-sight-suggestion__metric{display:inline-block;padding:3px 6px;border-radius:999px;background:#eef4ef;color:#315b45;font-size:10px;font-weight:700}.tng-admin-sight-popup__actions{display:flex;gap:6px;flex-wrap:wrap}.tng-admin-sight-popup__actions button{font-size:11px}.tng-route-editor__legend{display:flex;gap:14px;align-items:center;margin-top:8px;color:#66746c;font-size:11px}.tng-route-editor__legend span{display:inline-flex;gap:5px;align-items:center}.tng-route-editor__legend i{width:10px;height:10px;border-radius:50%;background:#17613f}.tng-route-editor__legend .checkpoint i{background:#f16022}.tng-route-item__sight{display:block;margin-top:2px;color:#17613f;font-size:10px;font-weight:700}.tng-sight-suggestions{margin-top:14px;padding-top:12px;border-top:1px solid #dde5df}.tng-sight-suggestions__head{display:flex;justify-content:space-between;align-items:baseline;gap:8px;margin-bottom:8px}.tng-sight-suggestions__head strong{color:#173b2a}.tng-sight-suggestions__head small{color:#77847d}.tng-sight-suggestion{padding:9px;margin-bottom:7px;border:1px solid #dde5df;border-radius:9px;background:#fff}.tng-sight-suggestion.is-used{opacity:.58}.tng-sight-suggestion__top{display:flex;justify-content:space-between;gap:8px;align-items:center}.tng-sight-suggestion__title{font-weight:700;color:#173b2a;font-size:12px}.tng-sight-suggestion__metrics{display:flex;gap:4px;flex-wrap:wrap;margin-top:6px}.tng-sight-suggestion__actions{display:flex;gap:5px;margin-top:7px}.tng-sight-suggestion__actions button{font-size:10px;min-height:26px;line-height:24px;padding:0 7px}.tng-sight-suggestion__used{color:#65736b;font-size:10px;font-weight:700}
        ');
        wp_localize_script('tng-admin-leaflet','TNG_ADMIN_TOP_SIGHTS',['items'=>self::items()]);
        wp_add_inline_script('tng-admin-leaflet', <<<'JS'
(()=>{
 if(typeof L==='undefined')return;
 if(!L.__tngAdminCapture){L.__tngAdminCapture=1;const original=L.map;L.map=function(){const map=original.apply(this,arguments),el=arguments[0];if(el==='tng-admin-route-map'||(el&&el.id==='tng-admin-route-map'))window.TNG_ADMIN_ROUTE_MAP=map;return map;};}
 const esc=s=>String(s||'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]||c));
 const rows=()=>[...document.querySelectorAll('#tng-checkpoint-rows .tng-cp-row')];
 const field=(row,key)=>row?.querySelector(`[name$="[${key}]"]`);
 const set=(row,key,val)=>{let f=field(row,key);if(!f&&key==='sight_id'){f=document.createElement('input');f.type='hidden';f.name='tng_cp[0][sight_id]';row.querySelector('span:nth-child(2)')?.appendChild(f);}if(f){f.value=val;f.dispatchEvent(new Event('input',{bubbles:true}));f.dispatchEvent(new Event('change',{bubbles:true}));}};
 const activeIndex=()=>{const active=document.querySelector('#tng-route-list .tng-route-item.is-active');if(!active)return-1;return[...active.parentElement.children].indexOf(active);};
 const usedIds=()=>new Set(rows().map(r=>parseInt(field(r,'sight_id')?.value||'0',10)).filter(Boolean));
 const renumber=()=>rows().forEach((r,i)=>r.querySelectorAll('[name]').forEach(f=>f.name=f.name.replace(/tng_cp\[\d+\]/,`tng_cp[${i}]`)));
 const attach=(s,index)=>{const arr=rows(),row=arr[index];if(!row)return;set(row,'title',s.title);set(row,'instructions',`Visit ${s.title}.`);set(row,'latitude',Number(s.lat).toFixed(6));set(row,'longitude',Number(s.lng).toFixed(6));set(row,'radius','30');set(row,'xp',String(s.xp||25));set(row,'sight_id',String(s.id));let note=row.querySelector('.tng-attached-sight-note');if(!note){note=document.createElement('small');note.className='tng-attached-sight-note';row.querySelector('span:nth-child(2)')?.appendChild(note);}note.textContent=`Top Sight #${s.id}`;renumber();document.dispatchEvent(new CustomEvent('tng:checkpoint-form-changed'));};
 const addNew=s=>{const add=document.getElementById('tng-add-checkpoint');if(!add)return;add.click();setTimeout(()=>{const arr=rows();attach(s,arr.length-1);},30);};
 const hav=(a,b)=>{const R=6371000,d2r=Math.PI/180,dLat=(b[0]-a[0])*d2r,dLng=(b[1]-a[1])*d2r,x=Math.sin(dLat/2)**2+Math.cos(a[0]*d2r)*Math.cos(b[0]*d2r)*Math.sin(dLng/2)**2;return 2*R*Math.atan2(Math.sqrt(x),Math.sqrt(1-x));};
 const enrich=(items,pts)=>{if(!pts.length)return items.map(s=>({...s,routeDistance:null,alongMiles:null}));const cum=[0];for(let i=1;i<pts.length;i++)cum[i]=cum[i-1]+hav(pts[i-1],pts[i]);return items.map(s=>{let best=Infinity,idx=0;for(let i=0;i<pts.length;i++){const d=hav([Number(s.lat),Number(s.lng)],pts[i]);if(d<best){best=d;idx=i;}}return{...s,routeDistance:best,alongMiles:cum[idx]/1609.344};}).sort((a,b)=>(a.routeDistance??Infinity)-(b.routeDistance??Infinity));};
 const metricText=s=>{if(s.routeDistance==null)return'';const off=s.routeDistance<1609.344?`${Math.round(s.routeDistance*3.28084)} ft from trail`:`${(s.routeDistance/1609.344).toFixed(1)} mi from trail`;const along=s.alongMiles==null?'':`${s.alongMiles.toFixed(1)} mi along route`;return [off,along].filter(Boolean);};
 const boot=()=>{
   const map=window.TNG_ADMIN_ROUTE_MAP;if(!map||typeof TNG_ADMIN_TOP_SIGHTS==='undefined')return false;if(map.__tngTopSightPicker)return true;map.__tngTopSightPicker=1;
   const raw=TNG_ADMIN_TOP_SIGHTS.items||[],markers=[];let items=raw,routePts=[];
   const side=document.querySelector('.tng-route-editor__side');
   const suggestionWrap=document.createElement('section');suggestionWrap.className='tng-sight-suggestions';suggestionWrap.innerHTML='<div class="tng-sight-suggestions__head"><strong>Suggested Top Sights</strong><small>Closest to route</small></div><div class="tng-sight-suggestions__list"></div>';side?.appendChild(suggestionWrap);
   const renderSuggestions=()=>{const list=suggestionWrap.querySelector('.tng-sight-suggestions__list');if(!list)return;const used=usedIds();list.innerHTML='';items.slice(0,8).forEach(s=>{const el=document.createElement('div');el.className='tng-sight-suggestion'+(used.has(Number(s.id))?' is-used':'');const metrics=metricText(s);el.innerHTML=`<div class="tng-sight-suggestion__top"><span class="tng-sight-suggestion__title">${esc(s.title)}</span>${used.has(Number(s.id))?'<span class="tng-sight-suggestion__used">USED</span>':''}</div><div class="tng-sight-suggestion__metrics">${metrics.map(m=>`<span class="tng-sight-suggestion__metric">${esc(m)}</span>`).join('')}<span class="tng-sight-suggestion__metric">${Number(s.xp)||25} XP</span></div><div class="tng-sight-suggestion__actions"><button type="button" class="button tng-suggest-focus">Show</button><button type="button" class="button button-primary tng-suggest-add" ${used.has(Number(s.id))?'disabled':''}>Add checkpoint</button></div>`;el.querySelector('.tng-suggest-focus').onclick=()=>{map.setView([Number(s.lat),Number(s.lng)],Math.max(map.getZoom(),15));const rec=markers.find(x=>Number(x.sight.id)===Number(s.id));rec?.marker.openPopup();};el.querySelector('.tng-suggest-add').onclick=()=>{addNew(s);setTimeout(()=>{refreshUsed();renderSuggestions();},100);};list.appendChild(el);});};
   const refreshUsed=()=>{const used=usedIds();markers.forEach(({marker,sight})=>marker.setIcon(L.divIcon({className:'tng-admin-sight-marker'+(used.has(Number(sight.id))?' is-used':''),html:'📍',iconSize:[27,27],iconAnchor:[13,25]})));};
   const rebuildPopups=()=>markers.forEach(({marker,sight:s})=>{const metrics=metricText(s);marker.setPopupContent(`<div class="tng-admin-sight-popup"><strong>${esc(s.title)}</strong><div class="tng-admin-sight-popup__metrics">${metrics.map(m=>`<span>${esc(m)}</span>`).join('')}<span>${Number(s.xp)||25} XP</span></div><small>Top Sight #${s.id}</small><div class="tng-admin-sight-popup__actions"><button type="button" class="button button-primary tng-attach-sight" data-id="${s.id}">Attach to current</button><button type="button" class="button tng-add-sight" data-id="${s.id}">Add as checkpoint</button></div></div>`);});
   raw.forEach(s=>{const marker=L.marker([Number(s.lat),Number(s.lng)],{icon:L.divIcon({className:'tng-admin-sight-marker',html:'📍',iconSize:[27,27],iconAnchor:[13,25]}),zIndexOffset:250}).addTo(map);markers.push({marker,sight:s});});
   const applyRoute=pts=>{routePts=pts;items=enrich(raw,routePts);markers.forEach(rec=>{const found=items.find(x=>Number(x.id)===Number(rec.sight.id));if(found)rec.sight=found;});rebuildPopups();renderSuggestions();refreshUsed();};
   const route=(typeof TNG_ADMIN_ROUTE_EDITOR!=='undefined'&&TNG_ADMIN_ROUTE_EDITOR.routeUrl)||'';
   if(route){fetch(route,{credentials:'same-origin'}).then(r=>r.ok?r.text():Promise.reject()).then(text=>{const xml=new DOMParser().parseFromString(text,'application/xml');const pts=[...xml.querySelectorAll('trkpt,rtept')].map(n=>[parseFloat(n.getAttribute('lat')),parseFloat(n.getAttribute('lon'))]).filter(p=>Number.isFinite(p[0])&&Number.isFinite(p[1]));applyRoute(pts);}).catch(()=>applyRoute([]));}else applyRoute([]);
   map.on('popupopen',()=>setTimeout(()=>{document.querySelectorAll('.tng-attach-sight').forEach(b=>b.onclick=()=>{const s=items.find(x=>String(x.id)===b.dataset.id),i=activeIndex();if(!s)return;if(i<0){alert('Select a checkpoint in the list first, or choose Add as checkpoint.');return;}attach(s,i);map.closePopup();refreshUsed();renderSuggestions();});document.querySelectorAll('.tng-add-sight').forEach(b=>b.onclick=()=>{const s=items.find(x=>String(x.id)===b.dataset.id);if(!s)return;addNew(s);map.closePopup();setTimeout(()=>{refreshUsed();renderSuggestions();},100);});},0));
   const toolbar=document.querySelector('.tng-route-editor__toolbar');if(toolbar&&!document.querySelector('.tng-route-editor__legend')){const legend=document.createElement('div');legend.className='tng-route-editor__legend';legend.innerHTML='<span class="checkpoint"><i></i> Checkpoint</span><span><i></i> Available Top Sight</span>';toolbar.insertAdjacentElement('afterend',legend);}
   const observer=new MutationObserver(()=>{refreshUsed();renderSuggestions();});const rowWrap=document.getElementById('tng-checkpoint-rows');if(rowWrap)observer.observe(rowWrap,{childList:true,subtree:true,attributes:true,attributeFilter:['value']});
   refreshUsed();return true;
 };
 let n=0,t=setInterval(()=>{if(boot()||++n>60)clearInterval(t)},120);boot();
})();
JS
        ,'after');
    }
}
TNG_Checkpoint_Top_Sight_Picker::boot();
