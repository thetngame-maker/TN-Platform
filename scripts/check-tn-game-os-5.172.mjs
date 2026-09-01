import assert from 'node:assert/strict';
import fs from 'node:fs';
import {harness} from './check-tn-game-os-5.168.mjs';

const root=new URL('../experiences/wordpress/tn-game-os/',import.meta.url);
const read=path=>fs.readFileSync(new URL(path,root),'utf8');
const bootstrap=read('tn-game-os.php'),client=read('assets/js/saved-adventures.js');
const php=read('app/Modules/Destinations/class-adventure-ai.php'),css=read('assets/css/saved-adventures.css');
assert.match(bootstrap,/Version:\s*5\.1(?:7[2-9]|[8-9]\d)\.\d+/);
assert.match(bootstrap,/define\('TNG_OS_VERSION','5\.1(?:7[2-9]|[8-9]\d)\.\d+'\)/);
const refresh=client.slice(client.indexOf('const scheduleRefresh ='),client.indexOf('const draftReview ='));
assert.match(refresh,/control\.setAttribute\('aria-disabled','true'\)/);
assert.match(refresh,/control\.classList\.add\('is-schedule-paused'\)/);
assert.match(refresh,/scheduleRefresh\?\.scrollIntoView\(\{block:'nearest'\}\)/);
assert.match(refresh,/focusTarget\?\.focus\(\{preventScroll:true\}\)/);
assert.doesNotMatch(refresh,/control\.disabled\s*=\s*true|fetch\(|post\(|setTimeout|setInterval|localStorage|sessionStorage|innerHTML/);
assert.match(css,/\.is-schedule-paused\{opacity:\.6;cursor:not-allowed\}/);
const markup=php.match(/<section class="tng-adventure-library__schedule-refresh"[^]*?<\/section>/)?.[0];
assert.match(markup,/aria-label="Refresh saved schedule" tabindex="-1" hidden/);
assert.doesNotMatch(markup,/<\?php|\$plan|\$notes/);
const success=(request,data={})=>request.resolve({json:async()=>({success:true,data:{message:'Saved.',...data}})});

let h=harness();h.type(1,'notes','PRIVATE DRAFT');h.type(0,'schedule','2030-03-04');
let saving=h.submit(0,'schedule');success(h.requests[0]);await saving;
assert.ok(h.scheduleControls.every(c=>!c.disabled&&c.attributes['aria-disabled']==='true'&&c.classList.has('is-schedule-paused')));
let control=h.scheduleControls.find(c=>c.selector==='[data-tng-plan-start]');
let child={closest:selector=>control.closest(selector)};
let event=await h.dispatch('click',child);
assert.equal(event.stopped,true);assert.equal(event.prevented,true);assert.equal(h.requests.length,1);assert.equal(h.reloads.length,0);
assert.equal(h.scroll.at(-1).refreshPanel,h.refreshPanel);assert.equal(h.scroll.at(-1).options.block,'nearest');
assert.equal(h.focus.at(-1).scheduleReviewButton,h.scheduleReviewButton);assert.equal(h.focus.at(-1).options.preventScroll,true);
assert.doesNotMatch(h.status.textContent,/PRIVATE|2030|Plan/);

// Reverting the last draft makes refresh ready, so a blocked action guides
// keyboard focus to the button without activating it.
h.type(1,'notes','Notes 1');const beforeReload=h.reloads.length;
control=h.scheduleControls.find(c=>c.selector==='[data-tng-plan-calendar]');
event=await h.dispatch('click',control);assert.equal(event.stopped,true);assert.equal(h.reloads.length,beforeReload);
assert.equal(h.focus.at(-1).refreshButton,h.refreshButton);assert.equal(h.refreshButton.disabled,false);
assert.equal(h.requests.length,1);

// A library update keeps focus on the reminder until that request finishes.
saving=h.context.testPost({operation:'packing',plan_id:'owned-0',packing_key:'water',checked:'1'});
control=h.scheduleControls.find(c=>c.selector==='[data-tng-upcoming-calendar]');
await h.dispatch('click',control);assert.equal(h.focus.at(-1).refreshPanel,h.refreshPanel);assert.equal(h.requests.length,2);
success(h.requests[1]);await saving;await h.dispatch('click',control);
assert.equal(h.focus.at(-1).refreshButton,h.refreshButton);assert.equal(h.requests.length,2);

// Checkbox rollback and focus guidance coexist without changing prep data.
for(const selector of ['[data-tng-readiness-key]','[data-tng-packing-key]']) {
  control=h.scheduleControls.find(c=>c.selector===selector);
  for(const previous of [false,true]) {
    control.checked=!previous;event=await h.dispatch('change',control);
    assert.equal(event.stopped,true);assert.equal(control.checked,previous);assert.equal(h.focus.at(-1).refreshButton,h.refreshButton);
  }
}
assert.equal(h.requests.length,2);assert.equal(h.reloads.length,0);

// A pre-existing disabled state is never released by the pause decorator.
h=harness();control=h.scheduleControls[0];control.disabled=true;h.type(1,'rename','Draft');saving=h.clear(0);success(h.requests[0]);await saving;
assert.equal(control.disabled,true);assert.equal(control.attributes['aria-disabled'],'true');

// The guards remain private-safe without optional reminder markup.
h=harness({refreshPresent:false});h.type(1,'notes','Draft');saving=h.clear(0);success(h.requests[0]);await saving;
control=h.scheduleControls[0];event=await h.dispatch('click',control);
assert.equal(event.stopped,true);assert.equal(h.focus.length,0);assert.equal(h.scroll.length,0);assert.equal(h.requests.length,1);

h=harness();assert.ok(h.scheduleControls.every(c=>!c.attributes['aria-disabled']&&!c.classList.has('is-schedule-paused')));
console.log('TN Game OS 5.172.0 Accessible Paused Action Guidance passed');
