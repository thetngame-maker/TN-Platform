(()=>{
'use strict';

const bootRuntime=(root)=>{
  if(!root||root.dataset.runtimeExternalBooted==='1'||root.dataset.runtimeBooted==='1')return;
  const dataNode=root.querySelector('.tng-runtime-data');
  if(!dataNode)return;
  let data;
  try{data=JSON.parse(dataNode.textContent||'{}');}catch(error){return;}
  root.dataset.runtimeExternalBooted='1';
  root.dataset.runtimeBooted='1';

  const list=root.querySelector('.tng-runtime-list');
  const next=root.querySelector('.tng-next-card');
  const bar=root.querySelector('.tng-runtime-progress span');
  const dots=root.querySelector('.tng-runtime-dots');
  const errorBox=root.querySelector('.tng-runtime-error');
  const mapStatus=root.querySelector('.tng-map-status');
  const startButton=root.querySelector('.tng-runtime-start');
  const storage='tngQuestProgress:'+data.questId;
  let state={started:false,done:[],status:'not_started'};
  let saving=false,watchId=null,position=null,geoError='',map=null,userMarker=null,accuracyCircle=null,radiusCircle=null,checkpointMarkers=[];

  const esc=(value)=>String(value).replace(/[&<>'"]/g,(char)=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[char]));
  const load=()=>{try{return JSON.parse(localStorage.getItem(storage)||'{}');}catch(error){return {};}};
  const saveLocal=()=>{try{localStorage.setItem(storage,JSON.stringify(state));}catch(error){}}
  const showError=(message)=>{if(!errorBox)return;errorBox.textContent=message||'';errorBox.classList.toggle('is-visible',Boolean(message));};
  const api=async(method,body)=>{
    const response=await fetch(data.progressUrl,{method,credentials:'same-origin',headers:{'Content-Type':'application/json','X-WP-Nonce':data.restNonce||''},body:body?JSON.stringify(body):undefined});
    if(!response.ok)throw new Error('Progress request failed');
    return response.json();
  };
  const apply=(result)=>{state.started=Boolean(result.started);state.done=Array.isArray(result.completedStops)?result.completedStops.map(String):[];state.status=result.status||'not_started';};
  const persist=async()=>{
    saveLocal();
    if(!data.loggedIn||saving){render();return;}
    saving=true;
    try{apply(await api('POST',{started:state.started,completedStops:state.done}));saveLocal();}
    catch(error){showError('Progress is saved on this device, but account sync is unavailable.');}
    finally{saving=false;render();}
  };
  const typeLabel=(type)=>({gps:'GPS arrival',trivia:'Trivia',photo:'Photo challenge',qr:'QR code',manual:'Manual claim'}[type]||'Checkpoint');
  const feet=(meters)=>Math.round(Number(meters||0)*3.28084);
  const distance=(a,b)=>{const R=6371000,p=Math.PI/180,dLat=(b.lat-a.lat)*p,dLon=(b.lng-a.lng)*p,x=Math.sin(dLat/2)**2+Math.cos(a.lat*p)*Math.cos(b.lat*p)*Math.sin(dLon/2)**2;return R*2*Math.atan2(Math.sqrt(x),Math.sqrt(1-x));};
  const hasCoords=(stop)=>Number.isFinite(Number(stop.lat))&&Number.isFinite(Number(stop.lng));
  const currentInfo=(stop)=>{
    if(stop.type!=='gps')return{claimable:true,text:'This checkpoint does not require GPS.'};
    if(data.adminOverride)return{claimable:true,text:'Administrator test override enabled.'};
    if(!hasCoords(stop))return{claimable:false,text:'Location coordinates have not been added for this checkpoint.'};
    if(!position)return{claimable:false,text:geoError||'Turn on location to measure your distance.'};
    const meters=distance(position,{lat:Number(stop.lat),lng:Number(stop.lng)}),radius=Number(stop.radius||30),distanceFeet=feet(meters);
    return{claimable:distanceFeet<=radius,distanceFeet,radius,text:distanceFeet<=radius?'You are inside the arrival zone.':distanceFeet+' ft away · get within '+radius+' ft to claim.'};
  };
  const markerIcon=(kind,label)=>window.L.divIcon({className:'',html:`<div class="tng-map-marker tng-marker-${kind}">${esc(label)}</div>`,iconSize:[30,30],iconAnchor:[15,15]});
  const initMap=()=>{
    if(map||!window.L)return;
    const mapNode=root.querySelector('.tng-live-map');
    if(!mapNode)return;
    map=window.L.map(mapNode,{zoomControl:true,attributionControl:true});
    window.L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,attribution:'&copy; OpenStreetMap contributors'}).addTo(map);
    setTimeout(()=>map&&map.invalidateSize(),100);
  };
  const renderMap=(done,currentIndex)=>{
    initMap();if(!map)return;
    checkpointMarkers.forEach((marker)=>marker.remove());checkpointMarkers=[];
    if(radiusCircle){radiusCircle.remove();radiusCircle=null;}
    const bounds=[];
    (data.stops||[]).forEach((stop,index)=>{
      if(!hasCoords(stop))return;
      const id=String(stop.id),kind=done.has(id)?'done':index===currentIndex?'next':'future',label=done.has(id)?'✓':String(index+1),latLng=[Number(stop.lat),Number(stop.lng)];
      const marker=window.L.marker(latLng,{icon:markerIcon(kind,label)}).addTo(map).bindPopup(`<strong>${esc(stop.title)}</strong><br>${esc(typeLabel(stop.type))}`);
      checkpointMarkers.push(marker);bounds.push(latLng);
      if(index===currentIndex&&stop.type==='gps')radiusCircle=window.L.circle(latLng,{radius:Number(stop.radius||30)/3.28084,color:'#7f56d9',weight:2,fillColor:'#7f56d9',fillOpacity:.12}).addTo(map);
    });
    if(position){
      const latLng=[position.lat,position.lng];
      if(!userMarker)userMarker=window.L.marker(latLng,{icon:markerIcon('you','')}).addTo(map).bindPopup('Your location');else userMarker.setLatLng(latLng);
      if(!accuracyCircle)accuracyCircle=window.L.circle(latLng,{radius:position.accuracy,color:'#2563eb',weight:1,fillColor:'#2563eb',fillOpacity:.08}).addTo(map);else accuracyCircle.setLatLng(latLng).setRadius(position.accuracy);
      bounds.push(latLng);if(mapStatus)mapStatus.textContent='Live · accuracy ±'+feet(position.accuracy)+' ft';
    }else if(mapStatus)mapStatus.textContent=geoError||'Waiting for location';
    if(bounds.length===1)map.setView(bounds[0],16);else if(bounds.length>1)map.fitBounds(bounds,{padding:[35,35],maxZoom:17});
  };
  const startLocation=()=>{
    if(data.adminOverride){geoError='Administrator test override enabled.';render();return;}
    if(!navigator.geolocation){geoError='Location is not supported by this browser.';render();return;}
    if(watchId!==null)return;
    geoError='Locating…';render();
    watchId=navigator.geolocation.watchPosition((result)=>{position={lat:result.coords.latitude,lng:result.coords.longitude,accuracy:result.coords.accuracy};geoError='';render();},(error)=>{geoError=error.code===1?'Location permission was denied.':'Your location could not be determined.';render();},{enableHighAccuracy:true,maximumAge:3000,timeout:15000});
  };
  const render=()=>{
    root.classList.toggle('is-started',state.started);
    if(startButton)startButton.textContent=state.started?'Resume Quest':'Start Quest';
    const done=new Set((state.done||[]).map(String));
    let earned=0;(data.stops||[]).forEach((stop)=>{if(done.has(String(stop.id)))earned+=Number(stop.xp||0);});
    const completeNode=root.querySelector('[data-complete]'),earnedNode=root.querySelector('[data-earned]'),finalNode=root.querySelector('[data-final-xp]');
    if(completeNode)completeNode.textContent=done.size;if(earnedNode)earnedNode.textContent=earned;if(finalNode)finalNode.textContent=earned;
    if(bar)bar.style.width=(data.required?Math.min(100,done.size/data.required*100):0)+'%';
    const currentIndex=(data.stops||[]).findIndex((stop)=>!done.has(String(stop.id)));
    if(dots)dots.innerHTML=(data.stops||[]).map((stop,index)=>`<span class="tng-runtime-dot ${done.has(String(stop.id))?'is-done':''} ${index===currentIndex?'is-current':''}"></span>`).join('');
    const current=currentIndex>=0?data.stops[currentIndex]:null;
    if(next&&current){
      const info=currentInfo(current),statusClass=info.claimable?'tng-location-ready':'tng-location-far';
      next.innerHTML=`<div class="tng-next-label">Next checkpoint · ${currentIndex+1} of ${data.stops.length}</div><h3>${esc(current.title)}</h3><p>${esc(current.instruction||current.arrival||'Reach this checkpoint and complete the activity to continue.')}</p><div class="tng-next-meta"><span class="tng-next-chip">${esc(typeLabel(current.type))}</span><span class="tng-next-chip">${Number(current.xp||0)} XP</span>${current.type==='gps'?`<span class="tng-next-chip">${Number(current.radius||30)} ft radius</span>`:''}</div>${current.hint?`<p><strong>Hint:</strong> ${esc(current.hint)}</p>`:''}<div class="tng-location"><div class="tng-location-row"><div><div class="tng-location-status ${statusClass}">${info.claimable?'Ready to claim':'Location required'}</div><div class="tng-location-detail">${esc(info.text)}${position&&current.type==='gps'?` · GPS accuracy ±${feet(position.accuracy)} ft`:''}</div></div>${current.type==='gps'&&!data.adminOverride?'<button type="button" class="tng-location-button" data-location>Use my location</button>':''}</div></div><div class="tng-next-actions"><button type="button" class="tng-next-claim" data-claim-current="${esc(String(current.id))}" ${info.claimable?'':'disabled'}>Claim checkpoint</button><button type="button" class="tng-next-secondary" data-scroll-journey>View full journey</button></div>`;
    }else if(next)next.innerHTML='<div class="tng-next-label">Journey complete</div><h3>Every required checkpoint is complete.</h3><p>Your adventure has been saved.</p>';
    if(list)list.innerHTML=(data.stops||[]).map((stop,index)=>{const id=String(stop.id),complete=done.has(id),isCurrent=index===currentIndex,locked=!complete&&!isCurrent;return `<article class="tng-runtime-stop ${complete?'is-done':''} ${isCurrent?'is-current':''} ${locked?'is-locked':''}"><span class="tng-runtime-num">${complete?'✓':index+1}</span><div><h4>${esc(stop.title)}</h4><small>${esc(typeLabel(stop.type))} · ${Number(stop.xp||0)} XP</small></div><span class="tng-runtime-state">${complete?'Completed':isCurrent?'Next':'Locked'}</span></article>`;}).join('');
    const completePanel=root.querySelector('.tng-runtime-complete');if(completePanel)completePanel.classList.toggle('is-visible',done.size>=data.required&&data.required>0);
    renderMap(done,currentIndex);
  };

  if(startButton)startButton.addEventListener('click',()=>{state.started=true;persist();startLocation();render();root.scrollIntoView({behavior:'smooth',block:'start'});setTimeout(()=>map&&map.invalidateSize(),250);});
  const exitButton=root.querySelector('.tng-adventure-exit');if(exitButton)exitButton.addEventListener('click',()=>root.classList.remove('is-started'));
  if(next)next.addEventListener('click',(event)=>{if(event.target.closest('[data-location]'))startLocation();const claim=event.target.closest('[data-claim-current]');if(claim&&!claim.disabled){state.done=Array.from(new Set([...(state.done||[]).map(String),String(claim.dataset.claimCurrent)]));persist();if(navigator.vibrate)navigator.vibrate([80,50,120]);}if(event.target.closest('[data-scroll-journey]'))root.querySelector('.tng-journey')?.scrollIntoView({behavior:'smooth'});});
  const resetButton=root.querySelector('.tng-runtime-reset');if(resetButton)resetButton.addEventListener('click',()=>{state={started:true,done:[],status:'in_progress'};persist();});

  const init=async()=>{
    const local=load();apply({started:Boolean(local.started),completedStops:local.done||local.completedStops||[],status:local.status||'not_started'});
    if(data.loggedIn){try{apply(await api('GET'));saveLocal();}catch(error){showError('Account progress could not be loaded. Using device progress.');}}
    render();if(state.started){startLocation();setTimeout(()=>map&&map.invalidateSize(),250);}
  };
  init();
};

const bootAll=()=>document.querySelectorAll('.tng-runtime').forEach(bootRuntime);
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',bootAll,{once:true});else bootAll();
window.addEventListener('load',()=>setTimeout(bootAll,50),{once:true});
})();
