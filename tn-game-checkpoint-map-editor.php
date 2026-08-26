<?php
/**
 * TN Game Visual Checkpoint Map Editor
 * Drag, add, focus, and reorder game checkpoints against the linked trail GPX route.
 */
if (!defined('ABSPATH')) exit;

final class TNG_Checkpoint_Map_Editor {
    public static function boot(): void {
        add_action('add_meta_boxes_tng_game', [__CLASS__, 'meta_box']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue']);
    }

    private static function media_url($value): string {
        if (is_numeric($value)) { $url=wp_get_attachment_url(absint($value)); return $url?esc_url_raw($url):''; }
        if (is_array($value)) { foreach(['url','file','src','ID','id'] as $key) if(isset($value[$key])) { $url=self::media_url($value[$key]); if($url!=='') return $url; } return ''; }
        $value=trim((string)$value); if($value==='') return ''; if(strpos($value,'/')===0) return esc_url_raw(home_url($value)); return esc_url_raw($value);
    }

    private static function route_url(int $trail_id): string {
        if(!$trail_id) return '';
        foreach(['trail_gpx_url','trail_gpx','gpx_url','gpx_file','gpx','tng_gpx_url','tng_trail_gpx','route_gpx_url','route_gpx'] as $key){
            $url=self::media_url(get_post_meta($trail_id,$key,true)); if($url!=='') return $url;
            if(function_exists('get_field')) { $url=self::media_url(get_field($key,$trail_id)); if($url!=='') return $url; }
        }
        return '';
    }

    public static function meta_box(): void {
        add_meta_box('tng-visual-route-editor','TN Game — Visual Route Editor',[__CLASS__,'render'],'tng_game','normal','high');
    }

    public static function render(WP_Post $post): void {
        $trail_id=absint(get_post_meta($post->ID,'tng_trail_id',true));
        $route=self::route_url($trail_id);
        echo '<div class="tng-route-editor">';
        echo '<div class="tng-route-editor__toolbar"><div><strong>Visual checkpoint editor</strong><span>Drag pins to reposition. Click the map to add a checkpoint.</span></div><div><button type="button" class="button" id="tng-route-fit">Fit route</button></div></div>';
        echo '<div class="tng-route-editor__layout"><div id="tng-admin-route-map" class="tng-admin-route-map" aria-label="Visual checkpoint editor"></div><div class="tng-route-editor__side"><div class="tng-route-editor__summary"><b id="tng-route-count">0</b><span>checkpoints</span></div><div id="tng-route-list" class="tng-route-list"></div><p class="description">Changes on this map update the checkpoint form below. Click <strong>Update</strong> or <strong>Save</strong> to store them.</p></div></div>';
        if(!$trail_id) echo '<p class="notice notice-warning inline"><span>This game is not linked to a trail, so no GPX route can be loaded. Checkpoint editing still works.</span></p>';
        elseif(!$route) echo '<p class="notice notice-warning inline"><span>The linked trail has no GPX route. Checkpoints can still be positioned on the map.</span></p>';
        echo '</div>';
    }

    public static function enqueue(string $hook): void {
        if(!in_array($hook,['post.php','post-new.php'],true)) return;
        $screen=get_current_screen(); if(!$screen||$screen->post_type!=='tng_game') return;
        $post_id=isset($_GET['post'])?absint($_GET['post']):0;
        $trail_id=$post_id?absint(get_post_meta($post_id,'tng_trail_id',true)):0;
        $route=self::route_url($trail_id);
        wp_enqueue_style('tng-admin-leaflet','https://unpkg.com/leaflet@1.9.4/dist/leaflet.css',[],'1.9.4');
        wp_enqueue_script('tng-admin-leaflet','https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',[],'1.9.4',true);
        wp_add_inline_style('tng-admin-leaflet','
            .tng-route-editor__toolbar{display:flex;justify-content:space-between;gap:16px;align-items:center;margin-bottom:12px}.tng-route-editor__toolbar strong{display:block;font-size:14px}.tng-route-editor__toolbar span{display:block;color:#65736b;font-size:12px;margin-top:3px}.tng-route-editor__layout{display:grid;grid-template-columns:minmax(0,2.2fr) minmax(260px,.8fr);gap:14px}.tng-admin-route-map{height:440px;border:1px solid #dcdcde;border-radius:10px;background:#eef2ef;overflow:hidden}.tng-route-editor__side{border:1px solid #dfe5e1;border-radius:10px;background:#f8faf8;padding:12px;max-height:440px;overflow:auto}.tng-route-editor__summary{display:flex;align-items:baseline;gap:6px;margin-bottom:10px}.tng-route-editor__summary b{font-size:24px;color:#173b2a}.tng-route-editor__summary span{color:#66746c;font-size:11px;text-transform:uppercase;font-weight:700;letter-spacing:.05em}.tng-route-item{display:grid;grid-template-columns:28px minmax(0,1fr) auto;align-items:center;gap:8px;padding:9px;margin-bottom:7px;border:1px solid #dde5df;border-radius:9px;background:white}.tng-route-item.is-active{border-color:#f16022;box-shadow:0 0 0 1px #f16022}.tng-route-item__num{display:flex;width:26px;height:26px;align-items:center;justify-content:center;border-radius:8px;background:#f16022;color:#fff;font-weight:800}.tng-route-item__title{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:600;color:#173b2a}.tng-route-item__actions{display:flex;gap:3px}.tng-route-item__actions button{width:25px;height:25px;min-height:25px;padding:0;line-height:23px}.tng-admin-cp-marker{display:flex!important;align-items:center;justify-content:center;width:31px!important;height:31px!important;border-radius:10px;background:#f16022;color:#fff;border:3px solid #fff;box-shadow:0 4px 12px rgba(0,0,0,.25);font-weight:800}.tng-admin-cp-marker.is-active{transform:scale(1.15);box-shadow:0 0 0 3px rgba(241,96,34,.22),0 4px 12px rgba(0,0,0,.25)}@media(max-width:1050px){.tng-route-editor__layout{grid-template-columns:1fr}.tng-route-editor__side{max-height:none}.tng-admin-route-map{height:380px}}
        ');
        wp_localize_script('tng-admin-leaflet','TNG_ADMIN_ROUTE_EDITOR',['routeUrl'=>$route,'defaultLat'=>35.45,'defaultLng'=>-85.65]);
        wp_add_inline_script('tng-admin-leaflet', <<<'JS'
(()=>{
 const boot=()=>{
  const el=document.getElementById('tng-admin-route-map'), rows=document.getElementById('tng-checkpoint-rows'), list=document.getElementById('tng-route-list');
  if(!el||!rows||!list||typeof L==='undefined'||el.dataset.ready==='1')return false;el.dataset.ready='1';
  const map=L.map(el,{scrollWheelZoom:false});window.TNG_ADMIN_ROUTE_MAP=map;L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,attribution:'© OpenStreetMap contributors'}).addTo(map);
  const state={markers:[],routePts:[],routeLayer:null};
  const rowEls=()=>[...rows.querySelectorAll('.tng-cp-row')];
  const field=(row,key)=>row.querySelector(`[name$="[${key}]"]`);
  const val=(row,key)=>{const f=field(row,key);return f?f.value:''};
  const set=(row,key,v)=>{const f=field(row,key);if(f){f.value=v;f.dispatchEvent(new Event('change',{bubbles:true}));}};
  const cleanTitle=(row,i)=>val(row,'title').trim()||`Checkpoint ${i+1}`;
  const markerIcon=(i,active=false)=>L.divIcon({className:'tng-admin-cp-marker'+(active?' is-active':''),html:String(i+1),iconSize:[31,31],iconAnchor:[15,15]});
  const validCoord=(lat,lng)=>Number.isFinite(lat)&&Number.isFinite(lng)&&Math.abs(lat)<=90&&Math.abs(lng)<=180&&(lat!==0||lng!==0);
  const renumberNames=()=>rowEls().forEach((r,i)=>{const n=r.querySelector('.tng-cp-num');if(n)n.textContent=i+1;r.querySelectorAll('[name]').forEach(f=>f.name=f.name.replace(/tng_cp\[\d+\]/,`tng_cp[${i}]`));});
  const focus=(i,open=true)=>{state.markers.forEach((m,j)=>m.setIcon(markerIcon(j,j===i)));[...list.children].forEach((x,j)=>x.classList.toggle('is-active',j===i));const m=state.markers[i];if(m){map.panTo(m.getLatLng());if(open)m.openPopup();}};
  const reorder=(from,to)=>{const arr=rowEls();if(from<0||to<0||from>=arr.length||to>=arr.length||from===to)return;const node=arr[from];if(to>from)rows.insertBefore(node,arr[to].nextSibling);else rows.insertBefore(node,arr[to]);renumberNames();rebuild();};
  const rebuild=()=>{
   state.markers.forEach(m=>map.removeLayer(m));state.markers=[];list.innerHTML='';const bounds=[];
   rowEls().forEach((row,i)=>{
    const title=cleanTitle(row,i),lat=parseFloat(val(row,'latitude')),lng=parseFloat(val(row,'longitude'));
    const item=document.createElement('div');item.className='tng-route-item';item.innerHTML=`<span class="tng-route-item__num">${i+1}</span><span class="tng-route-item__title"></span><span class="tng-route-item__actions"><button type="button" class="button tng-route-up" title="Move up">↑</button><button type="button" class="button tng-route-down" title="Move down">↓</button></span>`;item.querySelector('.tng-route-item__title').textContent=title;item.addEventListener('click',e=>{if(e.target.closest('button'))return;focus(i)});item.querySelector('.tng-route-up').disabled=i===0;item.querySelector('.tng-route-down').disabled=i===rowEls().length-1;item.querySelector('.tng-route-up').onclick=()=>reorder(i,i-1);item.querySelector('.tng-route-down').onclick=()=>reorder(i,i+1);list.appendChild(item);
    if(validCoord(lat,lng)){
      const marker=L.marker([lat,lng],{icon:markerIcon(i),draggable:true,zIndexOffset:500}).addTo(map).bindPopup(`<strong>${title.replace(/[<>&]/g,'')}</strong><br><small>Drag to reposition</small>`);
      marker.on('dragend',()=>{const p=marker.getLatLng();set(row,'latitude',p.lat.toFixed(6));set(row,'longitude',p.lng.toFixed(6));});marker.on('click',()=>focus(i,false));state.markers[i]=marker;bounds.push([lat,lng]);
    }else state.markers[i]=null;
   });
   document.getElementById('tng-route-count').textContent=rowEls().length;
   if(!state.routePts.length&&bounds.length)map.fitBounds(bounds,{padding:[35,35],maxZoom:15});else if(!state.routePts.length&&!bounds.length)map.setView([TNG_ADMIN_ROUTE_EDITOR.defaultLat,TNG_ADMIN_ROUTE_EDITOR.defaultLng],10);
  };
  const addAt=(latlng)=>{const add=document.getElementById('tng-add-checkpoint');if(!add)return;add.click();setTimeout(()=>{const arr=rowEls(),row=arr[arr.length-1];if(!row)return;set(row,'title',`Checkpoint ${arr.length}`);set(row,'instructions','Visit this checkpoint.');set(row,'latitude',latlng.lat.toFixed(6));set(row,'longitude',latlng.lng.toFixed(6));set(row,'radius','30');set(row,'xp','25');renumberNames();rebuild();focus(arr.length-1);},20);};
  map.on('click',e=>{if(window.confirm('Add a checkpoint here?'))addAt(e.latlng)});
  rows.addEventListener('input',e=>{if(e.target.matches('[name$="[title]"]'))rebuild();});
  rows.addEventListener('click',e=>{if(e.target.closest('.tng-remove-cp'))setTimeout(rebuild,20)});
  const fit=()=>{const pts=[...state.routePts];state.markers.forEach(m=>{if(m){const p=m.getLatLng();pts.push([p.lat,p.lng]);}});if(pts.length)map.fitBounds(pts,{padding:[35,35],maxZoom:15});};
  document.getElementById('tng-route-fit')?.addEventListener('click',fit);
  const route=TNG_ADMIN_ROUTE_EDITOR.routeUrl||'';
  if(route){fetch(route,{credentials:'same-origin'}).then(r=>r.ok?r.text():Promise.reject()).then(text=>{const xml=new DOMParser().parseFromString(text,'application/xml');state.routePts=[...xml.querySelectorAll('trkpt,rtept')].map(n=>[parseFloat(n.getAttribute('lat')),parseFloat(n.getAttribute('lon'))]).filter(p=>validCoord(p[0],p[1]));if(state.routePts.length){state.routeLayer=L.polyline(state.routePts,{color:'#f16022',weight:5,opacity:.9}).addTo(map);}rebuild();fit();}).catch(()=>rebuild());}else rebuild();
  setTimeout(()=>map.invalidateSize(),120);setTimeout(()=>map.invalidateSize(),500);return true;
 };
 let n=0,t=setInterval(()=>{if(boot()||++n>50)clearInterval(t)},120);boot();
})();
JS
        ,'after');
    }
}
TNG_Checkpoint_Map_Editor::boot();
