<?php
/**
 * TN Game Checkpoint Top Sight Picker
 * Adds existing Top Sight discovery/attachment to the visual checkpoint editor.
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
          .tng-admin-sight-marker{display:flex!important;align-items:center;justify-content:center;width:27px!important;height:27px!important;border-radius:50%;background:#17613f;color:#fff;border:3px solid #fff;box-shadow:0 3px 10px rgba(0,0,0,.22);font-size:12px}.tng-admin-sight-marker.is-used{background:#7d8b83;opacity:.78}.tng-admin-sight-popup{min-width:210px}.tng-admin-sight-popup strong{color:#173b2a}.tng-admin-sight-popup small{display:block;color:#718078;margin:3px 0 8px}.tng-admin-sight-popup__actions{display:flex;gap:6px;flex-wrap:wrap}.tng-admin-sight-popup__actions button{font-size:11px}.tng-route-editor__legend{display:flex;gap:14px;align-items:center;margin-top:8px;color:#66746c;font-size:11px}.tng-route-editor__legend span{display:inline-flex;gap:5px;align-items:center}.tng-route-editor__legend i{width:10px;height:10px;border-radius:50%;background:#17613f}.tng-route-editor__legend .checkpoint i{background:#f16022}.tng-route-item__sight{display:block;margin-top:2px;color:#17613f;font-size:10px;font-weight:700}
        ');
        wp_localize_script('tng-admin-leaflet','TNG_ADMIN_TOP_SIGHTS',['items'=>self::items()]);
        wp_add_inline_script('tng-admin-leaflet', <<<'JS'
(()=>{
 if(typeof L==='undefined')return;
 if(!L.__tngAdminCapture){
   L.__tngAdminCapture=1;const original=L.map;
   L.map=function(){const map=original.apply(this,arguments),el=arguments[0];if(el==='tng-admin-route-map'||(el&&el.id==='tng-admin-route-map'))window.TNG_ADMIN_ROUTE_MAP=map;return map;};
 }
 const esc=s=>String(s||'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]||c));
 const rows=()=>[...document.querySelectorAll('#tng-checkpoint-rows .tng-cp-row')];
 const field=(row,key)=>row?.querySelector(`[name$="[${key}]"]`);
 const set=(row,key,val)=>{let f=field(row,key);if(!f&&key==='sight_id'){f=document.createElement('input');f.type='hidden';f.name=`tng_cp[0][sight_id]`;row.querySelector('span:nth-child(2)')?.appendChild(f);}if(f){f.value=val;f.dispatchEvent(new Event('input',{bubbles:true}));f.dispatchEvent(new Event('change',{bubbles:true}));}};
 const activeIndex=()=>{const active=document.querySelector('#tng-route-list .tng-route-item.is-active');if(!active)return-1;return[...active.parentElement.children].indexOf(active);};
 const usedIds=()=>new Set(rows().map(r=>parseInt(field(r,'sight_id')?.value||'0',10)).filter(Boolean));
 const renumber=()=>rows().forEach((r,i)=>r.querySelectorAll('[name]').forEach(f=>f.name=f.name.replace(/tng_cp\[\d+\]/,`tng_cp[${i}]`)));
 const attach=(s,index)=>{
   const arr=rows(),row=arr[index];if(!row)return;
   set(row,'title',s.title);set(row,'instructions',`Visit ${s.title}.`);set(row,'latitude',Number(s.lat).toFixed(6));set(row,'longitude',Number(s.lng).toFixed(6));set(row,'radius','30');set(row,'xp',String(s.xp||25));set(row,'sight_id',String(s.id));
   let note=row.querySelector('.tng-attached-sight-note');if(!note){note=document.createElement('small');note.className='tng-attached-sight-note';row.querySelector('span:nth-child(2)')?.appendChild(note);}note.textContent=`Top Sight #${s.id}`;
   renumber();document.dispatchEvent(new CustomEvent('tng:checkpoint-form-changed'));
 };
 const addNew=(s)=>{const add=document.getElementById('tng-add-checkpoint');if(!add)return;add.click();setTimeout(()=>{const arr=rows();attach(s,arr.length-1);},30);};
 const boot=()=>{
   const map=window.TNG_ADMIN_ROUTE_MAP;if(!map||typeof TNG_ADMIN_TOP_SIGHTS==='undefined')return false;if(map.__tngTopSightPicker)return true;map.__tngTopSightPicker=1;
   const items=TNG_ADMIN_TOP_SIGHTS.items||[],markers=[];
   const refreshUsed=()=>{const used=usedIds();markers.forEach(({marker,sight})=>marker.setIcon(L.divIcon({className:'tng-admin-sight-marker'+(used.has(Number(sight.id))?' is-used':''),html:'📍',iconSize:[27,27],iconAnchor:[13,25]})));};
   items.forEach(s=>{
     const icon=L.divIcon({className:'tng-admin-sight-marker',html:'📍',iconSize:[27,27],iconAnchor:[13,25]});
     const marker=L.marker([Number(s.lat),Number(s.lng)],{icon,zIndexOffset:250}).addTo(map);
     marker.bindPopup(`<div class="tng-admin-sight-popup"><strong>${esc(s.title)}</strong><small>${Number(s.xp)||25} XP · Top Sight #${s.id}</small><div class="tng-admin-sight-popup__actions"><button type="button" class="button button-primary tng-attach-sight" data-id="${s.id}">Attach to current</button><button type="button" class="button tng-add-sight" data-id="${s.id}">Add as checkpoint</button></div></div>`);
     markers.push({marker,sight:s});
   });
   map.on('popupopen',()=>setTimeout(()=>{
     document.querySelectorAll('.tng-attach-sight').forEach(b=>b.onclick=()=>{const s=items.find(x=>String(x.id)===b.dataset.id),i=activeIndex();if(!s)return;if(i<0){alert('Select a checkpoint in the list first, or choose Add as checkpoint.');return;}attach(s,i);map.closePopup();refreshUsed();});
     document.querySelectorAll('.tng-add-sight').forEach(b=>b.onclick=()=>{const s=items.find(x=>String(x.id)===b.dataset.id);if(!s)return;addNew(s);map.closePopup();setTimeout(refreshUsed,80);});
   },0));
   const toolbar=document.querySelector('.tng-route-editor__toolbar');if(toolbar&&!document.querySelector('.tng-route-editor__legend')){const legend=document.createElement('div');legend.className='tng-route-editor__legend';legend.innerHTML='<span class="checkpoint"><i></i> Checkpoint</span><span><i></i> Available Top Sight</span>';toolbar.insertAdjacentElement('afterend',legend);}
   const observer=new MutationObserver(()=>refreshUsed());const rowWrap=document.getElementById('tng-checkpoint-rows');if(rowWrap)observer.observe(rowWrap,{childList:true,subtree:true,attributes:true,attributeFilter:['value']});
   refreshUsed();return true;
 };
 let n=0,t=setInterval(()=>{if(boot()||++n>60)clearInterval(t)},120);boot();
})();
JS
        ,'after');
    }
}
TNG_Checkpoint_Top_Sight_Picker::boot();
