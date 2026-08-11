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

var observer=new MutationObserver(function(){hideLegacy();});
if(document.documentElement)observer.observe(document.documentElement,{childList:true,subtree:true});
setTimeout(function(){observer.disconnect();hideLegacy();},10000);
})();
