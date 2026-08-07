(function($){
  'use strict';

  $(function(){
    window.setTimeout(function(){
      if(window.TNG_OS_ADMIN){
        var commands=window.TNG_OS_ADMIN.commands||(window.TNG_OS_ADMIN.commands=[]);
        var exists=commands.some(function(command){
          return command&&command.url&&command.url.indexOf('page=tn-game-social-intelligence')!==-1;
        });
        if(!exists){
          commands.push({
            title:'Social Intelligence',
            description:'Review social inspiration, creator permissions, and planned TN Game content.',
            group:'Content',
            icon:'dashicons-share',
            url:window.location.origin+window.location.pathname+'?page=tn-game-social-intelligence',
            keywords:'social inspiration facebook instagram youtube tiktok creator content calendar permissions'
          });
        }
      }

      var menu=document.querySelector('#toplevel_page_tn-game-os');
      if(menu){
        menu.querySelectorAll('.wp-submenu a').forEach(function(link){
          var page='';
          try{page=new URL(link.href,window.location.href).searchParams.get('page')||'';}catch(e){}
          if(page==='tn-game-social-intelligence'){
            var item=link.closest('li');
            if(item){
              item.hidden=false;
              item.removeAttribute('aria-hidden');
            }
          }
        });
      }
    },0);
  });

  $(document).on('click', '.tng-si-generate', function(){
    var button = $(this);
    var card = button.closest('.tng-si-card');
    button.prop('disabled', true).text('Generating…');
    $.post(TNGSI.ajax, {action:'tng_si_generate_idea', nonce:TNGSI.nonce, item_id:button.data('id')})
      .done(function(response){
        if(response && response.success){
          card.find('.tng-si-idea').val(response.data.idea);
          card.find('details').prop('open', true);
        } else {
          window.alert((response && response.data && response.data.message) || 'Could not generate the idea.');
        }
      })
      .fail(function(){ window.alert('Could not generate the idea.'); })
      .always(function(){ button.prop('disabled', false).text('Regenerate idea'); });
  });
})(jQuery);
