(function(){
  'use strict';
  function qs(s,c){return (c||document).querySelector(s);}
  function qsa(s,c){return Array.prototype.slice.call((c||document).querySelectorAll(s));}
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
  function rewardText(){return text('.tng-runtime-side > .tng-runtime-card:first-child h2')||'XP';}
  function checkpointData(){
    return qsa('.tng-runtime-stop.is-complete').map(function(stop,index){
      var raw=text('small',stop);
      var xpMatch=raw.match(/(\d+)\s*XP/i);
      return {
        title:text('h3',stop)||('Checkpoint '+(index+1)),
        xp:xpMatch?(parseInt(xpMatch[1],10)||0):0,
        type:(stop.getAttribute('data-checkpoint-type')||'checkpoint').toLowerCase()
      };
    });
  }
  function typeIcon(type){
    if(type==='gps') return '📍';
    if(type==='photo') return '📸';
    if(type==='question') return '❓';
    if(type==='tap') return '✓';
    return '✓';
  }
  function buildCompletedRecap(){
    if(!isComplete() || qs('.tng-completed-recap')) return;
    var progress=qs('.tng-runtime-progress');
    if(!progress) return;
    var hero=qs('.tng-runtime-hero');
    var title=text('h1',hero)||'Adventure';
    var s=score();
    var reward=rewardText();
    var stops=checkpointData();
    var id=gameId();
    var list=stops.map(function(stop){
      return '<div class="tng-completed-recap__checkpoint"><span class="tng-completed-recap__check">'+typeIcon(stop.type)+'</span><div><strong>'+escapeHtml(stop.title)+'</strong><small>Completed'+(stop.xp?' · +'+stop.xp+' XP':'')+'</small></div></div>';
    }).join('');
    var recap=document.createElement('section');
    recap.className='tng-completed-recap';
    recap.id='tng-adventure-recap';
    recap.innerHTML='\
      <div class="tng-completed-recap__header">\
        <div class="tng-completed-recap__seal">✓</div>\
        <div class="tng-completed-recap__copy">\
          <span class="tng-completed-recap__eyebrow">Adventure saved</span>\
          <h2>'+escapeHtml(title)+' complete</h2>\
          <p>You finished the full route. Your checkpoints and Explorer XP are saved to your profile.</p>\
        </div>\
      </div>\
      <div class="tng-completed-recap__stats">\
        <div><strong>'+escapeHtml((s.total?s.done+'/'+s.total:'Complete'))+'</strong><span>Checkpoints</span></div>\
        <div><strong>'+escapeHtml(reward)+'</strong><span>XP earned</span></div>\
        <div><strong>Saved</strong><span>Explorer progress</span></div>\
      </div>\
      <div class="tng-completed-recap__body">\
        <div class="tng-completed-recap__route">\
          <span class="tng-completed-recap__label">Completed route</span>\
          <div class="tng-completed-recap__checkpoints">'+list+'</div>\
        </div>\
        <div class="tng-completed-recap__next">\
          <span class="tng-completed-recap__label">What next?</span>\
          <h3>Keep exploring Tennessee.</h3>\
          <p>Choose another adventure or open your Explorer profile to see your updated progress.</p>\
          <div class="tng-completed-recap__actions">\
            <a class="tng-completed-recap__primary" href="/games/">Find next adventure</a>\
            <a class="tng-completed-recap__secondary" href="/profile/">Explorer profile</a>\
            '+(id?'<a class="tng-completed-recap__details" href="/game/?game='+encodeURIComponent(id)+'">Game details</a>':'')+'\
          </div>\
        </div>\
      </div>';
    progress.insertAdjacentElement('afterend',recap);
  }
  function focusRecap(){
    var recap=qs('.tng-completed-recap');
    if(!recap) return;
    window.setTimeout(function(){
      try{recap.scrollIntoView({behavior:'smooth',block:'start',inline:'nearest'});}catch(e){}
    },180);
  }
  function build(){
    var pending=readPending()||{};
    var hero=qs('.tng-runtime-hero');
    var title=text('h1',hero)||'Adventure complete';
    var count=text('.tng-runtime-score strong',hero)||'Complete';
    var reward=rewardText();
    var lastXP=urlXP()||parseInt(pending.xp||0,10)||0;
    var detail='Every checkpoint is complete. Your adventure has been saved to your Explorer profile.';
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
    function close(landOnRecap){
      clearPending();
      cleanXP();
      overlay.classList.remove('is-visible');
      window.setTimeout(function(){overlay.remove();},280);
      if(landOnRecap) focusRecap();
    }
    qs('.tng-adventure-complete__close',overlay).addEventListener('click',function(){close(true);});
    qs('.tng-adventure-complete__continue',overlay).addEventListener('click',function(){close(true);});
    overlay.addEventListener('click',function(e){if(e.target===overlay)close(true);});
    document.addEventListener('keydown',function esc(e){if(e.key==='Escape'){document.removeEventListener('keydown',esc);close(true);}});
    document.body.appendChild(overlay);
    requestAnimationFrame(function(){overlay.classList.add('is-visible');});
  }
  document.addEventListener('DOMContentLoaded',function(){
    if(isComplete()){
      document.body.classList.add('tng-game-is-complete');
      buildCompletedRecap();
    }
    armFinalMission();
    if(!shouldCelebrate()) return;
    window.setTimeout(build,180);
  });
})();
