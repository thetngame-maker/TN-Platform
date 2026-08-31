import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const root=new URL('../experiences/wordpress/tn-game-os/',import.meta.url);
const read=path=>fs.readFileSync(new URL(path,root),'utf8');
const bootstrap=read('tn-game-os.php');
const client=read('assets/js/saved-adventures.js');
const server=read('app/Modules/Destinations/class-adventure-ai.php');
assert.match(bootstrap,/Version:\s*5\.164\.0/);
assert.match(bootstrap,/define\('TNG_OS_VERSION','5\.164\.0'\)/);
const start=server.indexOf("if (in_array($operation, ['archive','restore','duplicate'], true) && $updated === false)");
assert.ok(start > server.indexOf('$updated = update_user_meta'));
const guard=server.slice(start,server.indexOf('$messages =',start));
assert.match(guard,/\$expected_plan = \$operation === 'duplicate' \? \$copy : \$library\[\$index\];/);
assert.match(guard,/self::library\(get_current_user_id\(\)\)/);
assert.match(guard,/self::plan_index\(\$saved_library, \(string\)\$expected_plan\['id'\]\)/);
assert.match(guard,/\$saved_index < 0 \|\| !\$archive_state_matches \|\| \$saved_plan != \$expected_plan/);
assert.match(guard,/wp_send_json_error\(\['message'=>\$failure_messages\[\$operation\]\], 500\)/);
assert.doesNotMatch(guard,/update_user_meta|delete_user_meta|wp_send_json_success|\$_POST/);
const slice=(start,end)=>{
  const a=client.indexOf(start),b=client.indexOf(end,a);
  assert.ok(a>=0 && b>a);
  return client.slice(a,b);
};
const postCode=slice('let libraryUpdatePending = false;','const cleanupPrint =');
const clickCode=slice("root.addEventListener('click'","root.addEventListener('submit'");
const deferred=()=>{let resolve,reject;const promise=new Promise((yes,no)=>{resolve=yes;reject=no;});return {promise,resolve,reject};};
const messages={archive:'Adventure archived.',restore:'Adventure restored.',duplicate:'Adventure duplicated.'};
const labels={archive:'Archive',restore:'Restore adventure',duplicate:'Duplicate'};
const pendingLabels={archive:'Archiving…',restore:'Restoring…',duplicate:'Duplicating…'};
const success=(request,operation)=>request.resolve({json:async()=>({success:true,data:{message:messages[operation]}})});

// Execute the actual click handler and request guard with minimal DOM stand-ins.
const harness=(operation,{confirm=true}={})=>{
  let onClick,reloads=0;
  const requests=[],confirmations=[];
  const status={textContent:''};
  const card={dataset:Object.freeze({planId:'owned-plan',planState:operation==='restore'?'archived':'ready',planDate:'2030-01-02',planReadyCount:'4',planPackedCount:'6'})};
  const button={disabled:false,textContent:labels[operation],dataset:{tngPlanArchive:operation},closest:selector=>{
    if(selector==='[data-plan-id]')return card;
    if(selector==='[data-tng-plan-archive]' && operation!=='duplicate')return button;
    if(selector==='[data-tng-plan-duplicate]' && operation==='duplicate')return button;
    return null;
  }};
  const context={URLSearchParams,status,
    root:{dataset:{ajaxUrl:'/wp-admin/admin-ajax.php',nonce:'test-nonce'},addEventListener:(type,handler)=>{assert.equal(type,'click');onClick=handler;}},
    window:{confirm:message=>{confirmations.push(message);return confirm;},location:{reload:()=>{reloads++;}}},
    fetch:(url,options)=>{const request=deferred();requests.push({url,options,...request});return request.promise;}};
  const post=vm.runInNewContext(`${postCode}\n${clickCode}\npost;`,context);
  return {card,button,status,requests,confirmations,post,reloads:()=>reloads,act:()=>onClick({target:button})};
};

for(const operation of ['archive','restore','duplicate']) {
  for(const failure of ['server','network','invalid-json']) {
    const h=harness(operation),before=JSON.stringify(h.card.dataset);
    let saving=h.act();
    assert.equal(h.button.disabled,true);
    assert.equal(h.button.textContent,pendingLabels[operation]);
    assert.equal(h.reloads(),0);
    assert.equal(h.requests.length,1);
    const body=h.requests[0].options.body;
    assert.equal(body.get('operation'),operation);
    assert.equal(body.get('plan_id'),'owned-plan');
    assert.equal(body.get('nonce'),'test-nonce');
    assert.equal(h.requests[0].options.credentials,'same-origin');
    assert.equal(h.confirmations.length,operation==='archive'?1:0);
    if(failure==='server')h.requests[0].resolve({json:async()=>({success:false,data:{message:'The change could not be saved.'}})});
    if(failure==='network')h.requests[0].reject(new Error('Network unavailable'));
    if(failure==='invalid-json')h.requests[0].resolve({json:async()=>{throw new Error('Invalid response');}});
    await saving;
    assert.equal(h.button.disabled,false);
    assert.equal(h.button.textContent,labels[operation],'Failure restores the exact retry label');
    assert.equal(h.reloads(),0,'Rejected organization cannot reload away user input');
    assert.equal(JSON.stringify(h.card.dataset),before,'Rejected organization leaves displayed plan state intact');
    assert.ok(h.status.textContent);
    assert.notEqual(h.status.textContent,messages[operation]);
    assert.equal(h.requests.length,1,'No automatic retry');
    saving=h.act();assert.equal(h.requests.length,2);
    success(h.requests[1],operation);await saving;
    assert.equal(h.reloads(),1,'An explicit successful retry reloads confirmed state');
    assert.equal(h.status.textContent,messages[operation]);
    if(operation==='archive')assert.equal(h.confirmations.length,2,'Archive retry still needs confirmation');
  }
  const h=harness(operation);
  const pending=h.post({operation:'notes',plan_id:'other-plan',notes:'Saving'});
  await h.act();
  assert.equal(h.requests.length,1,'Organization never overlaps another library write');
  assert.equal(h.button.disabled,false);assert.equal(h.button.textContent,labels[operation]);
  assert.equal(h.reloads(),0);assert.match(h.status.textContent,/Another adventure update is still saving/);
  success(h.requests[0],operation);await pending;
  const saving=h.act();success(h.requests[1],operation);await saving;
  assert.equal(h.reloads(),1);
}
const h=harness('archive',{confirm:false});await h.act();
assert.equal(h.requests.length,0);assert.equal(h.reloads(),0);
assert.equal(h.button.disabled,false);assert.equal(h.button.textContent,'Archive');
assert.equal(h.confirmations.length,1);assert.match(h.confirmations[0],/restore it later/);
console.log('TN Game OS 5.164.0 Verified Saved Adventure Organization passed');
