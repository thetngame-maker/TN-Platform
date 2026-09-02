import assert from 'node:assert/strict';
import fs from 'node:fs';
import {harness} from './check-tn-game-os-5.168.mjs';

const root=new URL('../experiences/wordpress/tn-game-os/',import.meta.url);
const read=path=>fs.readFileSync(new URL(path,root),'utf8');
const bootstrap=read('tn-game-os.php'),client=read('assets/js/saved-adventures.js');
const php=read('app/Modules/Destinations/class-adventure-ai.php'),css=read('assets/css/saved-adventures.css');
assert.match(bootstrap,/Version:\s*5\.1(?:7[3-9]|[8-9]\d)\.\d+/);
assert.match(bootstrap,/define\('TNG_OS_VERSION','5\.1(?:7[3-9]|[8-9]\d)\.\d+'\)/);
const refresh=client.slice(client.indexOf('const scheduleRefresh ='),client.indexOf('prepOverview?.addEventListener'));
assert.match(refresh,/const scheduleReviewButton = scheduleRefresh\?\.querySelector\('\[data-tng-schedule-review-button\]'\)/);
assert.match(refresh,/const hasReviewableScheduleDraft = \(\) => adventureDraftFields\.some/);
assert.match(refresh,/scheduleReviewButton\.hidden = !scheduleRefreshNeeded \|\| !hasDrafts/);
assert.match(refresh,/scheduleReviewButton\.disabled = !canReview/);
assert.match(refresh,/draftReviewButton\.click\(\)/);
assert.doesNotMatch(refresh,/fetch\(|post\(|localStorage|sessionStorage|indexedDB|setTimeout|setInterval|sendBeacon|innerHTML|\.submit\(/);
const markup=php.match(/<section class="tng-adventure-library__schedule-refresh"[^]*?<\/section>/)?.[0];
assert.match(markup,/type="button" data-tng-schedule-review-button hidden>Review remaining edit<\/button>/);
assert.match(markup,/type="button" data-tng-schedule-refresh-button disabled>Refresh saved schedule<\/button>/);
assert.doesNotMatch(markup,/<\?php|\$plan|\$notes/);
assert.match(css,/schedule-actions\{display:flex/);
assert.match(css,/schedule-actions button\[hidden\]\{display:none\}/);

const success=(request,data={})=>request.resolve({json:async()=>({success:true,data:{message:'Saved.',...data}})});

// A private draft blocks refresh but offers one generic handoff to the
// existing reviewer. Neither the label nor status exposes draft content.
let h=harness();h.type(1,'notes','PRIVATE NOTES');let saving=h.clear(0);success(h.requests[0]);await saving;
assert.equal(h.scheduleReviewButton.hidden,false);assert.equal(h.scheduleReviewButton.disabled,false);
assert.match(h.scheduleReviewButton.textContent,/^Review remaining edit(?: · \d+)?$/);assert.equal(h.refreshButton.disabled,true);
assert.doesNotMatch(h.refreshMessage.textContent,/PRIVATE|Notes 1|Plan 1/);
let control=h.scheduleControls.find(c=>c.selector==='[data-tng-plan-start]');
await h.dispatch('click',control);assert.equal(h.focus.at(-1).scheduleReviewButton,h.scheduleReviewButton);
const requestCount=h.requests.length;h.scheduleReview();
assert.equal(h.focus.at(-1).field,h.plans[1].notes.field);assert.equal(h.scroll.at(-1).field,h.plans[1].notes.field);
assert.equal(h.plans[1].panel.open,true);assert.equal(h.requests.length,requestCount);

// Reverting the draft removes the handoff and makes explicit refresh ready.
h.type(1,'notes','Notes 1');assert.equal(h.scheduleReviewButton.hidden,true);assert.equal(h.scheduleReviewButton.disabled,true);
assert.equal(h.refreshButton.disabled,false);await h.dispatch('click',control);
assert.equal(h.focus.at(-1).refreshButton,h.refreshButton);assert.equal(h.requests.length,requestCount);

// A pending save without another reviewable field keeps focus on the reminder.
h=harness();saving=h.clear(0);success(h.requests[0]);await saving;
const pending=h.context.testPost({operation:'packing',plan_id:'owned-0',packing_key:'water',checked:'1'});
control=h.scheduleControls[0];await h.dispatch('click',control);
assert.equal(h.scheduleReviewButton.hidden,true);assert.equal(h.refreshButton.disabled,false);assert.equal(h.focus.at(-1).refreshPanel,h.refreshPanel);
h.scheduleReview();assert.equal(h.focus.at(-1).refreshPanel,h.refreshPanel);assert.equal(h.requests.length,2);
success(h.requests[1]);await pending;assert.equal(h.scheduleReviewButton.hidden,true);assert.equal(h.refreshButton.disabled,false);

// Disabled or disconnected drafts cannot become a dead-end review target.
h=harness();h.type(0,'rename','PRIVATE NAME');h.plans[0].rename.field.disabled=true;saving=h.clear(1);success(h.requests[0]);await saving;
assert.equal(h.scheduleReviewButton.hidden,false);assert.equal(h.scheduleReviewButton.disabled,true);
assert.doesNotMatch(h.scheduleReviewButton.textContent,/PRIVATE|Plan/);h.scheduleReview();assert.equal(h.requests.length,1);

// Optional reminder markup remains optional and clean pages expose no action.
h=harness({refreshPresent:false});h.type(0,'notes','PRIVATE');saving=h.clear(1);success(h.requests[0]);await saving;
await h.dispatch('click',h.scheduleControls[0]);assert.equal(h.focus.length,0);assert.equal(h.requests.length,1);
h=harness();assert.equal(h.scheduleReviewButton.hidden,true);assert.equal(h.scheduleReviewButton.disabled,true);
console.log('TN Game OS 5.173.0 Guided Schedule Refresh Draft Handoff passed');
