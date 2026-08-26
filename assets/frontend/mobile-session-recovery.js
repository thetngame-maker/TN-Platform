(()=>{
'use strict';

const root=document.querySelector('.tng-runtime');
const runtime=window.TNGQuestRuntime||{};
const cfg=window.TNGMobileRecovery||{};
if(!root||!runtime.questId||!runtime.storageKey)return;

const questId=String(runtime.questId);
const KEY=`tngMobileSession:v1:${questId}`;
const maxAge=Math.max(300,Number(cfg.maxAge||86400))*1000;
const backgroundThreshold=Math.max(10,Number(cfg.backgroundThreshold||30))*1000;
const heartbeat=Math.max(5,Number(cfg.heartbeat||15))*1000;
const labels=cfg.labels||{};
let hiddenAt=0;
let recovered=false;
let panel=null;

const safeParse=(value,fallback={})=>{try{return JSON.parse(value||'')||fallback;}catch(e){return fallback;}};
const questState=()=>safeParse(localStorage.getItem(runtime.storageKey),{});
const locationActive=()=>Boolean(root.querySelector('.tng-location-active,.tng-player-marker,.tng-live-location.is-active'))||/location active/i.test(root.textContent||'');
const snapshot=()=>{
  const state=questState();
  return {
    questId,
    updatedAt:Date.now(),
    started:Boolean(state.started||root.classList.contains('is-started')),
    completedStops:Array.isArray(state.completedStops)?Array.from(new Set(state.completedStops.map(String))):[],
    status:String(state.status||''),
    locationWasActive:locationActive(),
    scrollY:Math.max(0,Math.round(window.scrollY||0)),
    url:window.location.href,
    hiddenAt:hiddenAt||0
  };
};
const save=()=>{try{localStorage.setItem(KEY,JSON.stringify(snapshot()));}catch(e){}};
const load=()=>safeParse(localStorage.getItem(KEY),{});
const clear=()=>{try{localStorage.removeItem(KEY);}catch(e){}};
const notify=(detail)=>{
  if(typeof window.TNGNotify==='function')window.TNGNotify(detail);
  else window.dispatchEvent(new CustomEvent('tng:notify',{detail}));
};

const closePanel=()=>{if(!panel)return;panel.classList.remove('is-visible');setTimeout(()=>panel?.remove(),220);panel=null;};
const resumeGps=()=>{
  const button=root.querySelector('[data-locate],[data-use-location],.tng-use-location,.tng-location-button');
  if(button){button.click();notify({type:'info',title:labels.gpsTitle||'Resume live location',message:'Requesting your current location…'});}
  closePanel();
};
const showRecoveryPanel=()=>{
  if(panel||locationActive())return;
  panel=document.createElement('aside');
  panel.className='tng-session-recovery';
  panel.setAttribute('role','status');
  panel.innerHTML=`<div><strong>${labels.gpsTitle||'Resume live location'}</strong><span>${labels.gpsMessage||'Location updates paused while this quest was in the background.'}</span></div><div class="tng-session-recovery__actions"><button type="button" data-resume-gps>${labels.resumeGps||'Resume GPS'}</button><button type="button" data-dismiss-recovery>${labels.dismiss||'Not now'}</button></div>`;
  document.body.appendChild(panel);
  panel.querySelector('[data-resume-gps]')?.addEventListener('click',resumeGps);
  panel.querySelector('[data-dismiss-recovery]')?.addEventListener('click',closePanel);
  requestAnimationFrame(()=>panel?.classList.add('is-visible'));
};

const restore=(reason='return')=>{
  const session=load();
  if(!session.started||String(session.questId)!==questId)return;
  const age=Date.now()-Number(session.updatedAt||0);
  if(age<0||age>maxAge){clear();return;}

  const state=questState();
  const savedStops=Array.isArray(session.completedStops)?session.completedStops.map(String):[];
  const currentStops=Array.isArray(state.completedStops)?state.completedStops.map(String):[];
  const merged=Array.from(new Set([...currentStops,...savedStops]));
  if(merged.length!==currentStops.length||(!state.started&&session.started)){
    try{
      localStorage.setItem(runtime.storageKey,JSON.stringify({...state,started:true,completedStops:merged,status:session.status||state.status||'in_progress'}));
    }catch(e){}
    window.dispatchEvent(new CustomEvent('tng:session-restored',{detail:{questId,completedStops:merged,reason}}));
  }

  if(!recovered){
    recovered=true;
    notify({type:'success',title:labels.restoredTitle||'Journey restored',message:labels.restoredMessage||'Your quest progress is safe and ready to continue.',duration:3500});
  }
  if(session.locationWasActive&&!locationActive())showRecoveryPanel();
  save();
};

const onVisible=()=>{
  if(document.visibilityState!=='visible')return;
  const elapsed=hiddenAt?Date.now()-hiddenAt:0;
  restore(elapsed>=backgroundThreshold?'background':'visible');
  hiddenAt=0;
};

document.addEventListener('visibilitychange',()=>{
  if(document.visibilityState==='hidden'){
    hiddenAt=Date.now();
    save();
  }else onVisible();
});
window.addEventListener('pagehide',()=>{hiddenAt=Date.now();save();});
window.addEventListener('pageshow',(event)=>restore(event.persisted?'back-forward-cache':'page-show'));
window.addEventListener('online',()=>restore('online'));
window.addEventListener('beforeunload',save);
document.addEventListener('click',(event)=>{
  if(event.target.closest('[data-claim],.tng-checkpoint-claim,[data-detail-claim],[data-locate],[data-use-location],.tng-use-location,.tng-location-button'))setTimeout(save,500);
});

const observer=new MutationObserver(()=>save());
observer.observe(root,{subtree:true,attributes:true,attributeFilter:['class']});
setInterval(save,heartbeat);

const prior=load();
if(prior.started&&Date.now()-Number(prior.updatedAt||0)<=maxAge)restore('initial-load');
else save();
})();
