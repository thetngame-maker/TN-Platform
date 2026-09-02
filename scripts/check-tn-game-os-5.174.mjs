import assert from 'node:assert/strict';
import fs from 'node:fs';
import {harness} from './check-tn-game-os-5.168.mjs';

const root=new URL('../experiences/wordpress/tn-game-os/',import.meta.url);
const read=path=>fs.readFileSync(new URL(path,root),'utf8');
const bootstrap=read('tn-game-os.php'),client=read('assets/js/saved-adventures.js');
assert.match(bootstrap,/Version:\s*5\.174\.0/);
assert.match(bootstrap,/define\('TNG_OS_VERSION','5\.174\.0'\)/);
const refresh=client.slice(client.indexOf('const scheduleRefresh ='),client.indexOf('const draftReview ='));
assert.match(refresh,/const dirtyCount = adventureDraftFields\.filter\(isAdventureDraftChanged\)\.length/);
assert.match(refresh,/const pendingCount = adventureDraftFields\.filter\(isAdventureDraftPending\)\.length/);
assert.match(refresh,/scheduleRefresh\.setAttribute\('aria-busy',String\(scheduleRefreshNeeded && pendingCount > 0\)\)/);
assert.match(refresh,/Review remaining edit · \$\{dirtyCount\}/);
assert.match(refresh,/\$\{dirtyCount\} unsaved field/);
assert.match(refresh,/\$\{pendingCount\} save/);
assert.doesNotMatch(refresh,/fetch\(|post\(|localStorage|sessionStorage|indexedDB|setTimeout|setInterval|sendBeacon|innerHTML|\.submit\(/);

const success=(request,data={})=>request.resolve({json:async()=>({success:true,data:{message:'Saved.',...data}})});

// Counts include hidden and invalid private drafts, but never their values.
let h=harness();h.type(0,'notes','PRIVATE NOTES');h.type(0,'rename','PRIVATE NAME');h.type(1,'schedule','PRIVATE INVALID DATE',true);
let saving=h.clear(0);success(h.requests[0]);await saving;
assert.equal(h.scheduleReviewButton.textContent,'Review remaining edit · 3');
assert.match(h.refreshMessage.textContent,/3 unsaved fields/);assert.equal(h.refreshPanel.attributes['aria-busy'],'false');
assert.doesNotMatch(h.refreshMessage.textContent,/PRIVATE|Plan 0|Plan 1/);assert.equal(h.requests.length,1);

// Reverting one field immediately reduces count-only guidance.
h.type(0,'notes','Notes 0');assert.equal(h.scheduleReviewButton.textContent,'Review remaining edit · 2');
assert.match(h.refreshMessage.textContent,/2 unsaved fields/);assert.equal(h.requests.length,1);

// A pending private-field save is counted separately and marks the reminder busy.
saving=h.submit(0,'rename');assert.match(h.refreshMessage.textContent,/2 unsaved fields and 1 save in progress/);
assert.equal(h.refreshPanel.attributes['aria-busy'],'true');assert.doesNotMatch(h.refreshMessage.textContent,/PRIVATE/);
h.requests[1].resolve({json:async()=>({success:false,data:{message:'Try again.'}})});await saving;
assert.match(h.refreshMessage.textContent,/2 unsaved fields/);assert.doesNotMatch(h.refreshMessage.textContent,/save in progress/);
assert.equal(h.refreshPanel.attributes['aria-busy'],'false');assert.equal(h.scheduleReviewButton.textContent,'Review remaining edit · 2');

// A pending clean field has a generic waiting label and no dead-end review action.
h.plans[0].rename.field.value=h.plans[0].rename.field.defaultValue;
h.type(1,'schedule','2030-01-02');h.plans[0].notes.button.disabled=true;h.pageshow();
assert.equal(h.scheduleReviewButton.textContent,'Waiting for 1 save');assert.equal(h.scheduleReviewButton.disabled,true);
assert.match(h.refreshMessage.textContent,/1 save in progress/);assert.equal(h.refreshPanel.attributes['aria-busy'],'true');
assert.doesNotMatch(h.scheduleReviewButton.textContent,/PRIVATE|Plan/);

// Resolving the final pending state restores clean explicit refresh guidance.
h.plans[0].notes.button.disabled=false;h.pageshow();assert.equal(h.scheduleReviewButton.hidden,true);
assert.equal(h.refreshButton.disabled,false);assert.equal(h.refreshPanel.attributes['aria-busy'],'false');
assert.doesNotMatch(h.refreshMessage.textContent,/unsaved field|save in progress/);assert.equal(h.requests.length,2);

h=harness({refreshPresent:false});h.type(0,'notes','PRIVATE');saving=h.clear(1);success(h.requests[0]);await saving;
assert.equal(h.requests.length,1,'Count-only guidance adds no request and optional markup remains optional');
console.log('TN Game OS 5.174.0 Private Refresh Resolution Progress passed');
