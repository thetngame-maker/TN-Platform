import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const root=new URL('../experiences/wordpress/tn-game-os/',import.meta.url);
const read=path=>fs.readFileSync(new URL(path,root),'utf8');
const bootstrap=read('tn-game-os.php'),client=read('assets/js/saved-adventures.js');
assert.match(bootstrap,/Version:\s*5\.1(?:6[7-9]|[7-9]\d)\.\d+/);
assert.match(bootstrap,/define\('TNG_OS_VERSION','5\.1(?:6[7-9]|[7-9]\d)\.\d+'\)/);
const slice=(start,end)=>{const a=client.indexOf(start),b=client.indexOf(end,a);assert.ok(a>=0&&b>a);return client.slice(a,b);};
const warningCode=slice('const adventureDraftFields =','const scheduleWindows =');
const refreshCode=slice('const scheduleRefresh =','const draftReview =');
const inputCode=slice("root.addEventListener('input'","sort?.addEventListener");
const postCode=slice('let libraryUpdatePending = false;','const cleanupPrint =');
const clickCode=slice("root.addEventListener('click'","root.addEventListener('submit'");
const submitCode=slice("root.addEventListener('submit'",'\n})();');
assert.match(warningCode,/\[data-tng-plan-schedule\] input\[name="planned_date"\]/);
assert.match(warningCode,/field\.validity\?\.badInput/);
assert.match(warningCode,/querySelector\('\[data-tng-plan-clear-date\]'\)\?\.disabled/);
assert.doesNotMatch(warningCode,/fetch\(|post\(|localStorage|sessionStorage|indexedDB|setTimeout|setInterval|sendBeacon|console\.|innerHTML|\.hidden/);
assert.match(submitCode,/planned_date:submittedDate/);
assert.match(submitCode,/input\.defaultValue = submittedDate;/);
assert.match(clickCode,/if \(input\.value === submittedDate && !input\.validity\?\.badInput\) input\.value = '';/);
assert.match(clickCode,/input\.defaultValue = '';/);
const deferred=()=>{let resolve,reject;const promise=new Promise((yes,no)=>{resolve=yes;reject=no;});return {promise,resolve,reject};};
const success=request=>request.resolve({json:async()=>({success:true,data:{message:'Adventure date updated.'}})});

// Actual warning, input, request, submit, and clear handlers. Reload records
// whether they request a native warning; it does not emulate browser support.
const harness=(initial='2030-01-02')=>{
  const handlers={},listeners=new Map(),requests=[],reloads=[],status={textContent:''};
  const combined='[data-tng-plan-notes],[data-tng-plan-rename],[data-tng-plan-schedule]';
  const plans=[0,1].map(index=>{
    const card={hidden:false,dataset:Object.freeze({planId:'owned-'+index,planDate:initial,planReadyCount:'4',planPackedCount:'6'})};
    const make=(kind,value)=>{
      const button={disabled:false};
      const clear={disabled:false,closest:selector=>selector==='[data-plan-id]'?card:selector==='[data-tng-plan-schedule]'?form:selector==='[data-tng-plan-clear-date]'&&kind==='schedule'?clear:null};
      const field={value,defaultValue:value,validity:{badInput:false},focusCalls:0,focus(){this.focusCalls++;},closest:selector=>selector===combined||selector===`[data-tng-plan-${kind}]`?form:selector==='[data-tng-plan-notes] textarea'&&kind==='notes'?field:null};
      const count={textContent:''};
      const form={closest:selector=>selector===`[data-tng-plan-${kind}]`?form:selector==='[data-plan-id]'?card:null,
        querySelector:selector=>selector==='button[type="submit"]'?button:selector==='[data-tng-plan-clear-date]'?(kind==='schedule'?clear:null):selector==='[data-tng-notes-count]'?count:field};
      return {field,button,clear,form};
    };
    return {card,schedule:make('schedule',initial),rename:make('rename','Plan '+index),notes:make('notes','Notes '+index)};
  });
  const exit=()=>{const event={prevented:false,returnValue:'',preventDefault(){this.prevented=true;}};listeners.get('beforeunload')?.forEach(fn=>fn(event));return event;};
  const context={URLSearchParams,status,
    root:{dataset:{ajaxUrl:'/wp-admin/admin-ajax.php',nonce:'test-nonce'},querySelector:()=>null,querySelectorAll:selector=>{
      assert.equal(selector,'[data-tng-plan-notes] textarea[name="notes"], [data-tng-plan-rename] input[name="title"], [data-tng-plan-schedule] input[name="planned_date"]');
      return plans.flatMap(plan=>[plan.notes.field,plan.rename.field,plan.schedule.field]);
    },addEventListener:(type,handler)=>{handlers[type]=handler;}},
    window:{addEventListener:(type,handler)=>{if(!listeners.has(type))listeners.set(type,new Set());listeners.get(type).add(handler);},removeEventListener:(type,handler)=>listeners.get(type)?.delete(handler),location:{reload:()=>reloads.push({event:exit(),pending:plans.some(plan=>plan.schedule.button.disabled||plan.schedule.clear.disabled)})}},
    fetch:(url,options)=>{const request=deferred();requests.push({url,options,...request});return request.promise;}};
  vm.runInNewContext(`${warningCode}\n${refreshCode}\n${inputCode}\n${postCode}\n${clickCode}\n${submitCode}`,context);
  return {plans,requests,reloads,status,exit,warningCount:()=>listeners.get('beforeunload')?.size??0,
    type(index,kind,value,badInput=false){const field=plans[index][kind].field;field.value=value;field.validity.badInput=badInput;handlers.input({target:field});},
    act:(kind,index=0)=>kind==='clear'?handlers.click({target:plans[index].schedule.clear}):handlers.submit({target:plans[index].schedule.form,preventDefault(){}}),
    pageshow:()=>listeners.get('pageshow')?.forEach(fn=>fn())};
};

let h=harness();assert.equal(h.warningCount(),0);h.type(0,'schedule','2030-01-03');assert.equal(h.warningCount(),1);
h.type(0,'schedule','2030-01-02');assert.equal(h.warningCount(),0);assert.equal(h.requests.length,0);
h=harness('');h.type(0,'schedule','',true);assert.equal(h.warningCount(),1,'Partial invalid date input is protected even with an empty value');
await h.act('schedule');assert.equal(h.requests.length,0);assert.equal(h.plans[0].schedule.field.focusCalls,1);assert.equal(h.warningCount(),1);
h.type(0,'schedule','');assert.equal(h.warningCount(),0);

for(const kind of ['schedule','clear']) {
  h=harness();if(kind==='schedule')h.type(0,'schedule','2030-01-03');
  let saving=h.act(kind);assert.equal(h.warningCount(),1);assert.equal(h.exit().prevented,true);
  assert.equal(h.requests[0].options.body.get('planned_date'),kind==='clear'?'':'2030-01-03');
  assert.equal(h.requests[0].options.body.get('nonce'),'test-nonce');assert.equal(h.requests[0].options.credentials,'same-origin');
  success(h.requests[0]);await saving;
  assert.equal(h.plans[0].schedule.field.value,kind==='clear'?'':'2030-01-03');
  assert.equal(h.plans[0].schedule.field.defaultValue,kind==='clear'?'':'2030-01-03');
  assert.equal(h.warningCount(),0);assert.equal(h.reloads.length,1);
  assert.equal(h.reloads[0].event.prevented,false,'A confirmed save alone never asks to discard its own date');
  assert.equal(h.reloads[0].pending,false,'Controls are released before the reload');
  assert.match(h.status.textContent,/refresh to update preparation details/);

  h=harness();if(kind==='schedule')h.type(0,'schedule','2030-01-03');
  saving=h.act(kind);h.type(0,'schedule','2030-01-04');success(h.requests[0]);await saving;
  assert.equal(h.plans[0].schedule.field.value,'2030-01-04','A newer date edit is never overwritten');
  assert.equal(h.plans[0].schedule.field.defaultValue,kind==='clear'?'':'2030-01-03');
  assert.equal(h.warningCount(),1);assert.equal(h.reloads.length,0,'A newer date prevents the internal reload entirely');
  assert.equal(h.exit().returnValue,true,'External exits still get a generic warning');
  assert.equal(h.requests.length,1);
  h.type(0,'schedule',kind==='clear'?'':'2030-01-03');assert.equal(h.warningCount(),0);

  for(const failure of ['server','network','invalid-json']) {
    h=harness();h.type(0,'schedule','2030-01-03');saving=h.act(kind);h.type(0,'schedule','2030-01-04');
    if(failure==='server')h.requests[0].resolve({json:async()=>({success:false,data:{message:'Date save failed.'}})});
    if(failure==='network')h.requests[0].reject(new Error('Network unavailable'));
    if(failure==='invalid-json')h.requests[0].resolve({json:async()=>{throw new Error('Invalid response');}});
    await saving;
    assert.equal(h.plans[0].schedule.field.defaultValue,'2030-01-02');assert.equal(h.plans[0].schedule.field.value,'2030-01-04');
    assert.equal(h.warningCount(),1);assert.equal(h.reloads.length,0);assert.equal(h.requests.length,1);
    assert.equal(h.plans[0].schedule.button.disabled,false);assert.equal(h.plans[0].schedule.clear.disabled,false);
    saving=h.act(kind);success(h.requests[1]);await saving;assert.equal(h.warningCount(),0);assert.equal(h.reloads.length,1);
  }
  for(const otherKind of ['rename','notes','schedule']) {
    h=harness();h.plans[1].card.hidden=true;h.type(1,otherKind,otherKind==='schedule'?'2030-02-01':'Hidden draft');
    const saving=h.act(kind);success(h.requests[0]);await saving;
    assert.equal(h.warningCount(),1);assert.equal(h.reloads.length,0,'Other hidden plan drafts defer date reloads');
  }
}
h=harness();h.type(0,'schedule','2030-01-03');let saving=h.act('schedule');h.type(0,'schedule','2030-01-02');
assert.equal(h.warningCount(),1,'Reverting during a pending date save stays protected');success(h.requests[0]);await saving;
assert.equal(h.plans[0].schedule.field.defaultValue,'2030-01-03');assert.equal(h.plans[0].schedule.field.value,'2030-01-02');assert.equal(h.warningCount(),1);
for(const firstKind of ['schedule','clear']) {
  h=harness();saving=h.act(firstKind);await h.act(firstKind==='schedule'?'clear':'schedule');
  assert.equal(h.requests.length,1);assert.match(h.status.textContent,/Another adventure update is still saving/);assert.equal(h.warningCount(),1);
  success(h.requests[0]);await saving;assert.equal(h.warningCount(),0);
}
h=harness();h.plans[1].schedule.field.value='2030-02-02';h.pageshow();assert.equal(h.warningCount(),1);
h.plans[1].schedule.field.value='2030-01-02';h.pageshow();assert.equal(h.warningCount(),0);
h=harness();h.type(0,'schedule','',true);saving=h.act('clear');
const partial=h.plans[0].schedule.field;let valueWrites=0;
Object.defineProperty(partial,'value',{get:()=>'',set(){valueWrites++;partial.validity.badInput=false;}});
success(h.requests[0]);await saving;
assert.equal(valueWrites,0,'A successful clear must not erase opaque partial date input');
assert.equal(partial.validity.badInput,true);assert.equal(partial.defaultValue,'');assert.equal(h.reloads.length,0);
console.log('TN Game OS 5.167.0 Unsaved Adventure Date Exit Warning passed');
