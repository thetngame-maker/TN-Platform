(function(){
'use strict';
var cfg=window.TNGTripSmoothDev||{};if(!cfg.enabled)return;
var sim='live',timer=null,booted=false;
function body(){return document.body;}
function root(){return document.getElementById('tng-trip-mode-v1');}
function esc(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c];});}
function img(stop,klass){return stop&&stop.image?'<img class="'+klass+'" src="'+esc(stop.image)+'" alt="">':'<div class="'+klass+' tng-trip-mode__ph">TN</div>';}
function ensureShell(){
    var r=root();
    if(!r){
        r=document.createElement('section');r.id='tng-trip-mode-v1';r.className='tng-trip-mode tng-trip-mode--fast-shell';
        var main=document.querySelector('main')||document.querySelector('.container')||document.body;
        try{main.insertBefore(r,main.firstChild);}catch(e){document.body.appendChild(r);}
    }
    if(r.children&&r.children.length)return r;
    var state=cfg.initialState||null,stops=Array.isArray(cfg.initialStops)?cfg.initialStops:[];
    if(!state){
        r.innerHTML='<div class="tng-trip-mode__empty"><small>ACTIVE TRIP</small><h1>Trip mode</h1><p>Loading your trip…</p></div>';
        return r;
    }
    var route=Array.isArray(state.route)?state.route:[],completed=Array.isArray(state.completed)?state.completed:[],skipped=Array.isArray(state.skipped)?state.skipped:[];
    var done=completed.length+skipped.length,total=route.length,pct=total?Math.round(done/total*100):0;
    if(!total){
        r.innerHTML='<div class="tng-trip-mode__empty"><small>ACTIVE TRIP</small><h1>No trip started yet</h1><p>Add stops in Trip Builder, then start Trip Mode.</p></div>';
        return r;
    }
    var cur=stops[0]||null,nxt=stops[1]||null;
    if(!cur){
        r.innerHTML='<div class="tng-trip-mode__empty"><small>ACTIVE TRIP</small><h1>Trip mode</h1><p>Preparing your next stop…</p></div>';
        return r;
    }
    var html='<div class="tng-trip-mode__hero"><div><small>ACTIVE TRIP</small><h1>Trip mode</h1><p>Stay focused on the stop in front of you. TN Game keeps the rest of the day moving.</p></div><div class="tng-trip-mode__progress"><b>'+done+' / '+total+'</b><span>stops handled</span><i><em style="width:'+pct+'%"></em></i></div></div>';
    html+='<div class="tng-trip-mode__grid"><article class="tng-trip-mode__current">'+img(cur,'tng-trip-mode__current-img')+'<div class="tng-trip-mode__current-body"><small>CURRENT STOP</small><h2>'+esc(cur.title)+'</h2><p>'+esc(cur.category||'TN Game stop')+(cur.address?' · '+esc(cur.address):'')+'</p><div class="tng-trip-mode__eta"><strong>—</strong><span>Loading live ETA…</span></div><div class="tng-trip-mode__actions"><a class="tng-trip-mode__primary" href="'+esc(cur.url||'#')+'">View stop</a><button type="button" disabled>Loading route…</button></div></div></article>';
    html+='<aside class="tng-trip-mode__side"><div class="tng-trip-mode__stat"><span>Progress</span><strong>'+completed.length+' complete</strong><small>'+skipped.length+' skipped</small></div>'+(nxt?'<div class="tng-trip-mode__next"><small>UP NEXT</small>'+img(nxt,'tng-trip-mode__next-img')+'<strong>'+esc(nxt.title)+'</strong><span>'+esc(nxt.category||'Next stop')+'</span></div>':'')+'</aside></div>';
    r.innerHTML=html;
    return r;
}
function markHydrated(){if(root())body().classList.add('tng-trip-mode-hydrated');}
function arrival(){return document.querySelector('#tng-trip-mode-v1 .tng-trip-mode__arrival');}
function toast(msg){var old=document.querySelector('.tng-trip-dev-toast');if(old)old.remove();var t=document.createElement('div');t.className='tng-trip-dev-toast';t.textContent=msg;document.body.appendChild(t);setTimeout(function(){if(t.parentNode)t.remove();},2400);}
function statusFor(key){if(key==='enroute')return {cls:'is-enroute',label:'On the way',detail:'Developer preview · 4.2 mi from this stop'};if(key==='approaching')return {cls:'is-approaching',label:'Approaching stop',detail:'Developer preview · 0.3 mi away'};if(key==='arrived')return {cls:'is-arrived',label:"You've arrived",detail:'Developer preview · arrival state'};return null;}
function paint(){markHydrated();if(!cfg.isAdmin||sim==='live')return;var p=arrival();if(!p)return;var s=statusFor(sim);if(!s)return;p.className='tng-trip-mode__arrival '+s.cls;var strong=p.querySelector('strong'),copy=p.querySelector('p');if(strong)strong.textContent=s.label;if(copy)copy.textContent=s.detail;var complete=document.querySelector('#tng-trip-mode-v1 .tng-trip-mode__complete');if(complete){complete.classList.toggle('is-arrived',sim==='arrived');complete.textContent=sim==='arrived'?'Complete stop ✓':'Complete stop';}}
function setSim(next){sim=next;body().classList.toggle('tng-trip-dev-simulating',sim!=='live');document.querySelectorAll('.tng-trip-dev button[data-state]').forEach(function(b){b.classList.toggle('is-active',b.getAttribute('data-state')===sim);});if(sim==='live'){toast('Developer simulation off · using live GPS');return;}paint();toast('Simulating: '+statusFor(sim).label);}
function panel(){if(!cfg.isAdmin||document.querySelector('.tng-trip-dev'))return;var el=document.createElement('aside');el.className='tng-trip-dev';el.innerHTML='<div class="tng-trip-dev__head"><strong>'+String(cfg.label||'Trip Mode Developer')+'</strong><span class="tng-trip-dev__badge">ADMIN ONLY</span></div><p class="tng-trip-dev__copy">Preview arrival states without changing the real trip, GPS position, visit history, or XP.</p><div class="tng-trip-dev__buttons"><button type="button" data-state="live" class="is-active">Live GPS</button><button type="button" data-state="enroute">On the way</button><button type="button" data-state="approaching">Approaching</button><button type="button" data-state="arrived">Arrived</button></div><small class="tng-trip-dev__note">Simulation is visual only. Real check-ins still require server-side proximity.</small>';document.body.appendChild(el);el.addEventListener('click',function(e){var b=e.target.closest('button[data-state]');if(b)setSim(b.getAttribute('data-state'));});}
function intercept(e){if(!cfg.isAdmin||sim==='live')return;var b=e.target.closest('.tng-trip-mode__arrival-checkin,.tng-trip-checkin__submit');if(!b)return;e.preventDefault();e.stopPropagation();e.stopImmediatePropagation();toast('Developer preview only · no real check-in or XP was recorded.');}
function boot(){if(booted)return;booted=true;ensureShell();markHydrated();panel();document.addEventListener('click',intercept,true);var obs=new MutationObserver(function(){markHydrated();if(sim!=='live'){clearTimeout(timer);timer=setTimeout(paint,20);}});obs.observe(document.documentElement,{childList:true,subtree:true,characterData:true,attributes:true,attributeFilter:['class']});setInterval(function(){markHydrated();if(sim!=='live')paint();},700);}
function earlyBoot(){if(document.body){boot();return;}setTimeout(earlyBoot,10);}earlyBoot();
})();
