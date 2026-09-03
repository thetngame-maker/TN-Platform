import assert from 'node:assert/strict';
import fs from 'node:fs';
import {harness} from './check-tn-game-os-5.168.mjs';

const root=new URL('../experiences/wordpress/tn-game-os/',import.meta.url);
const read=path=>fs.readFileSync(new URL(path,root),'utf8');
const bootstrap=read('tn-game-os.php'),client=read('assets/js/saved-adventures.js');
const php=read('app/Modules/Destinations/class-adventure-ai.php');
assert.match(bootstrap,/Version:\s*5\.1(?:7[5-9]|[8-9]\d)\.\d+/);
assert.match(bootstrap,/define\('TNG_OS_VERSION','5\.1(?:7[5-9]|[8-9]\d)\.\d+'\)/);
const refresh=client.slice(client.indexOf('const scheduleRefresh ='),client.indexOf('const draftReview ='));
assert.match(refresh,/const scheduleRefreshMessageId = scheduleRefreshMessage\?\.id \|\| ''/);
assert.match(refresh,/control\.getAttribute\('aria-describedby'\)/);
assert.match(refresh,/!descriptions\.includes\(scheduleRefreshMessageId\)/);
assert.match(refresh,/control\.setAttribute\('aria-describedby',\[\.\.\.descriptions,scheduleRefreshMessageId\]\.join\(' '\)\)/);
assert.doesNotMatch(refresh,/fetch\(|post\(|localStorage|sessionStorage|indexedDB|setTimeout|setInterval|sendBeacon|innerHTML|\.submit\(/);
const markup=php.match(/<section class="tng-adventure-library__schedule-refresh"[^]*?<\/section>/)?.[0];
assert.match(markup,/id="tng-schedule-refresh-message" data-tng-schedule-refresh-message/);
assert.equal((php.match(/id="tng-schedule-refresh-message"/g)||[]).length,1);
assert.doesNotMatch(markup,/<\?php|\$plan|\$notes/);

const success=request=>request.resolve({json:async()=>({success:true,data:{message:'Saved.'}})});

// Every paused control references the shared private-safe explanation.
let h=harness();h.scheduleControls[0].attributes['aria-describedby']='existing-help';
h.type(1,'notes','PRIVATE NOTES');let saving=h.clear(0);success(h.requests[0]);await saving;
assert.equal(h.scheduleControls[0].attributes['aria-describedby'],'existing-help tng-schedule-refresh-message');
for(const control of h.scheduleControls) {
  const ids=control.attributes['aria-describedby'].split(/\s+/);
  assert.equal(ids.filter(id=>id==='tng-schedule-refresh-message').length,1);
  assert.equal(control.attributes['aria-disabled'],'true');
  assert.doesNotMatch(control.attributes['aria-describedby'],/PRIVATE|Notes|Plan|2030/);
}
assert.equal(h.requests.length,1);

// Repeated input, restored-page, and blocked-action synchronization never duplicates the ID.
h.type(1,'notes','PRIVATE NOTES 2');h.pageshow();await h.dispatch('click',h.scheduleControls[1]);
for(const control of h.scheduleControls) {
  const ids=control.attributes['aria-describedby'].split(/\s+/);
  assert.equal(ids.filter(id=>id==='tng-schedule-refresh-message').length,1);
}
assert.equal(h.requests.length,1);

// Optional or incomplete reminder markup does not invent a dangling reference.
h=harness({refreshMessageId:''});h.type(1,'rename','PRIVATE NAME');saving=h.clear(0);success(h.requests[0]);await saving;
assert.ok(h.scheduleControls.every(control=>!control.attributes['aria-describedby']));assert.equal(h.requests.length,1);
h=harness({refreshPresent:false});h.type(1,'schedule','PRIVATE DATE',true);saving=h.clear(0);success(h.requests[0]);await saving;
assert.ok(h.scheduleControls.every(control=>!control.attributes['aria-describedby']));assert.equal(h.requests.length,1);

// A fresh page does not carry transient paused descriptions.
h=harness();assert.ok(h.scheduleControls.every(control=>!control.attributes['aria-describedby']&&!control.attributes['aria-disabled']));
console.log('TN Game OS 5.175.0 Accessible Paused Control Explanation passed');
