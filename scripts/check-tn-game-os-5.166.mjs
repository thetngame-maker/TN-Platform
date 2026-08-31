import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const root=new URL('../experiences/wordpress/tn-game-os/',import.meta.url);
const read=path=>fs.readFileSync(new URL(path,root),'utf8');
const bootstrap=read('tn-game-os.php'),client=read('assets/js/saved-adventures.js');
assert.match(bootstrap,/Version:\s*5\.1(?:6[6-9]|[7-9]\d)\.\d+/);
assert.match(bootstrap,/define\('TNG_OS_VERSION','5\.1(?:6[6-9]|[7-9]\d)\.\d+'\)/);
const slice=(start,end)=>{
  const a=client.indexOf(start),b=client.indexOf(end,a);
  assert.ok(a>=0 && b>a);
  return client.slice(a,b);
};
const warningCode=slice('const adventureDraftFields =','const scheduleWindows =');
const inputCode=slice("root.addEventListener('input'","sort?.addEventListener");
const postCode=slice('let libraryUpdatePending = false;','const cleanupPrint =');
const submitCode=slice("root.addEventListener('submit'",'\n})();');
assert.match(warningCode,/textarea\[name="notes"\], \[data-tng-plan-rename\] input\[name="title"\]/);
assert.match(warningCode,/field\.value !== field\.defaultValue/);
assert.match(warningCode,/event\.returnValue = true/);
assert.doesNotMatch(warningCode,/fetch\(|post\(|localStorage|sessionStorage|indexedDB|setTimeout|setInterval|sendBeacon|console\.|innerHTML|\.hidden/);
assert.match(submitCode,/input\.defaultValue = savedTitle;/);
const deferred=()=>{let resolve,reject;const promise=new Promise((yes,no)=>{resolve=yes;reject=no;});return {promise,resolve,reject};};

// Run the real shared warning, input, request, notes, and rename handlers together.
const harness=(restored={})=>{
  const handlers={},listeners=new Map(),requests=[],status={textContent:''};
  const plans=[0,1].map(index=>{
    const title={textContent:'Plan '+index},printTitle={textContent:title.textContent};
    const state={textContent:'Saved'},count={textContent:'7 of 600'},printed={textContent:'Notes '+index};
    const print={hidden:false,querySelector:()=>printed};
    const panel={querySelector:()=>state,classList:{toggle(){}}};
    const card={hidden:false,dataset:{planId:'owned-'+index},querySelector:selector=>selector==='[data-plan-title]'?title:selector==='[data-plan-print-title]'?printTitle:selector==='[data-tng-print-notes]'?print:null};
    const makeForm=(kind,initial)=>{
      const button={disabled:false};
      const field={value:restored[index+':'+kind]??initial,defaultValue:initial,focusCalls:0,focus(){this.focusCalls++;},closest:selector=>{
        if(selector==='[data-tng-plan-notes],[data-tng-plan-rename],[data-tng-plan-schedule]' || selector===`[data-tng-plan-${kind}]`)return form;
        if(kind==='notes' && selector==='[data-tng-plan-notes] textarea')return field;
        if(kind==='notes' && selector==='[data-tng-plan-notes-panel]')return panel;
        return null;
      }};
      const form={closest:selector=>selector===`[data-tng-plan-${kind}]`?form:selector==='[data-plan-id]'?card:selector==='[data-tng-plan-notes-panel]'&&kind==='notes'?panel:null,
        querySelector:selector=>selector==='button[type="submit"]'?button:selector==='[data-tng-notes-count]'?count:field};
      return {field,form,button};
    };
    return {card,title,printTitle,state,printed,rename:makeForm('rename',title.textContent),notes:makeForm('notes',printed.textContent)};
  });
  const context={URLSearchParams,status,nextCard:null,updatePrepOverview:()=>{},applyFilters:()=>{},
    root:{dataset:{ajaxUrl:'/wp-admin/admin-ajax.php',nonce:'test-nonce'},querySelectorAll:selector=>{
      assert.equal(selector,'[data-tng-plan-notes] textarea[name="notes"], [data-tng-plan-rename] input[name="title"], [data-tng-plan-schedule] input[name="planned_date"]');
      return plans.flatMap(plan=>[plan.notes.field,plan.rename.field]);
    },addEventListener:(type,handler)=>{handlers[type]=handler;}},
    window:{addEventListener:(type,handler)=>{if(!listeners.has(type))listeners.set(type,new Set());listeners.get(type).add(handler);},removeEventListener:(type,handler)=>listeners.get(type)?.delete(handler)},
    fetch:(url,options)=>{const request=deferred();requests.push({url,options,...request});return request.promise;}};
  vm.runInNewContext(`${warningCode}\n${inputCode}\n${postCode}\n${submitCode}`,context);
  return {plans,requests,status,warningCount:()=>listeners.get('beforeunload')?.size??0,
    type(index,kind,value){plans[index][kind].field.value=value;handlers.input({target:plans[index][kind].field});},
    submit:(index,kind)=>handlers.submit({target:plans[index][kind].form,preventDefault(){}}),
    pageshow:()=>listeners.get('pageshow')?.forEach(handler=>handler()),
    exit:()=>{const event={prevented:false,returnValue:'',preventDefault(){this.prevented=true;}};listeners.get('beforeunload')?.forEach(handler=>handler(event));return event;}};
};
const success=(request,data)=>request.resolve({json:async()=>({success:true,data:{message:'Saved.',...data}})});

let h=harness();assert.equal(h.warningCount(),0);
h.type(0,'rename','Draft name');h.type(0,'rename','Another draft');assert.equal(h.warningCount(),1);
let event=h.exit();assert.equal(event.prevented,true);assert.equal(event.returnValue,true,'No private name is placed in the browser dialog');
h.type(0,'rename','Plan 0');assert.equal(h.warningCount(),0);
h.plans[1].card.hidden=true;h.type(1,'rename','Hidden draft');assert.equal(h.warningCount(),1);
h.type(1,'rename','Plan 1');assert.equal(h.warningCount(),0);assert.equal(h.requests.length,0);

h=harness();h.type(0,'rename','  <b>Canonical name</b>  ');let saving=h.submit(0,'rename');
assert.equal(h.requests[0].options.body.get('title'),'<b>Canonical name</b>');
assert.equal(h.requests[0].options.body.get('nonce'),'test-nonce');assert.equal(h.requests[0].options.credentials,'same-origin');
assert.equal(h.warningCount(),1);success(h.requests[0],{title:'Canonical name'});await saving;
assert.equal(h.plans[0].rename.field.value,'Canonical name');assert.equal(h.plans[0].rename.field.defaultValue,'Canonical name');
assert.equal(h.plans[0].title.textContent,'Canonical name');assert.equal(h.plans[0].printTitle.textContent,'Canonical name');assert.equal(h.warningCount(),0);
h.type(0,'rename','Submitted');saving=h.submit(0,'rename');h.type(0,'rename','Newer draft');success(h.requests[1],{title:'Submitted'});await saving;
assert.equal(h.plans[0].rename.field.value,'Newer draft');assert.equal(h.plans[0].rename.field.defaultValue,'Submitted');assert.equal(h.warningCount(),1);
h.type(0,'rename','Submitted');assert.equal(h.warningCount(),0);
h.type(0,'rename','Next submission');saving=h.submit(0,'rename');h.type(0,'rename','Submitted');
assert.equal(h.warningCount(),1,'Reverting during an in-flight rename stays protected');success(h.requests[2],{title:'Next submission'});await saving;
assert.equal(h.plans[0].rename.field.value,'Submitted');assert.equal(h.plans[0].rename.field.defaultValue,'Next submission');assert.equal(h.warningCount(),1);

for(const failure of ['server','network','invalid-json']) {
  h=harness();h.type(0,'rename','Keep this name');saving=h.submit(0,'rename');
  if(failure==='server')h.requests[0].resolve({json:async()=>({success:false,data:{message:'Rename failed.'}})});
  if(failure==='network')h.requests[0].reject(new Error('Network unavailable'));
  if(failure==='invalid-json')h.requests[0].resolve({json:async()=>{throw new Error('Invalid response');}});
  await saving;
  assert.equal(h.plans[0].rename.field.defaultValue,'Plan 0');assert.equal(h.plans[0].rename.field.value,'Keep this name');
  assert.equal(h.plans[0].title.textContent,'Plan 0');assert.equal(h.plans[0].rename.button.disabled,false);assert.equal(h.warningCount(),1);assert.equal(h.requests.length,1);
  saving=h.submit(0,'rename');success(h.requests[1],{title:'Keep this name'});await saving;assert.equal(h.warningCount(),0);
}
for(const firstKind of ['rename','notes']) {
  h=harness();h.type(0,'rename','New name');h.type(0,'notes','New notes');
  const otherKind=firstKind==='rename'?'notes':'rename';
  saving=h.submit(0,firstKind);await h.submit(0,otherKind);
  assert.equal(h.requests.length,1);assert.match(h.status.textContent,/Another adventure update is still saving/);
  assert.equal(h.plans[0][otherKind].button.disabled,false);assert.equal(h.warningCount(),1);
  success(h.requests[0],firstKind==='rename'?{title:'New name'}:{notes:'New notes'});await saving;
  assert.equal(h.warningCount(),1,'Saving one field cannot clear another field draft');
  saving=h.submit(0,otherKind);success(h.requests[1],otherKind==='rename'?{title:'New name'}:{notes:'New notes'});await saving;
  assert.equal(h.warningCount(),0,'A single shared handler is removed once all fields are confirmed');
}
h=harness();h.type(0,'rename','First draft');h.type(1,'rename','Second draft');saving=h.submit(0,'rename');success(h.requests[0],{title:'First draft'});await saving;
assert.equal(h.warningCount(),1);h.type(1,'rename','Plan 1');assert.equal(h.warningCount(),0);
h=harness();saving=h.submit(0,'rename');assert.equal(h.warningCount(),1,'Unchanged names awaiting confirmation are protected');success(h.requests[0],{title:'Plan 0'});await saving;assert.equal(h.warningCount(),0);
h.type(0,'rename','   ');await h.submit(0,'rename');assert.equal(h.plans[0].rename.field.focusCalls,1);assert.equal(h.requests.length,1);assert.equal(h.warningCount(),1,'Rejected empty names retain their draft warning');
h=harness();h.type(0,'rename','Fallback');saving=h.submit(0,'rename');h.type(0,'rename','Later');success(h.requests[0],{});await saving;
assert.equal(h.plans[0].rename.field.defaultValue,'Fallback');assert.equal(h.plans[0].rename.field.value,'Later');assert.equal(h.warningCount(),1);
h=harness({'0:rename':'Restored name'});assert.equal(h.warningCount(),1);h.plans[0].rename.field.value='Plan 0';h.pageshow();assert.equal(h.warningCount(),0);
h.plans[1].rename.field.value='Restored later';h.pageshow();assert.equal(h.warningCount(),1);
console.log('TN Game OS 5.166.0 Unsaved Adventure Name Exit Warning passed');
