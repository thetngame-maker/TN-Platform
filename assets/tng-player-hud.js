(function(){
  'use strict';

  function ready(callback){
    if(document.readyState==='loading'){
      document.addEventListener('DOMContentLoaded',callback,{once:true});
    }else{
      callback();
    }
  }

  function number(value){
    return Number.parseInt(value,10)||0;
  }

  function format(value){
    return number(value).toLocaleString();
  }

  ready(()=>{
    const root=document.querySelector('.tng-player-hud');
    if(!root) return;

    const config=window.TNGPlayerHUD||{};
    const profileButton=root.querySelector('.tng-player-hud-profile');
    const avatar=root.querySelector('.tng-player-hud-avatar');
    const levelBadge=root.querySelector('.tng-player-hud-level');
    const hudName=root.querySelector('.tng-player-hud-name');
    const bar=root.querySelector('.tng-player-hud-bar span');
    const barWrap=root.querySelector('.tng-player-hud-bar');
    const xpText=root.querySelector('.tng-player-hud-xp-text');
    const gain=root.querySelector('.tng-player-hud-gain');
    const backdrop=root.querySelector('.tng-player-hud-backdrop');
    const panel=root.querySelector('.tng-player-hud-panel');
    const closeButton=root.querySelector('.tng-player-hud-close');
    const levelOverlay=root.querySelector('.tng-level-up-overlay');
    const levelNumber=root.querySelector('.tng-level-up-number');
    const levelTitleNumber=root.querySelector('.tng-level-up-overlay h2 span');
    const viewStats=root.querySelector('.tng-level-up-stats');
    const continueButton=root.querySelector('.tng-level-up-continue');

    let state={};
    try{
      state=JSON.parse(root.getAttribute('data-initial-state')||'{}');
    }catch(error){
      state={};
    }

    const storageKey=config.storageKey||'tng_player_hud';
    let stored=null;
    try{
      stored=JSON.parse(localStorage.getItem(storageKey)||'null');
    }catch(error){
      stored=null;
    }

    function openPanel(){
      panel.hidden=false;
      backdrop.hidden=false;
      document.body.classList.add('tng-player-hud-open');
      profileButton.setAttribute('aria-expanded','true');
      requestAnimationFrame(()=>panel.classList.add('is-open'));
    }

    function closePanel(){
      panel.classList.remove('is-open');
      document.body.classList.remove('tng-player-hud-open');
      profileButton.setAttribute('aria-expanded','false');
      setTimeout(()=>{
        panel.hidden=true;
        if(levelOverlay.hidden) backdrop.hidden=true;
      },220);
    }

    function closeLevelUp(){
      levelOverlay.classList.remove('is-visible');
      setTimeout(()=>{
        levelOverlay.hidden=true;
        if(panel.hidden) backdrop.hidden=true;
      },260);
    }

    function showLevelUp(newLevel){
      levelNumber.textContent=newLevel;
      levelTitleNumber.textContent=newLevel;
      backdrop.hidden=false;
      levelOverlay.hidden=false;
      requestAnimationFrame(()=>levelOverlay.classList.add('is-visible'));
    }

    function showGain(delta){
      if(delta<=0) return;

      gain.textContent='+'+format(delta)+' XP';
      gain.hidden=false;
      gain.classList.remove('is-visible');
      profileButton.classList.remove('is-xp-earned');

      void gain.offsetWidth;

      gain.classList.add('is-visible');
      profileButton.classList.add('is-xp-earned');

      setTimeout(()=>{
        gain.classList.remove('is-visible');
        profileButton.classList.remove('is-xp-earned');
        setTimeout(()=>gain.hidden=true,250);
      },2600);
    }

    function updatePanel(next){
      const heroImage=panel.querySelector('.tng-player-hud-panel-hero img');
      const heroName=panel.querySelector('.tng-player-hud-panel-hero h2');
      const heroLevel=panel.querySelector('.tng-player-hud-panel-hero strong');
      const heroXp=panel.querySelector('.tng-player-hud-panel-hero strong + span');
      const panelBar=panel.querySelector('.tng-player-hud-panel-bar span');
      const panelRemaining=panel.querySelector('.tng-player-hud-panel-progress > span');
      const achievementTitle=panel.querySelector('.tng-player-hud-achievement strong');
      const achievementDescription=panel.querySelector('.tng-player-hud-achievement strong + span');

      if(next.avatarUrl){
        avatar.src=next.avatarUrl;
        heroImage.src=next.avatarUrl;
      }

      hudName.textContent=next.displayName||'Explorer';
      levelBadge.textContent=next.level;
      bar.style.width=next.progress+'%';
      barWrap.setAttribute('aria-valuenow',next.progress);
      xpText.textContent=format(next.xp)+' XP';

      heroName.textContent=next.displayName||'Explorer';
      heroLevel.textContent='Level '+next.level;
      heroXp.textContent=format(next.xp)+' Explorer XP';
      panelBar.style.width=next.progress+'%';
      panelRemaining.textContent=format(next.remaining)+' XP until Level '+next.nextLevel;

      Object.entries(next.stats||{}).forEach(([key,value])=>{
        const element=panel.querySelector('[data-stat="'+key+'"]');
        if(element){
          element.textContent=key==='miles'
            ? Number(value||0).toFixed(2)
            : format(value);
        }
      });

      if(next.achievement){
        achievementTitle.textContent=next.achievement.title||'Your next achievement awaits';
        achievementDescription.textContent=next.achievement.description||'';
      }
    }

    function applyState(next,animate){
      const previousXp=number(state.xp);
      const previousLevel=number(state.level);
      const nextXp=number(next.xp);
      const nextLevel=number(next.level);

      updatePanel(next);

      if(animate && nextXp>previousXp){
        showGain(nextXp-previousXp);
      }

      if(animate && nextLevel>previousLevel){
        setTimeout(()=>showLevelUp(nextLevel),600);
      }

      state=next;

      try{
        localStorage.setItem(storageKey,JSON.stringify({
          xp:nextXp,
          level:nextLevel
        }));
      }catch(error){}
    }

    async function refresh(animate){
      const body=new URLSearchParams();
      body.set('action','tng_player_hud_state');
      body.set('nonce',config.nonce||'');

      try{
        const response=await fetch(config.ajaxUrl,{
          method:'POST',
          credentials:'same-origin',
          headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
          body:body.toString()
        });

        const payload=await response.json();
        if(payload&&payload.success&&payload.data){
          applyState(payload.data,animate);
        }
      }catch(error){}
    }

    profileButton.addEventListener('click',openPanel);
    closeButton.addEventListener('click',closePanel);
    backdrop.addEventListener('click',()=>{
      if(!levelOverlay.hidden) closeLevelUp();
      if(!panel.hidden) closePanel();
    });
    continueButton.addEventListener('click',closeLevelUp);
    viewStats.addEventListener('click',()=>{
      closeLevelUp();
      setTimeout(openPanel,260);
    });

    document.addEventListener('keydown',event=>{
      if(event.key!=='Escape') return;
      if(!levelOverlay.hidden) closeLevelUp();
      else if(!panel.hidden) closePanel();
    });

    document.addEventListener('tng:xp-earned',()=>{
      setTimeout(()=>refresh(true),250);
    });

    /*
     * First load establishes a baseline without showing a false level-up.
     * If another page already stored a lower XP/level, show the earned update.
     */
    if(stored&&number(state.xp)>number(stored.xp)){
      showGain(number(state.xp)-number(stored.xp));
    }
    if(stored&&number(state.level)>number(stored.level)){
      setTimeout(()=>showLevelUp(number(state.level)),500);
    }

    try{
      localStorage.setItem(storageKey,JSON.stringify({
        xp:number(state.xp),
        level:number(state.level)
      }));
    }catch(error){}

    const interval=Math.max(3000,number(config.pollInterval)||6000);
    window.setInterval(()=>refresh(true),interval);

    document.addEventListener('visibilitychange',()=>{
      if(!document.hidden) refresh(true);
    });
  });
})();