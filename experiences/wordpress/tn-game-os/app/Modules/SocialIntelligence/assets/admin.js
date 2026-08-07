(function($){
  'use strict';
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
