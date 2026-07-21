(function(){
  'use strict';

  function esc(value){
    const div=document.createElement('div');
    div.textContent=value==null?'':String(value);
    return div.innerHTML;
  }

  function haversineFeet(a,b){
    const R=6371000;
    const rad=value=>value*Math.PI/180;
    const dLat=rad(b.lat-a.lat);
    const dLng=rad(b.lng-a.lng);
    const q=Math.sin(dLat/2)**2+
      Math.cos(rad(a.lat))*Math.cos(rad(b.lat))*Math.sin(dLng/2)**2;
    return R*2*Math.atan2(Math.sqrt(q),Math.sqrt(1-q))*3.28084;
  }

  function parseGpx(text){
    const xml=new DOMParser().parseFromString(text,'application/xml');
    if(xml.querySelector('parsererror')) throw new Error('The GPX file could not be parsed.');

    let nodes=Array.from(xml.querySelectorAll('trkpt[lat][lon]'));
    if(!nodes.length) nodes=Array.from(xml.querySelectorAll('rtept[lat][lon]'));
    if(!nodes.length) nodes=Array.from(xml.querySelectorAll('wpt[lat][lon]'));

    const raw=nodes.map(node=>{
      const elevation=node.querySelector('ele');
      return {
        lat:parseFloat(node.getAttribute('lat')),
        lng:parseFloat(node.getAttribute('lon')),
        elevation:elevation?parseFloat(elevation.textContent)*3.28084:null
      };
    }).filter(point=>
      Number.isFinite(point.lat)&&
      Number.isFinite(point.lng)&&
      Number.isFinite(point.elevation)
    );

    if(raw.length<2) throw new Error('The GPX does not contain enough elevation points.');

    let feet=0;
    return raw.map((point,index)=>{
      if(index>0) feet+=haversineFeet(raw[index-1],point);
      return {
        lat:point.lat,
        lng:point.lng,
        distance:feet/5280,
        elevation:point.elevation
      };
    });
  }

  function nearestIndex(points,distance){
    let best=0;
    let difference=Infinity;
    points.forEach((point,index)=>{
      const current=Math.abs(point.distance-distance);
      if(current<difference){
        difference=current;
        best=index;
      }
    });
    return best;
  }

  function getMap(root){
    const mapElement=root.querySelector('.tng-trail-map');
    if(!mapElement) return null;
    return window.TNGMapInstances&&window.TNGMapInstances[mapElement.id]
      ? window.TNGMapInstances[mapElement.id]
      : null;
  }

  function createMovingMarker(root,point){
    const map=getMap(root);
    if(!map||!window.mapboxgl) return;

    let marker=root._tngElevationMarker;
    const lngLat=[point.lng,point.lat];

    if(!marker){
      const element=document.createElement('div');
      element.className='tng-elevation-map-marker';
      element.innerHTML='<span></span>';
      marker=new mapboxgl.Marker({element,anchor:'center'})
        .setLngLat(lngLat)
        .addTo(map);
      root._tngElevationMarker=marker;
    }else{
      marker.setLngLat(lngLat);
    }

    map.easeTo({
      center:lngLat,
      duration:250,
      essential:true
    });
  }


  function smoothElevations(points,windowSize){
    const radius=Math.max(1,Math.floor(windowSize/2));

    return points.map((point,index)=>{
      let total=0;
      let count=0;

      for(let offset=-radius;offset<=radius;offset++){
        const candidate=points[index+offset];
        if(!candidate) continue;
        total+=candidate.elevation;
        count++;
      }

      return total/Math.max(1,count);
    });
  }

  function calculateElevationGain(points){
    /*
     * GPX elevation often contains small GPS fluctuations. A short moving
     * average plus a small positive-change threshold gives a much more useful
     * hiking gain figure than adding every one-foot fluctuation.
     */
    const smoothed=smoothElevations(points,7);
    let gain=0;

    for(let index=1;index<smoothed.length;index++){
      const increase=smoothed[index]-smoothed[index-1];
      if(increase>=2) gain+=increase;
    }

    return Math.max(0,Math.round(gain));
  }

  function populateCalculatedTrailStats(root,points){
    const gain=calculateElevationGain(points);
    const gainElement=root.querySelector(
      '[data-tng-trail-stat="gain"] strong'
    );

    if(gainElement && gain>0){
      gainElement.textContent=gain.toLocaleString()+' ft';
      gainElement.setAttribute('data-tng-calculated-from-gpx','1');
    }
  }

  function chart(root,canvas,readout,points){
    let active=null;

    const min=Math.min(...points.map(point=>point.elevation));
    const max=Math.max(...points.map(point=>point.elevation));
    const distanceMax=Math.max(.01,points[points.length-1].distance);

    function draw(){
      const rect=canvas.getBoundingClientRect();
      const width=Math.max(280,rect.width);
      const height=Math.max(190,rect.height);
      const ratio=window.devicePixelRatio||1;
      canvas.width=Math.round(width*ratio);
      canvas.height=Math.round(height*ratio);

      const context=canvas.getContext('2d');
      context.setTransform(ratio,0,0,ratio,0,0);

      const pad={top:18,right:15,bottom:31,left:15};
      const chartWidth=width-pad.left-pad.right;
      const chartHeight=height-pad.top-pad.bottom;
      const elevationRange=Math.max(1,max-min);

      const x=point=>pad.left+(point.distance/distanceMax)*chartWidth;
      const y=point=>pad.top+chartHeight-((point.elevation-min)/elevationRange)*chartHeight;

      context.clearRect(0,0,width,height);
      context.strokeStyle='#e5e7eb';
      context.lineWidth=1;

      for(let i=0;i<=3;i++){
        const yy=pad.top+(chartHeight/3)*i;
        context.beginPath();
        context.moveTo(pad.left,yy);
        context.lineTo(width-pad.right,yy);
        context.stroke();
      }

      context.fillStyle='#6b7280';
      context.font='12px system-ui,-apple-system,BlinkMacSystemFont,sans-serif';
      context.textAlign='center';
      context.fillText('0 mi',pad.left,height-8);
      context.fillText(distanceMax.toFixed(1)+' mi',width-pad.right,height-8);

      const gradient=context.createLinearGradient(0,pad.top,0,pad.top+chartHeight);
      gradient.addColorStop(0,'rgba(34,197,94,.32)');
      gradient.addColorStop(1,'rgba(34,197,94,.04)');

      context.beginPath();
      points.forEach((point,index)=>{
        if(index===0) context.moveTo(x(point),y(point));
        else context.lineTo(x(point),y(point));
      });
      context.lineTo(x(points[points.length-1]),pad.top+chartHeight);
      context.lineTo(x(points[0]),pad.top+chartHeight);
      context.closePath();
      context.fillStyle=gradient;
      context.fill();

      context.beginPath();
      points.forEach((point,index)=>{
        if(index===0) context.moveTo(x(point),y(point));
        else context.lineTo(x(point),y(point));
      });
      context.strokeStyle='#16a34a';
      context.lineWidth=4;
      context.lineJoin='round';
      context.lineCap='round';
      context.stroke();

      if(active!==null){
        const point=points[active];
        const px=x(point);
        const py=y(point);

        context.strokeStyle='rgba(17,24,39,.28)';
        context.lineWidth=1;
        context.beginPath();
        context.moveTo(px,pad.top);
        context.lineTo(px,pad.top+chartHeight);
        context.stroke();

        context.fillStyle='#fff';
        context.strokeStyle='#111827';
        context.lineWidth=3;
        context.beginPath();
        context.arc(px,py,5,0,Math.PI*2);
        context.fill();
        context.stroke();
      }
    }

    function activate(event){
      const rect=canvas.getBoundingClientRect();
      const clientX=event.touches&&event.touches[0]
        ? event.touches[0].clientX
        : event.clientX;

      const left=15;
      const right=15;
      const width=Math.max(1,rect.width-left-right);
      const local=Math.max(0,Math.min(width,clientX-rect.left-left));
      const distance=(local/width)*distanceMax;
      active=nearestIndex(points,distance);

      const point=points[active];
      readout.innerHTML=
        '<strong>'+point.distance.toFixed(2)+' mi</strong>'+
        '<span>'+Math.round(point.elevation)+' ft elevation</span>';

      createMovingMarker(root,point);
      draw();
    }

    canvas.addEventListener('mousemove',activate);
    canvas.addEventListener('click',activate);
    canvas.addEventListener('touchstart',activate,{passive:true});
    canvas.addEventListener('touchmove',activate,{passive:true});
    canvas.addEventListener('mouseleave',()=>{
      active=null;
      readout.innerHTML=
        '<strong>Touch or hover on the graph</strong>'+
        '<span>The marker will move along the trail map</span>';
      draw();
    });

    window.addEventListener('resize',draw);
    requestAnimationFrame(draw);
  }


  function hideTravelerDescription(root){
    const heading=Array.from(document.querySelectorAll('h1,h2,h3,h4,h5,h6'))
      .find(item=>item.textContent.trim().toLowerCase()==='about this activity');

    if(!heading) return;

    heading.style.display='none';
    heading.setAttribute('data-tng-hidden-description','heading');

    Array.from(document.querySelectorAll(
      'p,.st-description,.st-description-wrapper,.st-content,.st-hr,hr'
    )).forEach(item=>{
      if(item===root || item.contains(root) || root.contains(item)) return;

      const afterHeading=Boolean(
        heading.compareDocumentPosition(item) & Node.DOCUMENT_POSITION_FOLLOWING
      );
      const beforeCard=Boolean(
        item.compareDocumentPosition(root) & Node.DOCUMENT_POSITION_FOLLOWING
      );

      if(!afterHeading || !beforeCard) return;

      const text=item.textContent.trim();
      if(!text && !item.matches('hr,.st-hr')) return;

      item.style.display='none';
      item.setAttribute('data-tng-hidden-description','content');
    });
  }

  function boot(){
    document.querySelectorAll('.tng-trail-experience').forEach(root=>{
      if(root.dataset.tngExperienceBooted==='1') return;
      root.dataset.tngExperienceBooted='1';

      let data;
      try{
        data=JSON.parse(root.getAttribute('data-trail-experience')||'{}');
      }catch(error){
        return;
      }

      if(data.hideTravelerDescription){
        hideTravelerDescription(root);
      }

      const canvas=root.querySelector('.tng-trail-profile-canvas');
      const readout=root.querySelector('.tng-trail-profile-readout');
      if(!canvas||!readout||!data.gpxUrl) return;

      fetch(data.gpxUrl,{credentials:'same-origin'})
        .then(response=>{
          if(!response.ok) throw new Error('Could not load the GPX file.');
          return response.text();
        })
        .then(parseGpx)
        .then(points=>{
          populateCalculatedTrailStats(root,points);
          chart(root,canvas,readout,points);
        })
        .catch(error=>{
          readout.innerHTML=
            '<strong>Elevation profile unavailable</strong>'+
            '<span>'+esc(error.message)+'</span>';
        });
    });
  }

  if(document.readyState==='loading'){
    document.addEventListener('DOMContentLoaded',boot);
  }else{
    boot();
  }
})();