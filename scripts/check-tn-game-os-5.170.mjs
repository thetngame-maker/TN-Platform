import assert from 'node:assert/strict';
import fs from 'node:fs';
import {harness} from './check-tn-game-os-5.168.mjs';

const root=new URL('../experiences/wordpress/tn-game-os/',import.meta.url);
const read=path=>fs.readFileSync(new URL(path,root),'utf8');
const bootstrap=read('tn-game-os.php'),client=read('assets/js/saved-adventures.js');
const php=read('app/Modules/Destinations/class-adventure-ai.php'),css=read('assets/css/saved-adventures.css');
assert.match(bootstrap,/Version:\s*5\.170\.0/);
assert.match(bootstrap,/define\('TNG_OS_VERSION','5\.170\.0'\)/);
const refresh=client.slice(client.indexOf('const scheduleRefresh ='),client.indexOf('const draftReview ='));
assert.doesNotMatch(refresh,/fetch\(|post\(|localStorage|sessionStorage|indexedDB|setTimeout|setInterval|innerHTML|console\.|\.value\s*=/);
assert.equal((client.match(/if \(refreshAfterSave\) requestScheduleRefresh\(\);/g)||[]).length,2);
assert.match(refresh,/if \(scheduleRefreshNeeded && !hasUnsavedAdventureDrafts\(\)\) window\.location\.reload\(\)/);
assert.match(refresh,/if \(libraryUpdatePending\)/);
assert.match(css,/schedule-refresh\[hidden\]\{display:none\}/);
assert.match(css,/@media print\{\.tng-adventure-library__schedule-refresh\{display:none!important\}\}/);
const markup=php.match(/<section class="tng-adventure-library__schedule-refresh"[^]*?<\/section>/)?.[0];
assert.ok(markup);assert.match(markup,/aria-label="Refresh saved schedule" hidden/);
assert.match(markup,/aria-live="polite" aria-atomic="true"/);
assert.match(markup,/type="button" data-tng-schedule-refresh-button disabled/);
assert.doesNotMatch(markup,/<\?php|\$notes|\$plan/);
assert.ok(php.indexOf(markup)>php.indexOf('<?php else: ?>',php.indexOf('public static function render_library')));
const success=(request,data={})=>request.resolve({json:async()=>({success:true,data:{message:'Saved.',...data}})});
const save=(h,kind)=>kind==='clear'?h.clear(0):h.submit(0,'schedule');

let h=harness();assert.equal(h.refreshPanel.hidden,true);h.refresh();assert.equal(h.reloads.length,0);
for(const kind of ['schedule','clear']) {
  h=harness();if(kind==='schedule')h.type(0,'schedule','2030-02-03');
  let saving=save(h,kind);assert.equal(h.refreshPanel.hidden,true);assert.equal(h.reloads.length,0);
  success(h.requests[0]);await saving;assert.equal(h.reloads.length,1);assert.equal(h.reloads[0].pending,false);
  // Reload is observed, not executed by this VM; the reminder remains available
  // if a browser or another page listener cancels an otherwise clean refresh.
  assert.equal(h.refreshPanel.hidden,false);assert.equal(h.refreshButton.disabled,false);
  h.type(1,'notes','New private draft');h.refresh();assert.equal(h.reloads.length,1);assert.equal(h.refreshButton.disabled,true);

  for(const other of ['notes','rename','schedule']) {
    h=harness();h.plans[1].card.hidden=true;h.type(1,other,other==='schedule'?'2031-03-04':'PRIVATE DRAFT');
    saving=save(h,kind);success(h.requests[0]);await saving;
    assert.equal(h.reloads.length,0);assert.equal(h.refreshPanel.hidden,false);assert.equal(h.refreshButton.disabled,true);
    assert.match(h.refreshMessage.textContent,/Save or revert remaining edits/);
    assert.doesNotMatch(h.refreshMessage.textContent,/PRIVATE|2031|Plan 1/);
    h.refresh();assert.equal(h.reloads.length,0,'Even a direct click cannot bypass the fresh draft check');
    h.review();assert.equal(h.plans[1].card.hidden,false);assert.equal(h.reloads.length,0);
    h.type(1,other,h.plans[1][other].field.defaultValue);assert.equal(h.refreshButton.disabled,false);
    assert.equal(h.reloads.length,0,'Reverting the last edit must not trigger a delayed reload');
    h.refresh();assert.equal(h.reloads.length,1);assert.equal(h.requests.length,1);
  }

  h=harness();if(kind==='schedule')h.type(0,'schedule','2030-02-03');
  saving=save(h,kind);h.type(0,'schedule','2030-04-05');success(h.requests[0]);await saving;
  assert.equal(h.reloads.length,0);assert.equal(h.plans[0].schedule.field.value,'2030-04-05');assert.equal(h.refreshButton.disabled,true);

  for(const failure of ['server','network','json']) {
    h=harness();h.type(0,'schedule','2030-02-03');saving=save(h,kind);
    if(failure==='server')h.requests[0].resolve({json:async()=>({success:false,data:{message:'Failed.'}})});
    if(failure==='network')h.requests[0].reject(Error('Offline'));
    if(failure==='json')h.requests[0].resolve({json:async()=>{throw Error('Invalid JSON');}});
    await saving;assert.equal(h.reloads.length,0);assert.equal(h.refreshPanel.hidden,true);
  }
}

h=harness();h.type(1,'notes','Submitted');let saving=h.clear(0);success(h.requests[0]);await saving;
saving=h.submit(1,'notes');h.type(1,'notes','Notes 1');h.refresh();assert.equal(h.reloads.length,0,'Reverting while a draft is saving remains guarded');
success(h.requests[1],{notes:'Submitted'});await saving;assert.equal(h.refreshButton.disabled,true);
h.type(1,'notes','Submitted');assert.equal(h.refreshButton.disabled,false);assert.equal(h.reloads.length,0);
assert.equal(h.refreshPanel.hidden,false,'Unrelated success messages never hide the schedule reminder');
saving=h.context.testPost({operation:'packing',plan_id:'owned-0',packing_key:'water',checked:'1'});
h.refresh();assert.equal(h.reloads.length,0);assert.match(h.status.textContent,/Wait for it to finish/);
success(h.requests[2]);await saving;h.refresh();assert.equal(h.reloads.length,1);

h=harness();h.type(1,'rename','Submitted name');saving=h.clear(0);success(h.requests[0]);await saving;
saving=h.submit(1,'rename');success(h.requests[1],{title:'Submitted name'});await saving;
assert.equal(h.refreshButton.disabled,false);assert.equal(h.reloads.length,0,'Saving the last name never reloads unexpectedly');
h.refresh();assert.equal(h.reloads.length,1);
h=harness();h.type(0,'schedule','',true);saving=h.clear(0);success(h.requests[0]);await saving;
assert.equal(h.refreshButton.disabled,true);assert.equal(h.reloads.length,0);h.refresh();assert.equal(h.reloads.length,0);
h.plans[0].schedule.field.validity.badInput=false;h.pageshow();assert.equal(h.refreshButton.disabled,false);assert.equal(h.reloads.length,0);
console.log('TN Game OS 5.170.0 Draft-Aware Schedule Refresh passed');
