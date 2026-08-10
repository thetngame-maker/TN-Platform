<?php
/**
 * TN Game Trail Top Sight Cards
 * Renders the same Top Sights used by the trail map as rich cards beneath the elevation profile.
 */
if (!defined('ABSPATH')) exit;

final class TNG_Trail_Top_Sight_Cards {
    public static function boot(): void {
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue'], 114);
    }

    private static function is_trail(): bool {
        return class_exists('TNG_Trail_UI') && TNG_Trail_UI::is_trail();
    }

    public static function enqueue(): void {
        if (!self::is_trail()) return;

        wp_add_inline_style('tng-trail-ui', '
            .tng-trail-sights-panel{margin-top:0}
            .tng-trail-sights-head{display:flex;justify-content:space-between;align-items:flex-end;gap:18px;margin-bottom:18px}
            .tng-trail-sights-head h2{margin:4px 0 0}.tng-trail-sights-head p{margin:0;max-width:360px;color:#718078;font-size:13px;line-height:1.45;text-align:right}
            .tng-trail-sights-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
            .tng-trail-sight-card{display:grid;grid-template-columns:118px 1fr;min-height:132px;border:1px solid #dce6df;border-radius:16px;background:#fff;overflow:hidden;text-decoration:none!important;color:#173b2a!important;transition:transform .18s ease,box-shadow .18s ease,border-color .18s ease}
            .tng-trail-sight-card:hover{transform:translateY(-2px);box-shadow:0 10px 28px rgba(25,62,43,.10);border-color:#f0b08f}
            .tng-trail-sight-card__media{position:relative;min-height:132px;background:linear-gradient(135deg,#174b35,#0f3827);overflow:hidden}
            .tng-trail-sight-card__media img{width:100%;height:100%;object-fit:cover;display:block}
            .tng-trail-sight-card__pin{position:absolute;left:10px;top:10px;width:30px;height:30px;border-radius:10px;background:#f16022;border:2px solid #fff;display:flex;align-items:center;justify-content:center;box-shadow:0 3px 10px rgba(0,0,0,.18);font-size:14px}
            .tng-trail-sight-card.is-visited .tng-trail-sight-card__pin{background:#17613f}
            .tng-trail-sight-card__body{display:flex;flex-direction:column;padding:14px 15px 13px;min-width:0}
            .tng-trail-sight-card__eyebrow{display:flex;align-items:center;gap:7px;flex-wrap:wrap;margin-bottom:4px;font-size:9px;font-weight:900;text-transform:uppercase;letter-spacing:.08em;color:#e85d24}
            .tng-trail-sight-card__visited{padding:3px 6px;border-radius:999px;background:#eaf3ed;color:#17613f}
            .tng-trail-sight-card h3{margin:0 0 5px;font-size:18px;line-height:1.12;color:#173b2a}
            .tng-trail-sight-card__excerpt{margin:0;color:#728078;font-size:11px;line-height:1.4;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
            .tng-trail-sight-card__meta{display:flex;gap:8px;flex-wrap:wrap;margin-top:auto;padding-top:9px}
            .tng-trail-sight-card__meta span{display:inline-flex;align-items:center;padding:4px 7px;border-radius:999px;background:#f4f7f4;color:#52675b;font-size:10px;font-weight:800}
            .tng-trail-sight-card__meta .xp{background:#fff0e8;color:#c94d19}
            @media(max-width:760px){.tng-trail-sights-head{align-items:flex-start;flex-direction:column}.tng-trail-sights-head p{text-align:left}.tng-trail-sights-grid{grid-template-columns:1fr}}
            @media(max-width:460px){.tng-trail-sight-card{grid-template-columns:92px 1fr}.tng-trail-sight-card__media{min-height:126px}.tng-trail-sight-card h3{font-size:16px}}
        ');

        wp_add_inline_script('tng-trail-leaflet', <<<'JS'
(()=>{
    const R=3958.7613,rad=d=>d*Math.PI/180;
    const hav=(a,b)=>{const dLat=rad(b.lat-a.lat),dLng=rad(b.lng-a.lng);const q=Math.sin(dLat/2)**2+Math.cos(rad(a.lat))*Math.cos(rad(b.lat))*Math.sin(dLng/2)**2;return 2*R*Math.asin(Math.sqrt(q));};
    const esc=s=>String(s||'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
    const parseRoute=text=>{
        const xml=new DOMParser().parseFromString(text,'application/xml');
        let nodes=[...xml.querySelectorAll('trkpt')];if(!nodes.length)nodes=[...xml.querySelectorAll('rtept')];
        const out=[];let dist=0;
        nodes.forEach(n=>{const lat=parseFloat(n.getAttribute('lat')),lng=parseFloat(n.getAttribute('lon'));if(!Number.isFinite(lat)||!Number.isFinite(lng))return;const p={lat,lng,dist};if(out.length)dist+=hav(out[out.length-1],p);p.dist=dist;out.push(p)});return out;
    };
    const along=(s,route)=>{if(!route.length)return null;let best=Infinity,dist=0;route.forEach(p=>{const d=hav({lat:+s.lat,lng:+s.lng},p);if(d<best){best=d;dist=p.dist}});return dist;};
    const render=async()=>{
        if(typeof TNG_TRAIL_TOP_SIGHTS==='undefined'||!Array.isArray(TNG_TRAIL_TOP_SIGHTS.items)||!TNG_TRAIL_TOP_SIGHTS.items.length)return false;
        if(document.querySelector('.tng-trail-sights-panel'))return true;
        const elevation=document.querySelector('.tng-trail-elevation');if(!elevation)return false;
        const panel=elevation.closest('.tng-trail-panel');if(!panel)return false;
        let route=[];
        try{if(typeof TNG_TRAIL_MAP!=='undefined'&&TNG_TRAIL_MAP.routeUrl){const r=await fetch(TNG_TRAIL_MAP.routeUrl,{credentials:'same-origin'});if(r.ok)route=parseRoute(await r.text())}}catch(e){}
        const items=TNG_TRAIL_TOP_SIGHTS.items.map(s=>({...s,routeMi:along(s,route)})).sort((a,b)=>{if(a.routeMi===null&&b.routeMi===null)return 0;if(a.routeMi===null)return 1;if(b.routeMi===null)return-1;return a.routeMi-b.routeMi});
        const cards=items.map(s=>{
            const visited=!!s.visited;
            const image=s.image?`<img src="${esc(s.image)}" alt="" loading="lazy">`:'';
            const distance=Number.isFinite(s.routeMi)?`<span>↔ ${s.routeMi<0.1?s.routeMi.toFixed(2):s.routeMi.toFixed(1)} mi along route</span>`:'';
            const xp=Number(s.xp)>0?`<span class="xp">⭐ ${Math.round(Number(s.xp))} XP</span>`:'';
            return `<a class="tng-trail-sight-card${visited?' is-visited':''}" href="${esc(s.url||'#')}">
                <div class="tng-trail-sight-card__media">${image}<span class="tng-trail-sight-card__pin">📍</span></div>
                <div class="tng-trail-sight-card__body">
                    <div class="tng-trail-sight-card__eyebrow">Top Sight ${visited?'<span class="tng-trail-sight-card__visited">✓ Visited</span>':''}</div>
                    <h3>${esc(s.title)}</h3>
                    ${s.excerpt?`<p class="tng-trail-sight-card__excerpt">${esc(s.excerpt)}</p>`:''}
                    <div class="tng-trail-sight-card__meta">${distance}${xp}</div>
                </div>
            </a>`;
        }).join('');
        const section=document.createElement('section');section.className='tng-trail-panel tng-trail-sights-panel';section.innerHTML=`<div class="tng-trail-sights-head"><div><span class="tng-eyebrow">Along the route</span><h2>Top Sights on this trail</h2></div><p>Discover the memorable places connected to this hike. Visited sights stay saved to your Explorer profile.</p></div><div class="tng-trail-sights-grid">${cards}</div>`;
        panel.insertAdjacentElement('afterend',section);
        return true;
    };
    let tries=0,t=setInterval(async()=>{if(await render()||++tries>40)clearInterval(t)},150);render();
})();
JS
        , 'after');
    }
}
TNG_Trail_Top_Sight_Cards::boot();
