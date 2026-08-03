(()=>{
'use strict';
const cfg=window.TNGGameplayNotifications||{};
const labels=cfg.labels||{};
let host=null;
let sequence=0;

const ensureHost=()=>{
  if(host&&document.body.contains(host)) return host;
  host=document.createElement('section');
  host.className='tng-notification-center';
  host.setAttribute('aria-live','polite');
  host.setAttribute('aria-label','TN Game notifications');
  document.body.appendChild(host);
  return host;
};

const iconFor=(type)=>({success:'✓',reward:'★',warning:'!',error:'×',info:'i'}[type]||'i');

const dismiss=(toast)=>{
  if(!toast||toast.dataset.closing==='1') return;
  toast.dataset.closing='1';
  toast.classList.add('is-leaving');
  window.setTimeout(()=>toast.remove(),220);
};

const notify=(input={})=>{
  const detail=typeof input==='string'?{message:input}:input;
  const type=['success','reward','warning','error','info'].includes(detail.type)?detail.type:'info';
  const toast=document.createElement('article');
  toast.className=`tng-toast tng-toast--${type}`;
  toast.dataset.toastId=String(++sequence);
  toast.setAttribute('role',type==='error'?'alert':'status');

  const icon=document.createElement('span');
  icon.className='tng-toast__icon';
  icon.setAttribute('aria-hidden','true');
  icon.textContent=detail.icon||iconFor(type);

  const copy=document.createElement('div');
  copy.className='tng-toast__copy';
  if(detail.title){const title=document.createElement('strong');title.textContent=String(detail.title);copy.appendChild(title);}
  if(detail.message){const message=document.createElement('span');message.textContent=String(detail.message);copy.appendChild(message);}

  if(detail.action&&detail.action.label){
    const action=document.createElement(detail.action.href?'a':'button');
    action.className='tng-toast__action';
    action.textContent=String(detail.action.label);
    if(detail.action.href) action.href=String(detail.action.href);
    else action.type='button';
    action.addEventListener('click',()=>{if(typeof detail.action.onClick==='function') detail.action.onClick();dismiss(toast);});
    copy.appendChild(action);
  }

  const close=document.createElement('button');
  close.type='button';
  close.className='tng-toast__close';
  close.setAttribute('aria-label',labels.close||'Dismiss notification');
  close.textContent='×';
  close.addEventListener('click',()=>dismiss(toast));

  toast.append(icon,copy,close);
  ensureHost().appendChild(toast);
  requestAnimationFrame(()=>toast.classList.add('is-visible'));

  const duration=Number.isFinite(Number(detail.duration))?Number(detail.duration):Number(cfg.defaultDuration||4200);
  if(duration>0) window.setTimeout(()=>dismiss(toast),duration);
  return toast;
};

window.TNGNotify=notify;
window.addEventListener('tng:notify',(event)=>notify(event.detail||{}));
window.addEventListener('tng:checkpoint-claimed',(event)=>notify({type:'success',title:'Checkpoint complete',message:event.detail?.title||'Your journey progress has been updated.'}));
window.addEventListener('tng:reward-earned',(event)=>notify({type:'reward',title:event.detail?.title||'Reward earned',message:event.detail?.message||'Your reward has been added.'}));
window.addEventListener('tng:badge-unlocked',(event)=>notify({type:'reward',title:'Badge unlocked',message:event.detail?.title||'A new achievement was added to your Explorer profile.'}));
window.addEventListener('tng:mission-complete',(event)=>notify({type:'success',title:'Mission complete',message:event.detail?.message||'Your Adventure Token reward is ready.'}));
window.addEventListener('offline',()=>notify({type:'warning',title:labels.offlineTitle||'You are offline',message:labels.offlineMessage||'Progress will remain on this device.',duration:7000}));
window.addEventListener('online',()=>notify({type:'success',title:labels.onlineTitle||'Back online',message:labels.onlineMessage||'Progress can sync again.'}));

document.addEventListener('DOMContentLoaded',()=>{
  ensureHost();
  const params=new URLSearchParams(window.location.search);
  const notice=params.get('tng_notice');
  if(notice) notify({type:params.get('tng_notice_type')||'success',message:notice});
});
})();
