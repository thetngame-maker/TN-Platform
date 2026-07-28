(()=>{
'use strict';

const root=document.querySelector('.tng-runtime');
const status=root?.querySelector('.tng-runtime-js-status');
const start=root?.querySelector('.tng-runtime-start');
const active=root?.querySelector('.tng-runtime-active');
const reset=root?.querySelector('.tng-runtime-reset');
const config=window.TNGQuestRuntime||{};

const fail=(message)=>{
  if(status){status.textContent=message;status.classList.add('is-error');}
  if(start)start.disabled=true;
};

if(!root||!start||!active||!config.storageKey){
  fail('Quest controls could not initialize. Reload the page or report this runtime error.');
  return;
}

const load=()=>{
  try{return JSON.parse(localStorage.getItem(config.storageKey)||'{}');}
  catch(error){return {};}
};
const save=(state)=>{
  try{localStorage.setItem(config.storageKey,JSON.stringify(state));return true;}
  catch(error){return false;}
};

const render=(state)=>{
  const started=Boolean(state.started);
  root.classList.toggle('is-started',started);
  active.hidden=!started;
  start.textContent=started?'Resume Quest':'Start Quest';
  status.textContent=started?'Quest started on this device.':'Quest controls are ready.';
  status.classList.remove('is-error');
};

start.addEventListener('click',()=>{
  const state={...load(),started:true,startedAt:load().startedAt||new Date().toISOString()};
  if(!save(state)){
    fail('This browser blocked local quest storage. Private browsing restrictions may be active.');
    return;
  }
  render(state);
  active.scrollIntoView({behavior:'smooth',block:'center'});
});

reset?.addEventListener('click',()=>{
  try{localStorage.removeItem(config.storageKey);}catch(error){}
  render({started:false});
  root.scrollIntoView({behavior:'smooth',block:'start'});
});

render(load());
})();
