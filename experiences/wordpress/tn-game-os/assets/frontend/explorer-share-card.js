(()=>{
'use strict';
const root=document.querySelector('.tng-runtime');
if(!root)return;
let modal=null;
const text=(selector,fallback='')=>document.querySelector(selector)?.textContent?.trim()||fallback;
const escapeHtml=value=>String(value).replace(/[&<>'"]/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[char]));
const profileData=()=>({
  name:text('[data-drawer-name]',text('[data-name]','Explorer')),
  rank:text('[data-drawer-rank]',text('[data-rank]','TN Game Explorer')),
  xp:text('[data-drawer-xp]','0'),
  checkpoints:text('[data-drawer-checkpoints]','0'),
  quests:text('[data-drawer-quests]','0'),
  streak:text('[data-drawer-streak]','0'),
  featured:text('[data-featured] strong',''),
  avatar:document.querySelector('[data-drawer-avatar] img,[data-avatar] img')?.src||''
});
const ensureModal=()=>{
  if(modal)return modal;
  modal=document.createElement('section');
  modal.className='tng-share-card-modal';
  modal.hidden=true;
  modal.innerHTML=`<div class="tng-share-card-backdrop" data-share-close></div><article class="tng-share-card-sheet"><button type="button" class="tng-share-card-close" data-share-close aria-label="Close">×</button><div class="tng-share-card-preview" data-share-preview></div><div class="tng-share-card-actions"><button type="button" data-share-native>Share</button><button type="button" data-share-download>Save image</button></div><p>Share your Explorer identity, progress, and featured achievement.</p></article>`;
  document.body.appendChild(modal);
  modal.addEventListener('click',event=>{
    if(event.target.closest('[data-share-close]'))close();
    if(event.target.closest('[data-share-native]'))share();
    if(event.target.closest('[data-share-download]'))download();
  });
  document.addEventListener('keydown',event=>{if(event.key==='Escape'&&!modal.hidden)close();});
  return modal;
};
const render=()=>{
  const data=profileData();
  const avatar=data.avatar?`<img src="${escapeHtml(data.avatar)}" alt="">`:`<span>${escapeHtml(data.name.slice(0,1).toUpperCase())}</span>`;
  ensureModal().querySelector('[data-share-preview]').innerHTML=`<div class="tng-share-brand">THE TN GAME</div><header><div class="tng-share-avatar">${avatar}</div><div><small>TN GAME EXPLORER</small><h2>${escapeHtml(data.name)}</h2><p>${escapeHtml(data.rank)}</p></div></header><div class="tng-share-stats"><article><strong>${escapeHtml(data.xp)}</strong><span>XP</span></article><article><strong>${escapeHtml(data.checkpoints)}</strong><span>Checkpoints</span></article><article><strong>${escapeHtml(data.quests)}</strong><span>Quests</span></article><article><strong>${escapeHtml(data.streak)}</strong><span>Day streak</span></article></div>${data.featured?`<div class="tng-share-featured"><span>✦</span><div><small>FEATURED ACHIEVEMENT</small><strong>${escapeHtml(data.featured)}</strong></div></div>`:''}<footer>Explore Tennessee. Collect discoveries. Build your story.</footer>`;
};
const open=()=>{render();ensureModal().hidden=false;document.body.classList.add('tng-share-card-open');};
const close=()=>{if(modal)modal.hidden=true;document.body.classList.remove('tng-share-card-open');};
const canvas=async()=>{
  const data=profileData(),c=document.createElement('canvas'),ctx=c.getContext('2d');
  c.width=1080;c.height=1350;
  const gradient=ctx.createLinearGradient(0,0,1080,1350);gradient.addColorStop(0,'#172041');gradient.addColorStop(1,'#70439a');ctx.fillStyle=gradient;ctx.fillRect(0,0,c.width,c.height);
  ctx.fillStyle='rgba(255,255,255,.08)';ctx.beginPath();ctx.arc(880,180,260,0,Math.PI*2);ctx.fill();
  ctx.fillStyle='#ffca55';ctx.font='700 28px Arial';ctx.fillText('THE TN GAME',80,95);
  ctx.fillStyle='#fff';ctx.font='700 24px Arial';ctx.fillText('TN GAME EXPLORER',80,220);
  ctx.font='700 70px Arial';ctx.fillText(data.name,80,310);
  ctx.fillStyle='rgba(255,255,255,.78)';ctx.font='32px Arial';ctx.fillText(data.rank,80,365);
  const stats=[[data.xp,'XP'],[data.checkpoints,'CHECKPOINTS'],[data.quests,'QUESTS'],[data.streak,'DAY STREAK']];
  stats.forEach((item,index)=>{const x=80+(index%2)*480,y=500+Math.floor(index/2)*190;ctx.fillStyle='rgba(255,255,255,.12)';ctx.roundRect(x,y,430,145,28);ctx.fill();ctx.fillStyle='#fff';ctx.font='700 48px Arial';ctx.fillText(item[0],x+34,y+62);ctx.fillStyle='rgba(255,255,255,.68)';ctx.font='700 19px Arial';ctx.fillText(item[1],x+34,y+105);});
  if(data.featured){ctx.fillStyle='rgba(255,202,85,.18)';ctx.roundRect(80,930,910,150,30);ctx.fill();ctx.fillStyle='#ffca55';ctx.font='700 20px Arial';ctx.fillText('FEATURED ACHIEVEMENT',120,980);ctx.fillStyle='#fff';ctx.font='700 38px Arial';ctx.fillText(data.featured,120,1035);}
  ctx.fillStyle='rgba(255,255,255,.72)';ctx.font='26px Arial';ctx.fillText('Explore Tennessee. Collect discoveries. Build your story.',80,1240);
  ctx.fillStyle='#fff';ctx.font='700 24px Arial';ctx.fillText('thetngame.com',80,1290);
  return c;
};
const blob=async()=>new Promise(resolve=>canvas().then(c=>c.toBlob(resolve,'image/png')));
const download=async()=>{const file=await blob();if(!file)return;const url=URL.createObjectURL(file),a=document.createElement('a');a.href=url;a.download='tn-game-explorer-card.png';a.click();setTimeout(()=>URL.revokeObjectURL(url),1500);};
const share=async()=>{const file=await blob();const data=profileData();try{if(file&&navigator.canShare&&navigator.canShare({files:[new File([file],'tn-game-explorer-card.png',{type:'image/png'})]})){await navigator.share({title:'My TN Game Explorer Card',text:`${data.name} — ${data.rank}`,files:[new File([file],'tn-game-explorer-card.png',{type:'image/png'})]});}else if(navigator.share){await navigator.share({title:'My TN Game Explorer Card',text:`${data.name} — ${data.rank}`,url:location.origin});}else{await download();}}catch(error){}}
document.addEventListener('click',event=>{const button=event.target.closest('[data-share-profile]');if(!button)return;event.preventDefault();event.stopImmediatePropagation();open();},true);
})();