import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const root=new URL('../experiences/wordpress/tn-game-os/',import.meta.url);
const read=path=>fs.readFileSync(new URL(path,root),'utf8');
const bootstrap=read('tn-game-os.php');
const client=read('assets/js/saved-adventures.js');
assert.match(bootstrap,/Version:\s*5\.157\.0/);
assert.match(bootstrap,/define\('TNG_OS_VERSION','5\.157\.0'\)/);
assert.match(client,/const comparePlanSchedule = \(a, b\) =>/);
assert.match(client,/const dateOrder = comparePlanSchedule\(a,b\);/);
assert.match(client,/comparePlanSchedule\(a,b\) \|\| launchCountFor\(a\)-launchCountFor\(b\)/);
assert.match(client,/if \(aRank === 0\) return comparePlanSchedule\(a,b\);/);
assert.match(client,/if \(aRank === 1\) return comparePlanSchedule\(b,a\);/);
assert.match(client,/nextCard = cards\.filter\(\(card\) => isUpcomingPrepCard\(card\)\)\.sort\(comparePlanSchedule\)/);

// Execute runtime code, not a duplicate implementation of its ordering rules.
const helpers=client.slice(client.indexOf('const launchCountFor ='),client.indexOf('const nextIncompleteCheckFor ='));
const overviewCode=client.slice(client.indexOf('const nextIncompleteCheckFor ='),client.indexOf('const applyFilters ='));
const filtersCode=client.slice(client.indexOf('const applyFilters ='),client.indexOf('const refreshPrepViews ='));
const nextCode=client.match(/^  nextCard = cards\.filter.*;$/m)?.[0];
assert.ok(nextCode);
assert.doesNotMatch(helpers,/post\(|fetch\(|localStorage|sessionStorage|\.click\(|checked\s*=/);
assert.doesNotMatch(client,/setInterval|Notification/);
const todayKey='2026-08-30';
const {startMinutesFor,comparePlanSchedule}=vm.runInNewContext(`${helpers}\n({startMinutesFor,comparePlanSchedule});`,{todayKey});
const cardFor=(id,date,start,count=0,state='ready',updated=0)=>({
  dataset:{planId:id,planDate:date,planStart:start == null ? start : String(start),planReadyCount:String(Math.min(4,count)),planPackedCount:String(Math.max(0,count-4)),planState:state,planUpdated:String(updated)},
  textContent:id,
  querySelector:()=>({textContent:id}),
  querySelectorAll:()=>count < 10 ? [{checked:false}] : []
});
for (const [input,expected] of [
  ['0',0],['1439',1439],[' 540 ',540],
  [undefined,600],[null,600],['',600],['   ',600],
  ['no time',600],['Infinity',600],['-1',600],['1440',600],['540.5',600]
]) assert.equal(startMinutesFor(cardFor('time',todayKey,input)),expected,`Start time ${input}`);
assert.ok(comparePlanSchedule(cardFor('a',todayKey,1439),cardFor('b','2026-08-31',0)) < 0,'Date wins before time');
assert.ok(comparePlanSchedule(cardFor('a',todayKey,540),cardFor('b',todayKey,900)) < 0,'Earlier same-day start wins');
assert.equal(comparePlanSchedule(cardFor('a',todayKey,540),cardFor('b',todayKey,540)),0,'Equal schedules tie');
assert.equal(comparePlanSchedule(cardFor('a',todayKey,''),cardFor('b',todayKey,600)),0,'Missing start uses the existing default');

const cards=[
  cardFor('late-needs',todayKey,900,2),
  cardFor('early-needs',todayKey,540,9),
  cardFor('early-fewer',todayKey,540,4),
  cardFor('ready',todayKey,480,10),
  cardFor('tomorrow-needs','2026-08-31',300,0),
  cardFor('past-earlier','2026-08-29',480,3,'ready',70),
  cardFor('past-later','2026-08-29',900,2,'ready',90),
  cardFor('undated','',600,1,'ready',40),
  cardFor('archived','2026-09-01',100,1,'archived',99)
];
const originalOrder=cards.map(card=>card.dataset.planId);
const sortCards=(mode,fixtures=cards,selectedFilter='all')=>{
  const appended=[];
  const filterStatus={textContent:''};
  vm.runInNewContext(`${helpers}\n${filtersCode}\napplyFilters();`,{
    todayKey,cards:fixtures,sort:{value:mode},search:{value:''},selectedFilter,
    grid:{append:card=>appended.push(card.dataset.planId)},filterStatus,filterEmpty:{hidden:false}
  });
  return {order:appended,visible:fixtures.filter(card=>!card.hidden).map(card=>card.dataset.planId),status:filterStatus.textContent};
};
assert.deepEqual(sortCards('prep').order,[
  'early-fewer','early-needs','late-needs','tomorrow-needs','ready',
  'past-later','past-earlier','undated','archived'
],'Preparation groups remain intact; date/time wins before completion tie-breaks');
assert.deepEqual(sortCards('date').order,[
  'ready','early-needs','early-fewer','late-needs','tomorrow-needs','archived',
  'past-later','past-earlier','undated'
],'Upcoming sorts earliest first, past sorts latest first, and undated stays last');
assert.deepEqual(cards.map(card=>card.dataset.planId),originalOrder,'Sorting does not mutate source card order');
assert.deepEqual(sortCards('prep',cards,'needs-prep').visible,['late-needs','early-needs','early-fewer','tomorrow-needs']);
assert.deepEqual(sortCards('prep',cards,'launch-ready').visible,['ready']);
assert.equal(sortCards('prep',cards,'needs-prep').status,'4 of 9 adventures shown');
assert.deepEqual(sortCards('date',[]).order,[]);

const overviewFor=(fixtures)=>{
  const fields=new Map();
  const prepOverview={hidden:false,querySelector:selector=>{
    if(!fields.has(selector))fields.set(selector,{textContent:''});
    return fields.get(selector);
  }};
  vm.runInNewContext(`${helpers}\n${overviewCode}\nupdatePrepOverview();`,{
    todayKey,cards:fixtures,prepOverview,prepFocus:null,priorityPrep:null
  });
  return {hidden:prepOverview.hidden,get:selector=>fields.get(selector)?.textContent};
};
let overview=overviewFor(cards);
assert.match(overview.get('[data-tng-prep-priority]'),/^early-fewer is the closest plan/);
assert.equal(overview.get('[data-tng-prep-upcoming]'),'5');
assert.equal(overview.get('[data-tng-prep-needed]'),'4');
assert.equal(overview.get('[data-tng-prep-ready]'),'1');
const refreshed=cards.map(card=>card.dataset.planId==='early-fewer' ? cardFor('early-fewer',todayKey,540,10) : card);
assert.match(overviewFor(refreshed).get('[data-tng-prep-priority]'),/^early-needs is the closest plan/,'Completing a plan refreshes to the next scheduled prep priority');
overview=overviewFor([cardFor('ready',todayKey,480,10)]);
assert.equal(overview.hidden,false);
assert.equal(overview.get('[data-tng-prep-priority]'),'Every upcoming adventure has all launch checks complete.');
assert.equal(overviewFor([]).hidden,true);
assert.equal(overviewFor(cards.filter(card=>['archived','undated','past-earlier'].includes(card.dataset.planId))).hidden,true);

const nextFor=fixtures=>vm.runInNewContext(`${helpers}\n${nextCode}\nnextCard;`,{todayKey,cards:fixtures,nextCard:null});
assert.equal(nextFor(cards).dataset.planId,'ready','Next banner follows schedule regardless of prep completeness');
assert.equal(nextFor([cardFor('archived',todayKey,0,1,'archived'),...cards]).dataset.planId,'ready');
assert.equal(nextFor([]),null);
console.log('TN Game OS 5.157.0 Start-Time-Aware Prep Priority passed');
