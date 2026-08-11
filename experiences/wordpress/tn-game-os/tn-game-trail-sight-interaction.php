<?php
/**
 * TN Game Trail Sight Interaction
 * Connects Top Sight cards and trail-map pins into a single two-way explorer experience.
 */
if (!defined('ABSPATH')) exit;

final class TNG_Trail_Sight_Interaction {
    public static function boot(): void {
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue'], 115);
    }

    private static function is_trail(): bool {
        return class_exists('TNG_Trail_UI') && TNG_Trail_UI::is_trail();
    }

    public static function enqueue(): void {
        if (!self::is_trail()) return;

        wp_add_inline_style('tng-trail-ui', '
            .tng-trail-sight-card{cursor:pointer;position:relative}
            .tng-trail-sight-card.is-map-active{border-color:#f16022!important;box-shadow:0 0 0 3px rgba(241,96,34,.12),0 12px 30px rgba(24,62,43,.12)!important;transform:translateY(-2px)}
            .tng-trail-sight-card__actions{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-top:9px;padding-top:9px;border-top:1px solid #edf1ee}
            .tng-trail-sight-card__map-action{appearance:none;border:0;background:transparent;padding:0;color:#e85d24;font-size:10px;font-weight:900;cursor:pointer}
            .tng-trail-sight-card__details{color:#173b2a!important;font-size:10px;font-weight:900;text-decoration:none!important}
            .tng-trail-sight-card__details:hover{color:#e85d24!important}
            .tng-trail-sight-marker.is-card-active{box-shadow:0 0 0 6px rgba(241,96,34,.20),0 7px 20px rgba(0,0,0,.30)!important;transform:scale(1.12);transform-origin:center;transition:transform .16s ease,box-shadow .16s ease}
            .tng-trail-sight-marker.is-visited.is-card-active{box-shadow:0 0 0 6px rgba(23,97,63,.20),0 7px 20px rgba(0,0,0,.30)!important}
            @media(max-width:640px){.tng-trail-sight-card__actions{align-items:flex-start;flex-direction:column}}
        ');

        wp_add_inline_script('tng-trail-leaflet', <<<'JS'
(()=>{
    const norm=s=>String(s||'').trim().toLowerCase().replace(/\s+/g,' ');
    const sleep=ms=>new Promise(r=>setTimeout(r,ms));
    const distSq=(a,b)=>Math.pow(Number(a.lat)-Number(b.lat),2)+Math.pow(Number(a.lng)-Number(b.lng),2);

    const items=()=>typeof TNG_TRAIL_TOP_SIGHTS!=='undefined'&&Array.isArray(TNG_TRAIL_TOP_SIGHTS.items)?TNG_TRAIL_TOP_SIGHTS.items:[];
    const map=()=>window.TNG_TRAIL_LEAFLET_MAP||null;

    const cardTitle=card=>norm(card.querySelector('h3')?.textContent||'');
    const findItemForCard=card=>{
        const title=cardTitle(card);
        return items().find(s=>norm(s.title)===title)||null;
    };
    const cards=()=>[...document.querySelectorAll('.tng-trail-sight-card')];
    const findCardForItem=item=>cards().find(c=>cardTitle(c)===norm(item.title))||null;

    const sightMarkers=()=>{
        const m=map(); if(!m) return [];
        const out=[];
        m.eachLayer(layer=>{
            if(typeof layer.getLatLng!=='function') return;
            const el=typeof layer.getElement==='function'?layer.getElement():null;
            if(!el||!el.classList||!el.classList.contains('tng-trail-sight-marker')) return;
            out.push(layer);
        });
        return out;
    };

    const findMarker=item=>{
        let best=null,bestD=Infinity;
        sightMarkers().forEach(marker=>{
            const ll=marker.getLatLng();
            const d=distSq({lat:item.lat,lng:item.lng},{lat:ll.lat,lng:ll.lng});
            if(d<bestD){bestD=d;best=marker;}
        });
        return bestD<0.000001?best:null;
    };

    const clearActive=()=>{
        cards().forEach(c=>c.classList.remove('is-map-active'));
        sightMarkers().forEach(marker=>marker.getElement()?.classList.remove('is-card-active'));
    };

    const markActive=(item,card,marker)=>{
        clearActive();
        card?.classList.add('is-map-active');
        marker?.getElement()?.classList.add('is-card-active');
    };

    const focusMap=(item,card,{scroll=true}={})=>{
        const m=map(); if(!m||!item) return;
        const marker=findMarker(item);
        markActive(item,card,marker);
        const zoom=Math.max(Number(m.getZoom?.()||0),15);
        m.setView([Number(item.lat),Number(item.lng)],zoom,{animate:true});
        if(marker&&typeof marker.openPopup==='function') marker.openPopup();
        if(scroll){
            const mapEl=document.getElementById('tng-trail-live-map');
            if(mapEl) mapEl.scrollIntoView({behavior:'smooth',block:'center'});
            setTimeout(()=>m.invalidateSize?.(),420);
        }
    };

    const focusCard=(item,marker)=>{
        const card=findCardForItem(item); if(!card) return;
        markActive(item,card,marker);
        card.scrollIntoView({behavior:'smooth',block:'center'});
    };

    const upgradeCards=()=>{
        cards().forEach(card=>{
            if(card.dataset.mapLinked==='1') return;
            const item=findItemForCard(card); if(!item) return;
            card.dataset.mapLinked='1';
            card.dataset.sightId=String(item.id||'');

            // Existing cards are anchors. Convert them to semantic interactive cards so
            // tapping the card can focus the map while View details remains a real link.
            if(card.tagName==='A'){
                const href=card.getAttribute('href')||item.url||'#';
                const article=document.createElement('article');
                article.className=card.className;
                article.dataset.mapLinked='1';
                article.dataset.sightId=String(item.id||'');
                article.tabIndex=0;
                article.innerHTML=card.innerHTML;
                const body=article.querySelector('.tng-trail-sight-card__body');
                if(body){
                    const actions=document.createElement('div');
                    actions.className='tng-trail-sight-card__actions';
                    actions.innerHTML=`<button type="button" class="tng-trail-sight-card__map-action">📍 Show on map</button><a class="tng-trail-sight-card__details" href="${href.replace(/"/g,'&quot;')}">View details →</a>`;
                    body.appendChild(actions);
                }
                card.replaceWith(article);
                card=article;
            }

            const activate=e=>{
                if(e.target.closest('a')) return;
                e.preventDefault();
                focusMap(item,card,{scroll:true});
            };
            card.addEventListener('click',activate);
            card.addEventListener('keydown',e=>{if(e.key==='Enter'||e.key===' '){activate(e);}});
            card.addEventListener('mouseenter',()=>focusMap(item,card,{scroll:false}));
            card.addEventListener('focusin',()=>focusMap(item,card,{scroll:false}));
        });
    };

    const bindMarkers=()=>{
        items().forEach(item=>{
            const marker=findMarker(item); if(!marker||marker.__tngCardBound) return;
            marker.__tngCardBound=1;
            marker.on('click',()=>{
                // Let Leaflet open its popup first, then connect the matching card.
                setTimeout(()=>focusCard(item,marker),120);
            });
        });
    };

    const boot=async()=>{
        if(!items().length) return false;
        for(let i=0;i<45;i++){
            upgradeCards();
            bindMarkers();
            if(cards().length&&sightMarkers().length) return true;
            await sleep(140);
        }
        return false;
    };
    boot();
})();
JS
        , 'after');
    }
}
TNG_Trail_Sight_Interaction::boot();
