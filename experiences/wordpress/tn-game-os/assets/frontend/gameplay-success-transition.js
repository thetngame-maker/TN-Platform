(function(){
  'use strict';
  function qs(s,c){return (c||document).querySelector(s);} 
  function getXP(){
    try { return parseInt(new URL(window.location.href).searchParams.get('runtime_xp')||'0',10)||0; }
    catch(e){ return 0; }
  }
  function cleanUrl(){
    try {
      var u=new URL(window.location.href);
      if(!u.searchParams.has('runtime_xp')) return;
      u.searchParams.delete('runtime_xp');
      history.replaceState({},document.title,u.pathname+(u.search?'?'+u.searchParams.toString():'')+u.hash);
    } catch(e){}
  }
  function target(){
    var complete=qs('.tng-runtime-progress .tng-eyebrow');
    if(complete && /game complete/i.test(complete.textContent||'')) return qs('.tng-runtime-progress');
    return qs('.tng-runtime-stop.is-next') || qs('.tng-runtime-progress');
  }
  function show(xp){
    var overlay=document.createElement('div');
    overlay.className='tng-checkpoint-success';
    overlay.setAttribute('role','status');
    overlay.setAttribute('aria-live','polite');
    overlay.innerHTML='<div class="tng-checkpoint-success__card"><div class="tng-checkpoint-success__icon">✓</div><div class="tng-checkpoint-success__eyebrow">CHECKPOINT COMPLETE</div><strong>+'+xp+' XP</strong><span>Explorer XP earned</span></div>';
    document.body.appendChild(overlay);
    requestAnimationFrame(function(){overlay.classList.add('is-visible');});
    window.setTimeout(function(){
      overlay.classList.remove('is-visible');
      window.setTimeout(function(){overlay.remove();},260);
      var el=target();
      if(el) window.setTimeout(function(){el.scrollIntoView({behavior:'smooth',block:'center'});},120);
    },1450);
  }
  document.addEventListener('DOMContentLoaded',function(){
    var xp=getXP();
    if(!xp) return;
    cleanUrl();
    window.setTimeout(function(){show(xp);},180);
  });
})();
