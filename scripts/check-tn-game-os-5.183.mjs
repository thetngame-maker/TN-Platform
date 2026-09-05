import assert from 'node:assert/strict';
import fs from 'node:fs';
import {harness} from './check-tn-game-os-5.168.mjs';

const root=new URL('../experiences/wordpress/tn-game-os/',import.meta.url);
const read=path=>fs.readFileSync(new URL(path,root),'utf8');
const bootstrap=read('tn-game-os.php'),client=read('assets/js/saved-adventures.js');
assert.match(bootstrap,/Version:\s*5\.183\.0/);
assert.match(bootstrap,/define\('TNG_OS_VERSION','5\.183\.0'\)/);
const review=client.slice(client.indexOf('const draftReview ='),client.indexOf("prepOverview?.addEventListener('click'"));
assert.match(review,/let statusBeforeDraftDismissal = null/);
assert.match(review,/const draftDismissedMessage = 'Edit confirmation dismissed\.'/);
assert.match(review,/if \(statusBeforeDraftDismissal !== null\)[^]*?status\?\.textContent === draftDismissedMessage[^]*?status\.textContent = statusBeforeDraftDismissal[^]*?statusBeforeDraftDismissal = null/);
assert.match(review,/statusBeforeDraftDismissal = status\.textContent[^]*?status\.textContent = draftDismissedMessage[^]*?status\.focus\(\{preventScroll:true\}\)/);
assert.doesNotMatch(review,/fetch\(|post\(|localStorage|sessionStorage|indexedDB|setTimeout|setInterval|sendBeacon|innerHTML|\.submit\(/);

const message='All edits on this page are saved.';
let h=harness({statusText:'2 saved adventures'});
h.type(0,'notes','PRIVATE NOTE');
h.type(0,'notes','Notes 0');
assert.equal(h.completionMessage.textContent,message);
h.dismissCompletion();
assert.equal(h.status.textContent,'Edit confirmation dismissed.');
assert.equal(h.focus.at(-1).status,h.status);
assert.equal(h.requests.length,0);

// Clean-page resynchronization keeps the useful dismissal result until actual
// edit or pending-save activity begins.
h.pageshow();
assert.equal(h.status.textContent,'Edit confirmation dismissed.');
h.type(1,'rename','PRIVATE NAME');
assert.equal(h.status.textContent,'2 saved adventures');
assert.equal(h.completion.hidden,true);
assert.equal(h.requests.length,0);
h.type(1,'rename','Plan 1');
assert.equal(h.completion.hidden,false);
assert.equal(h.completionMessage.textContent,message);
assert.equal(h.status.textContent,'2 saved adventures');

// A newer status from another operation wins; starting the next edit cycle
// clears the stale recovery snapshot without replacing newer feedback.
h.dismissCompletion();
h.status.textContent='Calendar file downloaded. Open it to confirm the event.';
h.type(0,'schedule','2031-03-04');
assert.equal(h.status.textContent,'Calendar file downloaded. Open it to confirm the event.');
h.type(0,'schedule','2030-01-02');
assert.equal(h.completion.hidden,false);
h.dismissCompletion();
assert.equal(h.status.textContent,'Edit confirmation dismissed.');
h.type(0,'notes','ANOTHER PRIVATE NOTE');
assert.equal(h.status.textContent,'Calendar file downloaded. Open it to confirm the event.');
assert.doesNotMatch(h.status.textContent,/PRIVATE|2030|2031/);
assert.equal(h.requests.length,0);

// The lifecycle remains optional-enhancement-safe without the review summary.
h=harness({summaryPresent:false,statusText:'1 saved adventure'});
h.type(0,'rename','<script>PRIVATE NAME</script>');
h.type(0,'rename','Plan 0');
h.dismissCompletion();
assert.equal(h.status.textContent,'Edit confirmation dismissed.');
h.type(0,'notes','PRIVATE');
assert.equal(h.status.textContent,'1 saved adventure');
assert.doesNotMatch(h.status.textContent,/PRIVATE|script|Plan 0/);
assert.equal(h.requests.length,0);
console.log('TN Game OS 5.183.0 Draft Dismissal Status Lifecycle passed');
