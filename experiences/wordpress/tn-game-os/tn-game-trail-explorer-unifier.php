<?php
/**
 * TN Game Trail Explorer Unifier
 * Combines route map, Top Sights, trail summary, and interactive elevation into one explorer panel.
 */
if (!defined('ABSPATH')) exit;

final class TNG_Trail_Explorer_Unifier {
    public static function boot(): void { add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue'], 116); }
    private static function is_trail(): bool { return class_exists('TNG_Trail_UI') && TNG_Trail_UI::is_trail(); }

    public static function enqueue(): void {
        if (!self::is_trail()) return;
        wp_add_inline_style('tng-trail-ui', '
            .tng-trail-explorer-panel{overflow:hidden}
            .tng-trail-explorer-summary{margin:18px -1px 0;padding:15px 0 0;border-top:1px solid #e4ebe6}
            .tng-trail-explorer-summary__bar{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:9px}
            .tng-trail-explorer-stat{display:flex;align-items:center;gap:10px;min-height:62px;padding:10px 12px;border:1px solid #e1e9e3;border-radius:14px;background:#f8faf8}
            .tng-trail-explorer-stat__icon{display:flex;flex:0 0 34px;width:34px;height:34px;align-items:center;justify-content:center;border-radius:11px;background:#e8f1eb;color:#17613f;font-size:15px;font-weight:900}
            .tng-trail-explorer-stat strong{display:block;color:#173b2a;font-size:15px;line-height:1.05}.tng-trail-explorer-stat small{display:block;margin-top:4px;color:#75837b;font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.06em}
            .tng-trail-explorer-stat--xp .tng-trail-explorer-stat__icon{background:#fff0e8;color:#e85d24}
            .tng-trail-sights-panel{order:2}
            .tng-trail-explorer-elevation{margin-top:20px;padding-top:20px;border-top:1px solid #e4ebe6;order:3}
            .tng-trail-explorer-elevation__head{display:flex;justify-content:space-between;align-items:flex-end;gap:18px;margin-bottom:14px}
            .tng-trail-explorer-elevation__head h3{margin:3px 0 0;color:#173b2a;font-size:24px;line-height:1.08}
            .tng-trail-explorer-elevation__head p{margin:0;max-width:330px;color:#718078;font-size:12px;line-height:1.4;text-align:right}
            .tng-trail-explorer-elevation .tng-trail-elevation{margin:0}.tng-trail-explorer-elevation .tng-trail-elevation.is-interactive{border-radius:16px}.tng-trail-elevation-source-panel{display:none!important}
            @media(max-width:760px){.tng-trail-explorer-summary__bar{grid-template-columns:repeat(2,minmax(0,1fr))}.tng-trail-explorer-elevation__head{align-items:flex-start;flex-direction:column}.tng-trail-explorer-elevation__head p{text-align:left}}
            @media(max-width:420px){.tng-trail-explorer-stat{padding:9px}.tng-trail-explorer-stat__icon{width:30px;height:30px;flex-basis:30px}.tng-trail-explorer-stat strong{font-size:13px}}
        ');

        wp_add_inline_script('tng-trail-leaflet', <<<'JS'
(()=>{
 const fmtMi=n=>(n<0.1?n.toFixed(2):n.toFixed(1))+' mi';
 const addSummary=(mapPanel)=>{
   if(mapPanel.querySelector('.tng-trail-explorer-summary')) return;
   const items=(typeof TNG_TRAIL_TOP_SIGHTS!=='undefined'&&Array.isArray(TNG_TRAIL_TOP_SIGHTS.items))?TNG_TRAIL_TOP_SIGHTS.items:[];
   const total=items.length,visited=items.filter(s=>!!s.visited).length,xp=items.reduce((n,s)=>n+(Number(s.xp)||0),0);
   let distance='Route';
   const distanceCard=[...document.querySelectorAll('.tng-trail-stat,.tng-stat-card')].find(el=>/distance/i.test(el.textContent||''));
   if(distanceCard){const strong=distanceCard.querySelector('strong,b');if(strong&&strong.textContent.trim())distance=strong.textContent.trim();}
   const summary=document.createElement('section');summary.className='tng-trail-explorer-summary';
   summary.innerHTML=`<div class="tng-trail-explorer-summary__bar">
    <div class="tng-trail-explorer-stat"><span class="tng-trail-explorer-stat__icon">↔</span><span><strong>${distance}</strong><small>Route distance</small></span></div>
    <div class="tng-trail-explorer-stat"><span class="tng-trail-explorer-stat__icon">📍</span><span><strong>${total}</strong><small>Top Sights</small></span></div>
    <div class="tng-trail-explorer-stat"><span class="tng-trail-explorer-stat__icon">✓</span><span><strong>${visited}/${total}</strong><small>Visited</small></span></div>
    <div class="tng-trail-explorer-stat tng-trail-explorer-stat--xp"><span class="tng-trail-explorer-stat__icon">★</span><span><strong>${xp} XP</strong><small>Available</small></span></div>
   </div>`;
   const sights=mapPanel.querySelector('.tng-trail-sights-panel');
   if(sights) mapPanel.insertBefore(summary,sights); else mapPanel.appendChild(summary);
 };
 const unify=()=>{
   const mapEl=document.getElementById('tng-trail-live-map');if(!mapEl)return false;
   const mapPanel=mapEl.closest('.tng-trail-panel');if(!mapPanel)return false;
   mapPanel.classList.add('tng-trail-explorer-panel');
   const sights=mapPanel.querySelector('.tng-trail-sights-panel');
   const elevation=document.querySelector('.tng-trail-elevation');
   if(!elevation)return false;
   let elevSection=mapPanel.querySelector('.tng-trail-explorer-elevation');
   if(!elevSection){
     const sourcePanel=elevation.closest('.tng-trail-panel');if(!sourcePanel||sourcePanel===mapPanel)return false;
     elevSection=document.createElement('section');elevSection.className='tng-trail-explorer-elevation';
     elevSection.innerHTML=`<div class="tng-trail-explorer-elevation__head"><div><span class="tng-eyebrow">Elevation</span><h3>Elevation profile</h3></div><p>Move across the profile to follow your position along the route above.</p></div>`;
     elevSection.appendChild(elevation);sourcePanel.classList.add('tng-trail-elevation-source-panel');
   }
   // Explicitly enforce the requested order: map -> summary -> Top Sights -> elevation.
   if(sights){
     if(elevSection.parentNode!==mapPanel)mapPanel.appendChild(elevSection);
     else mapPanel.appendChild(elevSection);
   } else if(elevSection.parentNode!==mapPanel) mapPanel.appendChild(elevSection);
   addSummary(mapPanel);
   const summary=mapPanel.querySelector('.tng-trail-explorer-summary');
   if(sights&&summary){mapPanel.insertBefore(summary,sights);mapPanel.insertBefore(sights,elevSection);}
   const map=window.TNG_TRAIL_LEAFLET_MAP;if(map&&typeof map.invalidateSize==='function'){setTimeout(()=>map.invalidateSize(),120);setTimeout(()=>map.invalidateSize(),420);}
   return !!sights;
 };
 let tries=0,t=setInterval(()=>{if(unify()||++tries>50)clearInterval(t)},140);unify();
})();
JS
        , 'after');
    }
}
TNG_Trail_Explorer_Unifier::boot();
