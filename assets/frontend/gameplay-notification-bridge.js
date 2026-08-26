(()=>{
'use strict';
const root=document.querySelector('.tng-runtime');
if(!root||typeof window.TNGNotify!=='function')return;

const seen={checkpoints:new Set(),missions:new Set(),badges:new Set()};
let ready=false,lastTokens=null,scanQueued=false;
const text=(node,selector)=>String(node?.querySelector(selector)?.textContent||'').trim();
const notify=detail=>window.dispatchEvent(new CustomEvent('tng:notify',{detail}));

const checkpointKey=node=>String(node?.dataset?.stopId||text(node,'.tng-checkpoint-title')||'');
const missionKey=node=>text(node,'.tng-mission-copy strong')||String([...node.parentNode?.children||[]].indexOf(node));
const badgeKey=node=>text(node,'strong,h3,h4')||String(node.textContent||'').trim().slice(0,80);

const snapshot=()=>{
  root.querySelectorAll('.tng-checkpoint.is-complete[data-stop-id]').forEach(node=>seen.checkpoints.add(checkpointKey(node)));
  root.querySelectorAll('.tng-daily-missions article.is-complete').forEach(node=>seen.missions.add(missionKey(node)));
  document.querySelectorAll('.tng-badge.is-unlocked,.tng-achievement.is-unlocked,[data-achievement].is-unlocked').forEach(node=>seen.badges.add(badgeKey(node)));
};

const scan=()=>{
  scanQueued=false;
  root.querySelectorAll('.tng-checkpoint.is-complete[data-stop-id]').forEach(node=>{
    const key=checkpointKey(node);if(!key||seen.checkpoints.has(key))return;seen.checkpoints.add(key);
    if(!ready)return;
    const title=text(node,'.tng-checkpoint-title')||'Checkpoint complete';
    const xp=(text(node,'.tng-checkpoint-label').match(/(\d+)\s*XP/i)||[])[1];
    notify({type:'success',title:'Checkpoint complete',message:xp?`${title} · +${xp} XP`:title});
  });
  root.querySelectorAll('.tng-daily-missions article.is-complete').forEach(node=>{
    const key=missionKey(node);if(!key||seen.missions.has(key))return;seen.missions.add(key);
    if(!ready)return;
    notify({type:'success',title:'Daily mission complete',message:`${key} is ready to claim.`});
  });
  document.querySelectorAll('.tng-badge.is-unlocked,.tng-achievement.is-unlocked,[data-achievement].is-unlocked').forEach(node=>{
    const key=badgeKey(node);if(!key||seen.badges.has(key))return;seen.badges.add(key);
    if(!ready)return;
    notify({type:'reward',title:'Badge unlocked',message:key});
  });
};
const queueScan=()=>{if(scanQueued)return;scanQueued=true;requestAnimationFrame(scan);};

// Every token-funded system already publishes this wallet event. A positive delta
// therefore produces one consistent reward toast for daily, weekly, community,
// test grants, and future token sources.
document.addEventListener('tng:wallet-updated',event=>{
  const tokens=Number(event.detail?.tokens);if(!Number.isFinite(tokens))return;
  if(lastTokens===null){lastTokens=tokens;return;}
  const delta=tokens-lastTokens;lastTokens=tokens;
  if(ready&&delta>0)notify({type:'reward',title:'Adventure Tokens earned',message:`+${delta.toLocaleString()} Tokens added to your wallet.`});
});

// Lightweight confirmation for social actions. The server-backed modules still
// own persistence; this only confirms the player's action after the click.
document.addEventListener('click',event=>{
  const reaction=event.target.closest('[data-reaction],.tng-reaction-button');
  if(reaction)window.setTimeout(()=>notify({type:'success',title:'Reaction sent',message:'Your friend can now see your reaction.'}),250);
  const challenge=event.target.closest('[data-challenge-user],.tng-activity-challenge');
  if(challenge)window.setTimeout(()=>notify({type:'info',title:'Challenge ready',message:'Choose a challenge to send to this Explorer.'}),250);
});

const observer=new MutationObserver(queueScan);
observer.observe(root,{subtree:true,childList:true,attributes:true,attributeFilter:['class']});
window.setTimeout(()=>{snapshot();lastTokens=lastTokens??Number(document.querySelector('[data-token-balance]')?.textContent?.replace(/[^0-9.-]/g,''));if(!Number.isFinite(lastTokens))lastTokens=null;ready=true;},900);
})();
