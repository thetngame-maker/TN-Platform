(function(){
'use strict';
var cfg=window.TNGTripDayPlanner||{};
if(!cfg.enabled)return;
var saved=(Array.isArray(cfg.savedIds)?cfg.savedIds:[]).map(Number).filter(Boolean);
if(!saved.length)return;
var sourceItems=[];
var routeMatrix=null;
var routingState='estimate';
var START_KEY='tng_trip_start_minutes_v1';
var MATRIX_CACHE_PREFIX='tng_trip_matrix_v1_';
var DAYS=['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];

function esc(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c];});}
function rad(v){return v*Math.PI/180;}
function miles(a,b){if(!a||!b||!isFinite(a.lat)||!isFinite(a.lng)||!isFinite(b.lat)||!isFinite(b.lng))return null;var R=3958.8,dLat=rad(b.lat-a.lat),dLng=rad(b.lng-a.lng),x=Math.sin(dLat/2)*Math.sin(dLat/2)+Math.cos(rad(a.lat))*Math.cos(rad(b.lat))*Math.sin(dLng/2)*Math.sin(dLng/2);return 2*R*Math.asin(Math.sqrt(x));}
function estimatedDriveMinutes(a,b){var d=miles(a,b);if(d==null)return 10;return Math.max(5,Math.round((d*1.22/35)*60));}
function bucket(p){return String((p.bucket||'')+' '+(p.category||'')+' '+(p.type||'')).toLowerCase();}
function isFood(p){return /food|restaurant|cafe|coffee|bakery|pizza|bar|grill|dining/.test(bucket(p));}
function dwell(p){var b=bucket(p);if(/trail|hike|park/.test(b))return 120;if(/event|concert|game/.test(b))return 120;if(isFood(p))return 70;if(/destination|sight|attraction/.test(b))return 60;return 50;}
function clock(mins){mins=Math.max(0,Math.round(mins));var h=Math.floor(mins/60)%24,m=mins%60,ap=h>=12?'PM':'AM',hh=h%12||12;return hh+':'+String(m).padStart(2,'0')+' '+ap;}
function slot(mins,p){if(isFood(p)&&mins>=690&&mins<855)return 'Lunch';if(mins<690)return 'Morning';if(mins<1050)return 'Afternoon';return 'Evening';}
function coord(p){return {lat:Number(p.lat),lng:Number(p.lng)};}
function hasCoord(p){return !!p&&isFinite(Number(p.lat))&&isFinite(Number(p.lng));}
function clampStart(v){v=Number(v);if(!isFinite(v))v=540;return Math.max(300,Math.min(1260,Math.round(v)));}
function getStart(){try{var raw=localStorage.getItem(START_KEY);if(raw===null||raw==='')return 540;return clampStart(raw);}catch(e){return 540;}}
function saveStart(v){try{localStorage.setItem(START_KEY,String(clampStart(v)));}catch(e){}}
function minutesToInput(mins){mins=clampStart(mins);return String(Math.floor(mins/60)).padStart(2,'0')+':'+String(mins%60).padStart(2,'0');}
function inputToMinutes(v){var m=String(v||'').match(/^(\d{1,2}):(\d{2})$/);if(!m)return getStart();return clampStart((Number(m[1])*60)+Number(m[2]));}

function routeMinutes(a,b){
    if(routeMatrix&&routeMatrix.indexById){
        var ai=routeMatrix.indexById[String(Number(a&&a.id))],bi=routeMatrix.indexById[String(Number(b&&b.id))];
        if(ai!=null&&bi!=null&&routeMatrix.durations&&routeMatrix.durations[ai]){
            var seconds=Number(routeMatrix.durations[ai][bi]);
            if(isFinite(seconds)&&seconds>=0)return Math.max(1,Math.round(seconds/60));
        }
    }
    return estimatedDriveMinutes(coord(a),coord(b));
}

function routingLabel(){
    if(routeMatrix)return 'Road routing';
    if(routingState==='loading')return 'Calculating…';
    return 'Estimated';
}

function matrixCacheKey(items){
    var compact=items.map(function(p){return [Number(p.id)||0,Number(p.lng).toFixed(5),Number(p.lat).toFixed(5)].join(':');}).join('|');
    var hash=0;for(var i=0;i<compact.length;i++){hash=((hash<<5)-hash)+compact.charCodeAt(i);hash|=0;}
    return MATRIX_CACHE_PREFIX+String(Math.abs(hash));
}

function applyMatrixPayload(payload,items){
    if(!payload||!Array.isArray(payload.durations)||payload.durations.length!==items.length)return false;
    var indexById={};items.forEach(function(p,i){indexById[String(Number(p.id))]=i;});
    routeMatrix={durations:payload.durations,distances:Array.isArray(payload.distances)?payload.distances:null,indexById:indexById,provider:'mapbox'};
    return true;
}

function loadRoadMatrix(items){
    var routing=cfg.routing||{};
    var max=Math.max(2,Math.min(25,Number(routing.maxCoordinates)||25));
    var matrixItems=items.filter(hasCoord).slice(0,max);
    if(routing.provider!=='mapbox'||!routing.token||!routing.matrixBase||matrixItems.length<2)return Promise.resolve(false);

    var cacheKey=matrixCacheKey(matrixItems);
    try{
        var cached=sessionStorage.getItem(cacheKey);
        if(cached){
            var parsed=JSON.parse(cached);
            if(parsed&&parsed.savedAt&&Date.now()-parsed.savedAt<6*60*60*1000&&applyMatrixPayload(parsed,matrixItems))return Promise.resolve(true);
        }
    }catch(e){}

    var coords=matrixItems.map(function(p){return Number(p.lng).toFixed(6)+','+Number(p.lat).toFixed(6);}).join(';');
    var url=String(routing.matrixBase)+coords+'?annotations=duration,distance&access_token='+encodeURIComponent(String(routing.token));
    return fetch(url,{method:'GET',mode:'cors',credentials:'omit'})
      .then(function(r){if(!r.ok)throw new Error('routing');return r.json();})
      .then(function(data){
          if(!data||!Array.isArray(data.durations))throw new Error('routing');
          if(!applyMatrixPayload(data,matrixItems))throw new Error('routing');
          try{sessionStorage.setItem(cacheKey,JSON.stringify({savedAt:Date.now(),durations:data.durations,distances:data.distances||null}));}catch(e){}
          return true;
      })
      .catch(function(){routeMatrix=null;return false;});
}

function timeTokenToMinutes(token){
    token=String(token||'').trim().toUpperCase().replace(/\./g,'');
    var m=token.match(/^(\d{1,2})(?::(\d{2}))?\s*(AM|PM)?$/);
    if(!m)return null;
    var h=Number(m[1]),min=Number(m[2]||0),ap=m[3]||'';
    if(min>59)return null;
    if(ap){if(h<1||h>12)return null;if(ap==='AM'&&h===12)h=0;if(ap==='PM'&&h!==12)h+=12;}
    else if(h>23)return null;
    return h*60+min;
}

function hoursTextForToday(raw){
    if(raw==null||raw==='')return '';
    var value=raw,day=DAYS[(new Date()).getDay()];
    if(typeof value==='string'){
        var trimmed=value.trim();
        if((trimmed.charAt(0)==='{'&&trimmed.slice(-1)==='}')||(trimmed.charAt(0)==='['&&trimmed.slice(-1)===']')){
            try{value=JSON.parse(trimmed);}catch(e){value=trimmed;}
        }
    }
    if(Array.isArray(value)){
        var line=value.find(function(x){return typeof x==='string'&&new RegExp('^'+day,'i').test(x.trim());});
        if(line)return line;
        value=value.map(function(x){return typeof x==='string'?x:'';}).filter(Boolean).join('\n');
    }else if(value&&typeof value==='object'){
        var direct=value[day]||value[day.toLowerCase()]||value[day.slice(0,3)]||value[day.slice(0,3).toLowerCase()];
        if(Array.isArray(direct))direct=direct.join(', ');
        if(direct!=null)return day+': '+String(direct);
        value=Object.keys(value).map(function(k){return k+': '+String(value[k]);}).join('\n');
    }
    var text=String(value||'').replace(/\\n/g,'\n');
    var lines=text.split(/\n|\s*;\s*|\s*\|\s*/).map(function(x){return x.trim();}).filter(Boolean);
    var match=lines.find(function(x){return new RegExp('^'+day+'\b','i').test(x);});
    if(match)return match;
    var idx=text.toLowerCase().indexOf(day.toLowerCase());
    if(idx>=0){
        var tail=text.slice(idx);
        var nextDay=DAYS.find(function(d){return d!==day&&tail.toLowerCase().indexOf(d.toLowerCase(),1)>0;});
        if(nextDay){var ni=tail.toLowerCase().indexOf(nextDay.toLowerCase(),1);tail=tail.slice(0,ni);}
        return tail.trim();
    }
    return text;
}

function parseTodayHours(p){
    var line=hoursTextForToday(p&&p.hours);
    if(!line)return null;
    if(/closed/i.test(line))return {closed:true,intervals:[],label:'Closed today'};
    if(/open\s*24\s*hours|24\s*hours/i.test(line))return {closed:false,intervals:[[0,1440]],label:'Open 24 hours'};
    var intervals=[];
    var re=/(\d{1,2}(?::\d{2})?\s*(?:AM|PM)?)\s*(?:–|—|-|to)\s*(\d{1,2}(?::\d{2})?\s*(?:AM|PM)?)/gi,m;
    while((m=re.exec(line))){
        var open=timeTokenToMinutes(m[1]),close=timeTokenToMinutes(m[2]);
        if(open==null||close==null)continue;
        if(close<=open)close+=1440;
        intervals.push([open,close]);
    }
    if(!intervals.length)return null;
    return {closed:false,intervals:intervals,label:line};
}

function availability(p,earliest,stay){
    var parsed=parseTodayHours(p);
    if(!parsed)return {start:earliest,wait:0,status:'Hours not verified',unknown:true,conflict:false};
    if(parsed.closed)return {start:earliest,wait:0,status:'Closed today',unknown:false,conflict:true};
    for(var i=0;i<parsed.intervals.length;i++){
        var open=parsed.intervals[i][0],close=parsed.intervals[i][1],candidate=Math.max(earliest,open);
        if(candidate+stay<=close){
            return {start:candidate,wait:Math.max(0,candidate-earliest),status:(candidate>earliest?'Opens '+clock(open):'Open during visit'),unknown:false,conflict:false,open:open,close:close};
        }
    }
    return {start:earliest,wait:0,status:'Outside today’s hours',unknown:false,conflict:true};
}

function optimize(items,startMinutes){
    if(items.length<2)return items.slice();
    var remaining=items.slice(),out=[],prev=null,elapsed=startMinutes;
    while(remaining.length){
        var best=0,bestScore=Infinity,bestStart=elapsed;
        remaining.forEach(function(p,i){
            var travel=prev?routeMinutes(prev,p):0;
            var arrival=elapsed+travel;
            var avail=availability(p,arrival,dwell(p));
            var visitStart=avail.conflict?arrival:avail.start;
            var score=travel+(avail.wait*.35);
            if(avail.conflict)score+=5000;
            if(isFood(p)){
                if(visitStart>=11*60+15&&visitStart<=13*60+30)score-=18;
                else if(visitStart<10*60+15)score+=16;
                if(out.some(isFood))score+=6;
            }else if(visitStart>=11*60+15&&visitStart<=13*60+30){
                score+=10;
            }
            if(!hasCoord(p))score+=50;
            if(score<bestScore){bestScore=score;best=i;bestStart=visitStart;}
        });
        var chosen=remaining.splice(best,1)[0];
        out.push(chosen);
        elapsed=bestStart+dwell(chosen);
        prev=chosen;
    }
    return out;
}

function makePlan(items,startMinutes){
    startMinutes=clampStart(startMinutes);
    var ordered=optimize(items,startMinutes),t=startMinutes,rows=[];
    ordered.forEach(function(p,i){
        var travel=i?routeMinutes(ordered[i-1],p):0;
        if(i)t+=travel;
        var avail=availability(p,t,dwell(p));
        var start=avail.conflict?t:avail.start,stay=dwell(p),end=start+stay;
        rows.push({place:p,start:start,end:end,travel:travel,slot:slot(start,p),wait:avail.wait||0,hoursStatus:avail.status,hoursUnknown:!!avail.unknown,hoursConflict:!!avail.conflict});
        t=end;
    });
    return {ordered:ordered,rows:rows,total:t-startMinutes,start:startMinutes,end:t};
}

function findAnchor(){
    var hs=Array.prototype.slice.call(document.querySelectorAll('h1,h2,h3'));
    var h=hs.find(function(el){return /arrange your stops/i.test(el.textContent||'');});
    if(!h)return document.querySelector('main')||document.body;
    return h.closest('section,article,.tng-card,.tn-card,.st-card')||h.parentElement.parentElement||h.parentElement;
}

function insertPlanner(box,anchor){
    var parent=anchor&&anchor.parentElement;
    if(parent){
        var style=window.getComputedStyle(parent);
        var isLayout=(style.display==='grid'||style.display==='flex');
        if(isLayout&&parent.children.length>1&&parent.parentNode){
            parent.parentNode.insertBefore(box,parent);
            box.classList.add('tng-day-planner--full');
            return;
        }
    }
    if(anchor&&anchor.parentNode){anchor.parentNode.insertBefore(box,anchor);return;}
    (document.querySelector('main')||document.body).appendChild(box);
}

function render(plan){
    var old=document.getElementById('tng-build-my-day');if(old)old.remove();
    var box=document.createElement('section');box.id='tng-build-my-day';box.className='tng-day-planner';
    var groups={};plan.rows.forEach(function(r){(groups[r.slot]||(groups[r.slot]=[])).push(r);});
    var conflicts=plan.rows.filter(function(r){return r.hoursConflict;}).length;
    var html='<div class="tng-day-planner__head"><div><small>SMART ITINERARY</small><h2>Build my day</h2><p>A suggested order based on your saved stops, road travel time, opening hours, and meal timing.</p></div><div class="tng-day-planner__summary"><b>'+plan.rows.length+'</b><span>stops</span><b>'+Math.max(1,Math.round(plan.total/60))+'</b><span>est. hr</span></div></div>';
    html+='<div class="tng-day-planner__controls"><label><span>Start day</span><input class="tng-day-planner__start" type="time" min="05:00" max="21:00" step="900" value="'+minutesToInput(plan.start)+'"></label><div class="tng-day-planner__window"><span>Suggested window</span><strong>'+clock(plan.start)+' – '+clock(plan.end)+'</strong></div><div class="tng-day-planner__window"><span>Travel time</span><strong>'+routingLabel()+'</strong></div></div>';
    html+='<div class="tng-day-planner__notice">Preview only — your current trip order will not change until you apply it.'+(conflicts?' <strong>'+conflicts+' stop'+(conflicts===1?' has':'s have')+' an hours conflict.</strong>':'')+'</div>';
    html+='<div class="tng-day-planner__timeline">';
    ['Morning','Lunch','Afternoon','Evening'].forEach(function(name){
        if(!groups[name])return;
        html+='<div class="tng-day-planner__period"><h3>'+name+'</h3>';
        groups[name].forEach(function(r){
            var p=r.place,img=p.image?'<img src="'+esc(p.image)+'" alt="">':'<span class="tng-day-planner__ph">TN</span>';
            var timing=clock(r.start)+'–'+clock(r.end)+(r.travel?' · '+r.travel+' min drive':'')+(r.wait?' · waits '+r.wait+' min':'');
            var detail=esc(p.category||p.bucket||'TN Game stop')+' · '+esc(r.hoursStatus||'Hours not verified');
            html+='<div class="tng-day-planner__stop'+(r.hoursConflict?' tng-day-planner__stop--conflict':'')+'">'+img+'<div><small>'+timing+'</small><strong>'+esc(p.title||'Saved stop')+'</strong><span>'+detail+'</span></div></div>';
        });
        html+='</div>';
    });
    html+='</div><div class="tng-day-planner__actions"><button type="button" class="tng-day-planner__apply">Apply suggested order</button><button type="button" class="tng-day-planner__refresh">Rebuild suggestion</button><span class="tng-day-planner__status" aria-live="polite"></span></div>';
    box.innerHTML=html;
    insertPlanner(box,findAnchor());
    box.querySelector('.tng-day-planner__start').addEventListener('change',function(){var start=inputToMinutes(this.value);saveStart(start);render(makePlan(sourceItems,start));});
    box.querySelector('.tng-day-planner__refresh').addEventListener('click',function(){render(makePlan(sourceItems.slice().reverse(),plan.start));});
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
        sourceItems=saved.map(function(id){return byId[id];}).filter(Boolean);
        if(!sourceItems.length)return;

        var canRoute=cfg.routing&&cfg.routing.provider==='mapbox'&&cfg.routing.token&&sourceItems.filter(hasCoord).length>=2;
        routingState=canRoute?'loading':'estimate';
        render(makePlan(sourceItems,getStart()));
        if(!canRoute)return;

        loadRoadMatrix(sourceItems).then(function(ok){
            routingState=ok?'road':'estimate';
            render(makePlan(sourceItems,getStart()));
        });
    }).catch(function(){});
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',boot);else boot();
})();
