(function(){
  const $=s=>document.querySelector(s);
  let map,markers=[],routeLayer=null,sights=[];

  function ajax(action,data={}){
    const body=new URLSearchParams({action,nonce:TNGCoreEditor.nonce,...data});
    return fetch(TNGCoreEditor.ajaxUrl,{
      method:'POST',
      credentials:'same-origin',
      headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
      body
    }).then(r=>r.json()).then(j=>{
      if(!j.success) throw new Error(j.data?.message||'Request failed.');
      return j.data;
    });
  }

  function status(text,error=false){
    const el=$('#tng-core-editor-status');
    el.textContent=text;
    el.classList.toggle('is-error',error);
  }

  function parseGpx(text){
    const doc=new DOMParser().parseFromString(text,'application/xml');
    let nodes=[...doc.querySelectorAll('trkpt[lat][lon]')];
    if(!nodes.length) nodes=[...doc.querySelectorAll('rtept[lat][lon]')];
    return nodes.map(n=>[
      parseFloat(n.getAttribute('lat')),
      parseFloat(n.getAttribute('lon'))
    ]).filter(p=>Number.isFinite(p[0])&&Number.isFinite(p[1]));
  }

  function popupHtml(item){
    return `<strong>${item.title}</strong><br>
      ${item.type||'Top Sight'}<br>
      <code>${item.lat.toFixed(6)}, ${item.lng.toFixed(6)}</code><br>
      <a href="${item.editUrl}" target="_blank" rel="noopener">Edit Top Sight</a>`;
  }

  function saveMarker(item,marker){
    const ll=marker.getLatLng();
    status('Saving '+item.title+'…');

    ajax('tng_core_editor_save_sight',{
      post_id:item.id,
      lat:ll.lat.toFixed(7),
      lng:ll.lng.toFixed(7)
    }).then(()=>{
      item.lat=ll.lat;
      item.lng=ll.lng;
      marker.setPopupContent(popupHtml(item));
      renderList(currentFiltered());
      status('Saved '+item.title);
      setTimeout(()=>status(''),1800);
    }).catch(e=>{
      status(e.message,true);
      marker.setLatLng([item.lat,item.lng]);
    });
  }

  function drawSights(list){
    markers.forEach(x=>map.removeLayer(x.marker));
    markers=[];

    list.forEach(item=>{
      const marker=L.marker([item.lat,item.lng],{draggable:true}).addTo(map);
      marker.bindPopup(popupHtml(item));
      marker.on('dragend',()=>saveMarker(item,marker));
      marker.on('click',()=>{
        map.panTo(marker.getLatLng());
        highlightList(item.id);
      });
      markers.push({item,marker});
    });

    renderList(list);
  }

  function renderList(list){
    const root=$('#tng-core-editor-list');
    root.innerHTML=list.map(item=>`
      <button class="tng-core-editor-row" data-id="${item.id}">
        <strong>${item.title}</strong>
        <span>${item.type||'Top Sight'}</span>
        <small>${item.lat.toFixed(5)}, ${item.lng.toFixed(5)}</small>
      </button>
    `).join('');

    root.querySelectorAll('[data-id]').forEach(btn=>{
      btn.addEventListener('click',()=>{
        const id=Number(btn.dataset.id);
        const found=markers.find(x=>x.item.id===id);
        if(!found) return;
        map.setView(found.marker.getLatLng(),Math.max(map.getZoom(),16));
        found.marker.openPopup();
        highlightList(id);
      });
    });
  }

  function highlightList(id){
    document.querySelectorAll('.tng-core-editor-row').forEach(el=>{
      el.classList.toggle('is-active',Number(el.dataset.id)===Number(id));
    });
  }

  function fitAll(){
    if(!markers.length) return;
    const group=L.featureGroup(markers.map(x=>x.marker));
    map.fitBounds(group.getBounds().pad(.15));
  }

  function currentFiltered(){
    const q=$('#tng-core-editor-search').value.trim().toLowerCase();
    return !q?sights:sights.filter(s=>
      s.title.toLowerCase().includes(q)||(s.type||'').toLowerCase().includes(q)
    );
  }

  function filterSights(){
    drawSights(currentFiltered());
  }

  function loadTrail(gpxUrl){
    if(routeLayer){
      map.removeLayer(routeLayer);
      routeLayer=null;
    }
    if(!gpxUrl) return;

    status('Loading GPX route…');
    fetch(gpxUrl).then(r=>{
      if(!r.ok) throw new Error('Could not load GPX.');
      return r.text();
    }).then(parseGpx).then(points=>{
      if(!points.length) throw new Error('No route points found.');
      routeLayer=L.polyline(points,{color:'#ff6b00',weight:5,opacity:.9}).addTo(map);
      map.fitBounds(routeLayer.getBounds().pad(.12));
      status('');
    }).catch(e=>status(e.message,true));
  }

  function init(){
    map=L.map('tng-core-editor-map').setView([35.25,-85.75],11);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{
      maxZoom:20,
      attribution:'&copy; OpenStreetMap contributors'
    }).addTo(map);

    Promise.all([
      ajax('tng_core_editor_get_sights'),
      ajax('tng_core_editor_get_trails')
    ]).then(([sightData,trailData])=>{
      sights=sightData;
      drawSights(sights);
      fitAll();

      const select=$('#tng-core-editor-trail');
      trailData.forEach(t=>{
        const opt=document.createElement('option');
        opt.value=t.gpxUrl;
        opt.textContent=t.title;
        select.appendChild(opt);
      });
      select.addEventListener('change',()=>loadTrail(select.value));
    }).catch(e=>status(e.message,true));

    $('#tng-core-editor-search').addEventListener('input',filterSights);
    $('#tng-core-editor-fit').addEventListener('click',fitAll);
  }

  document.addEventListener('DOMContentLoaded',init);
})();