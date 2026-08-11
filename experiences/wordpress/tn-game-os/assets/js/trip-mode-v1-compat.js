(function(){
'use strict';
function hideLegacy(){
    document.body.classList.add('tng-trip-mode-v1-page');
    var root=document.getElementById('tng-trip-mode-v1');
    if(!root)return false;

    var parent=root.parentElement;
    if(parent){
        Array.prototype.forEach.call(parent.children,function(child){
            if(child===root)return;
            child.classList.add('tng-trip-mode-v1-legacy-hidden');
        });
    }

    Array.prototype.forEach.call(document.querySelectorAll('body *'),function(el){
        if(el===root||root.contains(el)||el.classList.contains('tng-trip-mode-v1-legacy-hidden'))return;
        var text=(el.textContent||'').replace(/\s+/g,' ').trim().toUpperCase();
        if(!text)return;
        var style;
        try{style=window.getComputedStyle(el);}catch(e){return;}
        if(style.position!=='fixed'&&style.position!=='sticky')return;
        var looksLikeOldDock=text.indexOf('ACTIVE TRIP')!==-1 && (text.indexOf('TRIP MODE')!==-1||text.indexOf('NEXT:')!==-1);
        if(looksLikeOldDock)el.classList.add('tng-trip-mode-v1-legacy-hidden');
    });
    return true;
}

function run(){
    if(hideLegacy())return;
    var tries=0;
    var timer=setInterval(function(){
        tries++;
        if(hideLegacy()||tries>40)clearInterval(timer);
    },100);
}

if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',run);else run();

var observer=new MutationObserver(function(){hideLegacy();scheduleArrivalDecorate();});
if(document.documentElement)observer.observe(document.documentElement,{childList:true,subtree:true});
setTimeout(function(){observer.disconnect();hideLegacy();},10000);

/* Arrival intelligence ---------------------------------------------------- */
var cfg=window.TNGTripModeV1||{};
var arrivalPlaces=null;
var arrivalPlace=null;
var arrivalGeo=null;
var arrivalWatch=null;
var arrivalTimer=null;
var APPROACHING_MILES=0.50;
var ARRIVED_MILES=0.05;

function normalize(s){return String(s||'').replace(/[’‘]/g,"'").replace(/\s+/g,' ').trim().toLowerCase();}
function milesBetween(a,b){
    if(!a||!b)return Infinity;
    var r=3958.8,rad=Math.PI/180;
    var dLat=(Number(b.lat)-Number(a.lat))*rad,dLng=(Number(b.lng)-Number(a.lng))*rad;
    var la1=Number(a.lat)*rad,la2=Number(b.lat)*rad;
    var h=Math.sin(dLat/2)*Math.sin(dLat/2)+Math.cos(la1)*Math.cos(la2)*Math.sin(dLng/2)*Math.sin(dLng/2);
    return 2*r*Math.asin(Math.sqrt(h));
}
function currentTitle(){
    var h=document.querySelector('#tng-trip-mode-v1 .tng-trip-mode__current-body h2');
    return h?normalize(h.textContent):'';
}
function findCurrentPlace(){
    var title=currentTitle();
    if(!title||!arrivalPlaces)return null;
    var exact=arrivalPlaces.filter(function(p){return normalize(p&&p.title)===title;});
    if(exact.length)return exact[0];
    return null;
}
function loadArrivalPlaces(){
    if(arrivalPlaces||!cfg.placesEndpoint)return Promise.resolve(arrivalPlaces||[]);
    return fetch(cfg.placesEndpoint,{credentials:'same-origin',headers:{'X-WP-Nonce':cfg.restNonce||''}})
        .then(function(r){return r.json();})
        .then(function(d){arrivalPlaces=(d&&d.places)||[];return arrivalPlaces;})
        .catch(function(){arrivalPlaces=[];return arrivalPlaces;});
}
function statusForMiles(mi){
    if(!isFinite(mi))return {key:'locating',label:'Finding your location',detail:'Trip Mode will recognize when you get close.'};
    if(mi<=ARRIVED_MILES)return {key:'arrived',label:"You've arrived",detail:'You are at this stop. Complete it when you are ready.'};
    if(mi<=APPROACHING_MILES)return {key:'approaching',label:'Approaching stop',detail:mi<0.1?'Less than 0.1 mi away':mi.toFixed(1)+' mi away'};
    return {key:'enroute',label:'On the way',detail:mi.toFixed(1)+' mi from this stop'};
}
function ensureArrivalPanel(){
    var body=document.querySelector('#tng-trip-mode-v1 .tng-trip-mode__current-body');
    if(!body)return null;
    var existing=body.querySelector('.tng-trip-mode__arrival');
    if(existing)return existing;
    var eta=body.querySelector('.tng-trip-mode__eta');
    if(!eta)return null;
    var panel=document.createElement('div');
    panel.className='tng-trip-mode__arrival is-locating';
    panel.innerHTML='<span class="tng-trip-mode__arrival-dot"></span><div><small>TRIP STATUS</small><strong>Finding your location</strong><p>Trip Mode will recognize when you get close.</p></div>';
    eta.parentNode.insertBefore(panel,eta);
    return panel;
}
function paintArrival(){
    var panel=ensureArrivalPanel();
    if(!panel)return;
    arrivalPlace=findCurrentPlace();
    var valid=arrivalPlace&&isFinite(Number(arrivalPlace.lat))&&isFinite(Number(arrivalPlace.lng));
    var mi=valid&&arrivalGeo?milesBetween(arrivalGeo,{lat:Number(arrivalPlace.lat),lng:Number(arrivalPlace.lng)}):Infinity;
    var st=statusForMiles(mi);
    panel.className='tng-trip-mode__arrival is-'+st.key;
    var strong=panel.querySelector('strong'),p=panel.querySelector('p');
    if(strong)strong.textContent=st.label;
    if(p)p.textContent=st.detail;
    var complete=document.querySelector('#tng-trip-mode-v1 .tng-trip-mode__complete');
    if(complete){
        complete.classList.toggle('is-arrived',st.key==='arrived');
        complete.textContent=st.key==='arrived'?'Complete stop ✓':'Complete stop';
    }
}
function scheduleArrivalDecorate(){
    clearTimeout(arrivalTimer);
    arrivalTimer=setTimeout(function(){loadArrivalPlaces().then(paintArrival);},80);
}
function startArrivalLocation(){
    if(!navigator.geolocation)return;
    if(arrivalWatch!==null)return;
    navigator.geolocation.getCurrentPosition(function(pos){
        arrivalGeo={lat:pos.coords.latitude,lng:pos.coords.longitude,accuracy:pos.coords.accuracy||0};
        paintArrival();
    },function(){paintArrival();},{enableHighAccuracy:true,timeout:8000,maximumAge:30000});
    arrivalWatch=navigator.geolocation.watchPosition(function(pos){
        arrivalGeo={lat:pos.coords.latitude,lng:pos.coords.longitude,accuracy:pos.coords.accuracy||0};
        paintArrival();
    },function(){},{enableHighAccuracy:true,timeout:15000,maximumAge:15000});
}
function initArrival(){
    loadArrivalPlaces().then(function(){scheduleArrivalDecorate();startArrivalLocation();});
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',initArrival);else initArrival();
})();
