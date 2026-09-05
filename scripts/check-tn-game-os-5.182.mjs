import assert from 'node:assert/strict';
import fs from 'node:fs';
import {harness} from './check-tn-game-os-5.168.mjs';

const root=new URL('../experiences/wordpress/tn-game-os/',import.meta.url);
const read=path=>fs.readFileSync(new URL(path,root),'utf8');
const bootstrap=read('tn-game-os.php'),client=read('assets/js/saved-adventures.js');
const php=read('app/Modules/Destinations/class-adventure-ai.php');
assert.match(bootstrap,/Version:\s*5\.1(?:8[2-9]|9\d)\.\d+/);
assert.match(bootstrap,/define\('TNG_OS_VERSION','5\.1(?:8[2-9]|9\d)\.\d+'\)/);
const review=client.slice(client.indexOf('const draftReview ='),client.indexOf("prepOverview?.addEventListener('click'"));
assert.match(review,/draftReviewCompleteDismiss\?\.addEventListener\('click',[^]*?draftReviewComplete\.hidden = true[^]*?status\.textContent = draftDismissedMessage[^]*?status\.focus\(\{preventScroll:true\}\)/);
assert.doesNotMatch(review,/fetch\(|post\(|localStorage|sessionStorage|indexedDB|setTimeout|setInterval|sendBeacon|innerHTML|\.submit\(/);
assert.match(php,/<p class="tng-adventure-library__status" data-tng-library-status aria-live="polite" tabindex="-1">/);
const completeMarkup=php.match(/<section class="tng-adventure-library__draft-complete"[^]*?<\/section>/)?.[0];
assert.ok(completeMarkup);
assert.match(completeMarkup,/type="button" data-tng-draft-review-complete-dismiss>Dismiss<\/button>/);
assert.doesNotMatch(completeMarkup,/<\?php|\$plan|\$notes/);

const message='All edits on this page are saved.';
let h=harness();
h.type(0,'notes','PRIVATE NOTE');
h.type(0,'notes','Notes 0');
assert.equal(h.completion.hidden,false);
assert.equal(h.completionMessage.textContent,message);
assert.equal(h.focus.length,0);
h.dismissCompletion();
assert.equal(h.completion.hidden,true);
assert.equal(h.completionMessage.textContent,'');
assert.equal(h.status.textContent,'Edit confirmation dismissed.');
assert.equal(h.focus.length,1);
assert.equal(h.focus[0].status,h.status);
assert.equal(h.focus[0].options.preventScroll,true);
assert.equal(h.requests.length,0);
assert.doesNotMatch(h.status.textContent,/PRIVATE|Notes 0|Plan|2030/);

// Clean-page resynchronization does not reopen or refocus a dismissed result.
h.pageshow();
assert.equal(h.completion.hidden,true);
assert.equal(h.focus.length,1);

// A later edit cycle still creates a fresh confirmation and a second explicit
// dismissal produces the same generic, request-free focus handoff.
h.type(1,'rename','PRIVATE NAME');
h.type(1,'rename','Plan 1');
assert.equal(h.completion.hidden,false);
assert.equal(h.completionMessage.textContent,message);
h.dismissCompletion();
assert.equal(h.focus.length,2);
assert.equal(h.focus[1].status,h.status);
assert.equal(h.status.textContent,'Edit confirmation dismissed.');
assert.equal(h.requests.length,0);

// The focus handoff remains safe when the optional unsaved-review summary is
// absent; no draft value is copied into either status surface.
h=harness({summaryPresent:false});
h.type(0,'rename','<script>PRIVATE NAME</script>');
h.type(0,'rename','Plan 0');
assert.equal(h.completion.hidden,false);
h.dismissCompletion();
assert.equal(h.completion.hidden,true);
assert.equal(h.status.textContent,'Edit confirmation dismissed.');
assert.equal(h.focus.at(-1).status,h.status);
assert.doesNotMatch(h.status.textContent,/PRIVATE|script|Plan 0/);
assert.equal(h.requests.length,0);
console.log('TN Game OS 5.182.0 Accessible Confirmation Dismissal Handoff passed');
