(function(){
  'use strict';
  function qs(s,c){return (c||document).querySelector(s);}
  function text(s,c){var el=qs(s,c);return el?(el.textContent||'').trim():'';}
  function gameId(){
    try{return new URL(window.location.href).searchParams.get('game')||'';}catch(e){return '';}
  }
  function score(){
    var raw=text('.tng-runtime-score strong');
    var m=raw.match(/(\d+)\s*\/\s*(\d+)/);
    return m?{done:parseInt(m[1],10)||0,total:parseInt(m[2],10)||0}:{done:0,total:0};
  }
  function isComplete(){
    var s=score();
    if(s.total>0 && s.done>=s.total) return true;
    var eyebrow=qs('.tng-runtime-progress .tng-eyebrow');
    return !!(eyebrow && /game complete/i.test(eyebrow.textContent||''));
  }
  function pendingKey(){return 'tng_finish_pending_'+gameId();}
  function readPending(){
    try{
      var raw=sessionStorage.getItem(pendingKey());
      if(!raw) return null;
      var data=JSON.parse(raw);
      if(!data || !data.at || (Date.now()-data.at)>30*60*1000){sessionStorage.removeItem(pendingKey());return null;}
      return data;
    }catch(e){return null;}
  }
  function clearPending(){try{sessionStorage.removeItem(pendingKey());}catch(e){}}
  function activeXP(){
    var stop=qs('.tng-runtime-stop.is-next');
    if(!stop) return 0;
    var raw=text('small',stop)+' '+text('.tng-runtime-action',stop);
    var m=raw.match(/(\d+)\s*XP/i);
    return m?(parseInt(m[1],10)||0):0;
  }
  function armFinalMission(){
    if(isComplete()) return;
    var s=score();
    if(!s.total || s.done!==s.total-1) return;
    try{
      sessionStorage.setItem(pendingKey(),JSON.stringify({
        at:Date.now(),
        xp:activeXP(),
        title:text('.tng-runtime-stop.is-next h3')||''
      }));
    }catch(e){}
  }
  function urlXP(){
    try{return parseInt(new URL(window.location.href).searchParams.get('runtime_xp')||'0',10)||0;}catch(e){return 0;}
  }
  function shouldCelebrate(){
    if(!isComplete()) return false;
    if(urlXP()>0) return true;
    return !!readPending();
  }
  function cleanXP(){
    try{
      var u=new URL(window.location.href);
      u.searchParams.delete('runtime_xp');
      u.searchParams.delete('runtime_finish');
      history.replaceState({},document.title,u.pathname+(u.search?'?'+u.searchParams.toString():'')+u.hash);
    }catch(e){}
  }
  function escapeHtml(v){var d=document.createElement('div');d.textContent=String(v||'');return d.innerHTML;}
  function build(){
    var pending=readPending()||{};
    var hero=qs('.tng-runtime-hero');
    var title=text('h1',hero)||'Adventure complete';
    var count=text('.tng-runtime-score strong',hero)||'Complete';
    var reward=text('.tng-runtime-side > .tng-runtime-card:first-child h2')||'XP';
    var lastXP=urlXP()||parseInt(pending.xp||0,10)||0;
    var detail='Every checkpoint is complete. Your adventure has been saved to your Explorer profile.';
    var id=gameId();
    var overlay=document.createElement('div');
    overlay.className='tng-adventure-complete';
    overlay.setAttribute('role','dialog');
    overlay.setAttribute('aria-modal','true');
    overlay.setAttribute('aria-label','Adventure complete');
    overlay.innerHTML='\
      <div class="tng-adventure-complete__card">\
        <button type="button" class="tng-adventure-complete__close" aria-label="Continue to completed adventure">×</button>\
        <div class="tng-adventure-complete__badge">🏆</div>\
        <span class="tng-adventure-complete__eyebrow">Adventure complete</span>\
        <h2>'+escapeHtml(title)+'</h2>\
        <p class="tng-adventure-complete__subtitle">'+escapeHtml(detail)+'</p>\
        <div class="tng-adventure-complete__stats">\
          <div class="tng-adventure-complete__stat"><strong>'+escapeHtml(count)+'</strong><span>Checkpoints</span></div>\
          <div class="tng-adventure-complete__stat"><strong>'+escapeHtml(reward)+'</strong><span>Total reward</span></div>\
          <div class="tng-adventure-complete__stat"><strong>'+(lastXP?('+'+lastXP+' XP'):'Complete')+'</strong><span>Final checkpoint</span></div>\
        </div>\
        <div class="tng-adventure-complete__actions">\
          <button type="button" class="tng-adventure-complete__primary tng-adventure-complete__continue">Continue</button>\
          <a class="tng-adventure-complete__secondary" href="/games/">Play another game</a>\
          <a class="tng-adventure-complete__profile" href="/profile/">View Explorer profile</a>\
        </div>\
      </div>';
    function close(){
      clearPending();
      cleanXP();
      overlay.classList.remove('is-visible');
      window.setTimeout(function(){overlay.remove();},280);
    }
    qs('.tng-adventure-complete__close',overlay).addEventListener('click',close);
    qs('.tng-adventure-complete__continue',overlay).addEventListener('click',close);
    overlay.addEventListener('click',function(e){if(e.target===overlay)close();});
    document.addEventListener('keydown',function esc(e){if(e.key==='Escape'){document.removeEventListener('keydown',esc);close();}});
    document.body.appendChild(overlay);
    requestAnimationFrame(function(){overlay.classList.add('is-visible');});
  }
  document.addEventListener('DOMContentLoaded',function(){
    if(isComplete()) document.body.classList.add('tng-game-is-complete');
    armFinalMission();
    if(!shouldCelebrate()) return;
    window.setTimeout(build,180);
  });
})();
