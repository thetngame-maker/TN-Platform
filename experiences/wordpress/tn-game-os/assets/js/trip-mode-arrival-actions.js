(function(){
'use strict';
var cfg=window.TNGTripModeV1||{};
if(!cfg.enabled||!cfg.loggedIn)return;
var state=null,placesById={},currentPlace=null;
function esc(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c];});}
function getJson(url){return fetch(url,{credentials:'same-origin',headers:{'X-WP-Nonce':cfg.restNonce||''}}).then(function(r){return r.json();});}
function doneIds(){return ((state&&state.completed)||[]).concat((state&&state.skipped)||[]).map(Number);}
function currentId(){if(!state||!Array.isArray(state.route))return 0;var done=doneIds();for(var i=0;i<state.route.length;i++){var id=Number(state.route[i]);if(done.indexOf(id)<0)return id;}return 0;}
function findCurrent(){var id=currentId();currentPlace=placesById[id]||null;return currentPlace;}
function arrived(){var card=document.querySelector('#tng-trip-mode-v1 .tng-trip-mode__arrival-state');if(!card)return false;return /you.ve arrived/i.test(card.textContent||'')||card.classList.contains('is-arrived');}
function existingPanel(){return document.querySelector('#tng-trip-mode-v1 .tng-trip-mode__arrival-actions');}
function directionsUrl(p){if(!p)return'#';if(isFinite(Number(p.lat))&&isFinite(Number(p.lng)))return 'https://www.google.com/maps/dir/?api=1&destination='+encodeURIComponent(Number(p.lat)+','+Number(p.lng));if(p.address)return 'https://www.google.com/maps/search/?api=1&query='+encodeURIComponent(p.address);return p.url||'#';}
function renderPanel(){var root=document.getElementById('tng-trip-mode-v1');if(!root)return;var old=existingPanel();if(!arrived()){if(old)old.remove();return;}var p=findCurrent();var host=root.querySelector('.tng-trip-mode__current-body');if(!host)return;if(old)return;var panel=document.createElement('section');panel.className='tng-trip-mode__arrival-actions';panel.innerHTML='<div class="tng-trip-mode__arrival-actions-copy"><small>AT THIS STOP</small><strong>You’re here. Ready to check in?</strong><span>Confirm the visit when you’re ready, or open the place page for details.</span></div><div class="tng-trip-mode__arrival-actions-buttons"><button type="button" class="tng-trip-mode__arrival-checkin">Check in</button>'+(p&&p.url?'<a class="tng-trip-mode__arrival-secondary" href="'+esc(p.url)+'">View place</a>':'')+'<a class="tng-trip-mode__arrival-secondary" target="_blank" rel="noopener" href="'+esc(directionsUrl(p))+'">Directions</a></div>';
var eta=host.querySelector('.tng-trip-mode__eta');if(eta&&eta.parentNode)eta.parentNode.insertBefore(panel,eta.nextSibling);else host.appendChild(panel);
var btn=panel.querySelector('.tng-trip-mode__arrival-checkin');if(btn)btn.addEventListener('click',function(){var complete=root.querySelector('.tng-trip-mode__complete');if(complete)complete.click();});
}
function hydrate(){Promise.all([getJson(cfg.stateEndpoint),getJson(cfg.placesEndpoint)]).then(function(res){state=(res[0]&&res[0].state)||null;var list=(res[1]&&res[1].places)||res[1]||[];if(Array.isArray(list))list.forEach(function(p){if(p&&p.id)placesById[Number(p.id)]=p;});findCurrent();renderPanel();}).catch(function(){});}
function boot(){hydrate();var tries=0,timer=setInterval(function(){tries++;renderPanel();if(tries>120)clearInterval(timer);},1000);var observer=new MutationObserver(function(){renderPanel();});observer.observe(document.body,{subtree:true,childList:true,characterData:true,attributes:true,attributeFilter:['class']});setInterval(hydrate,30000);}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',boot);else boot();
})();
