(function(){
'use strict';
const cfg=window.TNGDiscoverySearch||{};
let timer=null;

function decodeEntities(value){
  const area=document.createElement('textarea');
  area.innerHTML=String(value??'');
  return area.value;
}

function escapeHtml(value){
  return decodeEntities(value).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
}

async function request(path){
  const response=await fetch(cfg.rest+path,{
    credentials:'same-origin',
    headers:{'X-WP-Nonce':cfg.nonce||''}
  });
  if(!response.ok)throw new Error('Search could not be loaded.');
  return response.json();
}

function params(root,limit){
  const form=root.querySelector('[data-tng-discovery-form]');
  const data=new FormData(form);
  const active=root.querySelector('[data-tng-category].is-active');
  const query=new URLSearchParams({
    q:String(data.get('q')||''),
    destination:String(data.get('destination')||''),
    when:String(data.get('when')||''),
    category:active?active.dataset.tngCategory:'all',
    limit:String(limit||12)
  });
  return query;
}

function resultCard(item){
  return '<a class="tng-discovery-result" href="'+escapeHtml(item.url)+'">'+
    '<div class="tng-discovery-result-image">'+
      (item.image?'<img src="'+escapeHtml(item.image)+'" alt="">':'<span>⌖</span>')+
    '</div><div class="tng-discovery-result-copy">'+
      '<span>'+escapeHtml(item.type)+(item.destination?' · '+escapeHtml(item.destination):'')+'</span>'+
      '<strong>'+escapeHtml(item.title)+'</strong>'+
      (item.excerpt?'<p>'+escapeHtml(item.excerpt)+'</p>':'')+
    '</div></a>';
}

async function performSearch(root){
  const results=root.querySelector('[data-tng-discovery-results]');
  const grid=root.querySelector('[data-tng-results-grid]');
  const empty=root.querySelector('[data-tng-results-empty]');
  const title=root.querySelector('[data-tng-results-title]');
  const query=root.querySelector('[data-tng-discovery-query]').value.trim();

  results.hidden=false;
  empty.hidden=true;
  grid.innerHTML='<div class="tng-discovery-empty"><strong>Searching TN Game OS…</strong></div>';
  title.textContent=query?'Results for “'+decodeEntities(query)+'”':'Explore South Cumberland';

  try{
    const data=await request('search?'+params(root,16).toString());
    grid.innerHTML=data.items.map(resultCard).join('');
    empty.hidden=data.items.length>0;
  }catch(error){
    grid.innerHTML='';
    empty.hidden=false;
    empty.querySelector('strong').textContent=error.message;
  }

  results.scrollIntoView({behavior:'smooth',block:'nearest'});
}

async function suggestions(root){
  const field=root.querySelector('[data-tng-discovery-query]');
  const box=root.querySelector('[data-tng-discovery-suggestions]');
  const value=field.value.trim();

  if(value.length<2){box.hidden=true;box.innerHTML='';return}

  try{
    const data=await request('search?'+params(root,6).toString());
    box.innerHTML=data.items.map(item=>
      '<a href="'+escapeHtml(item.url)+'">'+
        (item.image?'<img src="'+escapeHtml(item.image)+'" alt="">':'<span class="tng-discovery-suggestion-icon"></span>')+
        '<span><strong>'+escapeHtml(item.title)+'</strong><small>'+escapeHtml(item.type)+(item.destination?' · '+escapeHtml(item.destination):'')+'</small></span>'+
      '</a>'
    ).join('');
    box.hidden=!data.items.length;
  }catch(e){box.hidden=true}
}

function init(root){
  if(root.dataset.tngDiscoveryReady)return;
  root.dataset.tngDiscoveryReady='1';

  const form=root.querySelector('[data-tng-discovery-form]');
  const query=root.querySelector('[data-tng-discovery-query]');

  form.addEventListener('submit',event=>{
    event.preventDefault();
    performSearch(root);
  });

  query.addEventListener('input',()=>{
    window.clearTimeout(timer);
    timer=window.setTimeout(()=>suggestions(root),240);
  });

  root.addEventListener('click',async event=>{
    const category=event.target.closest('[data-tng-category]');
    if(category){
      root.querySelectorAll('[data-tng-category]').forEach(button=>button.classList.remove('is-active'));
      category.classList.add('is-active');
      performSearch(root);
      return;
    }

    if(event.target.closest('[data-tng-close-results]')){
      root.querySelector('[data-tng-discovery-results]').hidden=true;
      return;
    }

    const near=event.target.closest('[data-tng-near-search]');
    if(near){
      if(!navigator.geolocation){
        near.textContent='Location unavailable';
        return;
      }
      near.disabled=true;
      near.textContent='Finding nearby…';
      navigator.geolocation.getCurrentPosition(position=>{
        const url='/wp-json/tng-os/v1/destinations/nearby?lat='+
          encodeURIComponent(position.coords.latitude)+'&lng='+encodeURIComponent(position.coords.longitude);
        fetch(url).then(r=>r.json()).then(data=>{
          const item=(data.destinations&&data.destinations[0])||(data.experiences&&data.experiences[0]);
          if(item&&item.url)window.location.href=item.url;
          else{
            near.disabled=false;
            near.innerHTML='<span>◎</span> Nothing mapped nearby';
          }
        }).catch(()=>{
          near.disabled=false;
          near.innerHTML='<span>◎</span> Near me';
        });
      },()=>{
        near.disabled=false;
        near.innerHTML='<span>◎</span> Location blocked';
      },{enableHighAccuracy:true,timeout:12000,maximumAge:60000});
      return;
    }

    const surprise=event.target.closest('[data-tng-surprise]');
    if(surprise){
      surprise.disabled=true;
      try{
        const data=await request('surprise');
        window.location.href=data.url;
      }catch(e){
        surprise.disabled=false;
      }
    }
  });

  document.addEventListener('click',event=>{
    if(!root.contains(event.target)){
      const box=root.querySelector('[data-tng-discovery-suggestions]');
      if(box)box.hidden=true;
    }
  });
}

function autoReplace(){
  if(!cfg.autoReplace||!cfg.shortcodeHtml||document.querySelector('[data-tng-discovery-auto-mounted]'))return;

  const selectors=[
    '.st-search-form-wrapper',
    '.st-search-form',
    '.search-form-wrapper.st-search-form',
    '.search-form-wrapper[data-service]',
    '.search-form-mobile',
    '.home .search-form-wrapper',
    '.home .st-search-form'
  ];

  let original=null;
  for(const selector of selectors){
    const candidates=[...document.querySelectorAll(selector)].filter(el=>{
      const rect=el.getBoundingClientRect();
      return rect.width>500||el.querySelector('[name="check_in"],[name="check_out"],[name="adult_number"],[name="room_num_search"]');
    });
    if(candidates.length){original=candidates[0];break}
  }

  if(!original)return;

  const host=document.createElement('div');
  host.className='tng-discovery-auto-host';
  host.dataset.tngDiscoveryAutoMounted='1';
  host.innerHTML=cfg.shortcodeHtml;

  original.classList.add('tng-discovery-original-hidden');
  original.insertAdjacentElement('afterend',host);
  host.querySelectorAll('[data-tng-discovery-search]').forEach(init);
}

document.addEventListener('DOMContentLoaded',()=>{
  document.querySelectorAll('[data-tng-discovery-search]').forEach(init);
  autoReplace();
  window.setTimeout(autoReplace,800);
});
})();