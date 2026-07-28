(()=>{
'use strict';

const root=document.querySelector('.tng-runtime');
const status=root?.querySelector('.tng-runtime-js-status');
const start=root?.querySelector('.tng-runtime-start');
const active=root?.querySelector('.tng-runtime-active');
const reset=root?.querySelector('.tng-runtime-reset');
const list=root?.querySelector('.tng-runtime-checkpoints');
const bar=root?.querySelector('.tng-runtime-progress span');
const completedNode=root?.querySelector('[data-completed]');
const completedSmall=root?.querySelector('[data-completed-small]');
const percentNode=root?.querySelector('[data-percent]');
const nextCard=root?.querySelector('.tng-next-card');
const nextTitle=root?.querySelector('[data-next-title]');
const nextInstruction=root?.querySelector('[data-next-instruction]');
const nextDistance=root?.querySelector('[data-next-distance]');
const nextAction=root?.querySelector('[data-next-action]');
const locate=root?.querySelector('[data-locate]');
const locationStatus=root?.querySelector('.tng-location-status');
const mapNode=root?.querySelector('.tng-runtime-map');
const completion=root?.querySelector('.tng-completion');
const share=root?.querySelector('[data-share]');
const config=window.TNGQuestRuntime||{};

const fail=(message)=>{if(status){status.textContent=message;status.classList.add('is-error');}if(start)start.disabled=true;};
if(!root||!start||!active||!list||!config.storageKey){fail('Quest controls could not initialize. Reload the page or report this runtime error.');return;}

const currentScript=[...document.scripts].find(script=>String(script.src).includes('quest-runtime.js'));
if(currentScript&&!document.querySelector('link[data-tng-living-css]')){
  const link=document.createElement('link');
  link.rel='stylesheet';
  link.dataset.tngLivingCss='1';
  link.href=currentScript.src.replace(/quest-runtime\.js(?:\?.*)?$/,'living-quest.css?ver=1');
  document.head.appendChild(link);
}

const load=()=>{try{return JSON.parse(localStorage.getItem(config.storageKey)||'{}');}catch(error){return {};}};
const saveLocal=(value)=>{try{localStorage.setItem(config.storageKey,JSON.stringify(value));return true;}catch(error){return false;}};
const normalize=(value)=>({started:Boolean(value?.started),completedStops:Array.isArray(value?.completedStops)?value.completedStops.map(String):[],status:value?.status||'not_started',startedAt:value?.startedAt||''});
const escapeHtml=(value)=>String(value??'').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
const decodeTitle=(value)=>{const node=document.createElement('textarea');node.innerHTML=String(value??'');return node.value;};
const typeLabel=(type)=>({manual:'Manual checkpoint',gps:'GPS arrival',photo:'Photo challenge',trivia:'Trivia',qr:'QR code'}[type]||'Checkpoint');
const typeIcon=(type)=>({manual:'✓',gps:'◎',photo:'◉',trivia:'?',qr:'▦'}[type]||'•');
const meters=(a,b)=>{const radius=6371000,p=Math.PI/180,dLat=(b.lat-a.lat)*p,dLng=(b.lng-a.lng)*p,x=Math.sin(dLat/2)**2+Math.cos(a.lat*p)*Math.cos(b.lat*p)*Math.sin(dLng/2)**2;return radius*2*Math.atan2(Math.sqrt(x),Math.sqrt(1-x));};
const distanceLabel=(value)=>value==null?'':value<1000?`${Math.round(value)} m away`:`${(value/1609.344).toFixed(1)} mi away`;
const accuracyInfo=(value)=>value<=10?{label:'Excellent GPS',className:'is-excellent'}:value<=25?{label:'Good GPS',className:'is-good'}:value<=50?{label:'Fair GPS',className:'is-fair'}:{label:'Weak GPS',className:'is-weak'};

let state=normalize(load()),saving=false,position=null,watchId=null,map=null,playerMarker=null,accuracyCircle=null,arrivalCircle=null,checkpointMarkers=[],routeLine=null,detail=null,detailStop=null,sticky=null,toast=null,lastArrivalId='',lastRenderedPercent=0;
const stops=()=>Array.isArray(config.stops)?config.stops:[];
const doneSet=()=>new Set(state.completedStops.map(String));
const currentIndex=()=>stops().findIndex(stop=>!doneSet().has(String(stop.id)));
const currentStop=()=>{const index=currentIndex();return index>=0?stops()[index]:null;};

const api=async(method,body)=>{const response=await fetch(config.progressUrl,{method,credentials:'same-origin',headers:{'Content-Type':'application/json','X-WP-Nonce':config.restNonce||''},body:body?JSON.stringify(body):undefined});if(!response.ok)throw new Error('Progress request failed');return response.json();};
const persist=async()=>{saveLocal(state);if(!config.loggedIn||saving)return;saving=true;try{state=normalize(await api('POST',{started:state.started,completedStops:state.completedStops}));saveLocal(state);status.textContent='Progress synced to your TN Game account.';status.classList.remove('is-error');}catch(error){status.textContent='Progress is saved on this device, but account sync is unavailable.';status.classList.add('is-error');}finally{saving=false;render();}};

const canClaim=(stop)=>{if(config.developer)return true;if(stop.type!=='gps')return true;if(!position||!Number.isFinite(Number(stop.lat))||!Number.isFinite(Number(stop.lng)))return false;return meters(position,{lat:Number(stop.lat),lng:Number(stop.lng)})<=Number(stop.radius||30);};
const claimMessage=(stop)=>{if(stop.type!=='gps'||config.developer)return 'Claim checkpoint';if(!position)return 'Enable location to claim';if(!Number.isFinite(Number(stop.lat))||!Number.isFinite(Number(stop.lng)))return 'Location unavailable';const distance=meters(position,{lat:Number(stop.lat),lng:Number(stop.lng)});return distance<=Number(stop.radius||30)?'Claim checkpoint':distanceLabel(distance);};

const ensureSticky=()=>{
  if(sticky)return sticky;
  sticky=document.createElement('aside');
  sticky.className='tng-sticky-hud';
  sticky.hidden=true;
  sticky.innerHTML='<div class="tng-sticky-copy"><strong data-sticky-title></strong><span data-sticky-meta></span></div><div class="tng-sticky-progress"><span></span></div><button type="button" data-sticky-next>Next stop</button>';
  document.body.appendChild(sticky);
  sticky.querySelector('[data-sticky-next]').addEventListener('click',()=>{const stop=currentStop();if(stop)openDetail(stop);});
  return sticky;
};

const ensureToast=()=>{
  if(toast)return toast;
  toast=document.createElement('div');
  toast.className='tng-xp-toast';
  toast.hidden=true;
  toast.setAttribute('role','status');
  document.body.appendChild(toast);
  return toast;
};

const celebrate=(stop)=>{
  const node=ensureToast();
  node.innerHTML=`<span class="tng-xp-icon">✓</span><div><strong>Checkpoint complete</strong><span>+${Number(stop.xp||0)} XP · ${escapeHtml(decodeTitle(stop.title||'Checkpoint'))}</span></div>`;
  node.hidden=false;
  requestAnimationFrame(()=>node.classList.add('is-visible'));
  window.setTimeout(()=>{node.classList.remove('is-visible');window.setTimeout(()=>{node.hidden=true;},250);},3200);
};

const ensureDetail=()=>{
  if(detail)return detail;
  detail=document.createElement('section');
  detail.className='tng-checkpoint-detail';
  detail.hidden=true;
  detail.setAttribute('aria-modal','true');
  detail.setAttribute('role','dialog');
  detail.innerHTML=`<div class="tng-detail-backdrop" data-detail-close></div><article class="tng-detail-sheet"><button class="tng-detail-close" type="button" data-detail-close aria-label="Close checkpoint">×</button><div class="tng-detail-visual"><div class="tng-detail-icon" data-detail-icon>◎</div><span data-detail-type>Checkpoint</span></div><div class="tng-detail-body"><div class="tng-detail-kicker">CURRENT CHECKPOINT</div><h2 data-detail-title>Checkpoint</h2><div class="tng-detail-meta"><span data-detail-xp>25 XP</span><span data-detail-distance></span><span data-detail-radius></span></div><p class="tng-detail-story" data-detail-story></p><div class="tng-detail-hint" data-detail-hint hidden></div><div class="tng-detail-status" data-detail-status></div><div class="tng-detail-actions"><button type="button" data-detail-locate>Use my location</button><button type="button" data-detail-claim>Claim checkpoint</button></div></div></article>`;
  document.body.appendChild(detail);
  detail.addEventListener('click',event=>{if(event.target.closest('[data-detail-close]'))closeDetail();if(event.target.closest('[data-detail-locate]'))locate?.click();if(event.target.closest('[data-detail-claim]')&&detailStop)claimStop(String(detailStop.id));});
  document.addEventListener('keydown',event=>{if(event.key==='Escape'&&!detail.hidden)closeDetail();});
  return detail;
};

const openDetail=(stop)=>{
  const panel=ensureDetail(),done=doneSet(),index=stops().findIndex(item=>String(item.id)===String(stop.id)),current=index===currentIndex(),complete=done.has(String(stop.id)),locked=!complete&&!current;
  detailStop=stop;
  panel.querySelector('[data-detail-icon]').textContent=complete?'✓':typeIcon(stop.type);
  panel.querySelector('[data-detail-type]').textContent=typeLabel(stop.type);
  panel.querySelector('[data-detail-title]').textContent=decodeTitle(stop.title||'Checkpoint');
  panel.querySelector('[data-detail-xp]').textContent=`${Number(stop.xp||0)} XP reward`;
  panel.querySelector('[data-detail-radius]').textContent=stop.type==='gps'?`${Number(stop.radius||30)} m arrival zone`:'';
  panel.querySelector('[data-detail-story]').textContent=stop.instruction||'Complete this checkpoint to continue your journey.';
  const hint=panel.querySelector('[data-detail-hint]');hint.hidden=!stop.hint;hint.textContent=stop.hint?`Hint: ${stop.hint}`:'';
  const distance=position&&Number.isFinite(Number(stop.lat))&&Number.isFinite(Number(stop.lng))?distanceLabel(meters(position,{lat:Number(stop.lat),lng:Number(stop.lng)})):'';
  panel.querySelector('[data-detail-distance]').textContent=distance;
  const claim=panel.querySelector('[data-detail-claim]');claim.disabled=locked||complete||!canClaim(stop);claim.textContent=complete?'Checkpoint completed':locked?'Complete the previous stop':claimMessage(stop);
  panel.querySelector('[data-detail-status]').textContent=complete?'Completed — reward secured':locked?'Locked until the previous checkpoint is completed':current&&canClaim(stop)?'You have arrived — ready to claim':current?'This is your next checkpoint':'Checkpoint';
  panel.querySelector('[data-detail-locate]').hidden=stop.type!=='gps'||complete;
  panel.classList.toggle('is-complete',complete);panel.classList.toggle('is-locked',locked);panel.classList.toggle('is-arrived',current&&canClaim(stop));panel.hidden=false;document.body.classList.add('tng-detail-open');
};
const closeDetail=()=>{if(!detail)return;detail.hidden=true;detailStop=null;document.body.classList.remove('tng-detail-open');};

const claimStop=(id)=>{
  const index=stops().findIndex(stop=>String(stop.id)===id);
  if(index<0||index!==currentIndex())return;
  const stop=stops()[index];
  if(!canClaim(stop))return;
  state.completedStops=Array.from(new Set([...state.completedStops,id]));
  state.status=state.completedStops.length>=stops().length?'complete':'in_progress';
  lastArrivalId='';
  closeDetail();
  celebrate(stop);
  persist();
  render();
  if(state.status==='complete')completion?.scrollIntoView({behavior:'smooth',block:'center'});
};

const renderSticky=(percent,current)=>{
  const node=ensureSticky();
  node.hidden=!state.started;
  if(!state.started)return;
  node.querySelector('[data-sticky-title]').textContent=config.questTitle||'TN Game Quest';
  const distance=current&&position&&Number.isFinite(Number(current.lat))&&Number.isFinite(Number(current.lng))?distanceLabel(meters(position,{lat:Number(current.lat),lng:Number(current.lng)})):'Location off';
  node.querySelector('[data-sticky-meta]').textContent=current?`${percent}% · ${distance}`:`${percent}% · Quest complete`;
  node.querySelector('.tng-sticky-progress span').style.width=`${percent}%`;
  node.querySelector('[data-sticky-next]').textContent=current?'Next stop':'Complete';
  node.querySelector('[data-sticky-next]').disabled=!current;
};

const renderNext=(current)=>{if(!nextCard)return;if(!current){nextCard.hidden=true;return;}nextCard.hidden=false;nextTitle.textContent=decodeTitle(current.title||'Next checkpoint');nextInstruction.textContent=current.instruction||'Continue to the next checkpoint.';nextDistance.textContent=position&&Number.isFinite(Number(current.lat))&&Number.isFinite(Number(current.lng))?distanceLabel(meters(position,{lat:Number(current.lat),lng:Number(current.lng)})):current.type==='gps'?'Enable location for live distance':'';nextAction.onclick=()=>openDetail(current);};

const render=()=>{
  const allStops=stops(),done=doneSet(),nextIndex=currentIndex(),started=Boolean(state.started),count=done.size,percent=allStops.length?Math.min(100,Math.round((count/allStops.length)*100)):0,isComplete=allStops.length>0&&count>=allStops.length,current=nextIndex>=0?allStops[nextIndex]:null;
  root.classList.toggle('is-started',started);root.classList.toggle('is-complete',isComplete);active.hidden=!started;start.textContent=started?'Resume Quest':'Start Quest';
  if(!status.classList.contains('is-error'))status.textContent=started?(config.loggedIn?'Quest in progress.':'Quest progress saved on this device.'):'Quest controls are ready.';
  if(completedNode)completedNode.textContent=String(count);if(completedSmall)completedSmall.textContent=String(count);if(percentNode)percentNode.textContent=String(percent);if(bar)bar.style.width=percent+'%';if(completion)completion.hidden=!isComplete;
  if(percent!==lastRenderedPercent&&percentNode){percentNode.classList.remove('is-bumping');requestAnimationFrame(()=>percentNode.classList.add('is-bumping'));lastRenderedPercent=percent;}
  renderNext(current);renderSticky(percent,current);
  list.innerHTML=allStops.map((stop,index)=>{const id=String(stop.id),complete=done.has(id),isCurrent=index===nextIndex,locked=!complete&&!isCurrent,claimable=isCurrent&&canClaim(stop),action=isCurrent?`<button type="button" class="tng-checkpoint-claim" data-claim="${escapeHtml(id)}" ${claimable?'':'disabled'}>${escapeHtml(claimMessage(stop))}</button>`:'',distance=position&&Number.isFinite(Number(stop.lat))&&Number.isFinite(Number(stop.lng))?`<span class="tng-checkpoint-distance">${escapeHtml(distanceLabel(meters(position,{lat:Number(stop.lat),lng:Number(stop.lng)})))}</span>`:'';return `<article class="tng-checkpoint ${complete?'is-complete':''} ${isCurrent?'is-current':''} ${locked?'is-locked':''} ${claimable?'is-arrived':''}" data-stop-id="${escapeHtml(id)}"><button class="tng-checkpoint-number" type="button" data-detail="${escapeHtml(id)}">${complete?'✓':index+1}</button><div class="tng-checkpoint-copy"><span class="tng-checkpoint-label">${escapeHtml(typeLabel(stop.type))} · ${Number(stop.xp||0)} XP</span><h3><button type="button" class="tng-checkpoint-title" data-detail="${escapeHtml(id)}">${escapeHtml(decodeTitle(stop.title||'Checkpoint'))}</button></h3><p>${escapeHtml(stop.instruction||'Complete this checkpoint to continue.')}</p>${distance}${stop.hint?`<small><strong>Hint:</strong> ${escapeHtml(stop.hint)}</small>`:''}<button type="button" class="tng-checkpoint-view" data-detail="${escapeHtml(id)}">View checkpoint</button>${action}</div><span class="tng-checkpoint-state">${complete?'Completed':claimable?'Arrived':isCurrent?'Next':'Locked'}</span></article>`;}).join('')||'<div class="tng-runtime-empty">No checkpoints are configured for this quest yet.</div>';
  updateMap();if(detailStop&&!detail?.hidden)openDetail(detailStop);
};

const markerPosition=(stop,index,all)=>{const lat=Number(stop.lat),lng=Number(stop.lng),same=all.filter(item=>Number(item.lat)===lat&&Number(item.lng)===lng);if(same.length<2)return[lat,lng];const place=same.findIndex(item=>String(item.id)===String(stop.id)),angle=(Math.PI*2*place)/same.length,offset=.00018;return[lat+Math.cos(angle)*offset,lng+Math.sin(angle)*offset];};
const initMap=()=>{if(!mapNode||typeof window.L==='undefined'){if(mapNode)mapNode.innerHTML='<div class="tng-map-unavailable">Map could not load. Check your connection and reload.</div>';return;}map=L.map(mapNode,{zoomControl:true}).setView([35.7,-85.5],8);L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,attribution:'&copy; OpenStreetMap contributors'}).addTo(map);updateMap();setTimeout(()=>map?.invalidateSize(),100);};
const updateMap=()=>{
  if(!map)return;
  checkpointMarkers.forEach(marker=>marker.remove());checkpointMarkers=[];
  if(routeLine){routeLine.remove();routeLine=null;}if(arrivalCircle){arrivalCircle.remove();arrivalCircle=null;}
  const done=doneSet(),next=currentIndex(),bounds=[],route=[],all=stops();
  all.forEach((stop,index)=>{const lat=Number(stop.lat),lng=Number(stop.lng);if(!Number.isFinite(lat)||!Number.isFinite(lng))return;const pos=markerPosition(stop,index,all),complete=done.has(String(stop.id)),current=index===next,icon=L.divIcon({className:'',html:`<div class="tng-map-marker ${complete?'is-complete':current?'is-current':'is-locked'} ${current&&canClaim(stop)?'is-arrived':''}">${complete?'✓':index+1}</div>`,iconSize:[38,38],iconAnchor:[19,19]}),marker=L.marker(pos,{icon}).addTo(map).bindPopup(`<strong>${escapeHtml(decodeTitle(stop.title||'Checkpoint'))}</strong><br>${complete?'Completed':current?'Next checkpoint':'Locked'}`);marker.on('click',()=>openDetail(stop));checkpointMarkers.push(marker);bounds.push(pos);route.push(pos);if(current&&stop.type==='gps')arrivalCircle=L.circle([lat,lng],{radius:Number(stop.radius||30),color:canClaim(stop)?'#12b76a':'#7f56d9',fillColor:canClaim(stop)?'#12b76a':'#7f56d9',fillOpacity:.12,weight:2}).addTo(map);});
  if(route.length>1)routeLine=L.polyline(route,{color:'#7f56d9',weight:4,opacity:.72,dashArray:'8 8'}).addTo(map);
  if(position){const icon=L.divIcon({className:'',html:'<div class="tng-player-marker"><span></span></div>',iconSize:[32,32],iconAnchor:[16,16]});if(!playerMarker)playerMarker=L.marker([position.lat,position.lng],{icon}).addTo(map).bindPopup('You are here');else{playerMarker.setLatLng([position.lat,position.lng]);playerMarker.setIcon(icon);}if(!accuracyCircle)accuracyCircle=L.circle([position.lat,position.lng],{radius:position.accuracy,color:'#1677ff',fillColor:'#1677ff',fillOpacity:.08,weight:1}).addTo(map);else accuracyCircle.setLatLng([position.lat,position.lng]).setRadius(position.accuracy);bounds.push([position.lat,position.lng]);}
  if(bounds.length===1)map.setView(bounds[0],15);else if(bounds.length>1)map.fitBounds(bounds,{padding:[40,40],maxZoom:16});map.invalidateSize();
};

const maybeAutoOpenArrival=()=>{const stop=currentStop();if(!stop||stop.type!=='gps'||!canClaim(stop)||lastArrivalId===String(stop.id))return;lastArrivalId=String(stop.id);openDetail(stop);};
const handleLocation=(result)=>{position={lat:result.coords.latitude,lng:result.coords.longitude,accuracy:result.coords.accuracy};const accuracy=accuracyInfo(position.accuracy);locationStatus.innerHTML=`<span class="tng-gps-badge ${accuracy.className}">${accuracy.label}</span> <span>±${Math.round(position.accuracy)} m</span>`;locate.textContent='Location active';locate.classList.add('is-active');render();maybeAutoOpenArrival();};
const locationError=(error)=>{const messages={1:'Location permission was denied.',2:'Your location is currently unavailable.',3:'Location request timed out.'};locationStatus.textContent=messages[error.code]||'Location could not be started.';locate.textContent='Try location again';locate.classList.remove('is-active');};

start.addEventListener('click',()=>{state={...state,started:true,status:'in_progress',startedAt:state.startedAt||new Date().toISOString()};if(!saveLocal(state)){fail('This browser blocked local quest storage. Private browsing restrictions may be active.');return;}persist();render();active.scrollIntoView({behavior:'smooth',block:'start'});setTimeout(()=>map?.invalidateSize(),350);});
list.addEventListener('click',event=>{const detailButton=event.target.closest('[data-detail]');if(detailButton){const stop=stops().find(item=>String(item.id)===String(detailButton.dataset.detail));if(stop)openDetail(stop);return;}const button=event.target.closest('[data-claim]');if(button&&!button.disabled)claimStop(String(button.dataset.claim||''));});
reset?.addEventListener('click',()=>{if(!window.confirm('Reset all progress for this quest on this device?'))return;state=normalize({started:false,completedStops:[],status:'not_started'});lastArrivalId='';try{localStorage.removeItem(config.storageKey);}catch(error){}persist();render();root.scrollIntoView({behavior:'smooth',block:'start'});});
locate?.addEventListener('click',()=>{if(!navigator.geolocation){locationStatus.textContent='Location is not supported by this browser.';return;}locationStatus.textContent='Finding your location…';locate.textContent='Locating…';if(watchId!==null)navigator.geolocation.clearWatch(watchId);watchId=navigator.geolocation.watchPosition(handleLocation,locationError,{enableHighAccuracy:true,maximumAge:3000,timeout:15000});});
share?.addEventListener('click',async()=>{const shareData={title:config.questTitle||'TN Game Quest',text:`I completed ${config.questTitle||'a TN Game quest'} and earned ${Number(config.rewardXp||0)} XP!`,url:window.location.href};try{if(navigator.share)await navigator.share(shareData);else{await navigator.clipboard.writeText(window.location.href);share.textContent='Link copied';}}catch(error){}});

const init=async()=>{if(config.loggedIn){try{state=normalize(await api('GET'));saveLocal(state);}catch(error){status.textContent='Account progress could not be loaded. Using device progress.';status.classList.add('is-error');}}ensureSticky();ensureToast();initMap();render();};
init();
})();