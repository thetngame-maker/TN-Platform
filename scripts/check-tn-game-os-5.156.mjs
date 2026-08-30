import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
const root=new URL('../experiences/wordpress/tn-game-os/',import.meta.url);
const read=path=>fs.readFileSync(new URL(path,root),'utf8');
const bootstrap=read('tn-game-os.php');
const client=read('assets/js/saved-adventures.js');
assert.match(bootstrap,/Version:\s*5\.1(?:5[6-9]|[6-9]\d)\.\d+/);
assert.match(bootstrap,/define\('TNG_OS_VERSION','5\.1(?:5[6-9]|[6-9]\d)\.\d+'\)/);
assert.match(client,/const isUpcomingPrepCard = \(card\) => card\.dataset\.planState !== 'archived' && card\.dataset\.planDate >= todayKey;/);
assert.match(client,/isUpcomingPrepCard\(card\) && previousCount < 10 && launchCountFor\(card\) === 10/);
assert.match(client,/cards\.filter\(\(card\) => isUpcomingPrepCard\(card\)\)/);
assert.match(client,/const aUpcoming = isUpcomingPrepCard\(a\);/);
assert.match(client,/const bUpcoming = isUpcomingPrepCard\(b\);/);
assert.match(client,/const upcoming = isUpcomingPrepCard\(card\);/);
assert.match(client,/nextCard = cards\.filter\(\(card\) => isUpcomingPrepCard\(card\)\)/);
assert.equal((client.match(/card\.dataset\.planState !== 'archived' && card\.dataset\.planDate >= todayKey/g)||[]).length,1);
const helper=client.slice(client.indexOf('const isUpcomingPrepCard ='),client.indexOf('const nextIncompleteCheckFor ='));
assert.doesNotMatch(helper,/post\(|fetch\(|localStorage|sessionStorage|\.click\(|checked\s*=/);
assert.doesNotMatch(client,/setInterval|Notification/);

// Execute the actual runtime helpers with owner-rendered card fixtures.
const helpers=client.slice(client.indexOf('const launchCountFor ='),client.indexOf('const nextIncompleteCheckFor ='));
const {isUpcomingPrepCard,launchReadyStatusFor}=vm.runInNewContext(`${helpers}\n({isUpcomingPrepCard,launchReadyStatusFor});`,{todayKey:'2026-08-30'});
const cardFor=(state,date,ready=4,packed=6,title='Test adventure')=>({
  dataset:{planState:state,planDate:date,planReadyCount:String(ready),planPackedCount:String(packed)},
  querySelector:()=>({textContent:title})
});
for (const [state,date,expected] of [
  ['ready','2026-08-30',true],
  ['active','2026-08-31',true],
  ['completed','2026-09-01',true],
  ['ready','2026-08-29',false],
  ['archived','2026-08-30',false],
  ['archived','2026-09-01',false],
  ['ready','',false],
  ['ready',undefined,false]
]) {
  const card=cardFor(state,date);
  assert.equal(isUpcomingPrepCard(card),expected,`${state} / ${date}: upcoming scope`);
  assert.equal(launchReadyStatusFor(card,9,'Saved.'),expected ? 'Test adventure is launch ready. All 10 preparation checks are complete.' : 'Saved.',`${state} / ${date}: confirmation scope`);
  assert.equal(launchReadyStatusFor(card,10,'Saved.'),'Saved.','Already-complete plans do not repeat confirmation');
}
assert.equal(launchReadyStatusFor(cardFor('ready','2026-08-30',3,6),8,'Saved.'),'Saved.');
assert.equal(launchReadyStatusFor(cardFor('archived','2026-08-30'),9,''),'Checklist updated.');
assert.equal(launchReadyStatusFor(cardFor('ready','2026-08-30',4,6,'  '),9,'Saved.'),'This adventure is launch ready. All 10 preparation checks are complete.');
console.log('TN Game OS 5.156.0 Schedule-Safe Prep Scope passed');
