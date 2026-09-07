import assert from 'node:assert/strict';
import fs from 'node:fs';
import {harness} from './check-tn-game-os-5.168.mjs';

const root=new URL('../experiences/wordpress/tn-game-os/',import.meta.url);
const read=path=>fs.readFileSync(new URL(path,root),'utf8');
const bootstrap=read('tn-game-os.php'),client=read('assets/js/saved-adventures.js');
const review=client.slice(client.indexOf('const draftReview ='),client.indexOf("prepOverview?.addEventListener('click'"));
assert.match(bootstrap,/Version:\s*5\.189\.0/);
assert.match(bootstrap,/define\('TNG_OS_VERSION','5\.189\.0'\)/);
assert.match(review,/const dismissDraftReviewComplete = \(\) => \{\s*if \(!draftReviewComplete \|\| draftReviewComplete\.hidden\) return;/);
assert.doesNotMatch(review,/fetch\(|post\(|localStorage|sessionStorage|indexedDB|setTimeout|setInterval|sendBeacon|innerHTML|\.submit\(/);

// A repeated button event after the confirmation is hidden is inert and
// cannot overwrite the original status snapshot or move focus again.
let h=harness({statusText:'2 saved adventures'});
h.type(0,'notes','PRIVATE NOTE');
h.type(0,'notes','Notes 0');
h.dismissCompletion();
assert.equal(h.completion.hidden,true);
assert.equal(h.status.textContent,'Edit confirmation dismissed.');
assert.equal(h.focus.length,1);
h.dismissCompletion();
assert.equal(h.status.textContent,'Edit confirmation dismissed.');
assert.equal(h.focus.length,1);
assert.equal(h.requests.length,0);

// The next edit restores the actual pre-dismissal status, proving the repeat
// event did not replace the saved snapshot with the generic dismissal text.
h.type(1,'rename','PRIVATE NAME');
assert.equal(h.status.textContent,'2 saved adventures');
assert.equal(h.completion.hidden,true);
h.type(1,'rename','Plan 1');
assert.equal(h.completion.hidden,false);
assert.equal(h.completionMessage.textContent,'All edits on this page are saved.');

// Escape on the hidden confirmation is also inert.
h.keydownCompletion('Escape');
assert.equal(h.completion.hidden,true);
assert.equal(h.status.textContent,'Edit confirmation dismissed.');
const focusCount=h.focus.length;
const hiddenEscape=h.keydownCompletion('Escape');
assert.equal(hiddenEscape.prevented,false);
assert.equal(h.focus.length,focusCount);
assert.equal(h.requests.length,0);
assert.doesNotMatch(h.status.textContent,/PRIVATE|Notes 0|Plan|2030/);
console.log('TN Game OS 5.189.0 Repeat-Safe Dismissal Handoff passed');
