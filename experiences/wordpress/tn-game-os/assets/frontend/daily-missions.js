(()=>{
'use strict';
const root=document.querySelector('.tng-runtime'),runtime=window.TNGQuestRuntime||{},config=window.TNGDailyMissions||{};
if(!root||!runtime.questId)return;

const KEY='tngDailyMissions:v1';
const today=()=>new Date().toISOString().slice(0,10);
const yesterday=()=>{const d=new Date();d.setDate(d.getDate()-1);return d.toISOString().slice(0,10);};
const normalize=v=>({date:String(v?.date||''),tokens:Math.max(0,Number(v?.tokens||0)),completed:Array.isArray(v?.completed)?Array.from(new Set(v.completed.map(String))):[],claimed:Array.isArray(v?.claimed)?Array.from(new Set(v.claimed.map(String))):[],missionStreak:Math.max(0,Number(v?.missionStreak||0)),lastCompletedDate:String(v?.lastCompletedDate||'')});
const load=()=>{try{return normalize(JSON.parse(localStorage.getItem(KEY)||'{}'));}catch(e){return normalize({});}};
const save=s=>{try{localStorage.setItem(KEY,JSON.stringify(s));}catch(e){}};
let state=normalize(config.initialState||load()),card=null,syncing=false,queued=false;
if(state.date!==today())state={...state,date:today(),completed:[],claimed:[]};

const missions=[
  {key:'location',icon:'◎',title:'Find your bearings',text:'Activate live location in the quest map.',reward:10,target:1},
  {key:'checkpoint',icon:'✓',title:'Make a discovery',text:'Complete one checkpoint today.',reward:20,target:1},
  {key:'momentum',icon:'⚡',title:'Build momentum',text:'Complete two checkpoints today.',reward:35,target:2}
];

const api=async(method,body)=>{const r=await fetch(config.stateUrl,{method,credentials:'same-origin',headers:{'Content-Type':'application/json','X-WP-Nonce':config.restNonce||''},body:body?JSON.stringify(body):undefined});if(!r.ok)throw new Error();return r.json();};
const persist=async()=>{save(state);render();if(!config.loggedIn||syncing)return;syncing=true;try{state=normalize(await api('POST',state));save(state);render();}catch(e){}syncing=false;};
const completedCount=()=>root.querySelectorAll('.tng-checkpoint.is-complete[data-stop-id]').length;
const locationActive=()=>Boolean(root.querySelector('.tng-location-active,.tng-player-marker,.tng-live-location.is-active'))||/location active/i.test(root.textContent||'');
const progressFor=m=>m.key==='location'?(locationActive()?1:0):Math.min(m.target,completedCount());

const ensureCard=()=>{if(card)return card;card=document.createElement('section');card.className='tng-daily-missions';card.innerHTML=`<header><div><span>DAILY MISSIONS</span><h2>Today’s adventure</h2><p>Complete missions to earn Adventure Tokens.</p></div><div class="tng-mission-wallet"><strong data-token-balance>0</strong><small>Tokens</small></div></header><div class="tng-mission-list" data-mission-list></div><footer><span data-mission-streak>0-day mission streak</span><small>Daily missions reset tomorrow.</small></footer>`;const explorer=root.querySelector('.tng-explorer-card');if(explorer)explorer.insertAdjacentElement('afterend',card);else root.querySelector('.tng-runtime-hero')?.insertAdjacentElement('afterend',card);card.addEventListener('click',e=>{const button=e.target.closest('[data-claim-mission]');if(!button)return;claim(button.dataset.claimMission||'');});return card;};

const completeMission=key=>{if(state.completed.includes(key))return;state.completed.push(key);if(state.completed.length===missions.length){if(state.lastCompletedDate===yesterday())state.missionStreak+=1;else if(state.lastCompletedDate!==today())state.missionStreak=1;state.lastCompletedDate=today();}persist();};
const claim=key=>{const mission=missions.find(m=>m.key===key);if(!mission||!state.completed.includes(key)||state.claimed.includes(key))return;state.claimed.push(key);state.tokens+=mission.reward;persist();const button=card?.querySelector(`[data-claim-mission="${key}"]`);button?.closest('article')?.classList.add('is-claimed');};

const render=()=>{const node=ensureCard();node.querySelector('[data-token-balance]').textContent=state.tokens.toLocaleString();node.querySelector('[data-mission-streak]').textContent=`${state.missionStreak}-day mission streak`;node.querySelector('[data-mission-list]').innerHTML=missions.map(m=>{const value=progressFor(m),done=value>=m.target,claimed=state.claimed.includes(m.key),pct=Math.round((value/m.target)*100);return `<article class="${done?'is-complete':''} ${claimed?'is-claimed':''}"><span class="tng-mission-icon">${claimed?'✓':m.icon}</span><div class="tng-mission-copy"><strong>${m.title}</strong><small>${m.text}</small><div class="tng-mission-progress"><i style="width:${pct}%"></i></div><em>${value} / ${m.target}</em></div><div class="tng-mission-reward"><b>+${m.reward}</b><small>tokens</small>${done&&!claimed?`<button type="button" data-claim-mission="${m.key}">Claim</button>`:`<span>${claimed?'Collected':done?'Ready':'In progress'}</span>`}</div></article>`;}).join('');};

const reconcile=()=>{missions.forEach(m=>{if(progressFor(m)>=m.target)completeMission(m.key);});render();};
const queue=()=>{if(queued)return;queued=true;requestAnimationFrame(()=>{queued=false;reconcile();});};
const init=async()=>{render();const list=root.querySelector('.tng-runtime-checkpoints');if(list)new MutationObserver(queue).observe(list,{subtree:true,attributes:true,attributeFilter:['class']});document.addEventListener('click',e=>{if(e.target.closest('[data-use-location],.tng-use-location,.tng-location-button'))setTimeout(queue,800);});setInterval(queue,4000);reconcile();if(config.loggedIn){try{const remote=normalize(await api('GET'));state={...state,...remote,date:today(),completed:remote.date===today()?remote.completed:[],claimed:remote.date===today()?remote.claimed:[]};save(state);render();reconcile();}catch(e){}}};
init();
})();
