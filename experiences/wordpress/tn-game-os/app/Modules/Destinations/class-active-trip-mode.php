<?php
namespace TNG_OS\Modules\Destinations;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class Active_Trip_Mode implements Module_Interface {
    public function id(): string { return 'active_trip_mode'; }

    public function register(Container $container): void {
        $container->set('active_trip_mode', $this);
        add_action('wp_footer', [$this, 'footer'], 130);
    }

    public function boot(Container $container): void {}

    public function footer(): void {
        if (is_admin()) return;
        ?>
        <style>
        .tng-atm{position:fixed;left:50%;bottom:18px;transform:translateX(-50%);z-index:999997;width:min(720px,calc(100% - 24px));background:#17213f;color:#fff;border-radius:18px;box-shadow:0 18px 55px rgba(15,23,42,.32);padding:12px 14px;display:none;align-items:center;gap:12px}.tng-atm.is-visible{display:flex}.tng-atm-copy{min-width:0;flex:1}.tng-atm-kicker{font-size:10px;letter-spacing:.14em;text-transform:uppercase;color:#c9b8ff;font-weight:900}.tng-atm-title{font-size:15px;font-weight:900;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:2px}.tng-atm-meta{font-size:11px;color:#cbd3e6;margin-top:2px}.tng-atm-progress{width:120px;height:7px;border-radius:99px;background:rgba(255,255,255,.16);overflow:hidden}.tng-atm-progress span{display:block;height:100%;background:linear-gradient(90deg,#9c6cff,#2fd282);width:0}.tng-atm button,.tng-atm a{border:0;border-radius:10px;padding:10px 12px;font-weight:900;font-size:12px;cursor:pointer;text-decoration:none}.tng-atm-start,.tng-atm-next{background:#fff;color:#17213f}.tng-atm-open{background:#7c4ce0;color:#fff}.tng-atm-panel{position:fixed;inset:0;z-index:999998;background:rgba(15,23,42,.58);display:none;align-items:center;justify-content:center;padding:18px}.tng-atm-panel.is-open{display:flex}.tng-atm-card{width:min(560px,100%);max-height:88vh;overflow:auto;background:#f8f9fc;border-radius:22px;box-shadow:0 24px 80px rgba(15,23,42,.35)}.tng-atm-head{padding:24px;background:linear-gradient(135deg,#20294d,#73429b);color:#fff;position:relative}.tng-atm-head h2{margin:4px 48px 0 0;color:#fff}.tng-atm-close{position:absolute;right:18px;top:18px;width:40px;height:40px;padding:0!important;border-radius:50%!important;background:#fff!important;color:#17213f!important;font-size:22px!important}.tng-atm-body{padding:18px}.tng-atm-stop{background:#fff;border:1px solid #e0e5ef;border-radius:14px;padding:14px;margin-bottom:10px;display:flex;gap:12px;align-items:center}.tng-atm-stop.is-current{border:2px solid #8b5cf6;background:#f7f2ff}.tng-atm-stop.is-complete{background:#ecfdf3;border-color:#86efac}.tng-atm-num{width:34px;height:34px;border-radius:50%;display:grid;place-items:center;background:#eee7ff;color:#6b3fc0;font-weight:900;flex:none}.tng-atm-stop.is-complete .tng-atm-num{background:#14b86e;color:#fff}.tng-atm-stop-copy{flex:1;min-width:0}.tng-atm-stop-title{font-weight:900;color:#17213f}.tng-atm-stop-meta{font-size:11px;color:#667085;margin-top:3px}.tng-atm-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}.tng-atm-actions button{background:#e9edf5;color:#17213f}.tng-atm-actions .primary{background:#7c4ce0;color:#fff}.tng-atm-actions .danger{background:#fff1f2;color:#b42318}.tng-atm-empty{text-align:center;padding:24px;color:#667085}@media(max-width:620px){.tng-atm-progress{display:none}.tng-atm{bottom:10px}.tng-atm-open{display:none}.tng-atm button{padding:9px 10px}}
        </style>
        <div class="tng-atm" data-tng-active-bar>
            <div class="tng-atm-copy"><div class="tng-atm-kicker">Live trip</div><div class="tng-atm-title">Ready to begin</div><div class="tng-atm-meta"></div></div>
            <div class="tng-atm-progress"><span></span></div>
            <button type="button" class="tng-atm-start">Start trip</button>
            <button type="button" class="tng-atm-open">Trip mode</button>
        </div>
        <div class="tng-atm-panel" data-tng-active-panel>
            <div class="tng-atm-card">
                <div class="tng-atm-head"><div class="tng-atm-kicker">Destination AI</div><h2>Active trip</h2><button type="button" class="tng-atm-close">×</button></div>
                <div class="tng-atm-body"><div class="tng-atm-list"></div><div class="tng-atm-actions"></div></div>
            </div>
        </div>
        <script>
        (function(){
          const TRIP='tng_my_trip_v1', STATE='tng_active_trip_v1';
          const bar=document.querySelector('[data-tng-active-bar]'),panel=document.querySelector('[data-tng-active-panel]');
          if(!bar||!panel)return;
          const readTrip=()=>{try{const x=JSON.parse(localStorage.getItem(TRIP)||'[]');return Array.isArray(x)?x:[]}catch(e){return[]}};
          const readState=()=>{try{return Object.assign({active:false,index:0,completed:[],started:0},JSON.parse(localStorage.getItem(STATE)||'{}'))}catch(e){return{active:false,index:0,completed:[],started:0}}};
          const saveState=s=>localStorage.setItem(STATE,JSON.stringify(s));
          const clean=s=>{const trip=readTrip(),ids=trip.map(x=>Number(x.id));s.completed=(s.completed||[]).map(Number).filter(id=>ids.includes(id));if(s.index>=trip.length)s.index=Math.max(0,trip.length-1);if(!trip.length)s.active=false;return s};
          const current=()=>{const t=readTrip(),s=clean(readState());return {trip:t,state:s,stop:t[s.index]||null}};
          const directions=stop=>{if(!stop)return;const q=stop.lat&&stop.lng?stop.lat+','+stop.lng:(stop.title||'');const open=origin=>{let u='https://www.google.com/maps/dir/?api=1&travelmode=driving&destination='+encodeURIComponent(q);if(origin)u+='&origin='+encodeURIComponent(origin);window.open(u,'_blank','noopener');};if(navigator.geolocation)navigator.geolocation.getCurrentPosition(p=>open(p.coords.latitude+','+p.coords.longitude),()=>open(''),{timeout:5000,maximumAge:60000});else open('');};
          function render(){const {trip,state,stop}=current();bar.classList.toggle('is-visible',trip.length>0);if(!trip.length)return;const done=state.completed.length,pct=Math.round(done/trip.length*100);bar.querySelector('.tng-atm-progress span').style.width=pct+'%';bar.querySelector('.tng-atm-start').textContent=state.active?'Continue':'Start trip';bar.querySelector('.tng-atm-title').textContent=state.active&&stop?(state.index+1)+'. '+stop.title:'Your trip is ready';bar.querySelector('.tng-atm-meta').textContent=state.active?done+' of '+trip.length+' stops complete':trip.length+' stops ready';
            const list=panel.querySelector('.tng-atm-list');list.innerHTML=trip.map((x,i)=>{const complete=state.completed.includes(Number(x.id));return '<div class="tng-atm-stop '+(i===state.index&&state.active?'is-current ':'')+(complete?'is-complete':'')+'"><div class="tng-atm-num">'+(complete?'✓':i+1)+'</div><div class="tng-atm-stop-copy"><div class="tng-atm-stop-title">'+escapeHtml(x.title||'Trip stop')+'</div><div class="tng-atm-stop-meta">'+(complete?'Completed':i===state.index&&state.active?'Current stop':(x.detail||Math.round((x.minutes||60)/60*10)/10+' hr'))+'</div></div></div>'}).join('');
            const a=panel.querySelector('.tng-atm-actions');if(!state.active){a.innerHTML='<button class="primary" data-atm-start>Start trip</button><button data-atm-close>Not yet</button>';}else if(done>=trip.length){a.innerHTML='<button class="primary" data-atm-finish>Finish trip</button><button data-atm-directions>Directions</button>';}else{a.innerHTML='<button class="primary" data-atm-directions>Directions to current stop</button><button data-atm-complete>Mark stop complete</button><button data-atm-skip>Skip for now</button><button class="danger" data-atm-end>End trip</button>';}
            bind();
          }
          function start(){let s=clean(readState());s.active=true;s.started=s.started||Date.now();const trip=readTrip();let next=trip.findIndex(x=>!s.completed.includes(Number(x.id)));s.index=next<0?0:next;saveState(s);open();}
          function complete(){const {trip,state,stop}=current();if(!stop)return;const id=Number(stop.id);if(!state.completed.includes(id))state.completed.push(id);let next=trip.findIndex((x,i)=>i>state.index&&!state.completed.includes(Number(x.id)));if(next<0)next=trip.findIndex(x=>!state.completed.includes(Number(x.id)));if(next>=0)state.index=next;saveState(state);render();}
          function skip(){const {trip,state}=current();if(!trip.length)return;state.index=(state.index+1)%trip.length;saveState(state);render();}
          function finish(){localStorage.removeItem(STATE);close();render();window.dispatchEvent(new CustomEvent('tng:trip-completed'));}
          function end(){if(confirm('End this active trip? Your saved My Trip stops will remain.')){localStorage.removeItem(STATE);close();render();}}
          function open(){panel.classList.add('is-open');render();}
          function close(){panel.classList.remove('is-open');}
          function bind(){panel.querySelectorAll('[data-atm-start]').forEach(b=>b.onclick=start);panel.querySelectorAll('[data-atm-close]').forEach(b=>b.onclick=close);panel.querySelectorAll('[data-atm-directions]').forEach(b=>b.onclick=()=>directions(current().stop));panel.querySelectorAll('[data-atm-complete]').forEach(b=>b.onclick=complete);panel.querySelectorAll('[data-atm-skip]').forEach(b=>b.onclick=skip);panel.querySelectorAll('[data-atm-finish]').forEach(b=>b.onclick=finish);panel.querySelectorAll('[data-atm-end]').forEach(b=>b.onclick=end);}
          function escapeHtml(s){const d=document.createElement('div');d.textContent=String(s);return d.innerHTML;}
          bar.querySelector('.tng-atm-start').onclick=()=>readState().active?open():start();bar.querySelector('.tng-atm-open').onclick=open;panel.querySelector('.tng-atm-close').onclick=close;panel.onclick=e=>{if(e.target===panel)close();};window.addEventListener('tng:trip-updated',render);window.addEventListener('storage',render);render();
        })();
        </script>
        <?php
    }
}
