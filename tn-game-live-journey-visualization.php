<?php
/**
 * TN Game Live Journey Visualization
 * Adds role-aware visual cues to gameplay map markers and checkpoint cards.
 */
if (!defined('ABSPATH')) exit;

final class TNG_Live_Journey_Visualization {
    public static function boot(): void {
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue'], 98);
    }

    private static function is_gameplay(): bool {
        if (is_admin()) return false;
        $uri=(string)($_SERVER['REQUEST_URI']??'');
        return strpos($uri,'/game-play/')!==false || (function_exists('is_page') && is_page('game-play'));
    }

    private static function infer_role(array $cp): string {
        $role=sanitize_key((string)($cp['role']??''));
        if(in_array($role,['trail_start','top_sight','route','bonus','trail_finish'],true)) return $role;
        $title=strtolower(trim((string)($cp['title']??'')));
        if(preg_match('/trail start|start point|trailhead/',$title)) return 'trail_start';
        if(preg_match('/trail finish|finish point|route finish/',$title)) return 'trail_finish';
        if(!empty($cp['sight_id'])||!empty($cp['top_sight_id'])) return 'top_sight';
        return 'route';
    }

    public static function enqueue(): void {
        if(!self::is_gameplay()) return;
        $game_id=absint($_GET['game']??0); if(!$game_id) return;
        $cps=get_post_meta($game_id,'tng_game_checkpoints',true); if(!is_array($cps)||!$cps) return;
        $icons=['trail_start'=>'🥾','top_sight'=>'📍','route'=>'●','bonus'=>'⭐','trail_finish'=>'🏁'];
        $labels=['trail_start'=>'Trail Start','top_sight'=>'Top Sight','route'=>'Route','bonus'=>'Bonus','trail_finish'=>'Finish'];
        $data=[];
        foreach($cps as $i=>$cp){if(!is_array($cp))continue;$role=self::infer_role($cp);$data[]=['index'=>$i+1,'role'=>$role,'icon'=>$icons[$role]??'●','label'=>$labels[$role]??'Route'];}

        wp_register_style('tng-live-journey-visualization',false,[],TNG_OS_VERSION);wp_enqueue_style('tng-live-journey-visualization');
        wp_add_inline_style('tng-live-journey-visualization','
        .tng-journey-key{display:flex;flex-wrap:wrap;gap:7px;margin:10px 0 14px}.tng-journey-key__item{display:inline-flex;align-items:center;gap:5px;padding:5px 8px;border:1px solid #e2e8e3;border-radius:999px;background:#fff;color:#56655c;font-size:10px;font-weight:800}.tng-journey-key__icon{font-size:12px}
        .tng-game-runtime .leaflet-marker-icon.tng-journey-marker{overflow:visible!important}.tng-game-runtime .leaflet-marker-icon.tng-journey-marker::after{content:attr(data-tng-role-icon);position:absolute;right:-8px;top:-8px;display:grid;place-items:center;width:20px;height:20px;border:2px solid #fff;border-radius:999px;background:#173b2a;color:#fff;font-size:10px;line-height:1;box-shadow:0 4px 10px rgba(18,40,27,.18)}
        .tng-game-runtime .leaflet-marker-icon.tng-role-trail_start::after{background:#17613f}.tng-game-runtime .leaflet-marker-icon.tng-role-top_sight::after{background:#f26722}.tng-game-runtime .leaflet-marker-icon.tng-role-bonus::after{background:#d49b00}.tng-game-runtime .leaflet-marker-icon.tng-role-trail_finish::after{background:#0f2f22}.tng-game-runtime .leaflet-marker-icon.tng-role-route::after{background:#6e7b73}
        .tng-runtime-stop[data-checkpoint-role="trail_start"]{border-left:5px solid #17613f}.tng-runtime-stop[data-checkpoint-role="top_sight"]{border-left:5px solid #f26722}.tng-runtime-stop[data-checkpoint-role="bonus"]{border-left:5px solid #d49b00}.tng-runtime-stop[data-checkpoint-role="trail_finish"]{border-left:5px solid #0f2f22}.tng-runtime-stop[data-checkpoint-role="route"]{border-left:5px solid #95a39a}
        .tng-runtime-stop__journey-icon{display:inline-grid;place-items:center;width:22px;height:22px;margin-right:7px;border-radius:7px;background:#f3f6f3;font-size:12px;vertical-align:middle}.tng-runtime-stop__role{vertical-align:middle}
        @media(max-width:700px){.tng-journey-key{gap:5px}.tng-journey-key__item{padding:4px 7px;font-size:9px}.tng-game-runtime .leaflet-marker-icon.tng-journey-marker::after{width:18px;height:18px;right:-7px;top:-7px;font-size:9px}}
        ');
        wp_register_script('tng-live-journey-visualization','',[],TNG_OS_VERSION,true);wp_enqueue_script('tng-live-journey-visualization');
        wp_localize_script('tng-live-journey-visualization','TNG_LIVE_JOURNEY',$data);
        wp_add_inline_script('tng-live-journey-visualization', <<<'JS'
(()=>{
 const data=window.TNG_LIVE_JOURNEY||[];
 const byIndex=n=>data.find(x=>Number(x.index)===Number(n));
 const decorateStops=()=>{
   const stops=[...document.querySelectorAll('.tng-runtime-stop')];
   stops.forEach((stop,i)=>{
     const d=data[i];if(!d)return;
     stop.dataset.checkpointRole=d.role;
     const role=stop.querySelector('.tng-runtime-stop__role');
     if(role&&!role.querySelector('.tng-runtime-stop__journey-icon')){const icon=document.createElement('span');icon.className='tng-runtime-stop__journey-icon';icon.textContent=d.icon;role.prepend(icon);}
   });
   const list=document.querySelector('.tng-runtime-list');
   if(list&&!document.querySelector('.tng-journey-key')){
     const used=[];data.forEach(d=>{if(!used.some(x=>x.role===d.role))used.push(d);});
     const key=document.createElement('div');key.className='tng-journey-key';key.innerHTML=used.map(d=>`<span class="tng-journey-key__item"><span class="tng-journey-key__icon">${d.icon}</span>${d.label}</span>`).join('');
     list.insertAdjacentElement('beforebegin',key);
   }
 };
 const decorateMarkers=()=>{
   document.querySelectorAll('.tng-game-runtime .leaflet-marker-icon').forEach(marker=>{
     if(marker.classList.contains('tng-journey-marker'))return;
     const raw=(marker.textContent||'').trim();if(!/^\d+$/.test(raw))return;
     const d=byIndex(parseInt(raw,10));if(!d)return;
     marker.classList.add('tng-journey-marker','tng-role-'+d.role);marker.dataset.tngRoleIcon=d.icon;marker.title=`${d.label} · Checkpoint ${d.index}`;
   });
 };
 const apply=()=>{decorateStops();decorateMarkers();};
 const start=()=>{apply();new MutationObserver(()=>requestAnimationFrame(apply)).observe(document.body,{childList:true,subtree:true});};
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start);else start();
})();
JS
        ,'after');
    }
}
TNG_Live_Journey_Visualization::boot();
