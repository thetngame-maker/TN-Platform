import assert from 'node:assert/strict';
import fs from 'node:fs';
import {harness} from './check-tn-game-os-5.168.mjs';

const root=new URL('../experiences/wordpress/tn-game-os/',import.meta.url);
const read=path=>fs.readFileSync(new URL(path,root),'utf8');
const bootstrap=read('tn-game-os.php'),client=read('assets/js/saved-adventures.js');
const php=read('app/Modules/Destinations/class-adventure-ai.php');
assert.match(bootstrap,/Version:\s*5\.179\.0/);
assert.match(bootstrap,/define\('TNG_OS_VERSION','5\.179\.0'\)/);
const review=client.slice(client.indexOf('const draftReview ='),client.indexOf("prepOverview?.addEventListener('click'"));
assert.match(review,/const draftReviewPosition = draftReview\?\.querySelector\('\[data-tng-draft-review-position\]'\)/);
assert.match(review,/const draftReviewPositionId = draftReviewPosition\?\.id \|\| ''/);
assert.match(review,/removeDraftReviewDescription/);
assert.match(review,/describedDraft\.setAttribute\('aria-describedby',\[\.\.\.descriptions,draftReviewPositionId\]\.join\(' '\)\)/);
assert.match(review,/Reviewing \$\{type\} · \$\{index \+ 1\} of \$\{drafts\.length\} in this \$\{lastReviewedDraftScope \? 'type' : 'page'\} review\./);
assert.doesNotMatch(review,/fetch\(|post\(|localStorage|sessionStorage|indexedDB|setTimeout|setInterval|sendBeacon|innerHTML|\.submit\(/);
const markup=php.match(/<section class="tng-adventure-library__draft-review"[^]*?<\/section>/)?.[0];
assert.match(markup,/<small id="tng-draft-review-position" data-tng-draft-review-position hidden><\/small>/);
assert.doesNotMatch(markup,/<\?php|\$plan|\$notes/);

const positionId='tng-draft-review-position';
let h=harness();
assert.equal(h.position.hidden,true);assert.equal(h.position.textContent,'');
h.type(0,'notes','PRIVATE NOTE ONE');h.type(1,'notes','PRIVATE NOTE TWO');h.type(0,'rename','PRIVATE NAME');
h.plans[0].notes.field.setAttribute('aria-describedby','notes-help');
h.reviewType('notes');
assert.equal(h.focus.at(-1).field,h.plans[0].notes.field);
assert.equal(h.focus.at(-1).describedBy,`notes-help ${positionId}`,'Review context is connected before focus');
assert.equal(h.position.hidden,false);
assert.equal(h.position.textContent,'Reviewing note · 1 of 2 in this type review.');
assert.equal(h.plans[0].notes.field.getAttribute('aria-describedby'),`notes-help ${positionId}`);
assert.doesNotMatch(h.position.textContent,/PRIVATE|Plan|2030/);

// Moving review preserves unrelated descriptions and moves only the shared reference.
h.reviewType('notes');
assert.equal(h.position.textContent,'Reviewing note · 2 of 2 in this type review.');
assert.equal(h.plans[0].notes.field.getAttribute('aria-describedby'),'notes-help');
assert.equal(h.plans[1].notes.field.getAttribute('aria-describedby'),positionId);
h.reviewType('notes');
assert.equal(h.position.textContent,'Reviewing note · 1 of 2 in this type review.');
assert.equal(h.plans[0].notes.field.getAttribute('aria-describedby'),`notes-help ${positionId}`);
assert.equal(h.plans[0].notes.field.getAttribute('aria-describedby').split(positionId).length-1,1);
assert.equal(h.plans[1].notes.field.getAttribute('aria-describedby'),null);

// Page-wide review explains its own sequence and cleans up after resolution.
h.review();
assert.equal(h.focus.at(-1).field,h.plans[0].rename.field);
assert.equal(h.position.textContent,'Reviewing name · 2 of 3 in this page review.');
assert.equal(h.plans[0].notes.field.getAttribute('aria-describedby'),'notes-help');
assert.equal(h.plans[0].rename.field.getAttribute('aria-describedby'),positionId);
h.type(0,'rename','Plan 0');
assert.equal(h.position.hidden,true);assert.equal(h.position.textContent,'');
assert.equal(h.plans[0].rename.field.getAttribute('aria-describedby'),null);
assert.equal(h.summary.hidden,false,'Other private drafts remain reviewable');

// Disabled or disconnected selected fields also release the shared context.
h.reviewType('notes');assert.equal(h.position.hidden,false);
h.plans[0].notes.field.disabled=true;h.pageshow();
assert.equal(h.position.hidden,true);assert.equal(h.plans[0].notes.field.getAttribute('aria-describedby'),'notes-help');
h.reviewType('notes');assert.equal(h.focus.at(-1).field,h.plans[1].notes.field);
h.plans[1].notes.field.isConnected=false;h.pageshow();
assert.equal(h.position.hidden,true);assert.equal(h.plans[1].notes.field.getAttribute('aria-describedby'),null);

// Restored drafts do not claim a review position until the owner selects one.
h=harness({restored:true});assert.equal(h.summary.hidden,false);assert.equal(h.position.hidden,true);
h.review();assert.equal(h.position.textContent,'Reviewing note · 1 of 1 in this page review.');
assert.equal(h.requests.length,0);
console.log('TN Game OS 5.179.0 Accessible Draft Review Position passed');
