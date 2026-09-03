import assert from 'node:assert/strict';
import fs from 'node:fs';
import {harness} from './check-tn-game-os-5.168.mjs';

const root=new URL('../experiences/wordpress/tn-game-os/',import.meta.url);
const read=path=>fs.readFileSync(new URL(path,root),'utf8');
const bootstrap=read('tn-game-os.php'),client=read('assets/js/saved-adventures.js');
const php=read('app/Modules/Destinations/class-adventure-ai.php'),css=read('assets/css/saved-adventures.css');
assert.match(bootstrap,/Version:\s*5\.1(?:7[7-9]|[89]\d)\.\d+/);
assert.match(bootstrap,/define\('TNG_OS_VERSION','5\.1(?:7[7-9]|[89]\d)\.\d+'\)/);
const review=client.slice(client.indexOf('const draftReview ='),client.indexOf("prepOverview?.addEventListener('click'"));
assert.match(review,/querySelector\('\[data-tng-draft-review-type-actions\]'\)/);
assert.match(review,/querySelectorAll\('\[data-tng-draft-review-type\]'\)/);
assert.match(review,/const nextDraftReviewTarget = \(fieldName = ''\)/);
assert.match(review,/nextDraftReviewTarget\(button\.dataset\.tngDraftReviewType \|\| ''\)/);
assert.match(review,/reviewDraft\(button\.dataset\.tngDraftReviewType \|\| ''\)/);
assert.doesNotMatch(review,/fetch\(|post\(|localStorage|sessionStorage|indexedDB|setTimeout|setInterval|sendBeacon|innerHTML|\.submit\(/);
const markup=php.match(/<section class="tng-adventure-library__draft-review"[^]*?<\/section>/)?.[0];
assert.match(markup,/data-tng-draft-review-type-actions aria-label="Review unsaved edits by type" hidden/);
assert.match(markup,/data-tng-draft-review-type="title" hidden>Review names<\/button>/);
assert.match(markup,/data-tng-draft-review-type="notes" hidden>Review notes<\/button>/);
assert.match(markup,/data-tng-draft-review-type="planned_date" hidden>Review dates<\/button>/);
assert.doesNotMatch(markup,/<\?php|\$plan|\$notes/);
assert.match(css,/draft-types\[hidden\],\.tng-adventure-library__draft-types button\[hidden\]\{display:none\}/);

const button=(h,fieldName)=>h.typeReviewButtons.find(item=>item.dataset.tngDraftReviewType===fieldName);
const success=(request,data={})=>request.resolve({json:async()=>({success:true,data:{message:'Saved.',...data}})});

let h=harness();
assert.equal(h.typeActions.hidden,true);
assert.ok(h.typeReviewButtons.every(item=>item.hidden));
h.type(0,'notes','PRIVATE NOTE ONE');
assert.equal(h.typeActions.hidden,false);
assert.equal(button(h,'notes').hidden,false);
assert.match(button(h,'notes').textContent,/^Review (?:1 note|note · 1 of 1)$/);
assert.equal(button(h,'title').hidden,true);
h.type(1,'notes','PRIVATE NOTE TWO');
h.type(0,'rename','PRIVATE NAME');
h.type(1,'schedule','PRIVATE DATE',true);
assert.match(button(h,'notes').textContent,/^Review (?:2 notes|note · 1 of 2)$/);
assert.match(button(h,'title').textContent,/^Review (?:1 name|name · 1 of 1)$/);
assert.match(button(h,'planned_date').textContent,/^Review (?:1 date|date · 1 of 1)$/);
assert.doesNotMatch(h.typeReviewButtons.map(item=>item.textContent).join(' '),/PRIVATE|Plan|2030/);
assert.equal(h.requests.length,0);

// Each shortcut cycles only within its requested field type.
h.reviewType('notes');assert.equal(h.focus.at(-1).field,h.plans[0].notes.field);
h.reviewType('notes');assert.equal(h.focus.at(-1).field,h.plans[1].notes.field);
h.reviewType('notes');assert.equal(h.focus.at(-1).field,h.plans[0].notes.field);
h.reviewType('title');assert.equal(h.focus.at(-1).field,h.plans[0].rename.field);
h.reviewType('planned_date');assert.equal(h.focus.at(-1).field,h.plans[1].schedule.field);
assert.equal(h.plans[0].panel.open,true);
assert.equal(h.context.selectedFilter,'all');
assert.equal(h.search.value,'');
assert.ok(h.plans.every(plan=>!plan.card.hidden));
assert.equal(h.requests.length,0);

// Unavailable dirty fields remain counted but never create a dead shortcut.
h.plans[0].rename.field.disabled=true;
h.plans[1].schedule.field.isConnected=false;
h.pageshow();
assert.equal(button(h,'title').hidden,true);
assert.equal(button(h,'planned_date').hidden,true);
assert.equal(button(h,'notes').hidden,false);

// Save outcomes and reverts keep shortcuts synchronized with confirmed baselines.
h=harness();h.type(0,'rename','PRIVATE NAME');
let saving=h.submit(0,'rename');
assert.match(button(h,'title').textContent,/^Review (?:1 name|name · 1 of 1)$/);
h.requests[0].resolve({json:async()=>({success:false,data:{message:'Try again.'}})});await saving;
assert.equal(button(h,'title').hidden,false);
saving=h.submit(0,'rename');success(h.requests[1],{title:'PRIVATE NAME'});await saving;
assert.equal(button(h,'title').hidden,true);assert.equal(h.typeActions.hidden,true);
h=harness({restored:true});assert.equal(button(h,'notes').hidden,false);assert.match(button(h,'notes').textContent,/^Review (?:1 note|note · 1 of 1)$/);
h.type(0,'notes','Notes 0');assert.equal(button(h,'notes').hidden,true);assert.equal(h.typeActions.hidden,true);

// A pending clean save shows the existing summary without inventing a shortcut.
h=harness();h.plans[0].notes.button.disabled=true;h.pageshow();
assert.equal(h.summary.hidden,false);assert.equal(h.typeActions.hidden,true);
assert.ok(h.typeReviewButtons.every(item=>item.hidden));assert.equal(h.requests.length,0);
console.log('TN Game OS 5.177.0 Private Draft Type Review Shortcuts passed');
