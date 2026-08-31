import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const root=new URL('../experiences/wordpress/tn-game-os/',import.meta.url);
const read=path=>fs.readFileSync(new URL(path,root),'utf8');
const bootstrap=read('tn-game-os.php'),client=read('assets/js/saved-adventures.js');
assert.match(bootstrap,/Version:\s*5\.1(?:6[5-9]|[7-9]\d)\.\d+/);
assert.match(bootstrap,/define\('TNG_OS_VERSION','5\.1(?:6[5-9]|[7-9]\d)\.\d+'\)/);
const slice=(start,end)=>{
  const a=client.indexOf(start),b=client.indexOf(end,a);
  assert.ok(a>=0 && b>a);
  return client.slice(a,b);
};
const warningCode=slice('const adventureDraftFields =','const scheduleWindows =');
const inputCode=slice("root.addEventListener('input'","sort?.addEventListener");
const postCode=slice('let libraryUpdatePending = false;','const cleanupPrint =');
const submitCode=slice("root.addEventListener('submit'",'\n})();');
assert.match(warningCode,/textarea\[name="notes"\]/);
assert.match(warningCode,/window\.removeEventListener\('beforeunload', warnAboutUnsavedDrafts\)/);
assert.match(warningCode,/window\.addEventListener\('pageshow', syncDraftExitWarning\)/);
assert.match(warningCode,/event\.preventDefault\(\);\s*event\.returnValue = true;/);
assert.doesNotMatch(warningCode,/fetch\(|post\(|localStorage|sessionStorage|indexedDB|setTimeout|setInterval|sendBeacon|console\.|innerHTML|\.hidden/);
assert.match(submitCode,/notes\.defaultValue = savedNotes;/);

const deferred=()=>{let resolve,reject;const promise=new Promise((yes,no)=>{resolve=yes;reject=no;});return {promise,resolve,reject};};
const harness=(initial=['Saved A','Saved B'],restored={})=>{
  const handlers={},listeners=new Map(),requests=[],status={textContent:''};
  const items=initial.map((text,index)=>{
    const state={textContent:text?'Saved':'Optional'},count={textContent:text.length+' of 600'},button={disabled:false},printed={textContent:text};
    const print={hidden:!text,querySelector:()=>printed};
    const panel={classList:{toggle(){}},querySelector:()=>state};
    const card={hidden:false,dataset:{planId:'owned-'+index},querySelector:()=>print};
    const field={value:restored[index]??text,defaultValue:text,closest:selector=>(selector==='[data-tng-plan-notes]'||selector==='[data-tng-plan-notes],[data-tng-plan-rename]')?form:selector==='[data-tng-plan-notes-panel]'?panel:selector==='[data-tng-plan-notes] textarea'?field:null};
    const form={closest:selector=>selector==='[data-tng-plan-notes]'?form:selector==='[data-plan-id]'?card:selector==='[data-tng-plan-notes-panel]'?panel:null,
      querySelector:selector=>selector==='textarea[name="notes"]'?field:selector==='button[type="submit"]'?button:count};
    return {field,form,card,state,count,button,printed};
  });
  const context={URLSearchParams,status,
    root:{dataset:{ajaxUrl:'/wp-admin/admin-ajax.php',nonce:'test-nonce'},querySelectorAll:selector=>{assert.equal(selector,'[data-tng-plan-notes] textarea[name="notes"], [data-tng-plan-rename] input[name="title"]');return items.map(x=>x.field);},addEventListener:(type,handler)=>{handlers[type]=handler;}},
    window:{addEventListener:(type,handler)=>{if(!listeners.has(type))listeners.set(type,new Set());listeners.get(type).add(handler);},removeEventListener:(type,handler)=>listeners.get(type)?.delete(handler)},
    fetch:(url,options)=>{const request=deferred();requests.push({url,options,...request});return request.promise;}};
  vm.runInNewContext(`${warningCode}\n${inputCode}\n${postCode}\n${submitCode}`,context);
  return {items,status,requests,listeners,context,
    warningCount:()=>listeners.get('beforeunload')?.size??0,
    type(index,value){items[index].field.value=value;handlers.input({target:items[index].field});},
    submit:index=>handlers.submit({target:items[index].form,preventDefault(){}}),
    pageshow:()=>listeners.get('pageshow')?.forEach(handler=>handler()),
    exit:()=>{const event={prevented:false,returnValue:'',preventDefault(){this.prevented=true;}};listeners.get('beforeunload')?.forEach(handler=>handler(event));return event;},
    unrelatedInput:()=>handlers.input({target:{closest:()=>null}})};
};
const success=(request,notes)=>request.resolve({json:async()=>({success:true,data:{notes,message:'Planning notes updated.'}})});

let h=harness();assert.equal(h.warningCount(),0);assert.equal(h.exit().prevented,false);
h.unrelatedInput();assert.equal(h.warningCount(),0,'Unrelated inputs do not arm a clean-page warning');
h.type(0,'Draft A');h.type(0,'Draft A again');assert.equal(h.warningCount(),1,'Repeated edits never duplicate the handler');
let event=h.exit();assert.equal(event.prevented,true);assert.equal(event.returnValue,true,'The warning contains no private notes text');
h.items[1].card.hidden=true;h.type(1,'Hidden draft');h.type(0,'Saved A');
assert.equal(h.items[0].state.textContent,'Saved');assert.equal(h.warningCount(),1,'A hidden card still has an unsaved draft');
h.type(1,'Saved B');assert.equal(h.warningCount(),0,'Every field must be clean before removing the warning');
assert.equal(h.requests.length,0,'Typing and departure never save or transmit notes');
h=harness(['']);h.type(0,'Draft');h.type(0,'');assert.equal(h.items[0].state.textContent,'Optional');assert.equal(h.warningCount(),0);

h=harness(['Old']);h.type(0,' <b>Clean</b> ');let saving=h.submit(0);
assert.equal(h.exit().prevented,true);success(h.requests[0],'Clean');await saving;
assert.equal(h.items[0].field.value,'Clean');assert.equal(h.items[0].field.defaultValue,'Clean');assert.equal(h.warningCount(),0);
h.type(0,'');saving=h.submit(0);success(h.requests[1],'');await saving;
assert.equal(h.items[0].field.defaultValue,'');assert.equal(h.items[0].state.textContent,'Optional');assert.equal(h.warningCount(),0);

h=harness(['Old']);h.type(0,'Submitted');saving=h.submit(0);h.type(0,'Newer draft');success(h.requests[0],'Submitted');await saving;
assert.equal(h.items[0].field.value,'Newer draft');assert.equal(h.items[0].field.defaultValue,'Submitted');
assert.equal(h.items[0].printed.textContent,'Submitted');assert.equal(h.warningCount(),1);
h.type(0,'Submitted');assert.equal(h.warningCount(),0,'Reverting to the newly confirmed text removes the warning');
h.type(0,'Another submission');saving=h.submit(0);h.type(0,'Submitted');
assert.equal(h.warningCount(),1,'Reverting during a pending save remains protected');
success(h.requests[1],'Another submission');await saving;
assert.equal(h.items[0].field.value,'Submitted');assert.equal(h.items[0].field.defaultValue,'Another submission');assert.equal(h.warningCount(),1);

for(const failure of ['server','network','invalid-json']) {
  h=harness(['Original']);h.type(0,'Unsaved draft');saving=h.submit(0);
  if(failure==='server')h.requests[0].resolve({json:async()=>({success:false,data:{message:'Could not save.'}})});
  if(failure==='network')h.requests[0].reject(new Error('Network unavailable'));
  if(failure==='invalid-json')h.requests[0].resolve({json:async()=>{throw new Error('Invalid response');}});
  await saving;
  assert.equal(h.items[0].field.defaultValue,'Original');assert.equal(h.items[0].field.value,'Unsaved draft');
  assert.equal(h.warningCount(),1);assert.equal(h.items[0].button.disabled,false);assert.equal(h.requests.length,1);
  saving=h.submit(0);success(h.requests[1],'Unsaved draft');await saving;
  assert.equal(h.warningCount(),0,'A later explicit successful retry clears the warning');
}
h=harness(['Same']);saving=h.submit(0);assert.equal(h.warningCount(),1,'An unchanged save awaiting confirmation is protected');
success(h.requests[0],'Same');await saving;assert.equal(h.warningCount(),0);
h=harness();h.type(0,'First draft');h.type(1,'Second draft');saving=h.submit(0);await h.submit(1);
assert.equal(h.requests.length,1);assert.match(h.status.textContent,/Another adventure update is still saving/);
success(h.requests[0],'First draft');await saving;assert.equal(h.warningCount(),1,'Saving one plan cannot clear another plan draft');
saving=h.submit(1);success(h.requests[1],'Second draft');await saving;assert.equal(h.warningCount(),0);

h=harness(['Initial'],{0:'Restored draft'});assert.equal(h.warningCount(),1,'Restored input differs from the rendered saved baseline');
h.items[0].field.value='Initial';h.pageshow();assert.equal(h.warningCount(),0);
h.items[0].field.value='Restored later';h.pageshow();assert.equal(h.warningCount(),1,'Page restoration resynchronizes warning state');
const foreign=()=>{};h.context.window.addEventListener('beforeunload',foreign);h.type(0,'Initial');
assert.equal(h.listeners.get('beforeunload').has(foreign),true,'Do not remove another component warning');
h=harness([]);h.pageshow();assert.equal(h.warningCount(),0);assert.equal(h.exit().prevented,false);
console.log('TN Game OS 5.165.0 Unsaved Planning Notes Exit Warning passed');
