(function($){
'use strict';

let dirty=false;
let mediaFrame=null;

function activateTab(id){
  document.querySelectorAll('[data-ds-tab]').forEach(button=>{
    button.classList.toggle('is-active',button.dataset.dsTab===id);
  });
  document.querySelectorAll('[data-ds-panel]').forEach(panel=>{
    panel.classList.toggle('is-active',panel.dataset.dsPanel===id);
  });
  window.history.replaceState(null,'','#'+id);
  window.scrollTo({top:0,behavior:'smooth'});
}

function updateCoordinates(){
  const lat=document.querySelector('[data-lat]')?.value.trim()||'';
  const lng=document.querySelector('[data-lng]')?.value.trim()||'';
  const output=document.querySelector('[data-map-coordinate]');
  if(output)output.textContent=lat&&lng?lat+', '+lng:'Coordinates not set';
}

function updateTitle(){
  const title=document.querySelector('input[name="post_title"]')?.value||'Untitled Destination';
  document.querySelectorAll('[data-sidebar-title]').forEach(node=>node.textContent=title);
}

function markDirty(){
  dirty=true;
}

function clearDirty(){
  dirty=false;
}

$(function(){
  const studio=document.querySelector('[data-destination-studio]');
  if(!studio)return;

  const initialTab=window.location.hash.replace('#','');
  if(document.querySelector('[data-ds-tab="'+CSS.escape(initialTab)+'"]'))activateTab(initialTab);

  $(document).on('click','[data-ds-tab]',function(){
    activateTab(this.dataset.dsTab);
  });

  $(document).on('input change','[data-destination-form] input,[data-destination-form] textarea,[data-destination-form] select',function(){
    markDirty();
  });

  $(document).on('input','input[name="post_title"]',updateTitle);
  $(document).on('input','[data-lat],[data-lng]',updateCoordinates);

  $('[data-destination-form]').on('submit',function(){
    if(window.tinyMCE&&typeof window.tinyMCE.triggerSave==='function')window.tinyMCE.triggerSave();
    clearDirty();
  });

  window.addEventListener('beforeunload',function(event){
    if(!dirty)return;
    event.preventDefault();
    event.returnValue=TNGDestinationStudio.confirmLeave;
  });

  $(document).on('click','[data-select-image]',function(event){
    event.preventDefault();

    if(mediaFrame){
      mediaFrame.open();
      return;
    }

    mediaFrame=wp.media({
      title:TNGDestinationStudio.mediaTitle,
      button:{text:TNGDestinationStudio.mediaButton},
      library:{type:'image'},
      multiple:false
    });

    mediaFrame.on('select',function(){
      const attachment=mediaFrame.state().get('selection').first().toJSON();
      $('[data-thumbnail-id]').val(attachment.id).trigger('change');

      const url=attachment.sizes&&attachment.sizes.large?attachment.sizes.large.url:attachment.url;
      $('[data-image-preview]').attr('src',url).prop('hidden',false);
      $('[data-image-empty]').prop('hidden',true);
      $('[data-remove-image]').prop('hidden',false);
      $('[data-image-picker]').addClass('has-image');
      $('[data-select-image]').text('Change image');

      const sidebar=$('[data-sidebar-image]');
      if(sidebar.length){
        sidebar.attr('src',url);
      }else{
        $('.tng-ds-summary-image').html('<img src="'+url+'" alt="" data-sidebar-image>');
      }
      markDirty();
    });

    mediaFrame.open();
  });

  $(document).on('click','[data-remove-image]',function(event){
    event.preventDefault();
    $('[data-thumbnail-id]').val('').trigger('change');
    $('[data-image-preview]').attr('src','').prop('hidden',true);
    $('[data-image-empty]').prop('hidden',false);
    $('[data-remove-image]').prop('hidden',true);
    $('[data-image-picker]').removeClass('has-image');
    $('[data-select-image]').text('Choose image');
    $('.tng-ds-summary-image').html('<div data-sidebar-empty><span class="dashicons dashicons-location-alt"></span></div>');
    markDirty();
  });

  $(document).on('input','[data-linked-filter]',function(){
    filterLinked();
  });

  $(document).on('change','[data-linked-type]',function(){
    filterLinked();
  });

  function filterLinked(){
    const query=($('[data-linked-filter]').val()||'').toLowerCase().trim();
    const type=$('[data-linked-type]').val()||'';

    $('[data-linked-item]').each(function(){
      const matchesText=!query||(this.dataset.title||'').includes(query);
      const matchesType=!type||this.dataset.type===type;
      this.hidden=!(matchesText&&matchesType);
    });
  }
});
})(jQuery);