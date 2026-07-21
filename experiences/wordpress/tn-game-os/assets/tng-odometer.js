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
    const config=window.TNGOdometer||{};
    if(!config.ajaxUrl||!config.nonce||!navigator.geolocation) return;

    let sending=false;
    let queuedPosition=null;
    let lastSentAt=0;

    function updateMiles(value){
      document.querySelectorAll('[data-tng-odometer-miles]').forEach(element=>{
        element.textContent=Number(value||0).toFixed(2);
      });
    }

    async function send(position){
      if(sending){
        queuedPosition=position;
        return;
      }

      const now=Date.now();
      if(now-lastSentAt<4000){
        queuedPosition=position;
        return;
      }

      sending=true;
      lastSentAt=now;

      const body=new URLSearchParams();
      body.set('action','tng_odometer_update');
      body.set('nonce',config.nonce);
      body.set('post_id',config.postId);
      body.set('latitude',position.coords.latitude);
      body.set('longitude',position.coords.longitude);
      body.set('accuracy',position.coords.accuracy);
      body.set('client_time',position.timestamp||Date.now());

      try{
        const response=await fetch(config.ajaxUrl,{
          method:'POST',
          credentials:'same-origin',
          headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
          body:body.toString()
        });

        const payload=await response.json();
        if(payload&&payload.success&&payload.data){
          updateMiles(payload.data.miles);

          if(Number(payload.data.xpAwarded)>0){
            document.dispatchEvent(new CustomEvent('tng:xp-earned',{
              detail:{
                amount:Number(payload.data.xpAwarded),
                source:'odometer',
                miles:Number(payload.data.miles)
              }
            }));
          }
        }
      }catch(error){
        // The next valid GPS update will retry naturally.
      }finally{
        sending=false;
        if(queuedPosition){
          const next=queuedPosition;
          queuedPosition=null;
          window.setTimeout(()=>send(next),700);
        }
      }
    }

    navigator.geolocation.watchPosition(
      position=>{
        if(document.hidden) return;
        if(
          !position.coords ||
          !Number.isFinite(position.coords.latitude) ||
          !Number.isFinite(position.coords.longitude)
        ){
          return;
        }

        send(position);
      },
      ()=>{},
      {
        enableHighAccuracy:true,
        maximumAge:2000,
        timeout:15000
      }
    );
  });
})();