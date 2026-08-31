import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const root=new URL('../experiences/wordpress/tn-game-os/',import.meta.url);
const read=path=>fs.readFileSync(new URL(path,root),'utf8');
const bootstrap=read('tn-game-os.php');
const client=read('assets/js/saved-adventures.js');
assert.match(bootstrap,/Version:\s*5\.1(?:59|[6-9]\d)\.\d+/);
assert.match(bootstrap,/define\('TNG_OS_VERSION','5\.1(?:59|[6-9]\d)\.\d+'\)/);
assert.match(client,/let libraryUpdatePending = false;/);
assert.match(client,/if \(libraryUpdatePending\) throw new Error/);
assert.match(client,/finally \{\s*libraryUpdatePending = false;\s*\}/);

const start=client.indexOf('let libraryUpdatePending = false;');
const end=client.indexOf('const cleanupPrint =',start);
assert.ok(start > -1 && end > start);
const postCode=client.slice(start,end);
assert.equal((postCode.match(/await fetch\(/g)||[]).length,1);
assert.doesNotMatch(postCode,/localStorage|sessionStorage|setTimeout|setInterval|\.then\(|Notification/);
const busy=/Another adventure update is still saving.*try this change again/;
const deferred=()=>{
  let resolve,reject;
  const promise=new Promise((yes,no)=>{resolve=yes;reject=no;});
  return {promise,resolve,reject};
};
const success=(message='Saved.')=>({json:async()=>({success:true,data:{message}})});
const harness=()=>{
  const requests=[];
  const context={
    root:{dataset:{ajaxUrl:'/wp-admin/admin-ajax.php',nonce:'test-nonce'}},URLSearchParams,
    fetch:(url,options)=>{const result=deferred();requests.push({url,options,...result});return result.promise;}
  };
  const post=vm.runInNewContext(`${postCode}\npost;`,context);
  return {context,post,requests};
};

let h=harness();
const fields=Object.freeze({operation:'notes',plan_id:'plan-a',notes:'Keep this text & spacing.'});
let first=h.post(fields);
assert.equal(h.requests.length,1);
assert.equal(h.requests[0].url,'/wp-admin/admin-ajax.php');
assert.equal(h.requests[0].options.method,'POST');
assert.equal(h.requests[0].options.credentials,'same-origin');
assert.equal(h.requests[0].options.headers['Content-Type'],'application/x-www-form-urlencoded; charset=UTF-8');
assert.equal(h.requests[0].options.body.get('action'),'tng_adventure_library_action');
assert.equal(h.requests[0].options.body.get('nonce'),'test-nonce');
assert.equal(h.requests[0].options.body.get('notes'),fields.notes);
for (const operation of ['readiness','packing','notes','schedule','rename','duplicate','archive','restore','start']) {
  await assert.rejects(h.post({operation,plan_id:'plan-b'}),busy);
  assert.equal(h.requests.length,1,`${operation} must not send an overlapping request`);
}
const parsed=deferred();
h.requests[0].resolve({json:()=>parsed.promise});
await Promise.resolve();
await assert.rejects(h.post({operation:'packing',plan_id:'plan-a'}),busy,'Response parsing remains protected');
assert.equal(h.requests.length,1);
parsed.resolve({success:true,data:{message:'First update saved.'}});
assert.equal((await first).message,'First update saved.');
const retry=h.post({operation:'packing',plan_id:'plan-b',packing_key:'water',checked:'1'});
assert.equal(h.requests.length,2,'A later explicit retry is allowed');
h.requests[1].resolve(success());
assert.equal((await retry).message,'Saved.');

for (const [settle,expected] of [
  [request=>request.reject(new Error('Network unavailable')),/Network unavailable/],
  [request=>request.resolve({json:async()=>({success:false,data:{message:'Sign in again.'}})}),/Sign in again/],
  [request=>request.resolve({json:async()=>({success:false})}),/Saved Adventures could not update that plan/],
  [request=>request.resolve({json:async()=>{throw new SyntaxError('Invalid response JSON');}}),/Invalid response JSON/],
  [request=>request.resolve(null),/json/]
]) {
  h=harness();first=h.post({operation:'rename',plan_id:'plan-a',title:'New title'});
  const rejected=assert.rejects(first,expected);
  settle(h.requests[0]);await rejected;
  await Promise.resolve();
  assert.equal(h.requests.length,1,'A failed update is never automatically retried');
  const next=h.post({operation:'rename',plan_id:'plan-a',title:'Retry title'});
  assert.equal(h.requests.length,2,'Failure releases the guard for a manual retry');
  h.requests[1].resolve(success());await next;
}
h=harness();
await assert.rejects(h.post({operation:'notes',notes:{toString(){throw new Error('Cannot encode field');}}}),/Cannot encode field/);
assert.equal(h.requests.length,0);
first=h.post({operation:'notes',notes:'Retry text'});
h.requests[0].resolve(success());await first;

// Run the actual checkbox handler against a busy post helper.
const changeStart=client.indexOf("root.addEventListener('change'");
const changeEnd=client.indexOf('const calendarStamp =',changeStart);
assert.ok(changeStart > -1 && changeEnd > changeStart);
const changeCode=client.slice(changeStart,changeEnd);
for (const kind of ['packing','readiness']) for (const checked of [true,false]) {
  h=harness();first=h.post({operation:'notes',plan_id:'plan-a',notes:'Saving'});
  let onChange=null;
  const card={dataset:{planId:'plan-b',planReadyCount:'2',planPackedCount:'3'}};
  const checkbox={checked,disabled:false,dataset:{tngPackingKey:'water',tngReadinessKey:'route'},closest(selector){
    if(selector==='[data-plan-id]')return card;
    if(selector===`[data-tng-${kind}-key]`)return this;
    if(selector===`[data-tng-plan-${kind}]`)return {};
    return null;
  }};
  h.context.root.addEventListener=(event,callback)=>{assert.equal(event,'change');onChange=callback;};
  h.context.status={textContent:''};
  h.context.launchCountFor=()=>5;
  vm.runInNewContext(changeCode,h.context);
  await onChange({target:checkbox});
  assert.equal(checkbox.checked,!checked,`${kind} restores the original checked state`);
  assert.equal(checkbox.disabled,false,`${kind} remains retryable`);
  assert.match(h.context.status.textContent,busy);
  assert.equal(card.dataset.planReadyCount,'2');
  assert.equal(card.dataset.planPackedCount,'3');
  assert.equal(h.requests.length,1);
  h.requests[0].resolve(success());await first;
}

// Rejected form updates preserve user input and re-enable their submit button.
const submitStart=client.indexOf("root.addEventListener('submit'");
const submitEnd=client.lastIndexOf('\n})();');
assert.ok(submitStart > -1 && submitEnd > submitStart);
const submitCode=client.slice(submitStart,submitEnd);
for (const kind of ['notes','schedule','rename']) {
  h=harness();first=h.post({operation:'packing',plan_id:'plan-a',packing_key:'water',checked:'1'});
  let onSubmit=null,prevented=0;
  const card={dataset:{planId:'plan-b'}};
  const value=kind==='schedule'?'2026-09-05':'Keep this unsaved text';
  const input={value};
  const button={disabled:false};
  const form={closest(selector){
    if(selector===`[data-tng-plan-${kind}]`)return this;
    if(selector==='[data-plan-id]')return card;
    if(selector==='[data-tng-plan-notes-panel]')return {};
    return null;
  },querySelector:selector=>selector==='button[type="submit"]'?button:input};
  h.context.root.addEventListener=(event,callback)=>{assert.equal(event,'submit');onSubmit=callback;};
  h.context.status={textContent:''};
  h.context.syncNotesExitWarning=()=>{};
  vm.runInNewContext(submitCode,h.context);
  await onSubmit({target:form,preventDefault:()=>{prevented+=1;}});
  assert.equal(prevented,1);
  assert.equal(input.value,value,`${kind} retains unsaved input`);
  assert.equal(button.disabled,false,`${kind} can be retried`);
  assert.match(h.context.status.textContent,busy);
  assert.equal(h.requests.length,1);
  h.requests[0].resolve(success());await first;
}

// The guard is scoped to one page instance, not a cross-tab locking mechanism.
const left=harness(),right=harness();
const leftSave=left.post({operation:'notes',plan_id:'plan-a'});
const rightSave=right.post({operation:'notes',plan_id:'plan-b'});
assert.equal(left.requests.length,1);assert.equal(right.requests.length,1);
left.requests[0].resolve(success());right.requests[0].resolve(success());
await Promise.all([leftSave,rightSave]);
console.log('TN Game OS 5.159.0 Save-in-Progress Protection passed');
