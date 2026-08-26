(()=>{
'use strict';
const root=document.querySelector('.tng-runtime'),config=window.TNGAdventureRewards||{};
if(!root)return;

const REWARD_KEY='tngAdventureRewards:v1',WALLET_KEY='tngDailyMissions:v1';
const catalog=[
  {key:'violet_compass',icon:'✦',title:'Violet Compass',copy:'Give your active checkpoint marker a brighter violet compass style.',cost:40,className:'tng-reward-violet-compass'},
  {key:'golden_frame',icon:'◇',title:'Golden Explorer Frame',copy:'Add a gold frame to your Explorer avatar and profile identity.',cost:75,className:'tng-reward-golden-frame'},
  {key:'campfire_glow',icon:'🔥',title:'Campfire Glow',copy:'Surround your Explorer identity with a warm animated adventure glow.',cost:120,className:'tng-reward-campfire-glow'}
];
const normalize=v=>({tokens:Math.max(0,Number(v?.tokens||0)),unlocked:Array.isArray(v?.unlocked)?Array.from(new Set(v.unlocked.map(String))):[],equipped:String(v?.equipped||'')});
const loadWallet=()=>{try{return JSON.parse(localStorage.getItem(WALLET_KEY)||'{}');}catch(e){return {};}};
const saveWallet=w=>{try{localStorage.setItem(WALLET_KEY,JSON.stringify(w));}catch(e){}};
const load=()=>{try{const local=normalize(JSON.parse(localStorage.getItem(REWARD_KEY)||'{}'));const wallet=loadWallet();local.tokens=Math.max(0,Number(wallet.tokens??local.tokens));return local;}catch(e){return normalize({});}};
const save=s=>{try{localStorage.setItem(REWARD_KEY,JSON.stringify(s));}catch(e){}};
let state=normalize(config.initialState||load()),panel=null,drawer=null,busy=false;

const api=async(action,reward)=>{const r=await fetch(config.rewardsUrl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-WP-Nonce':config.restNonce||''},body:JSON.stringify({action,reward})});const data=await r.json();if(!r.ok)throw new Error(data?.message||'Reward action failed');return data;};
const itemFor=key=>catalog.find(item=>item.key===key);
const applyEquipped=()=>{catalog.forEach(item=>document.body.classList.toggle(item.className,state.equipped===item.key));};
const broadcast=()=>document.dispatchEvent(new CustomEvent('tng:wallet-updated',{detail:{tokens:state.tokens}}));

const ensurePanel=()=>{if(panel)return panel;panel=document.createElement('section');panel.className='tng-reward-vault-card';panel.innerHTML=`<div><span>ADVENTURE REWARDS</span><h2>Reward Vault</h2><p>Spend Adventure Tokens on Explorer cosmetics.</p></div><div class="tng-reward-vault-summary"><strong data-vault-tokens>0</strong><small>Tokens</small></div><button type="button" data-open-vault>Open vault</button>`;const missions=root.querySelector('.tng-daily-missions');if(missions)missions.insertAdjacentElement('afterend',panel);else root.querySelector('.tng-explorer-card')?.insertAdjacentElement('afterend',panel);panel.querySelector('[data-open-vault]')?.addEventListener('click',openDrawer);return panel;};
const ensureDrawer=()=>{if(drawer)return drawer;drawer=document.createElement('section');drawer.className='tng-reward-drawer';drawer.hidden=true;drawer.innerHTML=`<div class="tng-reward-backdrop" data-close-vault></div><article class="tng-reward-sheet"><button type="button" class="tng-reward-close" data-close-vault aria-label="Close">×</button><header><div><span>ADVENTURE TOKEN SHOP</span><h2>Reward Vault</h2><p>Unlock cosmetics that make your Explorer identity your own.</p></div><div class="tng-reward-wallet"><strong data-drawer-tokens>0</strong><small>Tokens</small></div></header><div class="tng-reward-catalog" data-reward-catalog></div><footer><p>Adventure Tokens are earned through Daily Missions.</p></footer></article>`;document.body.appendChild(drawer);drawer.addEventListener('click',e=>{if(e.target.closest('[data-close-vault]'))closeDrawer();const button=e.target.closest('[data-reward-action]');if(button)act(button.dataset.rewardAction||'',button.dataset.rewardKey||'');});document.addEventListener('keydown',e=>{if(e.key==='Escape'&&!drawer.hidden)closeDrawer();});return drawer;};
const openDrawer=()=>{ensureDrawer().hidden=false;document.body.classList.add('tng-reward-open');render();};
const closeDrawer=()=>{if(drawer)drawer.hidden=true;document.body.classList.remove('tng-reward-open');};

const act=async(action,key)=>{if(busy)return;const item=itemFor(key);if(!item&&key)return;busy=true;try{if(config.loggedIn){state=normalize(await api(action,key));}else if(action==='redeem'){if(state.unlocked.includes(key))return;if(state.tokens<item.cost)throw new Error('Complete more Daily Missions to earn enough tokens.');state.tokens-=item.cost;state.unlocked.push(key);state.equipped=key;const wallet=loadWallet();wallet.tokens=state.tokens;saveWallet(wallet);}else if(action==='equip'){if(key&&!state.unlocked.includes(key))return;state.equipped=key;}save(state);applyEquipped();broadcast();render();}catch(error){showNotice(error.message||'Unable to update reward.');}finally{busy=false;}};
const showNotice=message=>{const sheet=ensureDrawer().querySelector('.tng-reward-sheet');let notice=sheet.querySelector('.tng-reward-notice');if(!notice){notice=document.createElement('div');notice.className='tng-reward-notice';sheet.prepend(notice);}notice.textContent=message;setTimeout(()=>notice.remove(),3200);};

const render=()=>{const card=ensurePanel();card.querySelector('[data-vault-tokens]').textContent=state.tokens.toLocaleString();if(drawer){drawer.querySelector('[data-drawer-tokens]').textContent=state.tokens.toLocaleString();drawer.querySelector('[data-reward-catalog]').innerHTML=catalog.map(item=>{const unlocked=state.unlocked.includes(item.key),equipped=state.equipped===item.key,afford=state.tokens>=item.cost;let action='redeem',label=afford?'Unlock':'Need more tokens';if(unlocked){action='equip';label=equipped?'Equipped':'Equip';}return `<article class="${unlocked?'is-unlocked':''} ${equipped?'is-equipped':''}"><div class="tng-reward-preview ${item.className}"><span>${item.icon}</span></div><div class="tng-reward-copy"><span>${unlocked?'UNLOCKED':'COSMETIC REWARD'}</span><h3>${item.title}</h3><p>${item.copy}</p></div><div class="tng-reward-cost"><strong>${unlocked?'✓':item.cost}</strong><small>${unlocked?'Owned':'Tokens'}</small></div><button type="button" data-reward-action="${action}" data-reward-key="${item.key}" ${(!unlocked&&!afford)||equipped?'disabled':''}>${label}</button></article>`;}).join('');}applyEquipped();};

document.addEventListener('tng:wallet-updated',e=>{const tokens=Number(e.detail?.tokens);if(Number.isFinite(tokens)){state.tokens=Math.max(0,tokens);save(state);render();}});
const init=async()=>{render();if(config.loggedIn){try{const r=await fetch(config.rewardsUrl,{credentials:'same-origin',headers:{'X-WP-Nonce':config.restNonce||''}});if(r.ok){state=normalize(await r.json());save(state);render();broadcast();}}catch(e){}}};
init();
})();
