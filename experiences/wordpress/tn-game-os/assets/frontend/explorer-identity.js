(()=>{
'use strict';

const root=document.querySelector('.tng-runtime');
const runtime=window.TNGQuestRuntime||{};
const config=window.TNGExplorerIdentity||{};
if(!root||!runtime.questId)return;

const PROFILE_KEY='tngExplorerProfile:v1';
const defaultProfile=()=>({totalXp:0,completedCheckpoints:[],completedQuests:[],collections:{},badges:[],updatedAt:''});
const normalize=(value)=>({
  totalXp:Math.max(0,Number(value?.totalXp||0)),
  completedCheckpoints:Array.isArray(value?.completedCheckpoints)?value.completedCheckpoints.map(String):[],
  completedQuests:Array.isArray(value?.completedQuests)?value.completedQuests.map(String):[],
  collections:value?.collections&&typeof value.collections==='object'?value.collections:{},
  badges:Array.isArray(value?.badges)?value.badges.map(String):[],
  updatedAt:value?.updatedAt||''
});
const loadLocal=()=>{try{return normalize(JSON.parse(localStorage.getItem(PROFILE_KEY)||'{}'));}catch(error){return defaultProfile();}};
const saveLocal=(profile)=>{try{localStorage.setItem(PROFILE_KEY,JSON.stringify(profile));}catch(error){}};
const unique=(values)=>Array.from(new Set(values.map(String)));
const levelFor=(xp)=>Math.max(1,Math.floor(Math.sqrt(Math.max(0,xp)/100))+1);
const levelStart=(level)=>Math.pow(level-1,2)*100;
const levelEnd=(level)=>Math.pow(level,2)*100;
const badgeCatalog={
  first_step:{icon:'✦',title:'First Step',text:'Complete your first checkpoint'},
  trailblazer:{icon:'◆',title:'Trailblazer',text:'Complete 3 checkpoints'},
  quest_complete:{icon:'★',title:'Quest Complete',text:'Finish a full TN Game quest'},
  explorer_10:{icon:'⬟',title:'Explorer 10',text:'Complete 10 checkpoints'}
};

let profile=normalize(config.initialProfile||loadLocal());
let syncing=false;
let panel=null;
let lastXp=profile.totalXp;

const api=async(method,body)=>{
  const response=await fetch(config.profileUrl,{method,credentials:'same-origin',headers:{'Content-Type':'application/json','X-WP-Nonce':config.restNonce||''},body:body?JSON.stringify(body):undefined});
  if(!response.ok)throw new Error('Explorer profile request failed');
  return response.json();
};

const persist=async()=>{
  profile.completedCheckpoints=unique(profile.completedCheckpoints);
  profile.completedQuests=unique(profile.completedQuests);
  profile.badges=unique(profile.badges);
  saveLocal(profile);
  if(!config.loggedIn||syncing)return;
  syncing=true;
  try{
    profile=normalize(await api('POST',profile));
    saveLocal(profile);
  }catch(error){}
  syncing=false;
  render();
};

const ensurePanel=()=>{
  if(panel)return panel;
  panel=document.createElement('section');
  panel.className='tng-explorer-card';
  panel.innerHTML=`
    <div class="tng-explorer-person">
      <div class="tng-explorer-avatar" data-explorer-avatar></div>
      <div>
        <span class="tng-explorer-kicker">EXPLORER IDENTITY</span>
        <h2 data-explorer-name>Explorer</h2>
        <p data-explorer-rank>Level 1 Explorer</p>
      </div>
    </div>
    <div class="tng-explorer-level">
      <div class="tng-explorer-level-head"><strong data-explorer-level>Level 1</strong><span data-explorer-xp>0 XP</span></div>
      <div class="tng-explorer-level-bar"><span data-explorer-level-bar></span></div>
      <small data-explorer-next>100 XP to Level 2</small>
    </div>
    <div class="tng-explorer-stats">
      <div><strong data-explorer-checkpoints>0</strong><span>Checkpoints</span></div>
      <div><strong data-explorer-quests>0</strong><span>Quests</span></div>
      <div><strong data-explorer-collections>0</strong><span>Collections</span></div>
    </div>
    <button type="button" class="tng-explorer-badges-toggle" data-explorer-badges-toggle>View badges</button>
    <div class="tng-explorer-badges" data-explorer-badges hidden></div>`;
  const hero=root.querySelector('.tng-runtime-hero');
  hero?.insertAdjacentElement('afterend',panel);
  panel.querySelector('[data-explorer-badges-toggle]')?.addEventListener('click',()=>{
    const badges=panel.querySelector('[data-explorer-badges]');
    badges.hidden=!badges.hidden;
    panel.querySelector('[data-explorer-badges-toggle]').textContent=badges.hidden?'View badges':'Hide badges';
  });
  return panel;
};

const initials=(name)=>String(name||'Explorer').split(/\s+/).filter(Boolean).slice(0,2).map(part=>part[0]?.toUpperCase()||'').join('')||'E';
const collectionCount=()=>Object.values(profile.collections||{}).filter(value=>Number(value)>0).length;

const render=()=>{
  const card=ensurePanel();
  const level=levelFor(profile.totalXp),start=levelStart(level),end=levelEnd(level),within=profile.totalXp-start,span=Math.max(1,end-start),percent=Math.min(100,Math.round((within/span)*100));
  const name=config.displayName||'Explorer';
  const avatar=card.querySelector('[data-explorer-avatar]');
  avatar.innerHTML=config.avatarUrl?`<img src="${config.avatarUrl}" alt="">`:`<span>${initials(name)}</span>`;
  card.querySelector('[data-explorer-name]').textContent=name;
  card.querySelector('[data-explorer-rank]').textContent=`Level ${level} Explorer`;
  card.querySelector('[data-explorer-level]').textContent=`Level ${level}`;
  card.querySelector('[data-explorer-xp]').textContent=`${profile.totalXp.toLocaleString()} XP`;
  card.querySelector('[data-explorer-level-bar]').style.width=percent+'%';
  card.querySelector('[data-explorer-next]').textContent=`${Math.max(0,end-profile.totalXp).toLocaleString()} XP to Level ${level+1}`;
  card.querySelector('[data-explorer-checkpoints]').textContent=profile.completedCheckpoints.length;
  card.querySelector('[data-explorer-quests]').textContent=profile.completedQuests.length;
  card.querySelector('[data-explorer-collections]').textContent=collectionCount();
  const badges=card.querySelector('[data-explorer-badges]');
  badges.innerHTML=profile.badges.length?profile.badges.map(key=>{const badge=badgeCatalog[key]||{icon:'✦',title:key,text:'Achievement unlocked'};return `<article><span>${badge.icon}</span><div><strong>${badge.title}</strong><small>${badge.text}</small></div></article>`;}).join(''):'<p>Complete checkpoints to unlock your first badge.</p>';
  if(profile.totalXp>lastXp){card.classList.remove('is-leveling');void card.offsetWidth;card.classList.add('is-leveling');lastXp=profile.totalXp;}
};

const awardBadge=(key)=>{if(!profile.badges.includes(key))profile.badges.push(key);};
const checkpointKey=(stopId)=>`${runtime.questId}:${stopId}`;

const reconcile=()=>{
  const stops=Array.isArray(runtime.stops)?runtime.stops:[];
  const completeCards=Array.from(root.querySelectorAll('.tng-checkpoint.is-complete[data-stop-id]'));
  let changed=false;
  completeCards.forEach(card=>{
    const stopId=String(card.dataset.stopId||'');
    const key=checkpointKey(stopId);
    if(!stopId||profile.completedCheckpoints.includes(key))return;
    const stop=stops.find(item=>String(item.id)===stopId)||{};
    profile.completedCheckpoints.push(key);
    profile.totalXp+=Math.max(0,Number(stop.xp||0));
    const collection=String(stop.type||'checkpoint');
    profile.collections[collection]=Number(profile.collections[collection]||0)+1;
    changed=true;
  });
  if(root.classList.contains('is-complete')){
    const questKey=String(runtime.questId);
    if(!profile.completedQuests.includes(questKey)){profile.completedQuests.push(questKey);awardBadge('quest_complete');changed=true;}
  }
  if(profile.completedCheckpoints.length>=1)awardBadge('first_step');
  if(profile.completedCheckpoints.length>=3)awardBadge('trailblazer');
  if(profile.completedCheckpoints.length>=10)awardBadge('explorer_10');
  if(changed)persist();else render();
};

const observer=new MutationObserver(()=>reconcile());
observer.observe(root,{subtree:true,childList:true,attributes:true,attributeFilter:['class']});

const init=async()=>{
  if(config.loggedIn){
    try{profile=normalize(await api('GET'));saveLocal(profile);}catch(error){}
  }
  render();
  reconcile();
};

init();
})();
