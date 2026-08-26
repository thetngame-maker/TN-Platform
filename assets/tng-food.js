(function(){
  'use strict';

  function ready(callback){
    if(document.readyState==='loading'){
      document.addEventListener('DOMContentLoaded',callback,{once:true});
    }else{
      callback();
    }
  }

  ready(()=>{
    const config=window.TNGFood||{};
    const root=document.querySelector('.tng-food-experience');
    if(!root)return;

    const mapElement=root.querySelector('[data-tng-food-map]');
    const status=root.querySelector('[data-tng-food-status]');
    const checkinButton=root.querySelector('[data-tng-food-checkin]');

    function setStatus(message,type){
      if(!status)return;
      status.textContent=message||'';
      status.dataset.type=type||'';
    }

    if(
      mapElement &&
      window.mapboxgl &&
      config.mapboxToken &&
      Number(config.latitude) &&
      Number(config.longitude)
    ){
      mapboxgl.accessToken=config.mapboxToken;
      const map=new mapboxgl.Map({
        container:mapElement,
        style:config.mapboxStyle||'mapbox://styles/mapbox/outdoors-v12',
        center:[Number(config.longitude),Number(config.latitude)],
        zoom:15
      });
      map.addControl(new mapboxgl.NavigationControl(),'top-right');

      const markerElement=document.createElement('div');
      markerElement.className='tng-food-map-marker';
      markerElement.textContent='🍴';

      new mapboxgl.Marker({element:markerElement})
        .setLngLat([Number(config.longitude),Number(config.latitude)])
        .setPopup(new mapboxgl.Popup({offset:22}).setText(config.title||'Restaurant'))
        .addTo(map);
    }else if(mapElement){
      mapElement.innerHTML='<div class="tng-food-map-empty">Add coordinates and a Mapbox token to display the restaurant map.</div>';
    }

    if(checkinButton){
      checkinButton.addEventListener('click',()=>{
        if(!config.isLoggedIn){
          window.location.href=config.loginUrl;
          return;
        }
        if(!navigator.geolocation){
          setStatus('This device does not support GPS check-ins.','error');
          return;
        }

        checkinButton.disabled=true;
        setStatus('Checking your location…','working');

        navigator.geolocation.getCurrentPosition(async position=>{
          const body=new URLSearchParams();
          body.set('action','tng_food_checkin');
          body.set('nonce',config.nonce||'');
          body.set('post_id',config.postId||'');
          body.set('latitude',position.coords.latitude);
          body.set('longitude',position.coords.longitude);
          body.set('accuracy',position.coords.accuracy);

          try{
            const response=await fetch(config.ajaxUrl,{
              method:'POST',
              credentials:'same-origin',
              headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
              body:body.toString()
            });
            const payload=await response.json();

            if(payload&&payload.success){
              const data=payload.data||{};
              checkinButton.textContent='✓ Checked In';
              checkinButton.disabled=true;
              setStatus(data.message||'Restaurant completed!','success');

              document.querySelectorAll('.tng-progress-row').forEach(row=>{
                const label=row.querySelector('.tng-progress-row-label');
                if(label&&label.textContent.trim()==='Restaurants'){
                  const value=row.querySelector('strong');
                  if(value&&data.restaurants!==undefined)value.textContent=data.restaurants;
                }
              });

              if(Number(data.xpAwarded)>0){
                document.dispatchEvent(new CustomEvent('tng:xp-earned',{
                  detail:{amount:Number(data.xpAwarded),source:'restaurant-checkin'}
                }));
              }
            }else{
              checkinButton.disabled=false;
              setStatus((payload.data&&payload.data.message)||'Check-in failed.','error');
            }
          }catch(error){
            checkinButton.disabled=false;
            setStatus('Check-in failed. Please try again.','error');
          }
        },error=>{
          checkinButton.disabled=false;
          setStatus(
            error.code===1
              ? 'Location permission is required to check in.'
              : 'Unable to determine your location.',
            'error'
          );
        },{
          enableHighAccuracy:true,
          maximumAge:2000,
          timeout:15000
        });
      });
    }
  });
})();