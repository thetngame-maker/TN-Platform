<?php
/**
 * TN Game Route Direction
 * Persistent Forward / Reverse / Out & Back controls for trail-game authoring.
 */
if (!defined('ABSPATH')) exit;

final class TNG_Route_Direction {
    public static function boot(): void {
        add_action('admin_enqueue_scripts',[__CLASS__,'enqueue'],13);
        add_action('save_post_tng_game',[__CLASS__,'save'],20,2);
        add_filter('tng_game_route_direction',[__CLASS__,'direction_filter'],10,2);
    }

    public static function direction_filter($value,$game_id){
        $saved=get_post_meta(absint($game_id),'tng_route_direction',true);
        return in_array($saved,['forward','reverse','out_back'],true)?$saved:$value;
    }

    public static function save(int $post_id, WP_Post $post): void {
        if(defined('DOING_AUTOSAVE')&&DOING_AUTOSAVE) return;
        if(wp_is_post_revision($post_id)||!current_user_can('edit_post',$post_id)) return;
        if(!isset($_POST['tng_route_direction_nonce'])||!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['tng_route_direction_nonce'])),'tng_route_direction_save')) return;
        $direction=isset($_POST['tng_route_direction'])?sanitize_key(wp_unslash($_POST['tng_route_direction'])):'forward';
        $start=isset($_POST['tng_route_start'])?sanitize_key(wp_unslash($_POST['tng_route_start'])):'gpx_start';
        if(!in_array($direction,['forward','reverse','out_back'],true))$direction='forward';
        if(!in_array($start,['gpx_start','gpx_end'],true))$start='gpx_start';
        update_post_meta($post_id,'tng_route_direction',$direction);
        update_post_meta($post_id,'tng_route_start',$start);
    }

    public static function enqueue(string $hook): void {
        if(!in_array($hook,['post.php','post-new.php'],true)) return;
        $screen=get_current_screen(); if(!$screen||$screen->post_type!=='tng_game') return;
        $post_id=isset($_GET['post'])?absint($_GET['post']):0;
        $direction=$post_id?get_post_meta($post_id,'tng_route_direction',true):'';
        $start=$post_id?get_post_meta($post_id,'tng_route_start',true):'';
        if(!in_array($direction,['forward','reverse','out_back'],true))$direction='forward';
        if(!in_array($start,['gpx_start','gpx_end'],true))$start='gpx_start';
        wp_enqueue_script('tng-admin-leaflet','https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',[],'1.9.4',true);
        wp_add_inline_style('tng-admin-leaflet','
          .tng-route-direction{margin:12px 0;padding:11px;border:1px solid #dfe5e1;border-radius:9px;background:#fff}.tng-route-direction__head{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:8px}.tng-route-direction__head strong{color:#173b2a}.tng-route-direction__state{padding:4px 7px;border-radius:999px;background:#e9f4ec;color:#17613f;font-size:10px;font-weight:800}.tng-route-direction__grid{display:grid;grid-template-columns:1fr 1fr;gap:8px}.tng-route-direction label{display:block;color:#65736b;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.04em}.tng-route-direction select{width:100%;margin-top:4px}.tng-route-direction__preview{margin-top:9px;padding:8px;border-radius:8px;background:#f7f9f7;color:#5f6f66;font-size:11px;line-height:1.4}.tng-route-direction__actions{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px}.tng-route-direction__actions button{font-size:11px}.tng-route-end-marker{display:flex!important;align-items:center;justify-content:center;width:26px!important;height:26px!important;border-radius:50%;border:3px solid #fff;box-shadow:0 3px 10px rgba(0,0,0,.22);font-size:11px;font-weight:900}.tng-route-end-marker.is-start{background:#17613f;color:#fff}.tng-route-end-marker.is-finish{background:#f16022;color:#fff}@media(max-width:1100px){.tng-route-direction__grid{grid-template-columns:1fr}}
        ');
        wp_localize_script('tng-admin-leaflet','TNG_ROUTE_DIRECTION',[ 'direction'=>$direction,'start'=>$start,'nonce'=>wp_create_nonce('tng_route_direction_save') ]);
        wp_add_inline_script('tng-admin-leaflet', <<<'JS'
(()=>{
 const rowsWrap=()=>document.getElementById('tng-checkpoint-rows');
 const rows=()=>[...(rowsWrap()?.querySelectorAll('.tng-cp-row')||[])];
 const field=(row,key)=>row?.querySelector(`[name$="[${key}]"]`);
 const coord=row=>[parseFloat(field(row,'latitude')?.value||''),parseFloat(field(row,'longitude')?.value||'')];
 const valid=p=>Number.isFinite(p[0])&&Number.isFinite(p[1])&&Math.abs(p[0])<=90&&Math.abs(p[1])<=180&&(p[0]!==0||p[1]!==0);
 const hav=(a,b)=>{const R=6371000,d2r=Math.PI/180,dLat=(b[0]-a[0])*d2r,dLng=(b[1]-a[1])*d2r,x=Math.sin(dLat/2)**2+Math.cos(a[0]*d2r)*Math.cos(b[0]*d2r)*Math.sin(dLng/2)**2;return 2*R*Math.atan2(Math.sqrt(x),Math.sqrt(1-x));};
 const renumber=()=>rows().forEach((r,i)=>{const n=r.querySelector('.tng-cp-num');if(n)n.textContent=i+1;r.querySelectorAll('[name]').forEach(f=>f.name=f.name.replace(/tng_cp\[\d+\]/,`tng_cp[${i}]`));});
 const forceRebuild=()=>{const f=field(rows()[0],'title');if(f)f.dispatchEvent(new Event('input',{bubbles:true}));document.dispatchEvent(new CustomEvent('tng:checkpoint-form-changed'));};
 let route=[],cum=[],startMarker=null,endMarker=null;
 const cumulative=()=>{cum=[0];for(let i=1;i<route.length;i++)cum[i]=cum[i-1]+hav(route[i-1],route[i]);};
 const metric=p=>{if(!route.length||!valid(p))return null;let best=Infinity,idx=0;for(let i=0;i<route.length;i++){const d=hav(p,route[i]);if(d<best){best=d;idx=i;}}return{idx,mi:(cum[idx]||0)/1609.344};};
 const ensureHidden=(name,value)=>{const form=document.getElementById('post')||document.querySelector('form.metabox-base-form')||document.querySelector('form');if(!form)return null;let input=form.querySelector(`[name="${name}"]`);if(!input){input=document.createElement('input');input.type='hidden';input.name=name;form.appendChild(input);}input.value=value;return input;};
 const direction=()=>document.getElementById('tng-route-direction-select')?.value||TNG_ROUTE_DIRECTION.direction||'forward';
 const start=()=>document.getElementById('tng-route-start-select')?.value||TNG_ROUTE_DIRECTION.start||'gpx_start';
 const syncHidden=()=>{ensureHidden('tng_route_direction',direction());ensureHidden('tng_route_start',start());ensureHidden('tng_route_direction_nonce',TNG_ROUTE_DIRECTION.nonce||'');};
 const ensurePanel=()=>{const side=document.querySelector('.tng-route-editor__side');if(!side)return null;let p=side.querySelector('.tng-route-direction');if(p)return p;p=document.createElement('section');p.className='tng-route-direction';p.innerHTML=`<div class="tng-route-direction__head"><strong>Route direction</strong><span class="tng-route-direction__state">${TNG_ROUTE_DIRECTION.direction==='reverse'?'Reverse':TNG_ROUTE_DIRECTION.direction==='out_back'?'Out & Back':'Forward'}</span></div><div class="tng-route-direction__grid"><label>Behavior<select id="tng-route-direction-select"><option value="forward">Forward</option><option value="reverse">Reverse</option><option value="out_back">Out & Back</option></select></label><label>Starting end<select id="tng-route-start-select"><option value="gpx_start">GPX start</option><option value="gpx_end">GPX end</option></select></label></div><div class="tng-route-direction__preview"></div><div class="tng-route-direction__actions"><button type="button" class="button button-primary tng-route-direction-apply">Apply checkpoint order</button><button type="button" class="button tng-route-direction-flip">Flip start/end</button></div>`;const health=side.querySelector('.tng-route-health');if(health)health.insertAdjacentElement('beforebegin',p);else side.prepend(p);p.querySelector('#tng-route-direction-select').value=TNG_ROUTE_DIRECTION.direction||'forward';p.querySelector('#tng-route-start-select').value=TNG_ROUTE_DIRECTION.start||'gpx_start';p.querySelectorAll('select').forEach(s=>s.addEventListener('change',()=>{syncHidden();renderPreview();renderEnds();}));p.querySelector('.tng-route-direction-apply').onclick=applyOrder;p.querySelector('.tng-route-direction-flip').onclick=()=>{const s=p.querySelector('#tng-route-start-select');s.value=s.value==='gpx_start'?'gpx_end':'gpx_start';syncHidden();renderPreview();renderEnds();};syncHidden();return p;};
 const orderedMetrics=()=>rows().map((row,i)=>({row,i,m:metric(coord(row))})).filter(x=>x.m);
 function applyOrder(){if(!route.length)return;const wrap=rowsWrap();if(!wrap)return;const good=orderedMetrics(),bad=rows().filter(r=>!metric(coord(r)));const dir=direction(),st=start();let ascending=st==='gpx_start';if(dir==='reverse')ascending=!ascending;good.sort((a,b)=>ascending?a.m.mi-b.m.mi:b.m.mi-a.m.mi);good.forEach(x=>wrap.appendChild(x.row));bad.forEach(r=>wrap.appendChild(r));renumber();forceRebuild();setTimeout(()=>{renderPreview();renderEnds();},80);}
 const renderPreview=()=>{const p=ensurePanel();if(!p)return;const d=direction(),s=start(),ms=orderedMetrics();const state=p.querySelector('.tng-route-direction__state'),preview=p.querySelector('.tng-route-direction__preview');state.textContent=d==='reverse'?'Reverse':d==='out_back'?'Out & Back':'Forward';const startLabel=s==='gpx_start'?'GPX start':'GPX end';let text=d==='out_back'?`Start at ${startLabel}, travel to the opposite end, then return toward the starting end.`:`Start at ${startLabel} and play checkpoints ${d==='reverse'?'against':'with'} the selected GPX direction.`;if(ms.length){const vals=ms.map(x=>x.m.mi);text+=` Current checkpoints span ${Math.min(...vals).toFixed(1)}–${Math.max(...vals).toFixed(1)} mi along the route.`;}preview.textContent=text;};
 const renderEnds=()=>{const map=window.TNG_ADMIN_ROUTE_MAP||null;if(!map||!route.length||typeof L==='undefined')return;if(startMarker)map.removeLayer(startMarker);if(endMarker)map.removeLayer(endMarker);const a=route[0],b=route[route.length-1],startIsA=start()==='gpx_start';const icon=(label,kind)=>L.divIcon({className:`tng-route-end-marker ${kind}`,html:label,iconSize:[26,26],iconAnchor:[13,13]});startMarker=L.marker(startIsA?a:b,{icon:icon('S','is-start'),interactive:false,zIndexOffset:700}).addTo(map);endMarker=L.marker(startIsA?b:a,{icon:icon('F','is-finish'),interactive:false,zIndexOffset:690}).addTo(map);};
 const load=()=>{const url=(typeof TNG_ADMIN_ROUTE_EDITOR!=='undefined'&&TNG_ADMIN_ROUTE_EDITOR.routeUrl)||'';if(!url)return false;fetch(url,{credentials:'same-origin'}).then(r=>r.ok?r.text():Promise.reject()).then(text=>{const xml=new DOMParser().parseFromString(text,'application/xml');route=[...xml.querySelectorAll('trkpt,rtept')].map(n=>[parseFloat(n.getAttribute('lat')),parseFloat(n.getAttribute('lon'))]).filter(valid);cumulative();ensurePanel();renderPreview();let tries=0,t=setInterval(()=>{if(window.TNG_ADMIN_ROUTE_MAP||++tries>30){clearInterval(t);renderEnds();}},120);rowsWrap()?.addEventListener('change',()=>setTimeout(renderPreview,25));document.addEventListener('tng:checkpoint-form-changed',()=>setTimeout(renderPreview,25));}).catch(()=>{});return true;};
 let tries=0,t=setInterval(()=>{if(load()||++tries>50)clearInterval(t)},120);load();
})();
JS
        ,'after');
    }
}
TNG_Route_Direction::boot();
