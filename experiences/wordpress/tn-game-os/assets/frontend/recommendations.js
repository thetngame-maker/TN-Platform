(function(){
  'use strict';

  function initializeWidget(widget){
    if(widget.dataset.tngInitialized === '1') return;
    widget.dataset.tngInitialized = '1';

    const tabs = Array.from(widget.querySelectorAll('[data-tng-rec-tab]'));
    const panels = Array.from(widget.querySelectorAll('[data-tng-rec-panel]'));
    const scroller = widget.querySelector('.tng-recommendations-tabs');

    function activate(key, focus){
      tabs.forEach(function(tab){
        const active = tab.dataset.tngRecTab === key;
        tab.classList.toggle('is-active', active);
        tab.setAttribute('aria-selected', active ? 'true' : 'false');

        if(active){
          tab.setAttribute('tabindex','0');
          if(focus) tab.focus({preventScroll:true});
        }else{
          tab.setAttribute('tabindex','-1');
        }
      });

      panels.forEach(function(panel){
        const active = panel.dataset.tngRecPanel === key;
        panel.classList.toggle('is-active', active);
        panel.hidden = !active;
      });
    }

    tabs.forEach(function(tab,index){
      tab.addEventListener('click',function(){
        activate(tab.dataset.tngRecTab,false);
      });

      tab.addEventListener('keydown',function(event){
        if(!['ArrowLeft','ArrowRight','Home','End'].includes(event.key)) return;
        event.preventDefault();

        let next=index;
        if(event.key==='ArrowRight') next=(index+1)%tabs.length;
        if(event.key==='ArrowLeft') next=(index-1+tabs.length)%tabs.length;
        if(event.key==='Home') next=0;
        if(event.key==='End') next=tabs.length-1;

        activate(tabs[next].dataset.tngRecTab,true);
        tabs[next].scrollIntoView({behavior:'smooth',block:'nearest',inline:'center'});
      });
    });

    widget.querySelectorAll('.tng-rec-scroll').forEach(function(button){
      button.addEventListener('click',function(){
        if(!scroller) return;
        const direction=button.classList.contains('tng-rec-scroll-left')?-1:1;
        scroller.scrollBy({
          left:direction*Math.max(260,scroller.clientWidth*.65),
          behavior:'smooth'
        });
      });
    });
  }

  function initializeAll(){
    document.querySelectorAll('[data-tng-recommendations]').forEach(initializeWidget);
  }

  if(document.readyState==='loading'){
    document.addEventListener('DOMContentLoaded',initializeAll);
  }else{
    initializeAll();
  }

  document.addEventListener('elementor/popup/show',initializeAll);
})();