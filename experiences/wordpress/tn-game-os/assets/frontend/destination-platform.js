(function(){
'use strict';
const cfg=window.TNGDestinationPlatform||{};

async function api(path,options={}){
  const response=await fetch(cfg.rest+path,{
    credentials:'same-origin',
    headers:{'Content-Type':'application/json','X-WP-Nonce':cfg.nonce||'',...(options.headers||{})},
    ...options
  });
  if(!response.ok){
    let message='Request failed';
    try{const error=await response.json();message=error.message||message}catch(e){}
    throw new Error(message);
  }
  return response.json();
}

function escapeHtml(value){
  return String(value??'').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
}

function renderNearbyGroup(title,items){
  if(!items||!items.length)return '';
  return '<section class="tng-dp-near-group"><h3>'+escapeHtml(title)+'</h3><ol>'+
    items.map(item=>'<li><span><strong><a href="'+escapeHtml(item.url)+'">'+escapeHtml(item.title)+'</a></strong><small>'+escapeHtml(item.type)+'</small></span><b>'+escapeHtml(item.distance)+' mi</b></li>').join('')+
    '</ol></section>';
}

async function updateTrip(action,postId){
  if(!cfg.loggedIn)throw new Error('Sign in to save a trip plan.');
  const result=await api('trip-plan',{method:'POST',body:JSON.stringify({action:action,post_id:postId||0})});
  document.querySelectorAll('[data-tng-trip-panel]').forEach(panel=>panel.innerHTML=result.html);
  document.querySelectorAll('[data-tng-trip-count]').forEach(counter=>counter.textContent=result.count+' saved');
  document.querySelectorAll('[data-tng-trip-clear]').forEach(button=>button.hidden=result.count===0);
  return result;
}

document.addEventListener('click',async function(event){
  const near=event.target.closest('[data-tng-near-me]');
  if(near){
    const results=near.closest('.tng-dp-near-me').querySelector('[data-tng-near-results]');
    if(!navigator.geolocation){results.textContent='Location is not supported by this browser.';return}
    near.disabled=true;near.textContent='Finding nearby places…';results.textContent='';
    navigator.geolocation.getCurrentPosition(async pos=>{
      try{
        const data=await api('destinations/nearby?lat='+encodeURIComponent(pos.coords.latitude)+'&lng='+encodeURIComponent(pos.coords.longitude));
        const html=renderNearbyGroup('Closest destinations',data.destinations)+renderNearbyGroup('Nearby experiences',data.experiences);
        results.innerHTML=html||'<div class="tng-dp-empty"><strong>No mapped places were found nearby.</strong><p>Add coordinates to listings or expand the supported area.</p></div>';
      }catch(e){results.textContent=e.message||'Could not load nearby places.'}
      near.disabled=false;near.textContent='Find places near me';
    },error=>{
      results.textContent=error.code===1?'Location permission was not granted.':'Your location could not be determined.';
      near.disabled=false;near.textContent='Find places near me';
    },{enableHighAccuracy:true,timeout:12000,maximumAge:60000});
    return;
  }

  const itinerary=event.target.closest('[data-tng-itinerary]');
  if(itinerary){
    const output=itinerary.parentElement.querySelector('[data-tng-itinerary-results]');
    output.textContent='Building your itinerary…';
    try{
      const data=await api('destinations/'+itinerary.dataset.destination+'/itinerary',{method:'POST',body:'{}'});
      output.innerHTML='<ol>'+data.items.map(item=>'<li><strong>'+escapeHtml(item.time)+'</strong> — '+escapeHtml(item.type)+': <a href="'+escapeHtml(item.url)+'">'+escapeHtml(item.title)+'</a></li>').join('')+'</ol><small>Smart itinerary generated from connected destination content.</small>';
    }catch(e){output.textContent=e.message||'Could not build the itinerary.'}
    return;
  }

  const add=event.target.closest('[data-tng-add-trip]');
  if(add){
    if(!cfg.loggedIn){window.location.href='/wp-login.php?redirect_to='+encodeURIComponent(window.location.href);return}
    add.disabled=true;
    try{
      await updateTrip('add',Number(add.dataset.listing));
      add.textContent='✓ Added';
      window.setTimeout(()=>{add.textContent='+ Add to trip';add.disabled=false},1600);
    }catch(e){add.textContent=e.message;window.setTimeout(()=>{add.textContent='+ Add to trip';add.disabled=false},2200)}
    return;
  }

  const remove=event.target.closest('[data-tng-trip-remove]');
  if(remove){
    remove.disabled=true;
    try{await updateTrip('remove',Number(remove.dataset.tngTripRemove))}catch(e){remove.disabled=false}
    return;
  }

  const clear=event.target.closest('[data-tng-trip-clear]');
  if(clear){
    clear.disabled=true;
    try{await updateTrip('clear',0)}finally{clear.disabled=false}
    return;
  }

  const refresh=event.target.closest('[data-tng-trip-refresh]');
  if(refresh){
    refresh.disabled=true;
    try{
      const result=await api('trip-plan');
      document.querySelectorAll('[data-tng-trip-panel]').forEach(panel=>panel.innerHTML=result.html);
      document.querySelectorAll('[data-tng-trip-count]').forEach(counter=>counter.textContent=result.count+' saved');
    }catch(e){}
    refresh.disabled=false;
  }
});
})();