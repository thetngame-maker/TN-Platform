(function($){
'use strict';

async function post(data){
  const response=await fetch(TNG_OS_ADMIN.ajaxUrl,{
    method:'POST',
    credentials:'same-origin',
    headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
    body:new URLSearchParams(data).toString()
  });
  return response.json();
}

function palette(){
  return document.querySelector('[data-tng-command-palette]');
}

function openPalette(){
  const node=palette();
  if(!node)return;
  node.hidden=false;
  document.body.classList.add('tng-os-command-open');
  const input=node.querySelector('[data-tng-command-input]');
  input.value='';
  renderCommands('');
  window.setTimeout(()=>input.focus(),20);
}

function closePalette(){
  const node=palette();
  if(!node)return;
  node.hidden=true;
  document.body.classList.remove('tng-os-command-open');
}

let selectedIndex=0;

function renderCommands(query){
  const container=document.querySelector('[data-tng-command-results]');
  if(!container)return;

  const normalized=String(query||'').toLowerCase().trim();
  const commands=(TNG_OS_ADMIN.commands||[]).filter(command=>{
    const haystack=[command.title,command.description,command.group,command.keywords].join(' ').toLowerCase();
    return !normalized||haystack.includes(normalized);
  }).slice(0,40);

  selectedIndex=0;
  let currentGroup='';
  let html='';

  commands.forEach((command,index)=>{
    if(command.group!==currentGroup){
      currentGroup=command.group;
      html+='<div class="tng-os-command-group">'+escapeHtml(currentGroup)+'</div>';
    }
    html+='<a class="tng-os-command-item '+(index===0?'is-selected':'')+'" data-command-index="'+index+'" href="'+escapeHtml(command.url)+'">'+
      '<span class="dashicons '+escapeHtml(command.icon||'dashicons-admin-generic')+'"></span>'+
      '<div><strong>'+escapeHtml(command.title)+'</strong><small>'+escapeHtml(command.description||'')+'</small></div><i>↵</i></a>';
  });

  if(!commands.length)html='<div class="tng-os-empty">No matching tools.</div>';
  container.innerHTML=html;
}

function escapeHtml(value){
  return String(value??'').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
}

function moveSelection(direction){
  const items=Array.from(document.querySelectorAll('.tng-os-command-item'));
  if(!items.length)return;
  items[selectedIndex]?.classList.remove('is-selected');
  selectedIndex=(selectedIndex+direction+items.length)%items.length;
  items[selectedIndex].classList.add('is-selected');
  items[selectedIndex].scrollIntoView({block:'nearest'});
}


function sidebarFocusEnabled(){
  const key=TNG_OS_ADMIN.focusMenuStorageKey||'tng_os_focus_sidebar';
  const saved=window.localStorage.getItem(key);
  if(saved===null)return TNG_OS_ADMIN.focusMenuDefault!==false;
  return saved==='1';
}

function setSidebarFocus(enabled){
  const key=TNG_OS_ADMIN.focusMenuStorageKey||'tng_os_focus_sidebar';
  window.localStorage.setItem(key,enabled?'1':'0');
  document.body.classList.toggle('tng-os-focused-sidebar',enabled);

  document.querySelectorAll('[data-tng-sidebar-toggle]').forEach(button=>{
    button.textContent=enabled?'Show WordPress Menu':'Focus TN Game OS';
    button.setAttribute('aria-pressed',enabled?'true':'false');
  });
}

function installSidebarToggle(){
  const menu=document.querySelector('#adminmenu');
  if(!menu||menu.querySelector('.tng-os-sidebar-toggle-item'))return;

  const item=document.createElement('li');
  item.className='tng-os-sidebar-toggle-item';
  item.innerHTML='<button type="button" data-tng-sidebar-toggle></button>';
  menu.appendChild(item);

  item.querySelector('button').addEventListener('click',()=>{
    setSidebarFocus(!document.body.classList.contains('tng-os-focused-sidebar'));
  });
}

function cleanSidebarVisually(){
  const visible=new Set(TNG_OS_ADMIN.visibleMenuPages||[]);
  const osMenu=document.querySelector('#toplevel_page_tn-game-os');

  if(osMenu){
    osMenu.querySelectorAll('.wp-submenu a').forEach(link=>{
      let page='';
      try{
        page=new URL(link.href,window.location.href).searchParams.get('page')||'';
      }catch(e){}

      const item=link.closest('li');
      if(item&&!visible.has(page)){
        item.hidden=true;
        item.setAttribute('aria-hidden','true');
      }
    });
  }

  (TNG_OS_ADMIN.legacyParentSlugs||[]).forEach(slug=>{
    const legacy=document.querySelector('#toplevel_page_'+CSS.escape(slug));
    if(legacy){
      legacy.hidden=true;
      legacy.setAttribute('aria-hidden','true');
    }
  });

  installSidebarToggle();
  setSidebarFocus(sidebarFocusEnabled());
}

$(function(){
  cleanSidebarVisually();
  $('[data-test-google]').on('click',async function(){
    const output=$('[data-google-result]').text('Testing…');
    try{
      const payload=await post({action:'tng_os_test_google',nonce:TNG_OS_ADMIN.googleNonce,key:$('#tng-os-google_places_key').val(),place_id:$('#tng-os-google_test_place_id').val()});
      output.text(payload.data&&payload.data.message?payload.data.message:'Completed.').css('color',payload.success?'#15803d':'#b91c1c');
    }catch(e){output.text('Connection test failed.').css('color','#b91c1c')}
  });

  $('[data-test-mapbox]').on('click',async function(){
    const output=$('[data-mapbox-result]').text('Testing…');
    try{
      const payload=await post({action:'tng_os_test_mapbox',nonce:TNG_OS_ADMIN.mapboxNonce,token:$('#tng-os-mapbox_token').val()});
      output.text(payload.data&&payload.data.message?payload.data.message:'Completed.').css('color',payload.success?'#15803d':'#b91c1c');
    }catch(e){output.text('Connection test failed.').css('color','#b91c1c')}
  });

  $(document).on('click','.tng-os-open-command,.tng-os-command-trigger>a',function(event){
    event.preventDefault();
    openPalette();
  });

  $(document).on('click','[data-tng-command-close]',closePalette);

  $(document).on('input','[data-tng-command-input]',function(){
    renderCommands(this.value);
  });

  $(document).on('keydown',function(event){
    const modifier=navigator.platform.toLowerCase().includes('mac')?event.metaKey:event.ctrlKey;
    if(modifier&&event.key.toLowerCase()==='k'){
      event.preventDefault();
      palette()&&!palette().hidden?closePalette():openPalette();
      return;
    }

    const node=palette();
    if(!node||node.hidden)return;

    if(event.key==='Escape'){event.preventDefault();closePalette()}
    if(event.key==='ArrowDown'){event.preventDefault();moveSelection(1)}
    if(event.key==='ArrowUp'){event.preventDefault();moveSelection(-1)}
    if(event.key==='Enter'){
      const selected=document.querySelector('.tng-os-command-item.is-selected');
      if(selected){event.preventDefault();window.location.href=selected.href}
    }
  });

  $(document).on('mousemove','.tng-os-command-item',function(){
    $('.tng-os-command-item').removeClass('is-selected');
    $(this).addClass('is-selected');
    selectedIndex=Number(this.dataset.commandIndex||0);
  });

  $(document).on('click','.tng-os-dismiss-notice',async function(){
    const article=this.closest('[data-notice-id]');
    if(!article)return;
    const id=article.dataset.noticeId;
    article.style.opacity='.45';
    try{
      const payload=await post({action:'tng_os_dismiss_notice',nonce:TNG_OS_ADMIN.dismissNonce,id:id});
      if(payload.success)article.remove();else article.style.opacity='1';
    }catch(e){article.style.opacity='1'}
  });
});
})(jQuery);