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
    if(complete && /game complete/i.test(complete.textContent||'')) {
      return qs('.tng-runtime-progress');
    }
    return qs('.tng-runtime-stop.is-next') || qs('.tng-runtime-progress');
  }

  function focusTarget(behavior){
    var el=target();
    if(!el) return false;
    try {
      el.scrollIntoView({behavior:behavior||'auto',block:'center',inline:'nearest'});
      return true;
    } catch(e) {
      var top=el.getBoundingClientRect().top + window.pageYOffset - Math.max(90,(window.innerHeight-el.offsetHeight)/2);
      window.scrollTo(0,Math.max(0,top));
      return true;
    }
  }

  function show(xp){
    var overlay=document.createElement('div');
    overlay.className='tng-checkpoint-success';
    overlay.setAttribute('role','status');
    overlay.setAttribute('aria-live','polite');
    overlay.innerHTML='<div class="tng-checkpoint-success__card"><div class="tng-checkpoint-success__icon">✓</div><div class="tng-checkpoint-success__eyebrow">CHECKPOINT COMPLETE</div><strong>+'+xp+' XP</strong><span>Next mission ready</span></div>';
    document.body.appendChild(overlay);
    requestAnimationFrame(function(){overlay.classList.add('is-visible');});

    window.setTimeout(function(){
      overlay.classList.remove('is-visible');
      window.setTimeout(function(){overlay.remove();},260);
      window.setTimeout(function(){focusTarget('smooth');},100);
    },1350);
  }

  function beginSuccessFlow(xp){
    try {
      if('scrollRestoration' in history) history.scrollRestoration='manual';
    } catch(e){}

    // Beat Safari/browser scroll restoration and put the new active mission in view first.
    focusTarget('auto');
    window.setTimeout(function(){focusTarget('auto');},60);
    window.setTimeout(function(){focusTarget('auto');},220);

    cleanUrl();
    window.setTimeout(function(){show(xp);},260);
  }

  document.addEventListener('DOMContentLoaded',function(){
    var xp=getXP();
    if(!xp) return;
    beginSuccessFlow(xp);
  });
})();
