(()=>{
'use strict';
const root=document.querySelector('.tng-runtime'),cfg=window.TNGQuestMemory||{},runtime=window.TNGQuestRuntime||{};
if(!root)return;
let card=null;
const esc=v=>String(v??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
const n=v=>Number(v)||0;
const state=()=>{const stops=Array.isArray(runtime.stops)?runtime.stops:[];const total=stops.length||root.querySelectorAll('.tng-checkpoint-card').length;const done=n(root.querySelector('[data-completed]')?.textContent)||root.querySelectorAll('.tng-checkpoint-card.is-complete,[data-status="complete"]').length;return{total,done,xp:n(runtime.rewardXp),title:runtime.questTitle||root.querySelector('h1')?.textContent?.trim()||'Completed quest',duration:root.querySelector('.tng-runtime-facts span:nth-child(3)')?.textContent?.trim()||'Adventure complete'};};
const complete=()=>{const s=state();return s.total>0&&s.done>=s.total;};
const share=async()=>{const s=state(),data={title:`I completed ${s.title}`,text:`I completed ${s.title} on The TN Game: ${s.total} checkpoints and ${s.xp} XP.`,url:location.href};try{if(navigator.share)await navigator.share(data);else{await navigator.clipboard.writeText(`${data.text} ${data.url}`);alert('Quest recap copied.');}}catch(e){}};
const render=()=>{if(!complete()){card?.remove();card=null;return;}const s=state();if(!card){card=document.createElement('section');card.className='tng-quest-memory';const completion=root.querySelector('.tng-completion');(completion||root.querySelector('.tng-runtime-active')||root).insertAdjacentElement('afterend',card);}card.innerHTML=`<div class="tng-memory-mark">✓</div><div class="tng-memory-copy"><span>ADVENTURE MEMORY</span><h2>${esc(s.title)}</h2><p>This completed journey is now part of your Explorer story.</p><div><b>${s.total} checkpoints</b><b>${s.xp.toLocaleString()} XP</b><b>${esc(s.duration)}</b></div></div><div class="tng-memory-actions"><button type="button" data-memory-share>Share recap</button><a href="${esc(cfg.worldUrl||'/?tng_world=1')}">Find another quest</a></div>`;card.querySelector('[data-memory-share]').onclick=share;};
window.addEventListener('tng:quest-complete',render);window.addEventListener('tng:progress-updated',render);
new MutationObserver(()=>requestAnimationFrame(render)).observe(root.querySelector('.tng-runtime-active')||root,{subtree:true,childList:true,attributes:true,attributeFilter:['class','data-status']});
render();
})();