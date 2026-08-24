(function(){
'use strict';
var cfg=window.TNGTripSmoothDev||{};if(!cfg.enabled)return;
var sim='live',timer=null;
function root(){return document.getElementById('tng-trip-mode-v1');}
function arrival(){return document.querySelector('#tng-trip-mode-v1 .tng-trip-mode__arrival');}
function toast(msg){var old=document.querySelector('.tng-trip-dev-toast');if(old)old.remove();var t=document.createElement('div');t.className='tng-trip-dev-toast';t.textContent=msg;document.body.appendChild(t);setTimeout(function(){if(t.parentNode)t.remove();},2400);}
function statusFor(key){
 if(key==='enroute')return {cls:'is-enroute',label:'On the way',detail:'Developer preview · 4.2 mi from this stop'};
 if(key==='approaching')return {cls:'is-approaching',label:'Approaching stop',detail:'Developer preview · 0.3 mi away'};
 if(key==='arrived')return {cls:'is-arrived',label:"You've arrived",detail:'Developer preview · arrival state'};
 return null;
}
function paint(){
 if(!cfg.isAdmin||sim==='live')return;
 var p=arrival();if(!p)return;
 var s=statusFor(sim);if(!s)return;
 p.className='tng-trip-mode__arrival '+s.cls;
 var strong=p.querySelector('strong'),copy=p.querySelector('p');
 if(strong)strong.textContent=s.label;
 if(copy)copy.textContent=s.detail;
 var complete=document.querySelector('#tng-trip-mode-v1 .tng-trip-mode__complete');
 if(complete){complete.classList.toggle('is-arrived',sim==='arrived');complete.textContent=sim==='arrived'?'Complete stop ✓':'Complete stop';}
}
function setSim(next){
 sim=next;
 document.body.classList.toggle('tng-trip-dev-simulating',sim!=='live');
 document.querySelectorAll('.tng-trip-dev button[data-state]').forEach(function(b){b.classList.toggle('is-active',b.getAttribute('data-state')===sim);});
 if(sim==='live'){toast('Developer simulation off · using live GPS');return;}
 paint();toast('Simulating: '+statusFor(sim).label);
}
function panel(){
 if(!cfg.isAdmin||document.querySelector('.tng-trip-dev'))return;
 var el=document.createElement('aside');el.className='tng-trip-dev';
 el.innerHTML='<div class="tng-trip-dev__head"><strong>'+String(cfg.label||'Trip Mode Developer')+'</strong><span class="tng-trip-dev__badge">ADMIN ONLY</span></div><p class="tng-trip-dev__copy">Preview arrival states without changing the real trip, GPS position, visit history, or XP.</p><div class="tng-trip-dev__buttons"><button type="button" data-state="live" class="is-active">Live GPS</button><button type="button" data-state="enroute">On the way</button><button type="button" data-state="approaching">Approaching</button><button type="button" data-state="arrived">Arrived</button></div><small class="tng-trip-dev__note">Simulation is visual only. Real check-ins still require server-side proximity.</small>';
 document.body.appendChild(el);
 el.addEventListener('click',function(e){var b=e.target.closest('button[data-state]');if(b)setSim(b.getAttribute('data-state'));});
}
function intercept(e){
 if(!cfg.isAdmin||sim==='live')return;
 var b=e.target.closest('.tng-trip-mode__arrival-checkin,.tng-trip-checkin__submit');if(!b)return;
 e.preventDefault();e.stopPropagation();e.stopImmediatePropagation();
 toast('Developer preview only · no real check-in or XP was recorded.');
}
function boot(){
 panel();document.addEventListener('click',intercept,true);
 var obs=new MutationObserver(function(){if(sim!=='live'){clearTimeout(timer);timer=setTimeout(paint,20);}});
 obs.observe(document.documentElement,{childList:true,subtree:true});
 if(root()&&sim!=='live')paint();
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',boot);else boot();
})();
