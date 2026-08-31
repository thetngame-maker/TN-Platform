import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const root=new URL('../experiences/wordpress/tn-game-os/',import.meta.url);
const read=path=>fs.readFileSync(new URL(path,root),'utf8');
const bootstrap=read('tn-game-os.php');
const client=read('assets/js/saved-adventures.js');
const server=read('app/Modules/Destinations/class-adventure-ai.php');
assert.match(bootstrap,/Version:\s*5\.1(?:6[3-9]|[7-9]\d)\.\d+/);
assert.match(bootstrap,/define\('TNG_OS_VERSION','5\.1(?:6[3-9]|[7-9]\d)\.\d+'\)/);
const start=server.indexOf("if ($operation === 'schedule' && $updated === false)");
assert.ok(start > server.indexOf('$updated = update_user_meta'));
const guard=server.slice(start,server.indexOf('$messages =',start));
assert.match(guard,/self::library\(get_current_user_id\(\)\)/);
assert.match(guard,/self::plan_index\(\$saved_library, \$plan_id\)/);
assert.match(guard,/array_flip\(\['planned_date','readiness','readiness_updated_at','packing','packing_updated_at'\]\)/);
assert.match(guard,/\$saved_index < 0 \|\| \$saved_schedule != \$expected_schedule/);
assert.match(guard,/wp_send_json_error\(\['message'=>.*\], 500\)/);
assert.doesNotMatch(guard,/update_user_meta|wp_send_json_success|\$_POST/);

const slice=(start,end)=>{
  const a=client.indexOf(start),b=client.indexOf(end,a);
  assert.ok(a >= 0 && b > a);
  return client.slice(a,b);
};
const postCode=slice('let libraryUpdatePending = false;','const cleanupPrint =');
const clickCode=slice("root.addEventListener('click'","root.addEventListener('submit'");
const submitCode=slice("root.addEventListener('submit'",'\n})();');
const deferred=()=>{let resolve,reject;const promise=new Promise((yes,no)=>{resolve=yes;reject=no;});return {promise,resolve,reject};};
const success=request=>request.resolve({json:async()=>({success:true,data:{message:'Adventure date updated.'}})});

// Exercise the real submit/clear-date handlers and request guard, not a replica.
const harness=(kind)=>{
  const handlers={},requests=[];
  let reloads=0;
  const status={textContent:''};
  const card={dataset:Object.freeze({planId:'owned-plan',planDate:'2030-01-02',planReadyCount:'4',planPackedCount:'6'})};
  const input={value:'2030-01-03',focusCalls:0,focus(){this.focusCalls++;}};
  const button={disabled:false,closest:selector=>selector==='[data-plan-id]'?card:selector==='[data-tng-plan-clear-date]'?button:null};
  const form={closest:selector=>selector==='[data-tng-plan-schedule]'?form:selector==='[data-plan-id]'?card:null,
    querySelector:selector=>selector==='button[type="submit"]'?button:input};
  const context={URLSearchParams,status,
    root:{dataset:{ajaxUrl:'/wp-admin/admin-ajax.php',nonce:'test-nonce'},addEventListener:(event,handler)=>{handlers[event]=handler;}},
    window:{location:{reload:()=>{reloads++;}}},
    fetch:(url,options)=>{const request=deferred();requests.push({url,options,...request});return request.promise;}};
  const post=vm.runInNewContext(`${postCode}\n${clickCode}\n${submitCode}\npost;`,context);
  return {card,input,button,status,requests,post,reloads:()=>reloads,
    act:()=>kind==='clear'?handlers.click({target:button}):handlers.submit({target:form,preventDefault(){}})};
};

for(const kind of ['schedule','clear']) {
  for(const failure of ['server','network','invalid-json']) {
    const h=harness(kind),original=JSON.stringify(h.card.dataset);
    let saving=h.act();
    assert.equal(h.button.disabled,true);
    assert.equal(h.reloads(),0,'Pending changes never reload the page');
    assert.equal(h.requests.length,1);
    const body=h.requests[0].options.body;
    assert.equal(body.get('operation'),'schedule');
    assert.equal(body.get('planned_date'),kind==='clear'?'':'2030-01-03');
    assert.equal(body.get('plan_id'),'owned-plan');
    assert.equal(body.get('nonce'),'test-nonce');
    assert.equal(h.requests[0].options.credentials,'same-origin');
    h.input.value='2030-01-04';
    if(failure==='server')h.requests[0].resolve({json:async()=>({success:false,data:{message:'Adventure date could not be saved. Try that change again.'}})});
    if(failure==='network')h.requests[0].reject(new Error('Network unavailable'));
    if(failure==='invalid-json')h.requests[0].resolve({json:async()=>{throw new Error('Invalid response');}});
    await saving;
    assert.equal(h.button.disabled,false,'Failed controls remain retryable');
    assert.equal(h.reloads(),0,'A rejected date update cannot reload away user input');
    assert.equal(h.input.value,'2030-01-04','Failure retains the latest date input');
    assert.equal(JSON.stringify(h.card.dataset),original,'Failed saves preserve rendered schedule and preparation counts');
    assert.ok(h.status.textContent);
    assert.doesNotMatch(h.status.textContent,/date updated/);
    assert.equal(h.requests.length,1,'No automatic retry');
    saving=h.act();
    assert.equal(h.requests.length,2);
    assert.equal(h.requests[1].options.body.get('planned_date'),kind==='clear'?'':'2030-01-04');
    success(h.requests[1]);await saving;
    assert.equal(h.reloads(),1,'Only a successful manual retry reloads confirmed server state');
    assert.equal(h.status.textContent,'Adventure date updated.');
  }
  const h=harness(kind);
  const pending=h.post({operation:'notes',plan_id:'other-plan',notes:'Saving'});
  await h.act();
  assert.equal(h.requests.length,1,'A date update does not overlap another write');
  assert.equal(h.button.disabled,false);
  assert.equal(h.reloads(),0);
  assert.match(h.status.textContent,/Another adventure update is still saving/);
  success(h.requests[0]);await pending;
  const saving=h.act();success(h.requests[1]);await saving;
  assert.equal(h.reloads(),1);
}
const h=harness('schedule');h.input.value='';await h.act();
assert.equal(h.input.focusCalls,1);assert.equal(h.requests.length,0);assert.equal(h.reloads(),0);
console.log('TN Game OS 5.163.0 Verified Adventure Scheduling passed');
