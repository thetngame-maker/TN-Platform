(function(){
  'use strict';
  function qs(s,c){return (c||document).querySelector(s);} 
  function text(s,c){var el=qs(s,c);return el?(el.textContent||'').trim():'';}
  function isComplete(){
    var eyebrow=qs('.tng-runtime-progress .tng-eyebrow');
    return !!(eyebrow && /game complete/i.test(eyebrow.textContent||''));
  }
  function justFinished(){
    try { return parseInt(new URL(window.location.href).searchParams.get('runtime_xp')||'0',10)>0 && isComplete(); }
    catch(e){ return false; }
  }
  function cleanXP(){
    try{
      var u=new URL(window.location.href);
      u.searchParams.delete('runtime_xp');
      history.replaceState({},document.title,u.pathname+(u.search?'?'+u.searchParams.toString():'')+u.hash);
    }catch(e){}
  }
  function gameId(){
    try{return new URL(window.location.href).searchParams.get('game')||'';}catch(e){return '';}
  }
  function build(){
    var hero=qs('.tng-runtime-hero');
    var title=text('h1',hero)||'Adventure complete';
    var count=text('.tng-runtime-score strong',hero)||'Complete';
    var reward=text('.tng-runtime-side > .tng-runtime-card:first-child h2')||'XP';
    var lastXP=0;
    try{lastXP=parseInt(new URL(window.location.href).searchParams.get('runtime_xp')||'0',10)||0;}catch(e){}
    var detail=text('.tng-runtime-side > .tng-runtime-card:first-child p')||'Your adventure has been recorded on your Explorer account.';
    var id=gameId();
    var overlay=document.createElement('div');
    overlay.className='tng-adventure-complete';
    overlay.setAttribute('role','dialog');
    overlay.setAttribute('aria-modal','true');
    overlay.setAttribute('aria-label','Adventure complete');
    overlay.innerHTML='\
      <div class="tng-adventure-complete__card">\
        <button type="button" class="tng-adventure-complete__close" aria-label="Close">×</button>\
        <div class="tng-adventure-complete__badge">🏆</div>\
        <span class="tng-adventure-complete__eyebrow">Adventure complete</span>\
        <h2>'+escapeHtml(title)+'</h2>\
        <p class="tng-adventure-complete__subtitle">'+escapeHtml(detail)+'</p>\
        <div class="tng-adventure-complete__stats">\
          <div class="tng-adventure-complete__stat"><strong>'+escapeHtml(count)+'</strong><span>Checkpoints</span></div>\
          <div class="tng-adventure-complete__stat"><strong>'+escapeHtml(reward)+'</strong><span>Total reward</span></div>\
          <div class="tng-adventure-complete__stat"><strong>+'+lastXP+' XP</strong><span>Final checkpoint</span></div>\
        </div>\
        <div class="tng-adventure-complete__actions">\
          <a class="tng-adventure-complete__primary" href="/games/">Play another game</a>\
          <a class="tng-adventure-complete__secondary" href="'+(id?'/game/?game='+encodeURIComponent(id):'/games/')+'">Game details</a>\
          <a class="tng-adventure-complete__profile" href="/profile/">View Explorer profile</a>\
        </div>\
      </div>';
    function close(){overlay.classList.remove('is-visible');window.setTimeout(function(){overlay.remove();},280);}
    qs('.tng-adventure-complete__close',overlay).addEventListener('click',close);
    overlay.addEventListener('click',function(e){if(e.target===overlay)close();});
    document.addEventListener('keydown',function esc(e){if(e.key==='Escape'){document.removeEventListener('keydown',esc);close();}});
    document.body.appendChild(overlay);
    requestAnimationFrame(function(){overlay.classList.add('is-visible');});
    cleanXP();
  }
  function escapeHtml(v){var d=document.createElement('div');d.textContent=String(v||'');return d.innerHTML;}
  document.addEventListener('DOMContentLoaded',function(){
    if(isComplete()) document.body.classList.add('tng-game-is-complete');
    if(!justFinished()) return;
    window.setTimeout(build,220);
  });
})();
