import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const root=new URL('../experiences/wordpress/tn-game-os/',import.meta.url);
const read=path=>fs.readFileSync(new URL(path,root),'utf8');
const bootstrap=read('tn-game-os.php');
const client=read('assets/js/saved-adventures.js');
assert.match(bootstrap,/Version:\s*5\.158\.0/);
assert.match(bootstrap,/define\('TNG_OS_VERSION','5\.158\.0'\)/);
assert.match(client,/let planNavigationRequest = 0;/);
assert.equal((client.match(/const request = \+\+planNavigationRequest;/g)||[]).length,2);
assert.equal((client.match(/if \(search\) search\.value = '';/g)||[]).length,3);
assert.match(client,/priorityPrep\?\.card !== target\.card \|\| priorityPrep\?\.checkbox !== target\.checkbox/);
assert.match(client,/!target\.card\.isConnected \|\| target\.card\.hidden \|\| !target\.checkbox\.isConnected \|\| target\.checkbox\.hidden \|\| target\.checkbox\.disabled \|\| target\.checkbox\.checked/);
assert.match(client,/document\.activeElement === focusOrigin/);
assert.match(client,/button:not\(:disabled\),a\[href\]/);

const prepStart=client.indexOf("prepFocus?.addEventListener('click'");
const prepEnd=client.indexOf('\n  });\n\n  const adventurePacks =',prepStart)+7;
const prepCode=client.slice(prepStart,prepEnd);
assert.ok(prepStart > -1 && prepEnd > prepStart);
const nextStart=client.indexOf('    const revealNextCard = () => {');
const nextEnd=client.indexOf('\n    };',nextStart)+7;
const nextCode=client.slice(nextStart,nextEnd);
assert.ok(nextStart > -1 && nextEnd > nextStart);
assert.doesNotMatch(prepCode,/post\(|fetch\(|localStorage|sessionStorage|checked\s*=/);
assert.doesNotMatch(nextCode,/post\(|fetch\(|checked\s*=/);
assert.doesNotMatch(client,/setInterval|Notification/);

const createClassList=()=>{
  const values=new Set();
  return {values,add:value=>values.add(value),remove:value=>values.delete(value)};
};
const prepHarness=()=>{
  const timers=[];
  let handler=null;
  const activeElement={id:'prep-button'};
  const checkbox={isConnected:true,hidden:false,disabled:false,checked:false,focusCalls:0,focus(){this.focusCalls+=1;}};
  const card={isConnected:true,hidden:false,classList:createClassList(),scrollCalls:0,scrollIntoView(){this.scrollCalls+=1;}};
  const context={
    prepFocus:{addEventListener:(type,callback)=>{assert.equal(type,'click');handler=callback;}},
    priorityPrep:{card,checkbox},cards:[card],search:{value:'waterfall'},selectedFilter:'all',planNavigationRequest:0,
    syncFilterControlsCalls:0,savePreferencesCalls:0,applyFiltersCalls:0,
    syncFilterControls:()=>{context.syncFilterControlsCalls+=1;},
    savePreferences:()=>{context.savePreferencesCalls+=1;},
    applyFilters:()=>{context.applyFiltersCalls+=1;card.hidden=false;},
    document:{activeElement},
    window:{setTimeout:(callback,delay)=>{timers.push({callback,delay});return timers.length;}}
  };
  vm.runInNewContext(prepCode,context);
  return {context,card,checkbox,timers,run:()=>handler()};
};
let prep=prepHarness();
prep.run();
assert.equal(prep.context.search.value,'');
assert.equal(prep.context.selectedFilter,'needs-prep');
assert.equal(prep.card.scrollCalls,1);
assert.ok(prep.card.classList.values.has('is-prep-focus'));
assert.equal(prep.timers[0].delay,450);
prep.timers[0].callback();
assert.equal(prep.checkbox.focusCalls,1);
assert.ok(!prep.card.classList.values.has('is-prep-focus'));
assert.equal(prep.context.syncFilterControlsCalls,1);
assert.equal(prep.context.savePreferencesCalls,1);
assert.equal(prep.context.applyFiltersCalls,1);

for (const mutate of [
  harness=>{harness.context.priorityPrep={card:harness.card,checkbox:{...harness.checkbox}};},
  harness=>{harness.card.isConnected=false;},
  harness=>{harness.card.hidden=true;},
  harness=>{harness.checkbox.isConnected=false;},
  harness=>{harness.checkbox.hidden=true;},
  harness=>{harness.checkbox.disabled=true;},
  harness=>{harness.checkbox.checked=true;},
  harness=>{harness.context.document.activeElement={id:'another-control'};}
]) {
  prep=prepHarness();prep.run();mutate(prep);prep.timers[0].callback();
  assert.equal(prep.checkbox.focusCalls,0,'Stale or unavailable prep controls do not receive focus');
  assert.ok(!prep.card.classList.values.has('is-prep-focus'),'The original highlight is cleaned up');
}
prep=prepHarness();
const other=prepHarness();
prep.run();
prep.context.priorityPrep=other.context.priorityPrep;
prep.timers[0].callback();
assert.equal(prep.checkbox.focusCalls,0);
assert.equal(other.checkbox.focusCalls,0,'Priority refresh cannot redirect the delayed focus');
assert.ok(!prep.card.classList.values.has('is-prep-focus'));
prep=prepHarness();
prep.run();
prep.context.priorityPrep={card:prep.card,checkbox:prep.checkbox};
prep.timers[0].callback();
assert.equal(prep.checkbox.focusCalls,1,'Refreshing an unchanged target remains focusable');
prep=prepHarness();
prep.context.priorityPrep=null;
prep.run();
assert.equal(prep.timers.length,0,'No preparation target creates no handoff');
prep=prepHarness();
prep.run();prep.run();
assert.equal(prep.context.planNavigationRequest,2);
prep.timers[0].callback();
assert.equal(prep.checkbox.focusCalls,0,'Older preparation handoff is canceled');
prep.timers[1].callback();
assert.equal(prep.checkbox.focusCalls,1,'Newest preparation handoff completes once');

const nextHarness=()=>{
  const timers=[];
  let controlFocusCalls=0;
  const activeElement={id:'next-banner-button'};
  const target={
    isConnected:true,hidden:true,classList:createClassList(),scrollCalls:0,
    scrollIntoView(){this.scrollCalls+=1;},
    querySelector(selector){assert.equal(selector,'button:not(:disabled),a[href]');return {isConnected:true,focus:()=>{controlFocusCalls+=1;}};}
  };
  const context={
    nextCard:target,cards:[target],search:{value:'unrelated query'},selectedFilter:'launch-ready',planNavigationRequest:0,
    syncFilterControlsCalls:0,savePreferencesCalls:0,applyFiltersCalls:0,
    syncFilterControls:()=>{context.syncFilterControlsCalls+=1;},
    savePreferences:()=>{context.savePreferencesCalls+=1;},
    applyFilters:()=>{context.applyFiltersCalls+=1;target.hidden=false;},
    document:{activeElement},window:{setTimeout:(callback,delay)=>{timers.push({callback,delay});return timers.length;}}
  };
  vm.runInNewContext(`${nextCode}\nthis.revealNextCard = revealNextCard;`,context);
  return {context,target,timers,focusCalls:()=>controlFocusCalls,run:()=>context.revealNextCard()};
};
let next=nextHarness();
next.run();
assert.equal(next.context.search.value,'');
assert.equal(next.context.selectedFilter,'all');
assert.equal(next.target.hidden,false);
assert.equal(next.target.scrollCalls,1);
assert.equal(next.timers[0].delay,450);
next.timers[0].callback();
assert.equal(next.focusCalls(),1);
assert.equal(next.context.syncFilterControlsCalls,1);
assert.equal(next.context.savePreferencesCalls,1);
assert.equal(next.context.applyFiltersCalls,1);

for (const mutate of [
  harness=>{harness.context.nextCard={};},
  harness=>{harness.target.isConnected=false;},
  harness=>{harness.target.hidden=true;},
  harness=>{harness.context.document.activeElement={id:'another-control'};}
]) {
  next=nextHarness();next.run();mutate(next);next.timers[0].callback();
  assert.equal(next.focusCalls(),0,'Stale, hidden, removed, or superseded plan does not receive focus');
}
next=nextHarness();
next.run();next.run();
next.timers[0].callback();
assert.equal(next.focusCalls(),0,'Older Next adventure handoff is canceled');
next.timers[1].callback();
assert.equal(next.focusCalls(),1,'Newest Next adventure handoff completes once');

// Both controls share the request counter: the most recent explicit navigation wins.
for (const prepFirst of [true,false]) {
  prep=prepHarness();
  let nextFocusCalls=0;
  prep.card.querySelector=()=>({focus:()=>{nextFocusCalls+=1;}});
  prep.context.nextCard=prep.card;
  vm.runInNewContext(`${nextCode}\nthis.revealNextCard = revealNextCard;`,prep.context);
  if(prepFirst){prep.run();prep.context.revealNextCard();}
  else{prep.context.revealNextCard();prep.run();}
  prep.timers[0].callback();
  assert.equal(prep.checkbox.focusCalls,0);
  assert.equal(nextFocusCalls,0);
  if(!prepFirst)assert.ok(prep.card.classList.values.has('is-prep-focus'),'An old timer cannot remove the new highlight');
  prep.timers[1].callback();
  assert.equal(prep.checkbox.focusCalls,prepFirst?0:1);
  assert.equal(nextFocusCalls,prepFirst?1:0);
  assert.ok(!prep.card.classList.values.has('is-prep-focus'));
}
next=nextHarness();next.run();next.target.querySelector=()=>null;next.timers[0].callback();
assert.equal(next.focusCalls(),0,'A plan with no enabled action has nothing to focus');
console.log('TN Game OS 5.158.0 Reliable Plan Navigation passed');
