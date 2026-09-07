import assert from 'node:assert/strict';
import fs from 'node:fs';
import {harness} from './check-tn-game-os-5.168.mjs';

const root=new URL('../experiences/wordpress/tn-game-os/',import.meta.url);
const read=path=>fs.readFileSync(new URL(path,root),'utf8');
const bootstrap=read('tn-game-os.php'),client=read('assets/js/saved-adventures.js');
const php=read('app/Modules/Destinations/class-adventure-ai.php'),css=read('assets/css/saved-adventures.css');
assert.match(bootstrap,/Version:\s*5\.1(?:8[7-9]|9\d)\.\d+/);
assert.match(bootstrap,/define\('TNG_OS_VERSION','5\.1(?:8[7-9]|9\d)\.\d+'\)/);
assert.match(php,/<p class="tng-adventure-library__status" data-tng-library-status(?: role="status")? aria-live="polite" aria-atomic="true" tabindex="-1">/);
assert.match(css,/\.tng-adventure-library__status:focus\{outline:3px solid rgba\(224,107,38,\.5\);outline-offset:3px;border-radius:4px\}/);
const review=client.slice(client.indexOf('const draftReview ='),client.indexOf("prepOverview?.addEventListener('click'"));
assert.match(review,/status\.textContent = draftDismissedMessage;[^]*?status\.focus\(\{preventScroll:true\}\)/);
assert.doesNotMatch(review,/fetch\(|post\(|localStorage|sessionStorage|indexedDB|setTimeout|setInterval|sendBeacon|innerHTML|\.submit\(/);

// Both dismissal paths update the complete atomic status with one generic
// result, then move focus to the same stable, visibly outlined element.
let h=harness({statusText:'2 saved adventures'});
h.type(0,'notes','PRIVATE NOTE');
h.type(0,'notes','Notes 0');
h.dismissCompletion();
assert.equal(h.completion.hidden,true);
assert.equal(h.status.textContent,'Edit confirmation dismissed.');
assert.equal(h.focus.at(-1).status,h.status);
assert.equal(h.focus.at(-1).options.preventScroll,true);
assert.equal(h.requests.length,0);
assert.doesNotMatch(h.status.textContent,/PRIVATE|Notes 0|Plan|2030/);

h.type(1,'rename','PRIVATE NAME');
assert.equal(h.status.textContent,'2 saved adventures');
h.type(1,'rename','Plan 1');
const event=h.keydownCompletion('Escape');
assert.equal(event.prevented,true);
assert.equal(h.completion.hidden,true);
assert.equal(h.status.textContent,'Edit confirmation dismissed.');
assert.equal(h.focus.at(-1).status,h.status);
assert.equal(h.focus.length,2);
assert.equal(h.requests.length,0);
console.log('TN Game OS 5.187.0 Complete Dismissal Announcement passed');
