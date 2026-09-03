import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const root=new URL('../experiences/wordpress/tn-game-os/',import.meta.url);
const read=path=>fs.readFileSync(new URL(path,root),'utf8');
const bootstrap=read('tn-game-os.php'),client=read('assets/js/saved-adventures.js');
const php=read('app/Modules/Destinations/class-adventure-ai.php'),css=read('assets/css/saved-adventures.css');
assert.match(bootstrap,/Version:\s*5\.1(?:6[8-9]|[7-9]\d)\.\d+/);
assert.match(bootstrap,/define\('TNG_OS_VERSION','5\.1(?:6[8-9]|[7-9]\d)\.\d+'\)/);
const slice=(start,end)=>{const a=client.indexOf(start),b=client.indexOf(end,a);assert.ok(a>=0&&b>a);return client.slice(a,b);};
const warningCode=slice('const adventureDraftFields =','const scheduleWindows =');
const refreshCode=slice('const scheduleRefresh =','const draftReview =');
const reviewCode=slice('const draftReview =',"prepOverview?.addEventListener('click'");
const filterCode=slice('const applyFilters =','const refreshPrepViews =');
const controlsCode=slice('const syncFilterControls =','const updateCardLaunchStatus =');
const inputCode=slice("root.addEventListener('input'","sort?.addEventListener");
const postCode=slice('let libraryUpdatePending = false;','const cleanupPrint =');
const clickCode=slice("root.addEventListener('click'","root.addEventListener('submit'");
const submitCode=slice("root.addEventListener('submit'",'\n})();');
assert.doesNotMatch(reviewCode,/fetch\(|post\(|savePreferences|localStorage|sessionStorage|indexedDB|setTimeout|setInterval|sendBeacon|console\.|innerHTML|\.submit\(/);
assert.match(reviewCode,/\+\+planNavigationRequest/);
assert.match(reviewCode,/field\.focus\(\{preventScroll:true\}\)/);
assert.match(css,/draft-review\[hidden\]\{display:none\}/);
assert.match(css,/@media print\{\.tng-adventure-library__draft-review\{display:none!important\}\}/);
const markup=php.match(/<section class="tng-adventure-library__draft-review"[^]*?<\/section>/)?.[0];
assert.ok(markup);assert.match(markup,/aria-label="Unsaved adventure edits" hidden/);
assert.match(markup,/aria-live="polite" aria-atomic="true"/);
assert.match(markup,/type="button" data-tng-draft-review-next/);
assert.doesNotMatch(markup,/<\?php|\$plan|\$notes/);
assert.equal(php.split('data-tng-draft-review aria-label').length-1,1);
assert.ok(php.indexOf(markup)>php.indexOf('<?php else: ?>',php.indexOf('public static function render_library')));

const deferred=()=>{let resolve,reject;const promise=new Promise((yes,no)=>{resolve=yes;reject=no;});return {promise,resolve,reject};};
const success=(request,data={})=>request.resolve({json:async()=>({success:true,data:{message:'Saved.',...data}})});
// DOM stand-ins run the actual review/filter and save handlers, not a second
// implementation. These checks do not claim visual or native browser coverage.
export const harness=({restored=false,summaryPresent=true,refreshPresent=true,refreshMessageId='tng-schedule-refresh-message'}={})=>{
  const handlers={},captureHandlers={},listeners=new Map(),requests=[],reloads=[],focus=[],scroll=[],status={textContent:''};
  const scheduleDependentSelector='[data-tng-readiness-key],[data-tng-packing-key],[data-tng-prep-focus],[data-tng-next-action],[data-tng-plan-start],[data-tng-plan-calendar],[data-tng-upcoming-calendar],[data-tng-plan-print]';
  const scheduleControls=scheduleDependentSelector.split(',').map(selector=>{
    const classes=new Set(),control={selector,disabled:false,attributes:{},classList:{add:value=>classes.add(value)},
      getAttribute(name){return this.attributes[name]??null;},setAttribute(name,value){this.attributes[name]=value;},closest:query=>query===scheduleDependentSelector||query===selector?control:null};
    control.classList.has=value=>classes.has(value);
    if(selector==='[data-tng-readiness-key]'||selector==='[data-tng-packing-key]')control.checked=false;
    return control;
  });
  const count={textContent:''},types={textContent:'',hidden:true};
  const reviewButton={disabled:false,addEventListener:(type,handler)=>{handlers.review=handler;},click:()=>handlers.review?.()};
  const summary={hidden:true,querySelector:selector=>selector==='[data-tng-draft-review-count]'?count:selector==='[data-tng-draft-review-types]'?types:reviewButton};
  const refreshMessage={id:refreshMessageId,textContent:''},scheduleReviewButton={hidden:true,disabled:true,textContent:'Review remaining edit',addEventListener:(type,handler)=>{handlers.scheduleReview=handler;},focus:options=>focus.push({scheduleReviewButton,options})};
  const refreshButton={disabled:true,addEventListener:(type,handler)=>{handlers.refresh=handler;}};
  const refreshPanel={hidden:true,attributes:{},setAttribute(name,value){this.attributes[name]=value;},querySelector:selector=>selector==='[data-tng-schedule-refresh-message]'?refreshMessage:selector==='[data-tng-schedule-review-button]'?scheduleReviewButton:selector==='[data-tng-schedule-refresh-button]'?refreshButton:null,
    scrollIntoView:options=>scroll.push({refreshPanel,options}),focus:options=>focus.push({refreshPanel,options})};
  refreshButton.focus=options=>focus.push({refreshButton,options});
  const search={value:'nothing matches'},sort={value:'recent'};
  const control=(key,value)=>({dataset:{[key]:value},attributes:{},setAttribute(name,value){this.attributes[name]=value;}});
  const filters=['all','archived','needs-prep'].map(value=>control('tngAdventureFilter',value));
  const prepFilters=['upcoming','needs-prep','launch-ready'].map(value=>control('tngPrepFilter',value));
  const plans=[0,1].map(index=>{
    const title={textContent:'Plan '+index},state={textContent:'Saved'},notesCount={textContent:''};
    const panel={open:false,querySelector:()=>state,classList:{toggle(){}}};
    const card={isConnected:true,hidden:true,textContent:title.textContent,dataset:{planId:'owned-'+index,planState:'ready',planUpdated:String(index)},classList:{remove(){}},scrollIntoView:()=>scroll.push(card),querySelector:selector=>selector==='[data-plan-title]'?title:null};
    const make=(kind,initial)=>{
      const button={disabled:false};
      const field={name:kind==='rename'?'title':kind==='schedule'?'planned_date':'notes',value:restored&&index===0&&kind==='notes'?'Restored private draft':initial,defaultValue:initial,validity:{badInput:false},isConnected:true,disabled:false,
        scrollIntoView(options){scroll.push({field,options});},
        focus(options){focus.push({field,options});},closest:selector=>{
          if(selector==='[data-tng-plan-notes],[data-tng-plan-rename],[data-tng-plan-schedule]'||selector===`[data-tng-plan-${kind}]`)return form;
          if(selector==='[data-plan-id]')return card;
          if(selector==='[data-tng-plan-notes-panel]'&&kind==='notes')return panel;
          if(selector==='[data-tng-plan-notes] textarea'&&kind==='notes')return field;
          return null;
        }};
      const clear={disabled:false,closest:selector=>selector==='[data-plan-id]'?card:selector==='[data-tng-plan-schedule]'?form:selector==='[data-tng-plan-clear-date]'&&kind==='schedule'?clear:null};
      const form={closest:selector=>selector===`[data-tng-plan-${kind}]`?form:selector==='[data-plan-id]'?card:selector==='[data-tng-plan-notes-panel]'&&kind==='notes'?panel:null,
        querySelector:selector=>selector==='button[type="submit"]'?button:selector==='[data-tng-plan-clear-date]'?(kind==='schedule'?clear:null):selector==='[data-tng-notes-count]'?notesCount:field};
      return {field,button,form,clear};
    };
    return {card,title,panel,notes:make('notes','Notes '+index),rename:make('rename',title.textContent),schedule:make('schedule','2030-01-02')};
  });
  const context={URLSearchParams,status,search,sort,filters,prepFilters,nextCard:null,selectedFilter:'archived',planNavigationRequest:7,cards:plans.map(p=>p.card),grid:null,filterStatus:null,filterEmpty:null,
    launchCountFor:()=>0,isUpcomingPrepCard:()=>true,updatePrepOverview(){},
    root:{dataset:{ajaxUrl:'/wp-admin/admin-ajax.php',nonce:'test-nonce'},querySelector:selector=>selector==='[data-tng-schedule-refresh]'?(refreshPresent?refreshPanel:null):summaryPresent?summary:null,querySelectorAll:selector=>selector===scheduleDependentSelector?scheduleControls:plans.flatMap(p=>[p.schedule.field,p.notes.field,p.rename.field]),addEventListener:(type,handler,capture)=>{if(capture){(captureHandlers[type]??=[]).push(handler);}else handlers[type]=handler;}},
    window:{addEventListener:(type,handler)=>{if(!listeners.has(type))listeners.set(type,new Set());listeners.get(type).add(handler);},removeEventListener:(type,handler)=>listeners.get(type)?.delete(handler),location:{reload(){reloads.push({pending:plans.some(p=>p.schedule.button.disabled||p.schedule.clear.disabled||p.notes.button.disabled||p.rename.button.disabled)});}}},
    fetch:(url,options)=>{const request=deferred();requests.push({url,options,...request});return request.promise;}};
  vm.runInNewContext(`${warningCode}\n${refreshCode}\n${controlsCode}\n${filterCode}\n${reviewCode}\n${inputCode}\n${postCode}\n${clickCode}\n${submitCode}\nglobalThis.testPost = post;`,context);
  const capture=(type,target)=>{
    const event={type,target,prevented:false,stopped:false,preventDefault(){this.prevented=true;},stopImmediatePropagation(){this.stopped=true;}};
    for(const handler of captureHandlers[type]??[]){handler(event);if(event.stopped)break;}
    return event;
  };
  return {plans,requests,reloads,status,refreshPanel,refreshMessage,scheduleReviewButton,refreshButton,summary,count,types,reviewButton,search,sort,focus,scroll,context,scheduleControls,capture,
    async dispatch(type,target){const event=capture(type,target);if(!event.stopped)await handlers[type]?.(event);return event;},refresh:()=>handlers.refresh(),
    type(index,kind,value,badInput=false){const field=plans[index][kind].field;field.value=value;field.validity.badInput=badInput;handlers.input({target:field});},
    submit:(index,kind)=>handlers.submit({target:plans[index][kind].form,preventDefault(){}}),
    clear:(index)=>handlers.click({target:plans[index].schedule.clear}),
    review:()=>handlers.review?.(),scheduleReview:()=>handlers.scheduleReview?.(),pageshow:()=>listeners.get('pageshow')?.forEach(fn=>fn()),warning:()=>listeners.get('beforeunload')?.size??0};
};

let h=harness();assert.equal(h.summary.hidden,true);assert.equal(h.reviewButton.disabled,true);
h.type(0,'notes','<script>PRIVATE NOTES</script>');assert.equal(h.count.textContent,'1 unsaved field across 1 adventure.');assert.equal(h.summary.hidden,false);
h.type(0,'rename','PRIVATE NAME');h.type(1,'schedule','2031-03-04');
assert.equal(h.count.textContent,'3 unsaved fields across 2 adventures.');assert.doesNotMatch(h.count.textContent,/PRIVATE|2031|script/);
assert.equal(h.requests.length,0);assert.equal(h.warning(),1);
for(const field of [h.plans[0].notes.field,h.plans[0].rename.field,h.plans[1].schedule.field,h.plans[0].notes.field]) {
  h.review();assert.equal(h.focus.at(-1).field,field);assert.equal(h.scroll.at(-1).field,field);
  assert.equal(h.focus.at(-1).options.preventScroll,true);
}
assert.equal(h.plans[0].panel.open,true);assert.equal(h.context.selectedFilter,'all');assert.equal(h.search.value,'');assert.equal(h.sort.value,'recent');
assert.equal(h.context.planNavigationRequest,11);assert.ok(h.plans.every(p=>!p.card.hidden));assert.equal(h.requests.length,0);
assert.deepEqual(h.context.filters.map(button=>button.attributes['aria-pressed']),['true','false','false']);
assert.ok(h.context.prepFilters.every(button=>button.attributes['aria-pressed']==='false'));
assert.equal(h.plans[0].notes.field.value,'<script>PRIVATE NOTES</script>');
h.type(0,'notes','Notes 0');h.type(0,'rename','Plan 0');h.type(1,'schedule','2030-01-02');assert.equal(h.summary.hidden,true);assert.equal(h.count.textContent,'');assert.equal(h.warning(),0);

h=harness();h.type(0,'schedule','',true);assert.equal(h.count.textContent,'1 unsaved field across 1 adventure.');h.review();assert.equal(h.focus.at(-1).field,h.plans[0].schedule.field);
h=harness({restored:true});assert.equal(h.summary.hidden,false);assert.equal(h.count.textContent,'1 unsaved field across 1 adventure.');
h.plans[0].notes.field.value='Notes 0';h.pageshow();assert.equal(h.summary.hidden,true);
h=harness({summaryPresent:false});h.type(0,'rename','Private');assert.equal(h.warning(),1,'Warning works without optional summary markup');

for(const kind of ['notes','rename','schedule']) {
  h=harness();const value=kind==='schedule'?'2030-01-03':'Submitted '+kind;
  h.type(0,kind,value);let saving=h.submit(0,kind);
  assert.equal(h.count.textContent,'1 unsaved field across 1 adventure. 1 save in progress.');
  success(h.requests[0],kind==='notes'?{notes:value}:kind==='rename'?{title:value}:{});await saving;
  assert.equal(h.summary.hidden,true);assert.equal(h.warning(),0);
  const submitted=kind==='schedule'?'2030-01-04':value+'new',newer=kind==='schedule'?'2030-01-05':value+'newer';
  h.type(0,kind,submitted);saving=h.submit(0,kind);h.type(0,kind,newer);success(h.requests[1]);await saving;
  assert.equal(h.count.textContent,'1 unsaved field across 1 adventure.');assert.equal(h.warning(),1);
  assert.equal(h.plans[0][kind].field.value,newer);
  for(const failure of ['server','network','json']) {
    h=harness();h.type(0,kind,value);saving=h.submit(0,kind);
    if(failure==='server')h.requests[0].resolve({json:async()=>({success:false,data:{message:'Failed.'}})});
    if(failure==='network')h.requests[0].reject(new Error('Offline'));
    if(failure==='json')h.requests[0].resolve({json:async()=>{throw Error('Invalid JSON');}});
    await saving;assert.equal(h.count.textContent,'1 unsaved field across 1 adventure.');assert.equal(h.reviewButton.disabled,false);assert.equal(h.warning(),1);
  }
}
h=harness();let saving=h.clear(0);assert.equal(h.count.textContent,'1 save in progress.');assert.equal(h.reviewButton.disabled,true);assert.equal(h.summary.hidden,false);
h.type(1,'notes','Hidden edit');success(h.requests[0]);await saving;assert.equal(h.count.textContent,'1 unsaved field across 1 adventure.');h.review();assert.equal(h.focus.at(-1).field,h.plans[1].notes.field);
h=harness();h.type(0,'notes','Draft');saving=h.submit(0,'notes');h.type(0,'notes','Notes 0');assert.equal(h.count.textContent,'1 save in progress.');assert.equal(h.warning(),1);
success(h.requests[0],{notes:'Draft'});await saving;assert.equal(h.count.textContent,'1 unsaved field across 1 adventure.');
h=harness();h.type(0,'notes','Draft');h.type(1,'rename','Other draft');h.plans[0].notes.field.isConnected=false;h.review();assert.equal(h.focus.at(-1).field,h.plans[1].rename.field);
h.plans[1].rename.field.disabled=true;h.pageshow();assert.equal(h.reviewButton.disabled,true);const n=h.focus.length;h.review();assert.equal(h.focus.length,n);
console.log('TN Game OS 5.168.0 Private Unsaved Edit Review passed');
