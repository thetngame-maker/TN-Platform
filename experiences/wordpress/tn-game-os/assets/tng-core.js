(function(){
  function icon(t){t=(t||'').toLowerCase();if(t.includes('waterfall'))return'💧';if(t.includes('view'))return'👁️';if(t.includes('historic'))return'🏛️';if(t.includes('cave'))return'🪨';if(t.includes('trailhead'))return'🥾';if(t.includes('parking'))return'🅿️';if(t.includes('swimming'))return'🏊';return'📍'}
  function dist(a,b,c,d){const R=6371000,rad=x=>x*Math.PI/180,dl=rad(c-a),dn=rad(d-b),q=Math.sin(dl/2)**2+Math.cos(rad(a))*Math.cos(rad(c))*Math.sin(dn/2)**2;return R*2*Math.atan2(Math.sqrt(q),Math.sqrt(1-q))*3.28084}
  function marker(s){const e=document.createElement('div');e.className='tng-sight-marker';e.dataset.sightId=s.id;e.innerHTML='<span>'+icon(s.type)+'</span>';return e}
  function esc(v){return String(v??'').replace(/[&<>'"]/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[m]))}

  function popup(s,c,claimed){
    const image=s.image?'<div class="tng-claim-disc '+(claimed?'is-claimed':'')+'"><img src="'+esc(s.image)+'" alt=""></div>':'<div class="tng-claim-disc '+(claimed?'is-claimed':'')+'"><span>'+icon(s.type)+'</span></div>';
    let action='';
    if(c.game){
      if(claimed){
        action='<div class="tng-popup-claimed">✓ Claimed</div>';
        if(c.devMode){
          action+='<button class="tng-popup-reset-btn" type="button" data-unclaim-id="'+esc(s.id)+'">Reset checkpoint</button>';
        }
      }
      else if(!c.loggedIn){action='<a class="tng-popup-claim-btn" href="'+esc(c.loginUrl)+'">Sign in to claim</a>'}
      else{action='<button class="tng-popup-claim-btn" type="button" data-claim-id="'+esc(s.id)+'" disabled>Activate location first</button><div class="tng-popup-distance" data-distance-id="'+esc(s.id)+'"></div>'}
    }
    return '<div class="tng-popup tng-game-popup">'+image+(s.type?'<div class="tng-popup-type">'+esc(s.type)+'</div>':'')+'<h3>'+esc(s.title)+'</h3>'+(s.description?'<p>'+esc(s.description)+'</p>':'')+(s.points?'<div class="tng-popup-points">+'+esc(s.points)+' Explorer XP</div>':'')+action+(s.url?'<a class="tng-popup-link" href="'+esc(s.url)+'">View details</a>':'')+'</div>';
  }

  async function serverClaim(c,s,position){
    const body=new FormData();
    body.append('action','tng_core_claim_checkpoint');
    body.append('nonce',c.nonce);
    body.append('checkpoint_id',s.id);
    body.append('activity_id',c.postId||0);
    body.append('latitude',position.coords.latitude);
    body.append('longitude',position.coords.longitude);
    const response=await fetch(c.ajaxUrl,{method:'POST',credentials:'same-origin',body});
    const json=await response.json();
    if(!json.success)throw new Error(json.data&&json.data.message?json.data.message:'Unable to claim checkpoint.');
    return json.data;
  }

  async function serverUnclaim(c,s){
    const body=new FormData();
    body.append('action','tng_core_unclaim_checkpoint');
    body.append('nonce',c.nonce);
    body.append('checkpoint_id',s.id);
    body.append('activity_id',c.postId||0);
    const response=await fetch(c.ajaxUrl,{method:'POST',credentials:'same-origin',body});
    const json=await response.json();
    if(!json.success)throw new Error(json.data&&json.data.message?json.data.message:'Unable to reset checkpoint.');
    return json.data;
  }

  function init(c){
    if(!c||!c.mapId||!c.token||!c.gpxUrl)return;
    if(c.devMode)document.body.classList.add('tng-dev-mode');
    mapboxgl.accessToken=c.token;
    const claimed=new Set((c.claimedIds||[]).map(String));
    let score=parseInt(c.score||0,10), latestPosition=null;
    const se=document.getElementById(c.mapId+'-score'),me=document.getElementById(c.mapId+'-message');
    if(se)se.textContent=score;

    const map=new mapboxgl.Map({container:c.mapId,style:c.style||'mapbox://styles/mapbox/outdoors-v12',center:[-85.74761,35.25178],zoom:c.zoom||13});
    window.TNGMapInstances=window.TNGMapInstances||{};
    window.TNGMapInstances[c.mapId]=map;
    map.once('load',()=>document.dispatchEvent(new CustomEvent('tng:map-ready',{detail:{mapId:c.mapId,map:map}})));
    const popupBySight=new Map();
    let activeSight=null,activeCardTimer=null;
    let cardOverlay=null,cardPanel=null;
    let autoOpenedSightId=null;
    let routePoints=[];
    let devMarker=null;
    let devPanel=null;
    let devPlayTimer=null;
    let devIndex=0;
    let devOdometerEnabled=false;
    let devOdometerLastPoint=null;
    let devOdometerSending=false;
    let devEditMode=false;
    let devShowAll=true;
    const devSightMarkers=new Map();
    map.addControl(new mapboxgl.NavigationControl(),'top-right');
    const geo=new mapboxgl.GeolocateControl({positionOptions:{enableHighAccuracy:true},trackUserLocation:true,showUserHeading:true});
    map.addControl(geo,'top-right');

    function collected(id){document.querySelectorAll('.tng-sight-marker[data-sight-id="'+id+'"]').forEach(e=>e.classList.add('tng-sight-collected'))}
    function uncollected(id){document.querySelectorAll('.tng-sight-marker[data-sight-id="'+id+'"]').forEach(e=>e.classList.remove('tng-sight-collected'))}
    function nearestMessage(position){
      if(!c.game||!Array.isArray(c.sights))return;
      let lat=position.coords.latitude,lng=position.coords.longitude,r=c.radiusFeet||30,n=null,nd=1e9;
      c.sights.forEach(s=>{
        if(!s||isNaN(s.lat)||isNaN(s.lng)||claimed.has(String(s.id)))return;
        let d=dist(lat,lng,s.lat,s.lng);
        if(d<nd){nd=d;n=s}
      });
      if(!n){
        if(me){me.classList.add('tng-game-message-success');me.innerHTML='All available checkpoints on this trail have been claimed.'}
        return;
      }
      if(nd<=r){
        if(me){me.classList.remove('tng-game-message-success');me.innerHTML='Within range of '+esc(n.title)+' — claim card opened.'}
        if(autoOpenedSightId!==String(n.id)){
          autoOpenedSightId=String(n.id);
          openCheckpointCard(n);
        }
      }else{
        autoOpenedSightId=null;
        if(me){me.classList.remove('tng-game-message-success');me.innerHTML='Nearest sight: '+esc(n.title)+' — '+Math.round(nd)+' ft away'}
      }
    }
    function setGamePosition(p,source){
      if(!p||!p.coords)return;
      latestPosition=p;
      nearestMessage(p);
      if(c.devMode&&source==='simulator'){
        const lngLat=[p.coords.longitude,p.coords.latitude];
        if(!devMarker){
          const el=document.createElement('div');
          el.className='tng-dev-location-marker';
          el.innerHTML='<span></span>';
          devMarker=new mapboxgl.Marker({element:el,anchor:'center'}).setLngLat(lngLat).addTo(map);
        }else devMarker.setLngLat(lngLat);
      }
    }
    function updatePosition(p){setGamePosition(p,'device')}
    geo.on('geolocate',updatePosition);
    if(c.game&&!c.devMode&&navigator.geolocation)navigator.geolocation.watchPosition(updatePosition,e=>{if(me)me.innerHTML='Location error: '+esc(e.message)},{enableHighAccuracy:true,maximumAge:0,timeout:10000});

    function simulatedPosition(point){
      return {coords:{latitude:point[1],longitude:point[0],accuracy:3,altitude:point.length>2?point[2]:null,altitudeAccuracy:5,heading:null,speed:0},timestamp:Date.now()};
    }
    function nearestRouteIndex(lng,lat){
      let best=0,bd=Infinity;
      routePoints.forEach((p,i)=>{const d=dist(lat,lng,p[1],p[0]);if(d<bd){bd=d;best=i}});
      return best;
    }

    function updateOdometerDisplays(miles){
      document.querySelectorAll('[data-tng-odometer-miles]').forEach(el=>{
        el.textContent=Number(miles||0).toFixed(2);
      });
    }
    async function submitSimulatedOdometerSegment(fromPoint,toPoint){
      if(!devOdometerEnabled||devOdometerSending||!c.canTestOdometer)return;
      if(!fromPoint||!toPoint)return;

      const segmentFeet=dist(fromPoint[1],fromPoint[0],toPoint[1],toPoint[0]);
      if(!Number.isFinite(segmentFeet)||segmentFeet<1)return;

      devOdometerSending=true;
      const status=devPanel&&devPanel.querySelector('[data-dev-odometer-status]');
      if(status)status.textContent='Adding simulated distance…';

      const body=new URLSearchParams();
      body.set('action',c.simulatorOdometerAction||'tng_simulator_odometer_update');
      body.set('nonce',c.nonce);
      body.set('post_id',c.postId);
      body.set('segment_feet',Math.min(segmentFeet,2640).toFixed(2));

      try{
        const response=await fetch(c.ajaxUrl,{
          method:'POST',
          credentials:'same-origin',
          headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
          body:body.toString()
        });
        const payload=await response.json();

        if(payload&&payload.success&&payload.data){
          updateOdometerDisplays(payload.data.miles);
          if(status){
            status.textContent='Simulated miles: '+Number(payload.data.miles).toFixed(2)+
              (Number(payload.data.xpAwarded)>0?' · +'+payload.data.xpAwarded+' XP':'');
          }
          if(Number(payload.data.xpAwarded)>0){
            document.dispatchEvent(new CustomEvent('tng:xp-earned',{
              detail:{
                amount:Number(payload.data.xpAwarded),
                source:'simulator-odometer',
                miles:Number(payload.data.miles)
              }
            }));
          }
        }else if(status){
          status.textContent='Simulator odometer update failed.';
        }
      }catch(error){
        if(status)status.textContent='Simulator odometer update failed.';
      }finally{
        devOdometerSending=false;
      }
    }

    function moveDevTo(index,center){
      if(!routePoints.length)return;
      const previousPoint=devOdometerLastPoint;
      devIndex=Math.max(0,Math.min(routePoints.length-1,Math.round(index)));
      const p=routePoints[devIndex];
      setGamePosition(simulatedPosition(p),'simulator');
      if(devOdometerEnabled){
        if(previousPoint)submitSimulatedOdometerSegment(previousPoint,p);
        devOdometerLastPoint=p;
      }else{
        devOdometerLastPoint=null;
      }
      if(center)map.easeTo({center:[p[0],p[1]],zoom:Math.max(map.getZoom(),16),duration:350});
      if(devPanel){
        const slider=devPanel.querySelector('[data-dev-slider]');
        if(slider)slider.value=devIndex;
        const pos=devPanel.querySelector('[data-dev-position]');
        if(pos)pos.textContent=(devIndex+1)+' / '+routePoints.length+' · '+p[1].toFixed(6)+', '+p[0].toFixed(6);
      }
    }
    function stopDevPlay(){
      if(devPlayTimer){clearInterval(devPlayTimer);devPlayTimer=null}
      if(devPanel){const b=devPanel.querySelector('[data-dev-play]');if(b)b.textContent='Play'}
    }
    function toggleDevPlay(){
      if(devPlayTimer){stopDevPlay();return}
      if(!routePoints.length)return;
      const speed=parseInt(devPanel.querySelector('[data-dev-speed]').value||500,10);
      const step=parseInt(devPanel.querySelector('[data-dev-step]').value||5,10);
      const b=devPanel.querySelector('[data-dev-play]');if(b)b.textContent='Pause';
      devPlayTimer=setInterval(()=>{
        if(devIndex>=routePoints.length-1){stopDevPlay();return}
        moveDevTo(devIndex+step,true);
      },speed);
    }
    async function saveDevSightPosition(s,marker){
      const ll=marker.getLngLat();
      const body=new FormData();
      body.append('action',c.editorSaveAction||'tng_core_editor_save_sight');
      body.append('nonce',c.nonce);
      body.append('post_id',s.id);
      body.append('lat',ll.lat.toFixed(7));
      body.append('lng',ll.lng.toFixed(7));
      if(devPanel){const n=devPanel.querySelector('[data-dev-save-status]');if(n)n.textContent='Saving '+s.title+'…'}
      try{
        const response=await fetch(c.ajaxUrl,{method:'POST',credentials:'same-origin',body});
        const json=await response.json();
        if(!json.success)throw new Error(json.data&&json.data.message?json.data.message:'Save failed.');
        s.lat=ll.lat;s.lng=ll.lng;
        if(devPanel){
          const n=devPanel.querySelector('[data-dev-save-status]');if(n)n.textContent='Saved '+s.title;
          setTimeout(()=>{if(n)n.textContent=''},1800);
        }
        if(latestPosition)nearestMessage(latestPosition);
      }catch(err){
        marker.setLngLat([s.lng,s.lat]);
        if(devPanel){const n=devPanel.querySelector('[data-dev-save-status]');if(n)n.textContent=err.message}
      }
    }
    function editorPopup(s,related){
      return '<div class="tng-dev-editor-popup"><strong>'+esc(s.title)+'</strong><span>'+esc(s.type||'Top Sight')+'</span><code>'+Number(s.lat).toFixed(6)+', '+Number(s.lng).toFixed(6)+'</code>'+(related?'<em>Trail checkpoint</em>':'<em>Other Top Sight</em>')+(s.editUrl?'<a href="'+esc(s.editUrl)+'" target="_blank" rel="noopener">Edit post</a>':'')+'</div>';
    }
    function registerDevMarker(s,marker,related){
      devSightMarkers.set(String(s.id),{s,marker,related});
      marker.setDraggable(devEditMode);
      marker.on('dragend',()=>saveDevSightPosition(s,marker));
    }
    function applyDevEditorState(){
      devSightMarkers.forEach(entry=>{
        entry.marker.setDraggable(devEditMode);
        if(!entry.related){
          const el=entry.marker.getElement();
          el.style.display=devShowAll?'flex':'none';
        }
      });
      if(devPanel){
        const note=devPanel.querySelector('[data-dev-edit-note]');
        if(note)note.textContent=devEditMode?'Drag markers to save new coordinates.':'Turn on Edit positions to drag markers.';
      }
    }
    function focusDevSight(id,teleport){
      const entry=devSightMarkers.get(String(id));
      if(!entry)return;
      const s=entry.s;
      entry.marker.getElement().style.display='flex';
      map.easeTo({center:[s.lng,s.lat],zoom:Math.max(map.getZoom(),17),duration:350});
      if(teleport&&routePoints.length){moveDevTo(nearestRouteIndex(s.lng,s.lat),true)}
      if(entry.related)openCheckpointCard(s);
      else{
        const popup=entry.marker.getPopup();
        if(popup){try{entry.marker.togglePopup()}catch(e){}}
      }
    }

    function buildDevSimulator(){
      if(!c.devMode||!c.game||!routePoints.length||document.getElementById(c.mapId+'-dev-simulator'))return;
      devPanel=document.createElement('section');
      devPanel.id=c.mapId+'-dev-simulator';
      devPanel.className='tng-dev-simulator';
      const devSightList=(c.devAllSights&&c.devAllSights.length?c.devAllSights:c.sights||[]);
      const sightOptions=devSightList.map(s=>'<option value="'+esc(s.id)+'">'+esc(s.title)+'</option>').join('');
      devPanel.innerHTML='<div class="tng-dev-head"><strong>Developer Route Simulator</strong><button type="button" data-dev-collapse>−</button></div>'+
        '<div class="tng-dev-body"><div class="tng-dev-position" data-dev-position></div>'+
        '<input data-dev-slider type="range" min="0" max="'+(routePoints.length-1)+'" value="0" step="1">'+
        '<div class="tng-dev-controls"><button type="button" data-dev-prev>◀ Rewind</button><button type="button" data-dev-play>Play</button><button type="button" data-dev-next>Forward ▶</button></div>'+
        '<div class="tng-dev-options"><label>Step<select data-dev-step><option value="1">1 point</option><option value="5" selected>5 points</option><option value="10">10 points</option><option value="25">25 points</option></select></label>'+
        '<label>Speed<select data-dev-speed><option value="1000">Slow</option><option value="500" selected>Normal</option><option value="200">Fast</option><option value="75">Very fast</option></select></label></div>'+
        '<div class="tng-dev-editor-tools"><label><input type="checkbox" data-dev-edit> Edit marker positions</label><label><input type="checkbox" data-dev-show-all checked> Show all Top Sights</label></div>'+
        (c.canTestOdometer
          ? '<div class="tng-dev-odometer-test"><label><input type="checkbox" data-dev-odometer> Count simulated miles and award XP</label><div data-dev-odometer-status>Testing is off.</div></div>'
          : '')+
        '<div class="tng-dev-teleport"><select data-dev-sight><option value="">Select Top Sight…</option>'+sightOptions+'</select><button type="button" data-dev-center>Center</button><button type="button" data-dev-teleport>Teleport</button></div>'+
        '<div class="tng-dev-save-status" data-dev-save-status></div><div class="tng-dev-note" data-dev-edit-note>Turn on Edit positions to drag markers.</div>'+
        '<div class="tng-dev-note">Admin-only tools. Odometer testing is off unless you explicitly enable it.</div></div>';
      document.body.appendChild(devPanel);
      devPanel.querySelector('[data-dev-collapse]').addEventListener('click',()=>{
        devPanel.classList.toggle('is-collapsed');
        devPanel.querySelector('[data-dev-collapse]').textContent=devPanel.classList.contains('is-collapsed')?'+':'−';
      });
      const odometerToggle=devPanel.querySelector('[data-dev-odometer]');
      if(odometerToggle){
        odometerToggle.addEventListener('change',()=>{
          devOdometerEnabled=odometerToggle.checked;
          devOdometerLastPoint=devOdometerEnabled&&routePoints[devIndex]
            ? routePoints[devIndex]
            : null;
          const status=devPanel.querySelector('[data-dev-odometer-status]');
          if(status){
            status.textContent=devOdometerEnabled
              ? 'Testing is on. Simulator movement will add real Miles and XP to this admin account.'
              : 'Testing is off.';
          }
        });
      }
      devPanel.querySelector('[data-dev-slider]').addEventListener('input',e=>{stopDevPlay();moveDevTo(parseInt(e.target.value,10),true)});
      devPanel.querySelector('[data-dev-prev]').addEventListener('click',()=>{stopDevPlay();const step=parseInt(devPanel.querySelector('[data-dev-step]').value||5,10);moveDevTo(devIndex-step,true)});
      devPanel.querySelector('[data-dev-next]').addEventListener('click',()=>{stopDevPlay();const step=parseInt(devPanel.querySelector('[data-dev-step]').value||5,10);moveDevTo(devIndex+step,true)});
      devPanel.querySelector('[data-dev-play]').addEventListener('click',toggleDevPlay);
      devPanel.querySelector('[data-dev-edit]').addEventListener('change',e=>{devEditMode=!!e.target.checked;applyDevEditorState()});
      devPanel.querySelector('[data-dev-show-all]').addEventListener('change',e=>{devShowAll=!!e.target.checked;applyDevEditorState()});
      devPanel.querySelector('[data-dev-center]').addEventListener('click',()=>{const id=devPanel.querySelector('[data-dev-sight]').value;if(id)focusDevSight(id,false)});
      devPanel.querySelector('[data-dev-teleport]').addEventListener('click',()=>{stopDevPlay();const id=devPanel.querySelector('[data-dev-sight]').value;if(id)focusDevSight(id,true)});
      applyDevEditorState();
      moveDevTo(0,false);
    }

    function ensureCheckpointCard(){
      if(cardOverlay)return;
      cardOverlay=document.createElement('div');
      cardOverlay.className='tng-checkpoint-overlay';
      cardOverlay.setAttribute('aria-hidden','true');
      cardOverlay.innerHTML='<div class="tng-checkpoint-backdrop" data-card-close></div><section class="tng-checkpoint-card" role="dialog" aria-modal="true" aria-label="Checkpoint"><button type="button" class="tng-checkpoint-close" data-card-close aria-label="Close">×</button><div class="tng-checkpoint-card-body"></div></section>';
      document.body.appendChild(cardOverlay);
      cardPanel=cardOverlay.querySelector('.tng-checkpoint-card-body');
      cardOverlay.querySelectorAll('[data-card-close]').forEach(el=>el.addEventListener('click',closeCheckpointCard));
      document.addEventListener('keydown',e=>{if(e.key==='Escape'&&cardOverlay.classList.contains('is-open'))closeCheckpointCard()});
    }

    function closeCheckpointCard(){
      if(!cardOverlay)return;
      cardOverlay.classList.remove('is-open');
      cardOverlay.setAttribute('aria-hidden','true');
      activeSight=null;
      if(activeCardTimer){clearInterval(activeCardTimer);activeCardTimer=null}
    }

    function refreshCheckpointCard(){
      if(!activeSight||!cardPanel)return;
      const s=activeSight;
      const btn=cardPanel.querySelector('[data-claim-id="'+s.id+'"]');
      const distanceEl=cardPanel.querySelector('[data-distance-id="'+s.id+'"]');
      if(!btn)return;
      if(claimed.has(String(s.id))){
        btn.disabled=true;btn.textContent='✓ Claimed';btn.classList.add('is-claimed');
        if(distanceEl)distanceEl.textContent='';
        return;
      }
      if(!latestPosition){
        btn.disabled=true;btn.textContent='Activate location first';
        if(distanceEl)distanceEl.textContent='';
        return;
      }
      const d=dist(latestPosition.coords.latitude,latestPosition.coords.longitude,s.lat,s.lng);
      const within=d<=(c.radiusFeet||30);
      btn.disabled=!within;
      btn.textContent=within?'Spin to Claim':'Move closer to claim';
      btn.classList.toggle('is-ready',within);
      if(distanceEl)distanceEl.textContent=Math.round(d)+' ft away · must be within '+(c.radiusFeet||30)+' ft';
    }

    function bindCheckpointClaim(s){
      if(!cardPanel)return;
      const btn=cardPanel.querySelector('[data-claim-id="'+s.id+'"]');
      if(!btn)return;
      btn.addEventListener('click',async()=>{
        if(!latestPosition||btn.disabled)return;
        btn.disabled=true;btn.textContent='Claiming…';
        const disc=cardPanel.querySelector('.tng-claim-disc');
        if(disc)disc.classList.add('is-spinning');
        try{
          const reward=await serverClaim(c,s,latestPosition);
          claimed.add(String(s.id));
          score=parseInt(reward.new_balance||score,10);
          if(se)se.textContent=score;
          collected(s.id);
          if(disc){disc.classList.remove('is-spinning');disc.classList.add('is-claimed')}
          cardPanel.innerHTML=popup(s,c,true);
          bindCheckpointReset(s);
          if(me){
            let message='🎉 '+esc(s.title)+' claimed! +'+reward.points_awarded+' Explorer XP';
            if(reward.trail_completed&&!reward.trail_already_completed){
              message+=' · 🥾 Trail completed! +'+reward.trail_points_awarded+' Explorer XP';
            }
            me.innerHTML=message;
            me.classList.add('tng-game-message-success')
          }
          autoOpenedSightId=null;
          setTimeout(()=>{
            closeCheckpointCard();
            if(latestPosition)nearestMessage(latestPosition);
          },900);
        }catch(err){
          if(disc)disc.classList.remove('is-spinning');
          btn.disabled=false;btn.textContent=err.message;
          setTimeout(refreshCheckpointCard,1800);
        }
      });
    }

    function bindCheckpointReset(s){
      if(!c.devMode||!cardPanel)return;
      const btn=cardPanel.querySelector('[data-unclaim-id="'+s.id+'"]');
      if(!btn)return;

      btn.addEventListener('click',async()=>{
        if(btn.disabled)return;
        btn.disabled=true;
        btn.textContent='Resetting…';

        try{
          const result=await serverUnclaim(c,s);
          claimed.delete(String(s.id));
          score=parseInt(result.new_balance||0,10);
          if(se)se.textContent=score;
          uncollected(s.id);

          cardPanel.innerHTML=popup(s,c,false);
          refreshCheckpointCard();
          bindCheckpointClaim(s);

          if(me){
            let message='🧪 '+esc(s.title)+' reset for testing';
            if(result.trail_reset){
              message+=' · Trail completion reset';
            }
            me.innerHTML=message;
            me.classList.remove('tng-game-message-success');
            me.classList.add('tng-game-message-dev');
          }

          autoOpenedSightId=null;
        }catch(err){
          btn.disabled=false;
          btn.textContent=err.message;
          window.setTimeout(()=>{btn.textContent='Reset checkpoint'},1800);
        }
      });
    }

    function openCheckpointCard(s){
      ensureCheckpointCard();
      activeSight=s;
      cardPanel.innerHTML=popup(s,c,claimed.has(String(s.id)));
      cardOverlay.classList.add('is-open');
      cardOverlay.setAttribute('aria-hidden','false');
      refreshCheckpointCard();
      bindCheckpointClaim(s);
      bindCheckpointReset(s);
      if(activeCardTimer)clearInterval(activeCardTimer);
      activeCardTimer=setInterval(refreshCheckpointCard,1000);
    }

    map.on('load',()=>{
      if(c.game&&!c.devMode)setTimeout(()=>{try{geo.trigger()}catch(e){}},900);
      fetch(c.gpxUrl).then(r=>{if(!r.ok)throw new Error('Could not load GPX file.');return r.text()}).then(txt=>{
        const gpx=new DOMParser().parseFromString(txt,'application/xml'),gj=toGeoJSON.gpx(gpx),bounds=new mapboxgl.LngLatBounds();let st=null,en=null;
        map.addSource('trail-route',{type:'geojson',data:gj});
        map.addLayer({id:'trail-route-line',type:'line',source:'trail-route',paint:{'line-width':5,'line-color':'#ff7a00'}});
        gj.features.forEach(f=>{let g=f.geometry;if(!g)return;function ex(co){if(!co||co.length<2)return;bounds.extend(co);if(!st)st=co;en=co;routePoints.push(co)}if(g.type==='LineString')g.coordinates.forEach(ex);if(g.type==='MultiLineString')g.coordinates.forEach(l=>l.forEach(ex));if(g.type==='Point')ex(g.coordinates)});
        if(Array.isArray(c.sights))c.sights.forEach(s=>{
          if(!s||isNaN(s.lat)||isNaN(s.lng))return;bounds.extend([s.lng,s.lat]);let el=marker(s);if(claimed.has(String(s.id)))el.classList.add('tng-sight-collected');
          popupBySight.set(String(s.id),s);
          const sightMarker=new mapboxgl.Marker({element:el,anchor:'bottom',draggable:false}).setLngLat([s.lng,s.lat]).addTo(map);
          el.addEventListener('click',e=>{e.preventDefault();e.stopPropagation();openCheckpointCard(s)});
          if(c.devMode)registerDevMarker(s,sightMarker,true)
        });
        if(c.devMode&&Array.isArray(c.devAllSights)){
          const relatedIds=new Set((c.sights||[]).map(s=>String(s.id)));
          c.devAllSights.forEach(s=>{
            if(!s||relatedIds.has(String(s.id))||isNaN(s.lat)||isNaN(s.lng))return;
            const el=marker(s);el.classList.add('tng-dev-editor-marker');
            const pop=new mapboxgl.Popup({offset:25,maxWidth:'280px'}).setHTML(editorPopup(s,false));
            const m=new mapboxgl.Marker({element:el,anchor:'bottom',draggable:false}).setLngLat([s.lng,s.lat]).setPopup(pop).addTo(map);
            registerDevMarker(s,m,false);
          });
        }
        applyDevEditorState();
        if(!bounds.isEmpty())map.fitBounds(bounds,{padding:55,maxZoom:15});
        if(st)new mapboxgl.Marker({color:'#22c55e'}).setLngLat(st).setPopup(new mapboxgl.Popup().setText('Start')).addTo(map);
        if(en)new mapboxgl.Marker({color:'#ef4444'}).setLngLat(en).setPopup(new mapboxgl.Popup().setText('Finish')).addTo(map);
        if(c.devMode)buildDevSimulator();
      }).catch(e=>{let el=document.getElementById(c.mapId);if(el)el.innerHTML='<div class="tng-map-error-inner">Map error: '+esc(e.message)+'</div>';console.error(e)})
    })
  }
  function boot(){if(!window.TNGTrailMaps||!Array.isArray(window.TNGTrailMaps))return;window.TNGTrailMaps.forEach(init)}
  document.readyState==='loading'?document.addEventListener('DOMContentLoaded',boot):boot()
})();
