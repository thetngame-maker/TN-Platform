import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const root=new URL('../experiences/wordpress/tn-game-os/',import.meta.url);
const read=path=>fs.readFileSync(new URL(path,root),'utf8');
const bootstrap=read('tn-game-os.php');
const client=read('assets/js/saved-adventures.js');
const server=read('app/Modules/Destinations/class-adventure-ai.php');
assert.match(bootstrap,/Version:\s*5\.1(?:6[1-9]|[7-9]\d)\.\d+/);
assert.match(bootstrap,/define\('TNG_OS_VERSION','5\.1(?:6[1-9]|[7-9]\d)\.\d+'\)/);
assert.match(client,/const submittedTitle = input\.value;/);
assert.match(client,/const savedTitle = typeof data\.title === 'string' && data\.title !== '' \? data\.title : title;/);
assert.match(server,/if \(\$operation === 'rename'\) \$response\['title'\] = \(string\)\$library\[\$index\]\['title'\];/);
assert.match(server,/\(\$operation === 'notes' \|\| \$operation === 'rename'\) && \$updated === false/);
const endpoint=server.slice(server.indexOf('public function ajax_library_action()'),server.indexOf('private static function library('));
assert.ok(endpoint.indexOf('check_ajax_referer') < endpoint.indexOf("$response['title']"));
assert.ok(endpoint.indexOf('self::library(get_current_user_id())') < endpoint.indexOf("$response['title']"));
assert.ok(endpoint.indexOf('update_user_meta') < endpoint.indexOf("$response['title']"));
const submitStart=client.indexOf("root.addEventListener('submit'");
const submitEnd=client.lastIndexOf('\n})();');
assert.ok(submitStart > -1 && submitEnd > submitStart);
const submitCode=client.slice(submitStart,submitEnd);
const renameCode=submitCode.slice(submitCode.indexOf("const form = event.target.closest('[data-tng-plan-rename]')"));
assert.match(renameCode,/updatePrepOverview\(\);\s*applyFilters\(\);/);
assert.equal((renameCode.match(/await post\(/g)||[]).length,1);
assert.doesNotMatch(renameCode,/innerHTML|localStorage|sessionStorage|setTimeout|setInterval|fetch\(/);
const viewsCode=client.slice(client.indexOf('const launchCountFor ='),client.indexOf('const refreshPrepViews ='));
const deferred=()=>{let resolve,reject;const promise=new Promise((yes,no)=>{resolve=yes;reject=no;});return {promise,resolve,reject};};

const harness=({withPrint=true,isNext=true,withOverview=true}={})=>{
  let handler;
  const title={textContent:'Old name'},printed={textContent:'Old name'},banner={textContent:'Old name'};
  const input={value:'Old name',focusCalls:0,focus(){this.focusCalls+=1;}};
  const button={disabled:false},status={textContent:''};
  const fields=new Map(),requests=[],appended=[];
  const card={dataset:{planId:'owned-plan',planDate:'2026-08-30',planStart:'600',planState:'ready',planReadyCount:'0',planPackedCount:'0',planUpdated:'0'},
    get textContent(){return title.textContent;},
    querySelector:selector=>selector==='[data-plan-title]'?title:selector==='[data-plan-print-title]'&&withPrint?printed:null,
    querySelectorAll:()=>[]};
  const form={closest:selector=>selector==='[data-tng-plan-rename]'?form:selector==='[data-plan-id]'?card:null,
    querySelector:selector=>selector==='input[name="title"]'?input:selector==='button[type="submit"]'?button:null};
  const context={
    root:{addEventListener:(type,callback)=>{assert.equal(type,'submit');handler=callback;},querySelector:()=>banner},status,
    post:payload=>{const result=deferred();requests.push({payload,...result});return result.promise;},
    cards:[card],nextCard:isNext?card:null,todayKey:'2026-08-30',priorityPrep:null,prepFocus:null,
    prepOverview:withOverview?{querySelector:selector=>{if(!fields.has(selector))fields.set(selector,{textContent:''});return fields.get(selector);}}:null,
    sort:{value:'title'},search:{value:''},selectedFilter:'all',grid:{append:item=>appended.push(item)},filterStatus:null,filterEmpty:null
  };
  vm.runInNewContext(`${viewsCode}\n${submitCode}\nupdatePrepOverview();`,context);
  return {context,title,printed,banner,input,button,status,requests,appended,card,
    overview:()=>fields.get('[data-tng-prep-priority]')?.textContent,
    submit:()=>handler({target:form,preventDefault(){}})};
};
const resolve=(request,title)=>request.resolve({title,message:'Adventure renamed.'});

let h=harness();h.input.value='  <b>Canonical name</b>  ';let saving=h.submit();
assert.equal(h.requests[0].payload.title,'<b>Canonical name</b>');
assert.equal(h.button.disabled,true);
assert.equal(h.title.textContent,'Old name','Displayed name stays confirmed while saving');
resolve(h.requests[0],'Canonical name');await saving;
assert.equal(h.input.value,'Canonical name');
assert.equal(h.title.textContent,'Canonical name');
assert.equal(h.printed.textContent,'Canonical name');
assert.equal(h.banner.textContent,'Canonical name');
assert.match(h.overview(),/^Canonical name is the closest plan/,'The real prep overview refreshes after renaming');
assert.equal(h.appended.length,1,'Search and sort refresh after the confirmed rename');
assert.equal(h.status.textContent,'Adventure renamed.');
assert.equal(h.button.disabled,false);

h=harness();h.input.value='Submitted name';saving=h.submit();h.input.value='Newer name draft';
resolve(h.requests[0],'Submitted name');await saving;
assert.equal(h.input.value,'Newer name draft');
assert.equal(h.title.textContent,'Submitted name');
assert.equal(h.printed.textContent,'Submitted name');
assert.equal(h.banner.textContent,'Submitted name');
assert.match(h.overview(),/^Submitted name is the closest plan/);
assert.match(h.status.textContent,/newer name edit is not saved yet/);
assert.equal(h.requests.length,1,'Newer text is not automatically submitted');
saving=h.submit();assert.equal(h.requests[1].payload.title,'Newer name draft');
resolve(h.requests[1],'Newer name draft');await saving;
assert.equal(h.title.textContent,'Newer name draft');
assert.equal(h.status.textContent,'Adventure renamed.');

h=harness();h.input.value='x'.repeat(110);saving=h.submit();resolve(h.requests[0],'x'.repeat(100));await saving;
assert.equal(h.title.textContent.length,100,'Displayed name uses the server length limit');
assert.equal(h.input.value.length,100);
h=harness();h.input.value='<b>Same name</b>';saving=h.submit();h.input.value='  Same name  ';
resolve(h.requests[0],'Same name');await saving;
assert.equal(h.input.value,'Same name');
assert.equal(h.status.textContent,'Adventure renamed.','An edit already matching the canonical name is not falsely unsaved');

for(const response of [{message:'Saved.'},{title:null,message:'Saved.'},{title:'',message:'Saved.'},{title:42,message:'Saved.'}]) {
  h=harness();h.input.value=' Submitted fallback ';saving=h.submit();h.input.value='Later draft';
  h.requests[0].resolve(response);await saving;
  assert.equal(h.input.value,'Later draft');
  assert.equal(h.title.textContent,'Submitted fallback','Older responses use the submitted snapshot, never newer text');
}
for(const message of ['Network unavailable','Another adventure update is still saving.','Adventure name could not be saved.']) {
  h=harness();h.input.value='Submitted name';saving=h.submit();h.input.value='Keep my draft';
  h.requests[0].reject(new Error(message));await saving;
  assert.equal(h.input.value,'Keep my draft');
  assert.equal(h.title.textContent,'Old name');
  assert.equal(h.printed.textContent,'Old name');
  assert.equal(h.banner.textContent,'Old name');
  assert.match(h.overview(),/^Old name is the closest plan/);
  assert.equal(h.appended.length,0);
  assert.equal(h.status.textContent,message);
  assert.equal(h.button.disabled,false);
  assert.equal(h.requests.length,1);
}
h=harness();h.input.value='   ';await h.submit();
assert.equal(h.requests.length,0);assert.equal(h.input.focusCalls,1);assert.equal(h.button.disabled,false);
h=harness({isNext:false,withPrint:false,withOverview:false});h.input.value='Another name';saving=h.submit();
resolve(h.requests[0],'Another name');await saving;
assert.equal(h.title.textContent,'Another name');assert.equal(h.banner.textContent,'Old name','Another adventure never changes the Next adventure title');
console.log('TN Game OS 5.161.0 Consistent Saved Adventure Names passed');
