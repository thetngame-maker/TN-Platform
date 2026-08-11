(function(){
'use strict';
var cfg=window.TNGTripDayPlanner||{};
if(!cfg.enabled)return;
var saved=(Array.isArray(cfg.savedIds)?cfg.savedIds:[]).map(Number).filter(Boolean);
if(!saved.length)return;

function esc(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c];});}
function rad(v){return v*Math.PI/180;}
function miles(a,b){if(!a||!b||!isFinite(a.lat)||!isFinite(a.lng)||!isFinite(b.lat)||!isFinite(b.lng))return null;var R=3958.8,dLat=rad(b.lat-a.lat),dLng=rad(b.lng-a.lng),x=Math.sin(dLat/2)*Math.sin(dLat/2)+Math.cos(rad(a.lat))*Math.cos(rad(b.lat))*Math.sin(dLng/2)*Math.sin(dLng/2);return 2*R*Math.asin(Math.sqrt(x));}
function driveMinutes(a,b){var d=miles(a,b);if(d==null)return 10;return Math.max(5,Math.round((d*1.22/35)*60));}
function bucket(p){return String((p.bucket||'')+' '+(p.category||'')+' '+(p.type||'')).toLowerCase();}
function isFood(p){return /food|restaurant|cafe|coffee|bakery|pizza|bar|grill|dining/.test(bucket(p));}
function dwell(p){var b=bucket(p);if(/trail|hike|park/.test(b))return 120;if(/event|concert|game/.test(b))return 120;if(isFood(p))return 70;if(/destination|sight|attraction/.test(b))return 60;return 50;}
function clock(mins){mins=Math.max(0,Math.round(mins));var h=Math.floor(mins/60)%24,m=mins%60,ap=h>=12?'PM':'AM',hh=h%12||12;return hh+':'+String(m).padStart(2,'0')+' '+ap;}
function slot(mins,p){if(isFood(p)&&mins>=690&&mins<855)return 'Lunch';if(mins<690)return 'Morning';if(mins<1050)return 'Afternoon';return 'Evening';}
function coord(p){return {lat:Number(p.lat),lng:Number(p.lng)};}

function optimize(items){
    if(items.length<2)return items.slice();
    var remaining=items.slice();
    var firstIndex=remaining.findIndex(function(p){return !isFood(p)&&isFinite(Number(p.lat))&&isFinite(Number(p.lng));});
    if(firstIndex<0)firstIndex=0;
    var out=[remaining.splice(firstIndex,1)[0]], elapsed=9*60;
    while(remaining.length){
        var prev=out[out.length-1], best=0, bestScore=Infinity;
        remaining.forEach(function(p,i){
            var travel=driveMinutes(coord(prev),coord(p)), score=travel;
            if(elapsed>=11*60&&elapsed<=13*60+30){score+=isFood(p)?-18:12;}
            else if(isFood(p)&&out.some(isFood)){score+=10;}
            if(!isFinite(Number(p.lat))||!isFinite(Number(p.lng)))score+=50;
            if(score<bestScore){bestScore=score;best=i;}
        });
        elapsed+=dwell(prev)+driveMinutes(coord(prev),coord(remaining[best]));
        out.push(remaining.splice(best,1)[0]);
    }
    return out;
}

function makePlan(items){
    var ordered=optimize(items), t=9*60, rows=[];
    ordered.forEach(function(p,i){
        var travel=i?driveMinutes(coord(ordered[i-1]),coord(p)):0;
        if(i)t+=travel;
        var start=t, stay=dwell(p), end=start+stay;
        rows.push({place:p,start:start,end:end,travel:travel,slot:slot(start,p)});
        t=end;
    });
    return {ordered:ordered,rows:rows,total:t-9*60};
}

function findAnchor(){
    var hs=Array.prototype.slice.call(document.querySelectorAll('h1,h2,h3'));
    var h=hs.find(function(el){return /arrange your stops/i.test(el.textContent||'');});
    if(!h)return document.querySelector('main')||document.body;
    return h.closest('section,article,.tng-card,.tn-card,.st-card')||h.parentElement.parentElement||h.parentElement;
}

function render(plan){
    var old=document.getElementById('tng-build-my-day');if(old)old.remove();
    var box=document.createElement('section');box.id='tng-build-my-day';box.className='tng-day-planner';
    var groups={};plan.rows.forEach(function(r){(groups[r.slot]||(groups[r.slot]=[])).push(r);});
    var html='<div class="tng-day-planner__head"><div><small>SMART ITINERARY</small><h2>Build my day</h2><p>A suggested order based on your saved stops, distance, and meal timing.</p></div><div class="tng-day-planner__summary"><b>'+plan.rows.length+'</b><span>stops</span><b>'+Math.max(1,Math.round(plan.total/60))+'</b><span>est. hr</span></div></div>';
    html+='<div class="tng-day-planner__notice">Preview only — your current trip order will not change until you apply it.</div>';
    html+='<div class="tng-day-planner__timeline">';
    ['Morning','Lunch','Afternoon','Evening'].forEach(function(name){
        if(!groups[name])return;
        html+='<div class="tng-day-planner__period"><h3>'+name+'</h3>';
        groups[name].forEach(function(r){var p=r.place,img=p.image?'<img src="'+esc(p.image)+'" alt="">':'<span class="tng-day-planner__ph">TN</span>';html+='<div class="tng-day-planner__stop">'+img+'<div><small>'+clock(r.start)+'–'+clock(r.end)+(r.travel?' · '+r.travel+' min drive':'')+'</small><strong>'+esc(p.title||'Saved stop')+'</strong><span>'+esc(p.category||p.bucket||'TN Game stop')+'</span></div></div>';});
        html+='</div>';
    });
    html+='</div><div class="tng-day-planner__actions"><button type="button" class="tng-day-planner__apply">Apply suggested order</button><button type="button" class="tng-day-planner__refresh">Rebuild suggestion</button><span class="tng-day-planner__status" aria-live="polite"></span></div>';
    box.innerHTML=html;
    var anchor=findAnchor();anchor.parentNode.insertBefore(box,anchor);
    box.querySelector('.tng-day-planner__refresh').addEventListener('click',function(){render(makePlan(plan.ordered.slice().reverse()));});
    box.querySelector('.tng-day-planner__apply').addEventListener('click',function(){applyOrder(plan,box);});
}

function applyOrder(plan,box){
    var btn=box.querySelector('.tng-day-planner__apply'),status=box.querySelector('.tng-day-planner__status');
    if(!cfg.ajaxUrl||!cfg.nonce){status.textContent='Trip saving is unavailable.';return;}
    btn.disabled=true;status.textContent='Saving suggested order…';
    var body=new URLSearchParams();body.set('action','tng_reorder_saved');body.set('nonce',cfg.nonce);plan.ordered.forEach(function(p){body.append('ids[]',String(p.id));});
    fetch(cfg.ajaxUrl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:body.toString()})
      .then(function(r){return r.json();}).then(function(r){if(!r||r.success!==true)throw new Error('save');status.textContent='Suggested order applied ✓';setTimeout(function(){window.location.reload();},700);})
      .catch(function(){status.textContent='Could not apply the order. Your trip was not changed.';btn.disabled=false;});
}

function boot(){
    fetch(cfg.endpoint,{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(data){
        var list=Array.isArray(data)?data:(Array.isArray(data.places)?data.places:[]),byId={};list.forEach(function(p){byId[Number(p.id)]=p;});
        var items=saved.map(function(id){return byId[id];}).filter(Boolean);
        if(!items.length)return;
        render(makePlan(items));
    }).catch(function(){});
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',boot);else boot();
})();
