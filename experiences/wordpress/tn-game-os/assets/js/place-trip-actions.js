(function(){
'use strict';
var cfg=window.TNGPlaceDiscovery||{};
var trip=cfg.trip||{};
var saved=new Set((Array.isArray(trip.savedIds)?trip.savedIds:[]).map(function(id){return Number(id);}).filter(Boolean));

function postIdFromCard(card){
    var mapLink=card.querySelector('.tng-place-nearby-card__actions a[href*="place="]');
    if(!mapLink)return 0;
    try{return Number(new URL(mapLink.href,window.location.href).searchParams.get('place'))||0;}catch(e){return 0;}
}

function setButtonState(button,id,isSaved){
    button.classList.toggle('is-saved',!!isSaved);
    button.setAttribute('aria-pressed',isSaved?'true':'false');
    button.textContent=isSaved?'Added ✓':'Add to trip';
    if(isSaved)saved.add(Number(id));else saved.delete(Number(id));
}

function toggleSaved(button,id){
    if(!trip.loggedIn){
        window.location.href=trip.loginUrl||window.location.href;
        return;
    }
    if(!trip.ajaxUrl||!trip.nonce)return;
    if(button.classList.contains('is-loading'))return;
    button.classList.add('is-loading');
    button.disabled=true;
    var body=new URLSearchParams();
    body.set('action','tng_toggle_saved');
    body.set('nonce',trip.nonce);
    body.set('post_id',String(id));
    fetch(trip.ajaxUrl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:body.toString()})
        .then(function(response){return response.json();})
        .then(function(response){
            if(!response||response.success!==true)throw new Error('Trip save failed');
            var data=response.data||{};
            setButtonState(button,id,!!data.saved);
        })
        .catch(function(){
            button.classList.add('has-error');
            var previous=button.textContent;
            button.textContent='Try again';
            setTimeout(function(){button.classList.remove('has-error');setButtonState(button,id,saved.has(Number(id)));},1400);
        })
        .finally(function(){button.classList.remove('is-loading');button.disabled=false;});
}

function enhanceCard(card){
    if(card.dataset.tngTripReady==='1')return;
    var id=postIdFromCard(card);
    var actions=card.querySelector('.tng-place-nearby-card__actions');
    if(!id||!actions)return;
    card.dataset.tngTripReady='1';
    var button=document.createElement('button');
    button.type='button';
    button.className='tng-place-nearby-card__trip';
    button.dataset.postId=String(id);
    setButtonState(button,id,saved.has(id));
    button.addEventListener('click',function(){toggleSaved(button,id);});
    actions.appendChild(button);
}

function enhanceAll(){
    document.querySelectorAll('.tng-place-nearby-card').forEach(enhanceCard);
}

function boot(){
    enhanceAll();
    var observer=new MutationObserver(function(){enhanceAll();});
    observer.observe(document.body,{childList:true,subtree:true});
    setTimeout(function(){observer.disconnect();},12000);
}

if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',boot);else boot();
})();
