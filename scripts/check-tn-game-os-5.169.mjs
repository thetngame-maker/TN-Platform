import assert from 'node:assert/strict';
import fs from 'node:fs';
import {harness} from './check-tn-game-os-5.168.mjs';

const root=new URL('../experiences/wordpress/tn-game-os/',import.meta.url);
const bootstrap=fs.readFileSync(new URL('tn-game-os.php',root),'utf8');
const client=fs.readFileSync(new URL('assets/js/saved-adventures.js',root),'utf8');
assert.match(bootstrap,/Version:\s*5\.1(?:69|[7-9]\d)\.\d+/);
assert.match(bootstrap,/define\('TNG_OS_VERSION','5\.1(?:69|[7-9]\d)\.\d+'\)/);
const review=client.slice(client.indexOf('const draftReview ='),client.indexOf("prepOverview?.addEventListener('click'"));
assert.match(review,/field\.focus\(\{preventScroll:true\}\);\s*field\.scrollIntoView\(\{block:'center'\}\);/);
assert.doesNotMatch(review,/card\.scrollIntoView|setTimeout|setInterval|innerHTML|fetch\(|post\(|localStorage|sessionStorage|savePreferences/);
const success=(request,data={})=>request.resolve({json:async()=>({success:true,data:{message:'Saved.',...data}})});

let h=harness();assert.equal(h.reviewButton.textContent,'Review unsaved edit');assert.equal(h.reviewButton.disabled,true);
h.type(0,'schedule','2031-04-05');h.type(0,'notes','PRIVATE NOTES');h.type(1,'rename','PRIVATE NAME');
assert.equal(h.reviewButton.textContent,'Review date · 1 of 3');
for(const [field,next] of [[h.plans[0].schedule.field,'Review notes · 2 of 3'],[h.plans[0].notes.field,'Review name · 3 of 3'],[h.plans[1].rename.field,'Review date · 1 of 3']]) {
  const values=h.plans.flatMap(p=>[p.schedule.field.value,p.notes.field.value,p.rename.field.value]);
  h.review();assert.equal(h.focus.at(-1).field,field);assert.equal(h.scroll.at(-1).field,field);assert.equal(h.scroll.at(-1).options.block,'center');
  assert.equal(h.reviewButton.textContent,next);assert.doesNotMatch(h.reviewButton.textContent,/PRIVATE|2031/);
  assert.deepEqual(h.plans.flatMap(p=>[p.schedule.field.value,p.notes.field.value,p.rename.field.value]),values);
}
assert.equal(h.plans[0].panel.open,true);assert.equal(h.requests.length,0);
h.type(0,'schedule','2030-01-02');assert.equal(h.reviewButton.textContent,'Review notes · 1 of 2');
h.type(0,'notes','Notes 0');assert.equal(h.reviewButton.textContent,'Review name · 1 of 1');
h.type(1,'rename','Plan 1');assert.equal(h.reviewButton.textContent,'Review unsaved edit');assert.equal(h.summary.hidden,true);
h.type(1,'schedule','',true);assert.equal(h.reviewButton.textContent,'Review date · 1 of 1');
h.review();assert.equal(h.focus.at(-1).field,h.plans[1].schedule.field);

h=harness();h.type(0,'notes','Submitted');h.type(1,'rename','Second');h.review();
assert.equal(h.reviewButton.textContent,'Review name · 2 of 2');
let saving=h.submit(0,'notes');success(h.requests[0],{notes:'Submitted'});await saving;
assert.equal(h.reviewButton.textContent,'Review name · 1 of 1');
h.type(0,'notes','New draft');assert.equal(h.reviewButton.textContent,'Review name · 2 of 2');
h.review();assert.equal(h.reviewButton.textContent,'Review notes · 1 of 2');
saving=h.submit(0,'notes');h.type(0,'notes','Newer draft');success(h.requests[1],{notes:'New draft'});await saving;
assert.equal(h.reviewButton.textContent,'Review notes · 1 of 2');assert.equal(h.plans[0].notes.field.value,'Newer draft');
saving=h.submit(0,'notes');h.requests[2].reject(Error('Offline'));await saving;
assert.equal(h.reviewButton.textContent,'Review notes · 1 of 2');

h=harness();saving=h.clear(0);assert.equal(h.reviewButton.textContent,'Review unsaved edit');assert.equal(h.reviewButton.disabled,true);
success(h.requests[0]);await saving;assert.equal(h.summary.hidden,true);
h=harness({restored:true});assert.equal(h.reviewButton.textContent,'Review notes · 1 of 1');h.review();
h.plans[0].notes.field.value='Notes 0';h.pageshow();assert.equal(h.reviewButton.disabled,true);
h=harness();h.type(0,'notes','Draft');h.type(1,'rename','Other');h.plans[0].notes.field.disabled=true;h.pageshow();
assert.equal(h.reviewButton.textContent,'Review name · 1 of 1');h.review();assert.equal(h.focus.at(-1).field,h.plans[1].rename.field);
h.plans[1].card.isConnected=false;h.review();assert.equal(h.reviewButton.textContent,'Review unsaved edit');assert.equal(h.focus.length,1);
h.plans[0].notes.field.disabled=false;h.pageshow();assert.equal(h.reviewButton.textContent,'Review notes · 1 of 1');
console.log('TN Game OS 5.169.0 Guided Unsaved Edit Review passed');
