import assert from 'node:assert/strict';
import fs from 'node:fs';
import {harness} from './check-tn-game-os-5.168.mjs';

const root=new URL('../experiences/wordpress/tn-game-os/',import.meta.url);
const read=path=>fs.readFileSync(new URL(path,root),'utf8');
const bootstrap=read('tn-game-os.php'),client=read('assets/js/saved-adventures.js');
const php=read('app/Modules/Destinations/class-adventure-ai.php'),css=read('assets/css/saved-adventures.css');
assert.match(bootstrap,/Version:\s*5\.1(?:7[6-9]|[89]\d)\.\d+/);
assert.match(bootstrap,/define\('TNG_OS_VERSION','5\.1(?:7[6-9]|[89]\d)\.\d+'\)/);
const review=client.slice(client.indexOf('const draftReview ='),client.indexOf("prepOverview?.addEventListener('click'"));
assert.match(review,/const draftReviewTypes = draftReview\?\.querySelector\('\[data-tng-draft-review-types\]'\)/);
assert.match(review,/changed\.reduce\(\(counts,field\) =>/);
assert.match(review,/\{title:'name',notes:'note',planned_date:'date'\}/);
assert.match(review,/\['name','note','date','edit'\]/);
assert.match(review,/draftReviewTypes\.hidden = !typeSummary/);
assert.doesNotMatch(review,/fetch\(|post\(|localStorage|sessionStorage|indexedDB|setTimeout|setInterval|sendBeacon|innerHTML|\.submit\(/);
const markup=php.match(/<section class="tng-adventure-library__draft-review"[^]*?<\/section>/)?.[0];
assert.match(markup,/<small data-tng-draft-review-types hidden><\/small>/);
assert.doesNotMatch(markup,/<\?php|\$plan|\$notes/);
assert.match(css,/draft-review small\[hidden\]\{display:none\}/);

const success=(request,data={})=>request.resolve({json:async()=>({success:true,data:{message:'Saved.',...data}})});

let h=harness();assert.equal(h.types.hidden,true);assert.equal(h.types.textContent,'');
h.type(0,'notes','PRIVATE NOTE');assert.equal(h.types.textContent,'1 note');assert.equal(h.types.hidden,false);
h.type(0,'rename','PRIVATE NAME');assert.equal(h.types.textContent,'1 name · 1 note');
h.type(1,'schedule','PRIVATE DATE',true);assert.equal(h.types.textContent,'1 name · 1 note · 1 date');
assert.doesNotMatch(h.types.textContent,/PRIVATE|Plan|2030/);assert.equal(h.count.textContent,'3 unsaved fields across 2 adventures.');
assert.equal(h.requests.length,0);

// Plural counts are type-specific and include edits on filtered cards.
h.type(1,'notes','SECOND PRIVATE NOTE');assert.equal(h.types.textContent,'1 name · 2 notes · 1 date');
assert.ok(h.plans.every(plan=>plan.card.hidden));assert.doesNotMatch(h.types.textContent,/PRIVATE|SECOND/);

// Pending and failed saves preserve the type until a confirmed baseline changes.
let saving=h.submit(0,'rename');assert.equal(h.types.textContent,'1 name · 2 notes · 1 date');
h.requests[0].resolve({json:async()=>({success:false,data:{message:'Try again.'}})});await saving;
assert.equal(h.types.textContent,'1 name · 2 notes · 1 date');
saving=h.submit(0,'rename');success(h.requests[1],{title:'PRIVATE NAME'});await saving;
assert.equal(h.types.textContent,'2 notes · 1 date');assert.doesNotMatch(h.types.textContent,/PRIVATE/);

// Reverting the remaining values hides the breakdown without issuing work.
h.type(0,'notes','Notes 0');h.type(1,'notes','Notes 1');h.type(1,'schedule','2030-01-02');
assert.equal(h.types.textContent,'');assert.equal(h.types.hidden,true);assert.equal(h.summary.hidden,true);
assert.equal(h.requests.length,2);

// A clean save-in-progress can keep the panel visible without inventing a type.
h=harness();h.plans[0].notes.button.disabled=true;h.pageshow();assert.equal(h.summary.hidden,false);
assert.equal(h.types.hidden,true);assert.equal(h.types.textContent,'');assert.equal(h.count.textContent,'1 save in progress.');
console.log('TN Game OS 5.176.0 Private Draft Type Summary passed');
