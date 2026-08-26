(function($){
  'use strict';

  function bindMediaButtons(context){
    $(context).find('[data-media-button]').off('click.tngcm').on('click.tngcm',function(event){
      event.preventDefault();

      const button=$(this);
      const wrapper=button.closest('.tng-cm-media-field');
      const input=wrapper.find('[data-media-id]');
      const label=wrapper.find('[data-media-name]');

      const frame=wp.media({
        title:'Choose TN Game Asset',
        button:{text:'Use this file'},
        multiple:false
      });

      frame.on('select',function(){
        const attachment=frame.state().get('selection').first().toJSON();
        input.val(attachment.id);
        label.text(attachment.filename||attachment.url);
      });

      frame.open();
    });
  }

  $(function(){
    bindMediaButtons(document);
  });
})(jQuery);
