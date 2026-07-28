(()=>{
'use strict';

const root=document.querySelector('.tng-runtime');
const status=root?.querySelector('.tng-runtime-js-status');
const start=root?.querySelector('.tng-runtime-start');
const active=root?.querySelector('.tng-runtime-active');
const reset=root?.querySelector('.tng-runtime-reset');
const list=root?.querySelector('.tng-runtime-checkpoints');
const bar=root?.querySelector('.tng-runtime-progress span');
const completedNode=root?.querySelector('[data-completed]');
const config=window.TNGQuestRuntime||{};

const fail=(message)=>{
  if(status){status.textContent=message;status.classList.add('is-error');}
  if(start)start.disabled=true;
};

if(!root||!start||!active||!list||!config.storageKey){
  fail('Quest controls could not initialize. Reload the page or report this runtime error.');
  return;
}

const load=()=>{try{return JSON.parse(localStorage.getItem(config.storageKey)||'{}');}catch(error){return {};}};
const saveLocal=(state)=>{try{localStorage.setItem(config.storageKey,JSON.stringify(state));return true;}catch(error){return false;}};
const normalize=(state)=>({started:Boolean(state?.started),completedStops:Array.isArray(state?.completedStops)?state.completedStops.map(String):[],status:state?.status||'not_started',startedAt:state?.startedAt||''});
let state=normalize(load());
let saving=false;

const api=async(method,body)=>{
  const response=await fetch(config.progressUrl,{method,credentials:'same-origin',headers:{'Content-Type':'application/json','X-WP-Nonce':config.restNonce||''},body:body?JSON.stringify(body):undefined});
  if(!response.ok)throw new Error('Progress request failed');
  return response.json();
};

const persist=async()=>{
  saveLocal(state);
  if(!config.loggedIn||saving)return;
  saving=true;
  try{state=normalize(await api('POST',{started:state.started,completedStops:state.completedStops}));saveLocal(state);status.textContent='Progress synced to your TN Game account.';}
  catch(error){status.textContent='Progress is saved on this device, but account sync is unavailable.';status.classList.add('is-error');}
  finally{saving=false;render();}
};

const typeLabel=(type)=>({manual:'Manual checkpoint',gps:'GPS arrival',photo:'Photo challenge',trivia:'Trivia',qr:'QR code'}[type]||'Checkpoint');
const escapeHtml=(value)=>String(value).replace(/[&<>"']/g,(char)=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));

const render=()=>{
  const stops=Array.isArray(config.stops)?config.stops:[];
  const done=new Set(state.completedStops.map(String));
  const currentIndex=stops.findIndex((stop)=>!done.has(String(stop.id)));
  const started=Boolean(state.started);
  root.classList.toggle('is-started',started);
  active.hidden=!started;
  start.textContent=started?'Resume Quest':'Start Quest';
  if(!status.classList.contains('is-error'))status.textContent=started?(config.loggedIn?'Quest in progress.':'Quest progress saved on this device.'):'Quest controls are ready.';
  const count=done.size;
  if(completedNode)completedNode.textContent=String(count);
  if(bar)bar.style.width=(stops.length?Math.min(100,(count/stops.length)*100):0)+'%';
  list.innerHTML=stops.map((stop,index)=>{
    const id=String(stop.id),complete=done.has(id),current=index===currentIndex,locked=!complete&&!current;
    const action=current?`<button type="button" class="tng-checkpoint-claim" data-claim="${escapeHtml(id)}">Claim checkpoint</button>`:'';
    return `<article class="tng-checkpoint ${complete?'is-complete':''} ${current?'is-current':''} ${locked?'is-locked':''}"><div class="tng-checkpoint-number">${complete?'✓':index+1}</div><div class="tng-checkpoint-copy"><span class="tng-checkpoint-label">${escapeHtml(typeLabel(stop.type))} · ${Number(stop.xp||0)} XP</span><h3>${escapeHtml(stop.title||'Checkpoint')}</h3><p>${escapeHtml(stop.instruction||'Complete this checkpoint to continue.')}</p>${stop.hint?`<small><strong>Hint:</strong> ${escapeHtml(stop.hint)}</small>`:''}${action}</div><span class="tng-checkpoint-state">${complete?'Completed':current?'Next':'Locked'}</span></article>`;
  }).join('')||'<div class="tng-runtime-empty">No checkpoints are configured for this quest yet.</div>';
};

start.addEventListener('click',()=>{
  state={...state,started:true,status:'in_progress',startedAt:state.startedAt||new Date().toISOString()};
  if(!saveLocal(state)){fail('This browser blocked local quest storage. Private browsing restrictions may be active.');return;}
  persist();render();active.scrollIntoView({behavior:'smooth',block:'start'});
});

list.addEventListener('click',(event)=>{
  const button=event.target.closest('[data-claim]');
  if(!button)return;
  const id=String(button.dataset.claim||'');
  if(!id)return;
  state.completedStops=Array.from(new Set([...state.completedStops,id]));
  state.status=state.completedStops.length>=(config.stops||[]).length?'complete':'in_progress';
  persist();render();
});

reset?.addEventListener('click',()=>{
  state=normalize({started:false,completedStops:[],status:'not_started'});
  try{localStorage.removeItem(config.storageKey);}catch(error){}
  persist();render();root.scrollIntoView({behavior:'smooth',block:'start'});
});

const init=async()=>{
  if(config.loggedIn){try{state=normalize(await api('GET'));saveLocal(state);}catch(error){status.textContent='Account progress could not be loaded. Using device progress.';status.classList.add('is-error');}}
  render();
};

init();
})();
