<?php
/**
 * TN Game Trail Explorer Unifier
 * Combines the route map, Top Sights, and interactive elevation profile into one continuous trail explorer panel.
 */
if (!defined('ABSPATH')) exit;

final class TNG_Trail_Explorer_Unifier {
    public static function boot(): void {
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue'], 116);
    }

    private static function is_trail(): bool {
        return class_exists('TNG_Trail_UI') && TNG_Trail_UI::is_trail();
    }

    public static function enqueue(): void {
        if (!self::is_trail()) return;

        wp_add_inline_style('tng-trail-ui', '
            .tng-trail-explorer-panel{overflow:hidden}
            .tng-trail-explorer-elevation{margin-top:20px;padding-top:20px;border-top:1px solid #e4ebe6}
            .tng-trail-explorer-elevation__head{display:flex;justify-content:space-between;align-items:flex-end;gap:18px;margin-bottom:14px}
            .tng-trail-explorer-elevation__head h3{margin:3px 0 0;color:#173b2a;font-size:24px;line-height:1.08}
            .tng-trail-explorer-elevation__head p{margin:0;max-width:330px;color:#718078;font-size:12px;line-height:1.4;text-align:right}
            .tng-trail-explorer-elevation .tng-trail-elevation{margin:0}
            .tng-trail-explorer-elevation .tng-trail-elevation.is-interactive{border-radius:16px}
            .tng-trail-elevation-source-panel{display:none!important}
            @media(max-width:760px){
                .tng-trail-explorer-elevation__head{align-items:flex-start;flex-direction:column}
                .tng-trail-explorer-elevation__head p{text-align:left}
            }
        ');

        wp_add_inline_script('tng-trail-leaflet', <<<'JS'
(()=>{
    const moveElevation=()=>{
        if(document.querySelector('.tng-trail-explorer-elevation')) return true;

        const mapEl=document.getElementById('tng-trail-live-map');
        if(!mapEl) return false;
        const mapPanel=mapEl.closest('.tng-trail-panel');
        if(!mapPanel) return false;

        const elevation=document.querySelector('.tng-trail-elevation');
        if(!elevation) return false;
        const sourcePanel=elevation.closest('.tng-trail-panel');
        if(!sourcePanel || sourcePanel===mapPanel) return false;

        const section=document.createElement('section');
        section.className='tng-trail-explorer-elevation';
        section.innerHTML=`
            <div class="tng-trail-explorer-elevation__head">
                <div>
                    <span class="tng-eyebrow">Elevation</span>
                    <h3>Elevation profile</h3>
                </div>
                <p>Move across the profile to follow your position along the route above.</p>
            </div>`;

        section.appendChild(elevation);
        mapPanel.appendChild(section);
        mapPanel.classList.add('tng-trail-explorer-panel');
        sourcePanel.classList.add('tng-trail-elevation-source-panel');

        // The chart may have initialized before being moved. A resize keeps its
        // map-linked interactions accurate after the layout changes.
        const map=window.TNG_TRAIL_LEAFLET_MAP;
        if(map && typeof map.invalidateSize==='function'){
            setTimeout(()=>map.invalidateSize(),120);
            setTimeout(()=>map.invalidateSize(),420);
        }
        return true;
    };

    let tries=0;
    const timer=setInterval(()=>{
        if(moveElevation() || ++tries>45) clearInterval(timer);
    },140);
    moveElevation();
})();
JS
        , 'after');
    }
}
TNG_Trail_Explorer_Unifier::boot();
