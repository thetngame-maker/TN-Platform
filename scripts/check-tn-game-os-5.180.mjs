import assert from 'node:assert/strict';
import fs from 'node:fs';
import {harness} from './check-tn-game-os-5.168.mjs';

const root=new URL('../experiences/wordpress/tn-game-os/',import.meta.url);
const read=path=>fs.readFileSync(new URL(path,root),'utf8');
const bootstrap=read('tn-game-os.php'),client=read('assets/js/saved-adventures.js');
const php=read('app/Modules/Destinations/class-adventure-ai.php'),css=read('assets/css/saved-adventures.css');
assert.match(bootstrap,/Version:\s*5\.180\.0/);
assert.match(bootstrap,/define\('TNG_OS_VERSION','5\.180\.0'\)/);
const review=client.slice(client.indexOf('const draftReview ='),client.indexOf("prepOverview?.addEventListener('click'"));
assert.match(review,/const draftReviewComplete = root\.querySelector\('\[data-tng-draft-review-complete\]'\)/);
assert.match(review,/let hadDraftReviewActivity = false/);
assert.match(review,/const hasDraftActivity = changed\.length > 0 \|\| pending > 0/);
assert.match(review,/draftReviewComplete\.textContent = 'All edits on this page are saved\.'/);
assert.match(review,/if \(!draftReview \|\| !draftReviewCount\) return/);
assert.doesNotMatch(review,/fetch\(|post\(|localStorage|sessionStorage|indexedDB|setTimeout|setInterval|sendBeacon|innerHTML|\.submit\(/);
assert.match(php,/<p class="tng-adventure-library__draft-complete" data-tng-draft-review-complete aria-live="polite" aria-atomic="true" hidden><\/p>/);
assert.match(css,/draft-complete\[hidden\]\{display:none\}/);
assert.match(css,/@media print\{\.tng-adventure-library__draft-review,\.tng-adventure-library__draft-complete\{display:none!important\}\}/);

const success=(request,data={})=>request.resolve({json:async()=>({success:true,data:{message:'Saved.',...data}})});
const message='All edits on this page are saved.';

let h=harness();
assert.equal(h.completion.hidden,true);assert.equal(h.completion.textContent,'');
h.pageshow();assert.equal(h.completion.hidden,true,'Untouched clean pages do not claim completion');
h.type(0,'notes','PRIVATE NOTE');assert.equal(h.completion.hidden,true);assert.equal(h.completion.textContent,'');
h.type(0,'notes','Notes 0');
assert.equal(h.completion.hidden,false);assert.equal(h.completion.textContent,message);
assert.doesNotMatch(h.completion.textContent,/PRIVATE|Plan|2030/);
h.type(1,'rename','PRIVATE NAME');assert.equal(h.completion.hidden,true);assert.equal(h.completion.textContent,'');

// Completion waits for every field, not merely the latest one changed.
h.type(0,'notes','ANOTHER PRIVATE NOTE');
h.type(1,'rename','Plan 1');assert.equal(h.completion.hidden,true);
h.type(0,'notes','Notes 0');assert.equal(h.completion.hidden,false);assert.equal(h.completion.textContent,message);

// Confirmed saves complete the cycle; failures and newer input keep it active.
h=harness();h.type(0,'rename','PRIVATE NAME');let saving=h.submit(0,'rename');
assert.equal(h.completion.hidden,true);
success(h.requests[0],{title:'PRIVATE NAME'});await saving;
assert.equal(h.completion.hidden,false);assert.equal(h.completion.textContent,message);
h.type(0,'rename','RETRY NAME');saving=h.submit(0,'rename');
h.requests[1].resolve({json:async()=>({success:false,data:{message:'Try again.'}})});await saving;
assert.equal(h.completion.hidden,true);assert.equal(h.completion.textContent,'');
saving=h.submit(0,'rename');h.type(0,'rename','NEWER PRIVATE NAME');success(h.requests[2],{title:'RETRY NAME'});await saving;
assert.equal(h.completion.hidden,true,'Newer unsaved input prevents completion');

// A pending-only cycle and confirmed date clear use the same state calculation.
h=harness();h.plans[0].notes.button.disabled=true;h.pageshow();assert.equal(h.completion.hidden,true);
h.plans[0].notes.button.disabled=false;h.pageshow();assert.equal(h.completion.textContent,message);
h=harness();saving=h.clear(0);assert.equal(h.completion.hidden,true);success(h.requests[0]);await saving;
assert.equal(h.completion.hidden,false);assert.equal(h.completion.textContent,message);

// Restored edits and pages without optional review-panel markup still confirm safely.
h=harness({restored:true});assert.equal(h.completion.hidden,true);
h.type(0,'notes','Notes 0');assert.equal(h.completion.textContent,message);
h=harness({summaryPresent:false});h.type(0,'rename','PRIVATE');h.type(0,'rename','Plan 0');
assert.equal(h.completion.hidden,false);assert.equal(h.completion.textContent,message);assert.equal(h.requests.length,0);
console.log('TN Game OS 5.180.0 Private Draft Resolution Confirmation passed');
