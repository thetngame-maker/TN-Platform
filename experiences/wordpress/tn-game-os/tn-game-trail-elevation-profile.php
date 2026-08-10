<?php
/**
 * TN Game Trail Elevation Profile
 * Replaces the decorative trail chart with the real GPX elevation profile and syncs it to the Leaflet route map.
 */
if (!defined('ABSPATH')) exit;

final class TNG_Trail_Elevation_Profile {
    public static function boot(): void {
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue'], 113);
    }

    private static function is_trail(): bool {
        return class_exists('TNG_Trail_UI') && TNG_Trail_UI::is_trail();
    }

    public static function enqueue(): void {
        if (!self::is_trail()) return;

        wp_add_inline_style('tng-trail-ui', '
            .tng-trail-elevation.is-interactive{position:relative;padding:18px 18px 12px;background:#eef5ef;border-radius:18px;overflow:hidden;touch-action:pan-y}
            .tng-elev-chart{position:relative;height:230px;cursor:crosshair;user-select:none;-webkit-user-select:none}
            .tng-elev-chart svg{display:block;width:100%;height:100%;overflow:visible}
            .tng-elev-area{fill:rgba(241,96,34,.18)}
            .tng-elev-line{fill:none;stroke:#f16022;stroke-width:4;stroke-linecap:round;stroke-linejoin:round;vector-effect:non-scaling-stroke}
            .tng-elev-guide{stroke:#153e2a;stroke-width:1.5;stroke-dasharray:4 4;opacity:.55;vector-effect:non-scaling-stroke}
            .tng-elev-dot{fill:#f16022;stroke:#fff;stroke-width:3;vector-effect:non-scaling-stroke}
            .tng-elev-readout{position:absolute;top:10px;left:10px;display:flex;gap:8px;align-items:center;z-index:3;pointer-events:none}
            .tng-elev-chip{display:flex;flex-direction:column;min-width:92px;padding:8px 11px;border-radius:12px;background:rgba(255,255,255,.94);box-shadow:0 5px 18px rgba(20,55,38,.10)}
            .tng-elev-chip strong{font-size:15px;color:#173b2a;line-height:1.05}.tng-elev-chip small{margin-top:3px;font-size:9px;text-transform:uppercase;letter-spacing:.08em;color:#718078;font-weight:800}
            .tng-elev-axis{display:flex!important;justify-content:space-between!important;margin-top:7px!important;color:#66776d!important;font-size:11px!important;font-weight:700!important}
            .tng-elev-hint{margin-top:8px;color:#75837b;font-size:11px;text-align:center}
            .tng-elev-map-marker{width:18px!important;height:18px!important;border-radius:50%;background:#f16022;border:4px solid #fff;box-shadow:0 4px 15px rgba(0,0,0,.28)}
            .tng-elev-loading{display:flex;height:210px;align-items:center;justify-content:center;color:#6f7e75;font-size:13px}
            @media(max-width:640px){.tng-elev-chart{height:190px}.tng-elev-readout{top:7px;left:7px}.tng-elev-chip{min-width:78px;padding:7px 9px}.tng-elev-chip strong{font-size:13px}}
        ');

        wp_add_inline_script('tng-trail-leaflet', <<<'JS'
(()=>{
    const EARTH_MI = 3958.7613;
    const metersToFeet = m => m * 3.280839895;
    const kmToMi = km => km * 0.6213711922;
    const rad = d => d * Math.PI / 180;
    const haversineMi = (a,b) => {
        const dLat=rad(b.lat-a.lat), dLon=rad(b.lng-a.lng);
        const x=Math.sin(dLat/2)**2 + Math.cos(rad(a.lat))*Math.cos(rad(b.lat))*Math.sin(dLon/2)**2;
        return 2*EARTH_MI*Math.asin(Math.sqrt(x));
    };
    const fmtMi = n => (n < 0.1 ? n.toFixed(2) : n.toFixed(1)) + ' mi';
    const fmtFt = n => Math.round(n).toLocaleString() + ' ft';

    const parse = text => {
        const xml = new DOMParser().parseFromString(text,'application/xml');
        let nodes=[...xml.querySelectorAll('trkpt')];
        if(!nodes.length) nodes=[...xml.querySelectorAll('rtept')];
        const points=[];
        let dist=0;
        nodes.forEach((n,i)=>{
            const lat=parseFloat(n.getAttribute('lat')), lng=parseFloat(n.getAttribute('lon'));
            const eleNode=n.querySelector('ele');
            const ele=eleNode ? parseFloat(eleNode.textContent) : NaN;
            if(!Number.isFinite(lat)||!Number.isFinite(lng)||!Number.isFinite(ele)) return;
            const p={lat,lng,eleFt:metersToFeet(ele),distMi:0};
            if(points.length) dist += haversineMi(points[points.length-1],p);
            p.distMi=dist;
            points.push(p);
        });
        return points;
    };

    const decimate = (pts,max=450) => {
        if(pts.length<=max) return pts.map((p,i)=>({...p,sourceIndex:i}));
        const out=[], step=(pts.length-1)/(max-1);
        for(let i=0;i<max;i++){
            const idx=Math.min(pts.length-1,Math.round(i*step));
            out.push({...pts[idx],sourceIndex:idx});
        }
        return out;
    };

    const boot = async () => {
        const wrap=document.querySelector('.tng-trail-elevation');
        if(!wrap || wrap.dataset.gpxReady==='1' || typeof TNG_TRAIL_MAP==='undefined') return false;
        const route=TNG_TRAIL_MAP.routeUrl||'';
        if(!route) return false;
        wrap.dataset.gpxReady='1';
        wrap.classList.add('is-interactive');
        wrap.innerHTML='<div class="tng-elev-loading">Loading elevation profile…</div>';

        try{
            const res=await fetch(route,{credentials:'same-origin'});
            if(!res.ok) throw new Error('GPX request failed');
            const raw=await res.text();
            const all=parse(raw);
            if(all.length<2) throw new Error('No elevation samples');
            const pts=decimate(all);
            const min=Math.min(...pts.map(p=>p.eleFt)), max=Math.max(...pts.map(p=>p.eleFt));
            const range=Math.max(40,max-min), total=pts[pts.length-1].distMi || 1;
            const W=800,H=210,top=18,bottom=18,left=6,right=6;
            const x=p=>left+(p.distMi/total)*(W-left-right);
            const y=p=>top+((max-p.eleFt)/range)*(H-top-bottom);
            const line=pts.map((p,i)=>(i?'L':'M')+x(p).toFixed(2)+' '+y(p).toFixed(2)).join(' ');
            const area=line+' L '+x(pts[pts.length-1]).toFixed(2)+' '+(H-bottom)+' L '+x(pts[0]).toFixed(2)+' '+(H-bottom)+' Z';

            wrap.innerHTML=`
                <div class="tng-elev-chart" role="img" aria-label="Interactive trail elevation profile">
                    <div class="tng-elev-readout">
                        <span class="tng-elev-chip"><strong class="tng-elev-distance">${fmtMi(0)}</strong><small>Distance</small></span>
                        <span class="tng-elev-chip"><strong class="tng-elev-height">${fmtFt(pts[0].eleFt)}</strong><small>Elevation</small></span>
                    </div>
                    <svg viewBox="0 0 ${W} ${H}" preserveAspectRatio="none" aria-hidden="true">
                        <path class="tng-elev-area" d="${area}"></path>
                        <path class="tng-elev-line" d="${line}"></path>
                        <line class="tng-elev-guide" x1="${x(pts[0])}" x2="${x(pts[0])}" y1="${top}" y2="${H-bottom}"></line>
                        <circle class="tng-elev-dot" cx="${x(pts[0])}" cy="${y(pts[0])}" r="7"></circle>
                    </svg>
                </div>
                <div class="tng-elev-axis"><span>Start</span><span>${fmtMi(total/2)}</span><span>${fmtMi(total)}</span></div>
                <div class="tng-elev-hint">Move across the profile to follow the route on the map.</div>`;

            const chart=wrap.querySelector('.tng-elev-chart');
            const guide=wrap.querySelector('.tng-elev-guide');
            const dot=wrap.querySelector('.tng-elev-dot');
            const distEl=wrap.querySelector('.tng-elev-distance');
            const eleEl=wrap.querySelector('.tng-elev-height');
            let mapMarker=null;

            const setIndex=i=>{
                i=Math.max(0,Math.min(pts.length-1,i));
                const p=pts[i], px=x(p), py=y(p);
                guide.setAttribute('x1',px); guide.setAttribute('x2',px);
                dot.setAttribute('cx',px); dot.setAttribute('cy',py);
                distEl.textContent=fmtMi(p.distMi); eleEl.textContent=fmtFt(p.eleFt);
                const map=window.TNG_TRAIL_LEAFLET_MAP;
                if(map && typeof L!=='undefined'){
                    if(!mapMarker){
                        const icon=L.divIcon({className:'tng-elev-map-marker',html:'',iconSize:[18,18],iconAnchor:[9,9]});
                        mapMarker=L.marker([p.lat,p.lng],{icon,interactive:false,zIndexOffset:900}).addTo(map);
                    } else mapMarker.setLatLng([p.lat,p.lng]);
                }
            };
            const move=e=>{
                const rect=chart.getBoundingClientRect();
                const clientX=e.touches&&e.touches[0]?e.touches[0].clientX:e.clientX;
                const ratio=Math.max(0,Math.min(1,(clientX-rect.left)/rect.width));
                setIndex(Math.round(ratio*(pts.length-1)));
            };
            chart.addEventListener('mousemove',move,{passive:true});
            chart.addEventListener('touchstart',move,{passive:true});
            chart.addEventListener('touchmove',move,{passive:true});
            chart.addEventListener('click',move);
            setIndex(0);
        }catch(err){
            wrap.dataset.gpxReady='0';
            wrap.classList.remove('is-interactive');
            return false;
        }
        return true;
    };

    if(!boot()){
        let n=0,t=setInterval(async()=>{if(await boot()||++n>35)clearInterval(t)},150);
    }
})();
JS
        , 'after');
    }
}
TNG_Trail_Elevation_Profile::boot();
