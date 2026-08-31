import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const root=new URL('../experiences/wordpress/tn-game-os/',import.meta.url);
const read=path=>fs.readFileSync(new URL(path,root),'utf8');
const bootstrap=read('tn-game-os.php');
const client=read('assets/js/saved-adventures.js');
const server=read('app/Modules/Destinations/class-adventure-ai.php');
assert.match(bootstrap,/Version:\s*5\.1[6-9]\d\.\d+/);
assert.match(bootstrap,/define\('TNG_OS_VERSION','5\.1[6-9]\d\.\d+'\)/);
assert.match(client,/const submittedNotes = notes\.value;/);
assert.match(client,/operation:'notes',plan_id:card\.dataset\.planId \|\| '',notes:submittedNotes/);
assert.match(client,/const savedNotes = typeof data\.notes === 'string' \? data\.notes : submittedNotes;/);
assert.match(server,/if \(\$operation === 'notes'\) \$response\['notes'\] = \(string\)\(\$library\[\$index\]\['notes'\] \?\? ''\);/);
assert.match(server,/wp_send_json_success\(\$response\);/);
const endpoint=server.slice(server.indexOf('public function ajax_library_action()'),server.indexOf('private static function library('));
assert.ok(endpoint.indexOf('check_ajax_referer') < endpoint.indexOf("$response['notes']"));
assert.ok(endpoint.indexOf('self::library(get_current_user_id())') < endpoint.indexOf("$response['notes']"));
assert.ok(endpoint.indexOf('update_user_meta') < endpoint.indexOf("$response['notes']"));

const inputStart=client.indexOf("root.addEventListener('input'");
const inputEnd=client.indexOf("sort?.addEventListener",inputStart);
const submitStart=client.indexOf("root.addEventListener('submit'");
const submitEnd=client.lastIndexOf('\n})();');
assert.ok(inputStart > -1 && inputEnd > inputStart && submitStart > -1 && submitEnd > submitStart);
const inputCode=client.slice(inputStart,inputEnd);
const submitCode=client.slice(submitStart,submitEnd);
const notesCode=submitCode.slice(0,submitCode.indexOf('const schedule ='));
assert.equal((notesCode.match(/await post\(/g)||[]).length,1);
assert.doesNotMatch(notesCode,/innerHTML|localStorage|sessionStorage|setTimeout|setInterval|fetch\(/);

const deferred=()=>{let resolve,reject;const promise=new Promise((yes,no)=>{resolve=yes;reject=no;});return {promise,resolve,reject};};
const harness=(initial='Old saved notes',withPrint=true)=>{
  const handlers={},requests=[],classes=new Set(initial?['has-notes']:[]);
  const state={textContent:initial?'Saved':'Optional'};
  const count={textContent:initial.length+' of 600'};
  const button={disabled:false};
  const printed={textContent:initial};
  const print={hidden:!initial,querySelector:()=>printed};
  const panel={classList:{toggle:(name,enabled)=>enabled?classes.add(name):classes.delete(name)},querySelector:()=>state};
  const card={dataset:{planId:'owned-plan'},querySelector:selector=>selector==='[data-tng-print-notes]'&&withPrint?print:null};
  const notes={value:initial,defaultValue:initial,closest:selector=>selector==='[data-tng-plan-notes]'?form:selector==='[data-tng-plan-notes-panel]'?panel:selector==='[data-tng-plan-notes] textarea'?notes:null};
  const form={closest:selector=>selector==='[data-tng-plan-notes]'?form:selector==='[data-plan-id]'?card:selector==='[data-tng-plan-notes-panel]'?panel:null,
    querySelector:selector=>selector==='textarea[name="notes"]'?notes:selector==='button[type="submit"]'?button:selector==='[data-tng-notes-count]'?count:null};
  const status={textContent:''};
  const context={root:{addEventListener:(type,callback)=>{handlers[type]=callback;}},status,syncNotesExitWarning:()=>{},
    post:fields=>{const result=deferred();requests.push({fields,...result});return result.promise;}};
  vm.runInNewContext(`${inputCode}\n${submitCode}`,context);
  return {notes,state,count,button,printed,print,status,requests,classes,
    type(value){notes.value=value;handlers.input({target:notes});},
    submit:()=>handlers.submit({target:form,preventDefault(){}})};
};

let h=harness();
h.type('Submitted text');
assert.equal(h.state.textContent,'Unsaved changes');
assert.equal(h.count.textContent,'14 of 600');
let saving=h.submit();
assert.equal(h.button.disabled,true);
assert.equal(h.requests[0].fields.notes,'Submitted text');
h.type('Newer unsaved text');
h.requests[0].resolve({notes:'Submitted text',message:'Planning notes updated.'});
await saving;
assert.equal(h.notes.value,'Newer unsaved text');
assert.equal(h.state.textContent,'Unsaved changes');
assert.equal(h.count.textContent,'18 of 600');
assert.equal(h.printed.textContent,'Submitted text');
assert.equal(h.print.hidden,false);
assert.equal(h.button.disabled,false);
assert.match(h.status.textContent,/newer edits are not saved yet/);
assert.equal(h.requests.length,1,'Newer text is never automatically saved');
saving=h.submit();
assert.equal(h.requests[1].fields.notes,'Newer unsaved text');
h.requests[1].resolve({notes:'Newer unsaved text',message:'Planning notes updated.'});await saving;
assert.equal(h.state.textContent,'Saved');
assert.equal(h.printed.textContent,'Newer unsaved text');
assert.equal(h.status.textContent,'Planning notes updated.');

h=harness();h.type(' <b>Meet at gate</b> ');saving=h.submit();
h.requests[0].resolve({notes:'Meet at gate',message:'Saved.'});await saving;
assert.equal(h.notes.value,'Meet at gate','Server normalization is reflected when no newer edits exist');
assert.equal(h.count.textContent,'12 of 600');
assert.equal(h.printed.textContent,'Meet at gate');
assert.equal(h.state.textContent,'Saved');

for(const editAfterSubmit of ['', 'A newer draft']) {
  h=harness();h.type('');saving=h.submit();h.type(editAfterSubmit);
  h.requests[0].resolve({notes:'',message:'Saved.'});await saving;
  assert.equal(h.notes.value,editAfterSubmit);
  assert.equal(h.state.textContent,editAfterSubmit?'Unsaved changes':'Optional');
  assert.equal(h.print.hidden,true,'A confirmed clear removes old printed notes');
  assert.equal(h.printed.textContent,'');
  assert.equal(h.classes.has('has-notes'),false);
}
h=harness();h.type(' <b>Clean</b> ');saving=h.submit();h.type('Clean');
h.requests[0].resolve({notes:'Clean',message:'Saved.'});await saving;
assert.equal(h.state.textContent,'Saved','Text already matching the canonical response is not falsely marked unsaved');

for(const response of [{message:'Saved.'},{notes:null,message:'Saved.'},{notes:42,message:'Saved.'}]) {
  h=harness();h.type('Submitted snapshot');saving=h.submit();h.type('Later draft');
  h.requests[0].resolve(response);await saving;
  assert.equal(h.printed.textContent,'Submitted snapshot','Legacy responses never read newer editor text as saved');
  assert.equal(h.notes.value,'Later draft');
  assert.equal(h.state.textContent,'Unsaved changes');
}
for(const message of ['Network unavailable','Another adventure update is still saving.']) {
  h=harness();h.type('Submitted snapshot');saving=h.submit();h.type('Keep my newer draft');
  h.requests[0].reject(new Error(message));await saving;
  assert.equal(h.notes.value,'Keep my newer draft');
  assert.equal(h.printed.textContent,'Old saved notes');
  assert.equal(h.state.textContent,'Unsaved changes');
  assert.equal(h.status.textContent,message);
  assert.equal(h.button.disabled,false);
  assert.equal(h.requests.length,1);
}
h=harness('',false);h.type('Private note');saving=h.submit();
h.requests[0].resolve({notes:'Private note',message:'Saved.'});await saving;
assert.equal(h.state.textContent,'Saved','A missing optional print panel does not break saving');
console.log('TN Game OS 5.160.0 Snapshot-Safe Notes Saving passed');
