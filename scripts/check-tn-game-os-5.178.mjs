import assert from 'node:assert/strict';
import fs from 'node:fs';
import {harness} from './check-tn-game-os-5.168.mjs';

const root=new URL('../experiences/wordpress/tn-game-os/',import.meta.url);
const read=path=>fs.readFileSync(new URL(path,root),'utf8');
const bootstrap=read('tn-game-os.php'),client=read('assets/js/saved-adventures.js');
assert.match(bootstrap,/Version:\s*5\.178\.0/);
assert.match(bootstrap,/define\('TNG_OS_VERSION','5\.178\.0'\)/);
const review=client.slice(client.indexOf('const draftReview ='),client.indexOf("prepOverview?.addEventListener('click'"));
assert.match(review,/const target = nextDraftReviewTarget\(button\.dataset\.tngDraftReviewType \|\| ''\)/);
assert.match(review,/button\.textContent = `Review \$\{type\} · \$\{target\.index \+ 1\} of \$\{target\.total\}`/);
assert.match(review,/const drafts = reviewableDrafts\(\)\.filter\(\(field\) => !fieldName \|\| field\.name === fieldName\)/);
assert.doesNotMatch(review,/fetch\(|post\(|localStorage|sessionStorage|indexedDB|setTimeout|setInterval|sendBeacon|innerHTML|\.submit\(/);

const button=(h,fieldName)=>h.typeReviewButtons.find(item=>item.dataset.tngDraftReviewType===fieldName);
const success=(request,data={})=>request.resolve({json:async()=>({success:true,data:{message:'Saved.',...data}})});

let h=harness();
h.type(0,'notes','PRIVATE NOTE ONE');
h.type(1,'notes','PRIVATE NOTE TWO');
h.type(0,'rename','PRIVATE NAME');
h.type(1,'schedule','PRIVATE DATE',true);
assert.equal(button(h,'notes').textContent,'Review note · 1 of 2');
assert.equal(button(h,'title').textContent,'Review name · 1 of 1');
assert.equal(button(h,'planned_date').textContent,'Review date · 1 of 1');
assert.doesNotMatch(h.typeReviewButtons.map(item=>item.textContent).join(' '),/PRIVATE|Plan|2030/);

// The chosen type advances independently and wraps after its last field.
h.reviewType('notes');
assert.equal(h.focus.at(-1).field,h.plans[0].notes.field);
assert.equal(button(h,'notes').textContent,'Review note · 2 of 2');
h.reviewType('notes');
assert.equal(h.focus.at(-1).field,h.plans[1].notes.field);
assert.equal(button(h,'notes').textContent,'Review note · 1 of 2');
h.reviewType('notes');
assert.equal(h.focus.at(-1).field,h.plans[0].notes.field);
assert.equal(button(h,'notes').textContent,'Review note · 2 of 2');

// Moving to another type resets unrelated cycles to their first current field.
h.reviewType('title');
assert.equal(h.focus.at(-1).field,h.plans[0].rename.field);
assert.equal(button(h,'notes').textContent,'Review note · 1 of 2');
assert.equal(button(h,'title').textContent,'Review name · 1 of 1');
h.review();
assert.equal(h.focus.at(-1).field,h.plans[1].schedule.field);
assert.equal(button(h,'planned_date').textContent,'Review date · 1 of 1');
assert.equal(h.requests.length,0);

// Current connected and enabled fields determine both position and total.
h.type(0,'notes','Notes 0');
assert.equal(button(h,'notes').textContent,'Review note · 1 of 1');
h.plans[1].notes.field.disabled=true;h.pageshow();
assert.equal(button(h,'notes').hidden,true);
h.plans[0].rename.field.isConnected=false;h.pageshow();
assert.equal(button(h,'title').hidden,true);
assert.equal(button(h,'planned_date').hidden,false);

// Failed saves retain progress; confirmed saves and reverts remove it.
h=harness();h.type(0,'rename','PRIVATE NAME');
let saving=h.submit(0,'rename');
assert.equal(button(h,'title').textContent,'Review name · 1 of 1');
h.requests[0].resolve({json:async()=>({success:false,data:{message:'Try again.'}})});await saving;
assert.equal(button(h,'title').textContent,'Review name · 1 of 1');
saving=h.submit(0,'rename');success(h.requests[1],{title:'PRIVATE NAME'});await saving;
assert.equal(button(h,'title').hidden,true);assert.equal(h.typeActions.hidden,true);
h=harness({restored:true});assert.equal(button(h,'notes').textContent,'Review note · 1 of 1');
h.type(0,'notes','Notes 0');assert.equal(button(h,'notes').hidden,true);assert.equal(h.typeActions.hidden,true);

// Pending-only state keeps the summary visible without inventing progress.
h=harness();h.plans[0].notes.button.disabled=true;h.pageshow();
assert.equal(h.summary.hidden,false);assert.equal(h.typeActions.hidden,true);
assert.ok(h.typeReviewButtons.every(item=>item.hidden));assert.equal(h.requests.length,0);
console.log('TN Game OS 5.178.0 Guided Draft Type Review Progress passed');
