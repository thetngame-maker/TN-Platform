(function(){
  'use strict';

  function ready(fn){
    if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',fn);
    else fn();
  }

  function text(el){return (el&&el.textContent||'').trim().toLowerCase();}

  function hideReviewSections(){
    const headings=Array.from(document.querySelectorAll('h1,h2,h3,h4,h5,h6'));
    headings.forEach(heading=>{
      const label=text(heading);
      if(label==='reviews'||label==='review'){
        const section=heading.closest('section,.st-reviews,.review-box,.reviews,.st-review,.st-list-review')||heading.parentElement;
        if(section) section.classList.add('tng-mobile-hidden-review');
        else heading.classList.add('tng-mobile-hidden-review');
      }
    });

    document.querySelectorAll(
      '.st-reviews,.st-review,.reviews,.review-box,.review-list,.st-list-review,'+
      '[class*="review-score"],[class*="review-box"]'
    ).forEach(el=>el.classList.add('tng-mobile-hidden-review'));
  }

  function hideOriginalShareControls(){
    const selectors=[
      '.service-share','.st-share','.btn-share','.share-item','.share-wrapper',
      '.st-share-wrapper','.service-share-wrapper','.share-icon',
      '[data-action="share"]','[aria-label*="share" i]','[title*="share" i]',
      '[class*="service-share"]','[class*="st-share"]'
    ];

    selectors.forEach(selector=>{
      document.querySelectorAll(selector).forEach(el=>{
        if(!el.closest('.tng-mobile-trail-bar')){
          const target=el.closest(
            '.st-action-btn,.service-share,.st-share,.btn-share,.share-item,'+
            '.share-wrapper,.st-share-wrapper,.service-share-wrapper'
          )||el;
          target.classList.add('tng-hide-original-mobile-share');
        }
      });
    });

    document.querySelectorAll('a,button').forEach(el=>{
      if(el.closest('.tng-mobile-trail-bar')) return;

      const haystack=[
        el.className||'',
        el.getAttribute('href')||'',
        el.getAttribute('onclick')||'',
        el.getAttribute('aria-label')||'',
        el.getAttribute('title')||''
      ].join(' ').toLowerCase();

      if(
        haystack.includes('share') ||
        el.querySelector('.fa-share,.fa-share-alt,.stt-icon-share,.icon-share')
      ){
        const target=el.closest('.st-action-btn,.service-share,.st-share,.share-item')||el;
        target.classList.add('tng-hide-original-mobile-share');
      }
    });
  }

  function findOriginalFavorite(){
    const selectors=[
      '.service-wishlist','.st-wishlist','.add-to-wishlist','.wishlist-icon',
      '[class*="wishlist"]','[data-action="wishlist"]','[aria-label*="favorite" i]',
      '[title*="favorite" i]'
    ];
    for(const selector of selectors){
      const el=document.querySelector(selector);
      if(el && !el.closest('.tng-mobile-trail-bar')) return el;
    }
    return null;
  }

  function syncFavorite(button,original){
    if(!original) return;
    const active=original.classList.contains('active')||
      original.classList.contains('added')||
      original.getAttribute('aria-pressed')==='true'||
      /remove|saved|favorited/.test(text(original));
    button.classList.toggle('is-active',active);
    button.setAttribute('aria-pressed',active?'true':'false');
  }

  function shareTrail(){
    const config=window.TNGMobileTrailActions||{};
    const payload={title:config.title||document.title,text:config.shareText||'',url:location.href.split('#')[0]};
    if(navigator.share){
      navigator.share(payload).catch(()=>{});
      return;
    }
    if(navigator.clipboard){
      navigator.clipboard.writeText(payload.url).then(()=>{
        const button=document.querySelector('.tng-mobile-share');
        if(!button)return;
        const old=button.querySelector('span:last-child').textContent;
        button.querySelector('span:last-child').textContent='Copied';
        setTimeout(()=>button.querySelector('span:last-child').textContent=old,1400);
      });
    }
  }

  function buildBar(){
    if(document.querySelector('.tng-mobile-trail-bar')) return;
    const config=window.TNGMobileTrailActions||{};
    const originalFavorite=findOriginalFavorite();

    const bar=document.createElement('nav');
    bar.className='tng-mobile-trail-bar';
    bar.setAttribute('aria-label','Trail actions');
    bar.innerHTML=
      '<button type="button" class="tng-mobile-action tng-mobile-share" aria-label="Share trail">'+
        '<span class="tng-mobile-action-icon">↗</span><span>Share</span>'+ 
      '</button>'+ 
      '<button type="button" class="tng-mobile-action tng-mobile-favorite" aria-label="Save trail" aria-pressed="false">'+
        '<span class="tng-mobile-action-icon">♡</span><span>Save</span>'+ 
      '</button>'+ 
      '<a class="tng-mobile-open-map" href="'+(config.mapUrl||'?trail_game_map=1')+'">'+
        '<span class="tng-mobile-map-icon">⌖</span><span>Open Map</span>'+ 
      '</a>';

    document.body.appendChild(bar);
    document.body.classList.add('tng-has-mobile-trail-bar');

    bar.querySelector('.tng-mobile-share').addEventListener('click',shareTrail);
    const favorite=bar.querySelector('.tng-mobile-favorite');
    favorite.addEventListener('click',()=>{
      if(originalFavorite){
        originalFavorite.dispatchEvent(new MouseEvent('click',{bubbles:true,cancelable:true,view:window}));
        setTimeout(()=>syncFavorite(favorite,originalFavorite),250);
      }else{
        favorite.classList.toggle('is-active');
        favorite.setAttribute('aria-pressed',favorite.classList.contains('is-active')?'true':'false');
      }
    });
    syncFavorite(favorite,originalFavorite);
  }

  function hideOriginalMobileControls(){
    hideOriginalShareControls();

    document.querySelectorAll(
      '.owner-info,.st-fix-price,.st-sticky-footer,.st-mobile-booking,'+
      '.booking-form-mobile,.st-bottom-booking,.st-mobile-footer,'+
      '[class*="mobile-booking"],[class*="sticky-book"]'
    ).forEach(el=>{
      if(!el.closest('.tng-mobile-trail-bar')) el.classList.add('tng-hide-traveler-mobile-booking');
    });

    document.querySelectorAll('.service-wishlist,.st-wishlist,.add-to-wishlist,.wishlist-icon,[class*="wishlist"]').forEach(el=>{
      if(!el.closest('.tng-mobile-trail-bar')) el.classList.add('tng-hide-original-mobile-action');
    });

    document.querySelectorAll('button,a').forEach(el=>{
      const label=text(el);
      if(label==='check'||label==='book now'){
        const parent=el.closest('.st-fix-price,.st-sticky-footer,.st-mobile-booking,.booking-form-mobile,.st-bottom-booking')||el;
        parent.classList.add('tng-hide-traveler-mobile-booking');
      }
    });
  }

  ready(()=>{
    if(!document.body.classList.contains('tng-hiking-trail-page')) return;

    const config=window.TNGMobileTrailActions||{};
    const isFullscreenMap=Boolean(
      config.fullscreenMap ||
      document.body.classList.contains('tng-fullscreen-trail-map-page') ||
      new URLSearchParams(window.location.search).get('trail_game_map')==='1'
    );

    hideReviewSections();
    hideOriginalMobileControls();

    if(isFullscreenMap){
      const existing=document.querySelector('.tng-mobile-trail-bar');
      if(existing) existing.remove();
      document.body.classList.remove('tng-has-mobile-trail-bar');
    }else{
      buildBar();
    }

    const observer=new MutationObserver(()=>{
      hideReviewSections();
      hideOriginalMobileControls();

      if(isFullscreenMap){
        const existing=document.querySelector('.tng-mobile-trail-bar');
        if(existing) existing.remove();
        document.body.classList.remove('tng-has-mobile-trail-bar');
      }
    });
    observer.observe(document.body,{childList:true,subtree:true});
  });
})();
