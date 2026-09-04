import assert from 'node:assert/strict';
import fs from 'node:fs';
import {harness} from './check-tn-game-os-5.168.mjs';

const root=new URL('../experiences/wordpress/tn-game-os/',import.meta.url);
const read=path=>fs.readFileSync(new URL(path,root),'utf8');
const bootstrap=read('tn-game-os.php'),client=read('assets/js/saved-adventures.js');
const php=read('app/Modules/Destinations/class-adventure-ai.php'),css=read('assets/css/saved-adventures.css');
assert.match(bootstrap,/Version:\s*5\.1(?:8[1-9]|9\d)\.\d+/);
assert.match(bootstrap,/define\('TNG_OS_VERSION','5\.1(?:8[1-9]|9\d)\.\d+'\)/);
const review=client.slice(client.indexOf('const draftReview ='),client.indexOf("prepOverview?.addEventListener('click'"));
assert.match(review,/const draftReviewCompleteMessage = draftReviewComplete\?\.querySelector\('\[data-tng-draft-review-complete-message\]'\)/);
assert.match(review,/const draftReviewCompleteDismiss = draftReviewComplete\?\.querySelector\('\[data-tng-draft-review-complete-dismiss\]'\)/);
assert.match(review,/draftReviewCompleteDismiss\?\.addEventListener\('click',[^]*?draftReviewCompleteMessage\.textContent = ''[^]*?draftReviewComplete\.hidden = true/);
assert.doesNotMatch(review,/fetch\(|post\(|localStorage|sessionStorage|indexedDB|setTimeout|setInterval|sendBeacon|innerHTML|\.submit\(/);
const completeMarkup=php.match(/<section class="tng-adventure-library__draft-complete"[^]*?<\/section>/)?.[0];
assert.ok(completeMarkup);
assert.match(completeMarkup,/data-tng-draft-review-complete aria-label="Edit confirmation" hidden/);
assert.match(completeMarkup,/data-tng-draft-review-complete-message aria-live="polite" aria-atomic="true"/);
assert.match(completeMarkup,/<button class="tng-ui-button tng-ui-button--secondary" type="button" data-tng-draft-review-complete-dismiss>Dismiss<\/button>/);
assert.doesNotMatch(completeMarkup,/<\?php|\$plan|\$notes/);
assert.match(css,/draft-complete\[hidden\]\{display:none\}/);
assert.match(css,/draft-complete button\{min-height:34px;flex:0 0 auto;padding:0 12px\}/);
assert.match(css,/@media print\{\.tng-adventure-library__draft-review,\.tng-adventure-library__draft-complete\{display:none!important\}\}/);

const success=(request,data={})=>request.resolve({json:async()=>({success:true,data:{message:'Saved.',...data}})});
const message='All edits on this page are saved.';

// A completed revert stays visible until explicitly dismissed. Dismissal is
// request-free and remains stable across page restoration without new activity.
let h=harness();
h.type(0,'notes','PRIVATE NOTE');
h.type(0,'notes','Notes 0');
assert.equal(h.completion.hidden,false);
assert.equal(h.completionMessage.textContent,message);
assert.equal(h.requests.length,0);
h.dismissCompletion();
assert.equal(h.completion.hidden,true);
assert.equal(h.completionMessage.textContent,'');
assert.equal(h.requests.length,0);
h.pageshow();
assert.equal(h.completion.hidden,true);
assert.equal(h.completionMessage.textContent,'');

// A later edit cycle clears the prior dismissal and produces a fresh generic
// confirmation only after all current-page activity resolves.
h.type(1,'rename','PRIVATE NAME');
assert.equal(h.completion.hidden,true);
h.type(1,'rename','Plan 1');
assert.equal(h.completion.hidden,false);
assert.equal(h.completionMessage.textContent,message);
assert.doesNotMatch(h.completionMessage.textContent,/PRIVATE|Plan|2030/);
h.dismissCompletion();
assert.equal(h.completion.hidden,true);

// Confirmed saves and date clears use the same lifecycle; failures do not
// falsely confirm, and the button never causes another network request.
h=harness();
h.type(0,'rename','PRIVATE SAVED NAME');
let saving=h.submit(0,'rename');
success(h.requests[0],{title:'PRIVATE SAVED NAME'});
await saving;
assert.equal(h.completion.hidden,false);
assert.equal(h.completionMessage.textContent,message);
const saveRequestCount=h.requests.length;
h.dismissCompletion();
assert.equal(h.requests.length,saveRequestCount);
h.type(0,'rename','PRIVATE RETRY NAME');
saving=h.submit(0,'rename');
h.requests[1].resolve({json:async()=>({success:false,data:{message:'Try again.'}})});
await saving;
assert.equal(h.completion.hidden,true);
assert.equal(h.completionMessage.textContent,'');
saving=h.clear(1);
success(h.requests[2]);
await saving;
assert.equal(h.completion.hidden,true,'Unresolved failed rename keeps completion hidden');
h.type(0,'rename','PRIVATE SAVED NAME');
assert.equal(h.completion.hidden,false);
h.dismissCompletion();

h=harness();
saving=h.clear(0);
success(h.requests[0]);
await saving;
assert.equal(h.completion.hidden,false);
h.dismissCompletion();
assert.equal(h.completion.hidden,true);
assert.equal(h.requests.length,1);

// Completion and dismissal remain optional-enhancement-safe when the review
// summary is absent, and no private value enters the confirmation surface.
h=harness({summaryPresent:false});
h.type(0,'notes','<script>PRIVATE NOTES</script>');
h.type(0,'notes','Notes 0');
assert.equal(h.completion.hidden,false);
assert.equal(h.completionMessage.textContent,message);
assert.doesNotMatch(h.completionMessage.textContent,/PRIVATE|script|Notes 0/);
h.dismissCompletion();
assert.equal(h.completion.hidden,true);
assert.equal(h.requests.length,0);
console.log('TN Game OS 5.181.0 Dismissible Draft Resolution Confirmation passed');
