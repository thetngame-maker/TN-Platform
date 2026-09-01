import assert from 'node:assert/strict';
import fs from 'node:fs';
import {harness} from './check-tn-game-os-5.168.mjs';

const root=new URL('../experiences/wordpress/tn-game-os/',import.meta.url);
const read=path=>fs.readFileSync(new URL(path,root),'utf8');
const bootstrap=read('tn-game-os.php'),client=read('assets/js/saved-adventures.js');
assert.match(bootstrap,/Version:\s*5\.1(?:7[1-9]|[8-9]\d)\.\d+/);
assert.match(bootstrap,/define\('TNG_OS_VERSION','5\.1(?:7[1-9]|[8-9]\d)\.\d+'\)/);
const refresh=client.slice(client.indexOf('const scheduleRefresh ='),client.indexOf('const draftReview ='));
assert.match(refresh,/\['click','change'\]\.forEach\(\(type\) => root\.addEventListener\(type, guardStaleScheduleAction, true\)\)/);
assert.match(refresh,/event\.preventDefault\(\);\s*event\.stopImmediatePropagation\(\)/);
assert.doesNotMatch(refresh,/fetch\(|post\(|localStorage|sessionStorage|indexedDB|setTimeout|setInterval|innerHTML|\.dataset\.[\w]+\s*=/);
const expected=['[data-tng-readiness-key]','[data-tng-packing-key]','[data-tng-prep-focus]','[data-tng-next-action]','[data-tng-plan-start]','[data-tng-plan-calendar]','[data-tng-upcoming-calendar]','[data-tng-plan-print]'];
const success=(request,data={})=>request.resolve({json:async()=>({success:true,data:{message:'Saved.',...data}})});
const save=(h,kind)=>kind==='clear'?h.clear(0):h.submit(0,'schedule');

let h=harness();assert.deepEqual(h.scheduleControls.map(c=>c.selector),expected);
for(const control of h.scheduleControls) {
  assert.equal(control.disabled,false);assert.equal(h.capture('click',control).stopped,false);
}
// Before refresh is required, existing disabled states are not overwritten.
h.scheduleControls[0].disabled=true;h.type(1,'notes','A draft');assert.equal(h.scheduleControls[0].disabled,true);
assert.equal(h.scheduleControls[1].disabled,false);

for(const kind of ['schedule','clear']) {
  for(const refreshPresent of [true,false]) {
    h=harness({refreshPresent});h.type(1,'notes','PRIVATE unsaved draft');
    if(kind==='schedule')h.type(0,'schedule','2031-03-04');
    const saving=save(h,kind);assert.ok(h.scheduleControls.every(c=>!c.disabled),'An unconfirmed save does not pause actions');
    success(h.requests[0]);await saving;
    assert.equal(h.reloads.length,0);assert.ok(h.scheduleControls.every(c=>c.attributes['aria-disabled']==='true'&&c.classList.has('is-schedule-paused')));
    const state=JSON.stringify(h.plans.map(p=>p.card.dataset));
    for(const control of h.scheduleControls) {
      // A nested click or direct event cannot bypass disabled styling.
      control.disabled=false;
      const child={closest:selector=>control.closest(selector)};
      const event=await h.dispatch('click',child);
      assert.equal(event.prevented,true);assert.equal(event.stopped,true);assert.equal(control.disabled,false);assert.equal(control.attributes['aria-disabled'],'true');
      assert.equal(h.requests.length,1);assert.equal(h.reloads.length,0);
      assert.doesNotMatch(h.status.textContent,/PRIVATE|2031/);
      assert.match(h.status.textContent,/Refresh the saved schedule/);
      if('checked' in control) {
        for(const previous of [false,true]) {
          control.checked=!previous;
          const changed=await h.dispatch('change',control);
          assert.equal(changed.stopped,true);assert.equal(changed.prevented,true);assert.equal(control.checked,previous);
          assert.equal(h.requests.length,1);
        }
      }
    }
    assert.equal(JSON.stringify(h.plans.map(p=>p.card.dataset)),state,'Blocked actions never rewrite preparation data');
    for(const p of h.plans)for(const kind of ['notes','rename','schedule']) {
      assert.equal(p[kind].button.disabled,false);assert.equal(h.capture('click',p[kind].field).stopped,false);
    }
    assert.equal(h.capture('click',{closest:()=>null}).stopped,false,'Unrelated navigation is not intercepted');
    h.review();assert.equal(h.focus.at(-1).field,h.plans[1].notes.field);
    const notesSave=h.submit(1,'notes');success(h.requests[1],{notes:'PRIVATE unsaved draft'});await notesSave;
    assert.equal(h.reloads.length,0);assert.ok(h.scheduleControls.every(c=>c.attributes['aria-disabled']==='true'),'Finishing drafts does not unlock stale schedule actions');
    if(refreshPresent){assert.equal(h.refreshButton.disabled,false);h.refresh();assert.equal(h.reloads.length,1);}
  }
  for(const failure of ['server','network','json']) {
    h=harness();h.type(0,'schedule','2030-02-03');const saving=save(h,kind);
    if(failure==='server')h.requests[0].resolve({json:async()=>({success:false,data:{message:'Failed.'}})});
    if(failure==='network')h.requests[0].reject(Error('Offline'));
    if(failure==='json')h.requests[0].resolve({json:async()=>{throw Error('Invalid JSON');}});
    await saving;assert.ok(h.scheduleControls.every(c=>!c.disabled&&!c.attributes['aria-disabled']));assert.equal(h.refreshPanel.hidden,true);
    for(const c of h.scheduleControls)assert.equal(h.capture('click',c).stopped,false);
  }
}
h=harness();const saving=h.clear(0);success(h.requests[0]);await saving;
assert.equal(h.reloads.length,1);assert.ok(h.scheduleControls.every(c=>c.attributes['aria-disabled']==='true'),'Clean refresh also pauses old controls until navigation completes');
h=harness();assert.ok(h.scheduleControls.every(c=>!c.disabled&&!c.attributes['aria-disabled']),'A fresh page starts without the transient pause');
console.log('TN Game OS 5.171.0 Refresh-Required Preparation Protection passed');
