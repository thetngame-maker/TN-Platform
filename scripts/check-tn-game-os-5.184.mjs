import assert from 'node:assert/strict';
import fs from 'node:fs';
import {harness} from './check-tn-game-os-5.168.mjs';

const root=new URL('../experiences/wordpress/tn-game-os/',import.meta.url);
const read=path=>fs.readFileSync(new URL(path,root),'utf8');
const bootstrap=read('tn-game-os.php'),client=read('assets/js/saved-adventures.js');
assert.match(bootstrap,/Version:\s*5\.1(?:8[4-9]|9\d)\.\d+/);
assert.match(bootstrap,/define\('TNG_OS_VERSION','5\.1(?:8[4-9]|9\d)\.\d+'\)/);
const review=client.slice(client.indexOf('const draftReview ='),client.indexOf("prepOverview?.addEventListener('click'"));
assert.match(review,/const dismissDraftReviewComplete = \(\) => \{/);
assert.match(review,/draftReviewCompleteDismiss\?\.addEventListener\('click', dismissDraftReviewComplete\)/);
assert.match(review,/draftReviewComplete\?\.addEventListener\('keydown', \(event\) => \{[^]*?event\.key !== 'Escape' \|\| event\.isComposing \|\| draftReviewComplete\.hidden[^]*?event\.preventDefault\(\)[^]*?dismissDraftReviewComplete\(\)/);
assert.doesNotMatch(review,/fetch\(|post\(|localStorage|sessionStorage|indexedDB|setTimeout|setInterval|sendBeacon|innerHTML|\.submit\(/);

const message='All edits on this page are saved.';
let h=harness({statusText:'2 saved adventures'});
h.type(0,'notes','PRIVATE NOTE');
h.type(0,'notes','Notes 0');
assert.equal(h.completion.hidden,false);
assert.equal(h.completionMessage.textContent,message);

// Unrelated keys and composition input preserve the confirmation and do not
// trigger the focus/status handoff.
let event=h.keydownCompletion('Enter');
assert.equal(event.prevented,false);
assert.equal(h.completion.hidden,false);
assert.equal(h.focus.length,0);
event=h.keydownCompletion('Escape',true);
assert.equal(event.prevented,false);
assert.equal(h.completion.hidden,false);
assert.equal(h.focus.length,0);

// Escape uses the same generic, accessible, request-free dismissal path.
event=h.keydownCompletion('Escape');
assert.equal(event.prevented,true);
assert.equal(h.completion.hidden,true);
assert.equal(h.completionMessage.textContent,'');
assert.equal(h.status.textContent,'Edit confirmation dismissed.');
assert.equal(h.focus.length,1);
assert.equal(h.focus[0].status,h.status);
assert.equal(h.focus[0].options.preventScroll,true);
assert.equal(h.requests.length,0);
assert.doesNotMatch(h.status.textContent,/PRIVATE|Notes 0|Plan|2030/);

// Escape on a hidden confirmation is inert. A later edit restores the prior
// status and can produce a fresh completion confirmation.
event=h.keydownCompletion('Escape');
assert.equal(event.prevented,false);
assert.equal(h.focus.length,1);
assert.equal(h.status.textContent,'Edit confirmation dismissed.');
h.type(1,'rename','PRIVATE NAME');
assert.equal(h.status.textContent,'2 saved adventures');
h.type(1,'rename','Plan 1');
assert.equal(h.completion.hidden,false);
assert.equal(h.completionMessage.textContent,message);
event=h.keydownCompletion('Escape');
assert.equal(event.prevented,true);
assert.equal(h.completion.hidden,true);
assert.equal(h.focus.length,2);
assert.equal(h.requests.length,0);

// Missing optional review markup does not affect scoped keyboard dismissal.
h=harness({summaryPresent:false,statusText:'1 saved adventure'});
h.type(0,'rename','<script>PRIVATE NAME</script>');
h.type(0,'rename','Plan 0');
event=h.keydownCompletion('Escape');
assert.equal(event.prevented,true);
assert.equal(h.completion.hidden,true);
assert.equal(h.status.textContent,'Edit confirmation dismissed.');
assert.doesNotMatch(h.status.textContent,/PRIVATE|script|Plan 0/);
assert.equal(h.requests.length,0);
console.log('TN Game OS 5.184.0 Keyboard-Safe Confirmation Dismissal passed');
