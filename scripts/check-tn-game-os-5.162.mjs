import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const root=new URL('../experiences/wordpress/tn-game-os/',import.meta.url);
const read=path=>fs.readFileSync(new URL(path,root),'utf8');
const bootstrap=read('tn-game-os.php');
const client=read('assets/js/saved-adventures.js');
const server=read('app/Modules/Destinations/class-adventure-ai.php');
assert.match(bootstrap,/Version:\s*5\.162\.0/);
assert.match(bootstrap,/define\('TNG_OS_VERSION','5\.162\.0'\)/);
const guardStart=server.indexOf("if (($operation === 'readiness' || $operation === 'packing') && $updated === false)");
const guardEnd=server.indexOf('$messages =',guardStart);
assert.ok(guardStart > server.indexOf('$updated = update_user_meta'));
const guard=server.slice(guardStart,guardEnd);
assert.match(guard,/self::library\(get_current_user_id\(\)\)/);
assert.match(guard,/self::plan_index\(\$saved_library, \$plan_id\)/);
assert.match(guard,/\$saved_index < 0 \|\| \$saved_checks != \$expected_checks/);
assert.match(guard,/wp_send_json_error\(\['message'=>.*\], 500\)/);
assert.doesNotMatch(guard,/update_user_meta|wp_send_json_success|\$_POST/);

const slice=(start,end)=>{
  const a=client.indexOf(start),b=client.indexOf(end,a);
  assert.ok(a >= 0 && b > a);
  return client.slice(a,b);
};
const helpers=slice('const updateCardLaunchStatus =','const nextIncompleteCheckFor =');
const postCode=slice('let libraryUpdatePending = false;','const cleanupPrint =');
const changeCode=slice("root.addEventListener('change'",'const calendarStamp =');
const deferred=()=>{let resolve,reject;const promise=new Promise((yes,no)=>{resolve=yes;reject=no;});return {promise,resolve,reject};};
const node=(textContent='')=>({textContent,style:{},classList:{toggle(){}}});

// Execute the real request helper and checkbox handler with a minimal DOM.
const harness=(kind,checked)=>{
  let onChange;
  const limit=kind==='packing'?6:4,oldCount=checked?limit-1:limit;
  const key=kind==='packing'?'water':'route';
  const requests=[],effects=[];
  const count=node(`${oldCount} of ${limit} ${kind==='packing'?'packed':'ready'}`);
  const printCount=node(`${kind==='packing'?'Packing':'Readiness'} · ${oldCount} of ${limit} complete`);
  const printMark=node(checked?'○':'✓');
  const printItem={querySelector:()=>printMark,classList:{toggle:(name,value)=>effects.push(['print',name,value])}};
  const label=node(checked?'9 of 10 complete':'Launch ready'),progress=node();
  progress.style.width=checked?'90%':'100%';
  const panel={querySelector:selector=>selector==='[data-tng-plan-launch-label]'?label:progress,classList:{toggle:(name,value)=>effects.push(['launch',name,value])}};
  const card={dataset:{planId:'owned-plan',planDate:'2030-01-02',planState:'ready',planReadyCount:String(kind==='readiness'?oldCount:4),planPackedCount:String(kind==='packing'?oldCount:6)},querySelector:selector=>{
    if(selector==='[data-tng-plan-launch-score]')return panel;
    if(selector==='[data-plan-title]')return node('My adventure');
    if(selector===`[data-tng-print-${kind}="${key}"]`)return printItem;
    if(selector===`[data-tng-print-${kind}-count]`)return printCount;
    return null;
  }};
  const checkbox={checked,disabled:false,dataset:{tngPackingKey:key,tngReadinessKey:key},closest:selector=>{
    if(selector===`[data-tng-${kind}-key]`)return checkbox;
    if(selector==='[data-plan-id]')return card;
    if(selector===`[data-tng-plan-${kind}]`)return fieldset;
    return null;
  }};
  const fieldset={querySelector:()=>count,querySelectorAll:()=>[checkbox,...Array.from({length:limit-1},()=>({checked:true}))]};
  const status=node();
  const context={URLSearchParams,status,nextCard:card,todayKey:'2030-01-02',
    root:{dataset:{ajaxUrl:'/wp-admin/admin-ajax.php',nonce:'test-nonce'},addEventListener:(event,handler)=>{assert.equal(event,'change');onChange=handler;}},
    fetch:(url,options)=>{const request=deferred();requests.push({url,options,...request});return request.promise;},
    refreshPrepViews:()=>effects.push(['prep']),updateNextLaunchStatus:()=>effects.push(['next'])};
  vm.runInNewContext(`${helpers}\n${postCode}\n${changeCode}`,context);
  return {card,checkbox,status,count,printCount,printMark,label,progress,requests,effects,change:()=>onChange({target:checkbox})};
};
const succeed=request=>request.resolve({json:async()=>({success:true,data:{message:'Checklist saved.'}})});

for(const kind of ['readiness','packing']) for(const checked of [true,false]) {
  for(const failure of ['server','network','invalid-json']) {
    const h=harness(kind,checked);
    const before=JSON.stringify({dataset:h.card.dataset,count:h.count.textContent,print:h.printCount.textContent,mark:h.printMark.textContent,label:h.label.textContent,width:h.progress.style.width});
    let saving=h.change();
    assert.equal(h.checkbox.disabled,true);
    assert.equal(h.requests.length,1);
    const body=h.requests[0].options.body;
    assert.equal(body.get('operation'),kind);
    assert.equal(body.get('checked'),checked?'1':'0');
    assert.equal(body.get('plan_id'),'owned-plan');
    assert.equal(body.get('nonce'),'test-nonce');
    assert.equal(h.requests[0].options.credentials,'same-origin');
    assert.equal(h.effects.length,0,'Pending saves do not update confirmed views');
    if(failure==='server')h.requests[0].resolve({json:async()=>({success:false,data:{message:'Checklist could not be saved.'}})});
    if(failure==='network')h.requests[0].reject(new Error('Network unavailable'));
    if(failure==='invalid-json')h.requests[0].resolve({json:async()=>{throw new Error('Invalid response');}});
    await saving;
    assert.equal(h.checkbox.checked,!checked,'Failure restores the previous checkbox');
    assert.equal(h.checkbox.disabled,false);
    assert.equal(JSON.stringify({dataset:h.card.dataset,count:h.count.textContent,print:h.printCount.textContent,mark:h.printMark.textContent,label:h.label.textContent,width:h.progress.style.width}),before);
    assert.equal(h.effects.length,0,'Rejected saves never refresh prep, launch, next, or print views');
    assert.doesNotMatch(h.status.textContent,/launch ready|Checklist saved/);
    assert.equal(h.requests.length,1,'No automatic retry');
    h.checkbox.checked=checked;
    saving=h.change();
    assert.equal(h.requests.length,2,'A deliberate retry is allowed');
    succeed(h.requests[1]);await saving;
    assert.equal(h.checkbox.checked,checked);
    assert.equal(h.checkbox.disabled,false);
    assert.equal(h.card.dataset[kind==='packing'?'planPackedCount':'planReadyCount'],String((kind==='packing'?6:4)-(checked?0:1)));
    assert.equal(h.printMark.textContent,checked?'✓':'○');
    assert.equal(h.label.textContent,checked?'Launch ready':'9 of 10 complete');
    assert.equal(h.progress.style.width,checked?'100%':'90%');
    assert.ok(h.effects.some(x=>x[0]==='prep'));
    assert.ok(h.effects.some(x=>x[0]==='next'));
    if(checked)assert.match(h.status.textContent,/My adventure is launch ready/);
    else assert.equal(h.status.textContent,'Checklist saved.');
  }
}
console.log('TN Game OS 5.162.0 Verified Prep Checklist Saves passed');
