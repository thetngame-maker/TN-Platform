import assert from 'node:assert/strict';
import fs from 'node:fs';
import {harness} from './check-tn-game-os-5.168.mjs';

const root=new URL('../experiences/wordpress/tn-game-os/',import.meta.url);
const read=path=>fs.readFileSync(new URL(path,root),'utf8');
const bootstrap=read('tn-game-os.php'),client=read('assets/js/saved-adventures.js');
const php=read('app/Modules/Destinations/class-adventure-ai.php'),css=read('assets/css/saved-adventures.css');
assert.match(bootstrap,/Version:\s*5\.185\.0/);
assert.match(bootstrap,/define\('TNG_OS_VERSION','5\.185\.0'\)/);
const review=client.slice(client.indexOf('const draftReview ='),client.indexOf("prepOverview?.addEventListener('click'"));
assert.match(review,/draftReviewComplete\?\.addEventListener\('keydown', \(event\) => \{[^]*?event\.key !== 'Escape'[^]*?dismissDraftReviewComplete\(\)/);
assert.doesNotMatch(review,/fetch\(|post\(|localStorage|sessionStorage|indexedDB|setTimeout|setInterval|sendBeacon|innerHTML|\.submit\(/);
const completeMarkup=php.match(/<section class="tng-adventure-library__draft-complete"[^]*?<\/section>/)?.[0];
assert.ok(completeMarkup);
assert.match(completeMarkup,/<span class="tng-adventure-library__sr-only" id="tng-draft-complete-shortcut">Press Escape to dismiss this confirmation\.<\/span>/);
assert.match(completeMarkup,/<button class="tng-ui-button tng-ui-button--secondary" type="button" data-tng-draft-review-complete-dismiss aria-describedby="tng-draft-complete-shortcut" aria-keyshortcuts="Escape">Dismiss<\/button>/);
assert.equal(php.split('id="tng-draft-complete-shortcut"').length-1,1);
assert.doesNotMatch(completeMarkup,/<\?php|\$plan|\$notes/);
assert.match(css,/\.tng-adventure-library__sr-only\{position:absolute!important;width:1px!important;height:1px!important;[^}]*clip:rect\(0,0,0,0\)!important;white-space:nowrap!important;border:0!important\}/);

// The semantic shortcut describes behavior that is exercised by the actual
// handler and remains generic, request-free, and private-value-safe.
let h=harness({statusText:'2 saved adventures'});
h.type(0,'notes','PRIVATE NOTE');
h.type(0,'notes','Notes 0');
const event=h.keydownCompletion('Escape');
assert.equal(event.prevented,true);
assert.equal(h.completion.hidden,true);
assert.equal(h.completionMessage.textContent,'');
assert.equal(h.status.textContent,'Edit confirmation dismissed.');
assert.equal(h.focus.at(-1).status,h.status);
assert.equal(h.requests.length,0);
assert.doesNotMatch(h.status.textContent,/PRIVATE|Notes 0|Plan|2030/);

// Later edit cycles retain the established status restoration and completion
// behavior after a shortcut dismissal.
h.type(1,'rename','PRIVATE NAME');
assert.equal(h.status.textContent,'2 saved adventures');
h.type(1,'rename','Plan 1');
assert.equal(h.completion.hidden,false);
assert.equal(h.completionMessage.textContent,'All edits on this page are saved.');
assert.equal(h.requests.length,0);
console.log('TN Game OS 5.185.0 Discoverable Keyboard Dismissal passed');
