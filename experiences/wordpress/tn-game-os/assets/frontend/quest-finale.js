(()=>{
'use strict';
const root=document.querySelector('.tng-runtime'),cfg=window.TNGQuestFinale||{};
if(!root)return;
let shown=false,overlay=null,lastComplete=false;
const text=(sel,fallback='')=>root.querySelector(sel)?.textContent?.trim()||fallback;
const num=v=>{const m=String(v||'').match(/[\d,]+/);return m?Number(m[0].replace(/,/g,'')):0;};
const questTitle=()=>text('.tng-runtime-hero h1, .tng-quest-hero h1, h1','Quest complete');
const checkpointCount=()=>root.querySelectorAll('.tng-checkpoint-card.is-complete, .tng-checkpoint-card[data-status="complete"], .tng-checkpoint-card .tng-checkpoint-complete').length||num(text('[data-complete-count]'));
const xpTotal=()=>num(text('.tng-runtime-hero .tng-runtime-chip:nth-child(2), [data-quest-xp]','0'));
const isComplete=()=>{
 const pct=num(text('[data-progress-percent], .tng-progress-percent, .tng-sticky-hud-percent','0'));
 const cards=[...root.querySelectorAll('.tng-checkpoint-card')];
 return pct>=100||(cards.length>0&&cards.every(c=>c.classList.contains('is-complete')||c.dataset.status==='complete'||c.querySelector('.tng-checkpoint-complete')));
};
const storageKey=()=>`tng_finale_seen_${cfg.questId||'quest'}`;
const share=async()=>{const data={title:`I completed ${questTitle()} on The TN Game`,text:`Quest complete! I finished ${questTitle()}, discovered ${checkpointCount()} checkpoints, and earned ${xpTotal()} XP.`,url:location.href};try{if(navigator.share)await navigator.share(data);else{await navigator.clipboard.writeText(`${data.text} ${data.url}`);toast('Quest result copied.');}}catch(e){}};
const toast=msg=>{const n=document.createElement('div');n.className='tng-finale-toast';n.textContent=msg;document.body.appendChild(n);setTimeout(()=>n.remove(),2200);};
const close=()=>{if(!overlay)return;overlay.hidden=true;document.body.classList.remove('tng-finale-open');localStorage.setItem(storageKey(),'1');};
const build=()=>{if(overlay)return overlay;overlay=document.createElement('div');overlay.className='tng-finale-overlay';overlay.hidden=true;overlay.innerHTML=`<section class="tng-finale" role="dialog" aria-modal="true" aria-labelledby="tng-finale-title"><button type="button" class="tng-finale-close" aria-label="Close">×</button><div class="tng-finale-burst" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i></div><header><span>QUEST COMPLETE</span><div class="tng-finale-emblem">✓</div><h2 id="tng-finale-title"></h2><p>You completed the journey and added a new chapter to your Explorer story.</p></header><div class="tng-finale-stats"><article><strong data-finale-checkpoints>0</strong><span>Checkpoints</span></article><article><strong data-finale-xp>0</strong><span>XP earned</span></article><article><strong>100%</strong><span>Complete</span></article></div><div class="tng-finale-reward"><div class="tng-finale-reward-icon">★</div><div><span>EXPLORER MILESTONE</span><strong>Quest Complete</strong><small>Your profile, journal, leaderboard, and weekly progress have been updated.</small></div></div><div class="tng-finale-actions"><button type="button" data-finale-share>Share achievement</button><a data-finale-world href="#">Explore the world</a></div><button type="button" class="tng-finale-continue" data-finale-close>Continue this quest</button></section>`;document.body.appendChild(overlay);overlay.addEventListener('click',e=>{if(e.target===overlay||e.target.closest('.tng-finale-close,[data-finale-close]'))close();if(e.target.closest('[data-finale-share]'))share();});document.addEventListener('keydown',e=>{if(e.key==='Escape'&&!overlay.hidden)close();});return overlay;};
const open=()=>{if(shown)return;shown=true;const n=build();n.querySelector('#tng-finale-title').textContent=questTitle();n.querySelector('[data-finale-checkpoints]').textContent=checkpointCount().toLocaleString();n.querySelector('[data-finale-xp]').textContent=xpTotal().toLocaleString();n.querySelector('[data-finale-world]').href=cfg.worldUrl||cfg.homeUrl||'/';n.hidden=false;document.body.classList.add('tng-finale-open');localStorage.setItem(storageKey(),'1');};
const reconcile=()=>{const complete=isComplete();if(complete&&!lastComplete&&!shown&&!localStorage.getItem(storageKey()))setTimeout(open,650);if(!complete){shown=false;localStorage.removeItem(storageKey());}lastComplete=complete;};
const observer=new MutationObserver(()=>requestAnimationFrame(reconcile));observer.observe(root,{subtree:true,childList:true,attributes:true,attributeFilter:['class','data-status','style']});
reconcile();
})();
