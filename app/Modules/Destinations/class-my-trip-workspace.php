<?php
namespace TNG_OS\Modules\Destinations;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class My_Trip_Workspace implements Module_Interface {
    private const PAGE = 'tng-my-trip-workspace';

    public function id(): string { return 'my_trip_workspace'; }

    public function register(Container $container): void {
        $container->set('my_trip_workspace', $this);
        add_action('admin_menu', [$this, 'menu'], 31);
        add_shortcode('tng_my_trip', [$this, 'shortcode']);
        add_action('wp_footer', [$this, 'footer'], 70);
    }

    public function boot(Container $container): void {}

    public function menu(): void {
        add_submenu_page('tn-game-os', 'My Trip Workspace', 'My Trip', 'manage_options', self::PAGE, [$this, 'page']);
    }

    public function page(): void {
        if (!current_user_can('manage_options')) wp_die('Unauthorized.');
        echo '<div class="wrap"><h1>My Trip Workspace</h1><p>The visitor workspace is stored on each visitor’s device. Add <code>[tng_my_trip]</code> to a page such as <strong>My Trip</strong> for a full-page itinerary editor.</p>';
        echo '<p><strong>Included:</strong> add from Explore Nearby, import Smart Day Planner plans, reorder stops, remove stops, clear the trip, and share the itinerary.</p>';
        echo '<h2>Recommended setup</h2><ol><li>Create a WordPress page named <strong>My Trip</strong>.</li><li>Add <code>[tng_my_trip]</code> to the page.</li><li>Add that page to the main or mobile menu.</li></ol></div>';
    }

    public function shortcode(array $atts = []): string {
        $atts = shortcode_atts(['title' => 'My Trip'], $atts, 'tng_my_trip');
        return '<div class="tng-my-trip-full" data-tng-trip-full><div class="tng-mt-full-head"><div><div class="tng-mt-kicker">Destination AI</div><h2>'.esc_html($atts['title']).'</h2><p>Build, reorder, save, and share your South Cumberland itinerary.</p></div></div><div data-tng-trip-list></div><div data-tng-trip-empty class="tng-mt-empty">Your trip is empty. Add places from Explore Nearby or save a Smart Day Planner itinerary.</div><div class="tng-mt-full-actions"><button type="button" data-tng-trip-share>Share trip</button><button type="button" data-tng-trip-clear>Clear trip</button></div></div>';
    }

    public function footer(): void {
        if (is_admin()) return;
        ?>
        <button type="button" class="tng-mt-launch" data-tng-trip-open aria-label="Open My Trip"><span>🗺️</span><strong>My Trip</strong><em data-tng-trip-count>0</em></button>
        <aside class="tng-mt-drawer" data-tng-trip-drawer hidden aria-label="My Trip workspace">
            <div class="tng-mt-backdrop" data-tng-trip-close></div>
            <section class="tng-mt-panel"><header><div><div class="tng-mt-kicker">Destination AI</div><h2>My Trip</h2></div><button type="button" data-tng-trip-close aria-label="Close">×</button></header><div class="tng-mt-summary"><strong data-tng-trip-count>0</strong> stops <span>·</span> <strong data-tng-trip-time>0 min</strong></div><div data-tng-trip-list></div><div data-tng-trip-empty class="tng-mt-empty">Add places from Explore Nearby or save a Day Planner itinerary.</div><footer><button type="button" data-tng-trip-share>Share</button><button type="button" data-tng-trip-clear>Clear</button></footer></section>
        </aside>
        <style>
        .tng-mt-launch{position:fixed;right:18px;bottom:18px;z-index:99970;border:0;border-radius:999px;background:#17213f;color:#fff;box-shadow:0 12px 30px rgba(23,33,63,.28);padding:12px 15px;display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:800}.tng-mt-launch em{font-style:normal;background:#7c4ce0;border-radius:999px;min-width:23px;height:23px;display:inline-flex;align-items:center;justify-content:center;font-size:12px}.tng-mt-drawer{position:fixed;inset:0;z-index:99990}.tng-mt-backdrop{position:absolute;inset:0;background:rgba(15,23,42,.56)}.tng-mt-panel{position:absolute;right:0;top:0;bottom:0;width:min(440px,94vw);background:#fff;box-shadow:-15px 0 45px rgba(15,23,42,.22);padding:22px;overflow:auto}.tng-mt-panel header{display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #e6e8ee;padding-bottom:15px}.tng-mt-panel header h2{margin:3px 0 0;font-size:30px;color:#17213f}.tng-mt-panel header button{border:0;background:#f0ebff;color:#6336ae;border-radius:50%;width:40px;height:40px;font-size:26px;cursor:pointer}.tng-mt-kicker{color:#7040c5;font-weight:800;font-size:11px;letter-spacing:.16em;text-transform:uppercase}.tng-mt-summary{padding:15px 0;color:#667085}.tng-mt-summary strong{color:#17213f}.tng-mt-item{display:grid;grid-template-columns:56px 1fr auto;gap:12px;align-items:center;border-top:1px solid #eceef2;padding:13px 0}.tng-mt-item img,.tng-mt-thumb{width:56px;height:56px;border-radius:12px;object-fit:cover;background:linear-gradient(135deg,#17213f,#70429a)}.tng-mt-item h3{font-size:16px;line-height:1.2;margin:0 0 4px}.tng-mt-item h3 a{color:#17213f;text-decoration:none}.tng-mt-meta{font-size:12px;color:#667085}.tng-mt-controls{display:grid;grid-template-columns:repeat(2,30px);gap:5px}.tng-mt-controls button{border:1px solid #ddd7ec;background:#faf9ff;border-radius:8px;height:30px;cursor:pointer;color:#6336ae}.tng-mt-remove{grid-column:1/-1!important;color:#b42318!important}.tng-mt-empty{padding:28px 10px;text-align:center;color:#667085;background:#faf9ff;border:1px dashed #d9d2e7;border-radius:14px}.tng-mt-panel footer,.tng-mt-full-actions{display:flex;gap:10px;margin-top:18px;padding-top:16px;border-top:1px solid #e6e8ee}.tng-mt-panel footer button,.tng-mt-full-actions button{border:0;border-radius:10px;padding:11px 15px;background:#17213f;color:#fff;font-weight:800;cursor:pointer}.tng-mt-panel footer button:last-child,.tng-mt-full-actions button:last-child{background:#f3f0fa;color:#6336ae}.tng-my-trip-full{max-width:1000px;margin:35px auto;padding:26px;background:#fff;border:1px solid #e2dcef;border-radius:24px;box-shadow:0 15px 38px rgba(23,33,63,.08)}.tng-mt-full-head h2{font-size:clamp(32px,5vw,48px);margin:5px 0;color:#17213f}.tng-mt-full-head p{color:#667085}.tng-my-trip-full .tng-mt-item{grid-template-columns:74px 1fr auto}.tng-my-trip-full .tng-mt-item img,.tng-my-trip-full .tng-mt-thumb{width:74px;height:74px}.tng-en-add-trip{display:inline-flex;align-items:center;justify-content:center;border:1px solid #7c4ce0;background:#fff;color:#7040c5;border-radius:11px;padding:9px 12px;font-weight:800;font-size:12px;cursor:pointer;margin-right:7px}.tng-en-add-trip.is-added{background:#e9fbf4;border-color:#17a673;color:#087a45}@media(max-width:600px){.tng-mt-launch{right:12px;bottom:12px}.tng-mt-launch strong{display:none}.tng-my-trip-full{margin:20px 12px;padding:18px}.tng-my-trip-full .tng-mt-item{grid-template-columns:58px 1fr auto}.tng-my-trip-full .tng-mt-item img,.tng-my-trip-full .tng-mt-thumb{width:58px;height:58px}}
        </style>
        <script>
        (function(){
            const KEY='tng_my_trip_v1';
            const read=()=>{try{const v=JSON.parse(localStorage.getItem(KEY)||'[]');return Array.isArray(v)?v:[]}catch(e){return[]}};
            const write=(items)=>{try{localStorage.setItem(KEY,JSON.stringify(items));window.dispatchEvent(new CustomEvent('tng:trip-updated',{detail:{count:items.length}}));}catch(e){}};
            const normalize=(s)=>({id:Number(s.id||0),title:String(s.title||'Untitled stop'),url:String(s.url||'#'),image:String(s.image||''),minutes:Number(s.minutes||60),detail:String(s.detail||'Destination stop')});
            function migrate(){if(read().length)return;try{const p=JSON.parse(localStorage.getItem('tng_saved_day_plan')||'null');if(p&&Array.isArray(p.stops)){write(p.stops.map(normalize));}}catch(e){}}
            function add(stop){let items=read(),n=normalize(stop);if(!items.some(x=>(n.id&&Number(x.id)===n.id)||x.url===n.url)){items.push(n);write(items);}render();}
            function duration(mins){return mins>=60?((Math.round(mins/6)/10)+' hr'):(mins+' min')}
            function renderList(root){const list=root.querySelector('[data-tng-trip-list]'),empty=root.querySelector('[data-tng-trip-empty]');if(!list)return;const items=read();list.innerHTML='';empty.hidden=items.length>0;items.forEach((s,i)=>{const row=document.createElement('article');row.className='tng-mt-item';row.innerHTML=(s.image?'<img src="'+escapeHtml(s.image)+'" alt="">':'<div class="tng-mt-thumb"></div>')+'<div><h3><a href="'+escapeHtml(s.url)+'">'+escapeHtml(s.title)+'</a></h3><div class="tng-mt-meta">'+escapeHtml(s.detail)+' · '+duration(Number(s.minutes||60))+'</div></div><div class="tng-mt-controls"><button data-up aria-label="Move up">↑</button><button data-down aria-label="Move down">↓</button><button class="tng-mt-remove" data-remove aria-label="Remove">Remove</button></div>';row.querySelector('[data-up]').onclick=()=>move(i,-1);row.querySelector('[data-down]').onclick=()=>move(i,1);row.querySelector('[data-remove]').onclick=()=>remove(i);list.appendChild(row);});}
            function render(){const items=read(),mins=items.reduce((a,s)=>a+Number(s.minutes||60),0);document.querySelectorAll('[data-tng-trip-count]').forEach(e=>e.textContent=String(items.length));document.querySelectorAll('[data-tng-trip-time]').forEach(e=>e.textContent=duration(mins));document.querySelectorAll('[data-tng-trip-drawer],[data-tng-trip-full]').forEach(renderList);decorate();}
            function move(i,d){let a=read(),j=i+d;if(j<0||j>=a.length)return;[a[i],a[j]]=[a[j],a[i]];write(a);render();}
            function remove(i){let a=read();a.splice(i,1);write(a);render();}
            function clearTrip(){if(confirm('Clear every stop from My Trip?')){write([]);render();}}
            async function share(){const a=read();if(!a.length)return;const text='My TN Game trip:\n'+a.map((s,i)=>(i+1)+'. '+s.title+' — '+s.url).join('\n');try{if(navigator.share)await navigator.share({title:'My TN Game Trip',text});else{await navigator.clipboard.writeText(text);alert('Trip copied to clipboard.');}}catch(e){}}
            function escapeHtml(v){const d=document.createElement('div');d.textContent=String(v||'');return d.innerHTML;}
            function decorate(){document.querySelectorAll('.tng-en-card').forEach(card=>{if(card.querySelector('.tng-en-add-trip'))return;const link=card.querySelector('h3 a'),image=card.querySelector('img'),reason=card.querySelector('.tng-en-reason'),footer=card.querySelector('.tng-en-footer');if(!link||!footer)return;const b=document.createElement('button');b.type='button';b.className='tng-en-add-trip';b.textContent='Add to My Trip';const exists=read().some(x=>x.url===link.href);if(exists){b.classList.add('is-added');b.textContent='Added';}b.onclick=()=>{add({title:link.textContent.trim(),url:link.href,image:image?image.src:'',minutes:60,detail:reason?reason.textContent.trim():'Explore Nearby'});b.classList.add('is-added');b.textContent='Added';};footer.insertBefore(b,footer.querySelector('.tng-en-link'));});}
            document.querySelectorAll('[data-tng-trip-open]').forEach(b=>b.onclick=()=>{const d=document.querySelector('[data-tng-trip-drawer]');if(d){d.hidden=false;document.body.style.overflow='hidden';render();}});document.querySelectorAll('[data-tng-trip-close]').forEach(b=>b.onclick=()=>{const d=document.querySelector('[data-tng-trip-drawer]');if(d){d.hidden=true;document.body.style.overflow='';}});document.querySelectorAll('[data-tng-trip-clear]').forEach(b=>b.onclick=clearTrip);document.querySelectorAll('[data-tng-trip-share]').forEach(b=>b.onclick=share);
            window.addEventListener('tng:day-plan-saved',()=>{try{const p=JSON.parse(localStorage.getItem('tng_saved_day_plan')||'null');if(p&&Array.isArray(p.stops)){let items=read();p.stops.map(normalize).forEach(s=>{if(!items.some(x=>(s.id&&Number(x.id)===s.id)||x.url===s.url))items.push(s)});write(items);render();}}catch(e){}});
            window.TNGTrip={add,get:read,clear:()=>{write([]);render()},open:()=>document.querySelector('[data-tng-trip-open]')?.click()};
            migrate();render();new MutationObserver(decorate).observe(document.body,{childList:true,subtree:true});
        })();
        </script>
        <?php
    }
}
