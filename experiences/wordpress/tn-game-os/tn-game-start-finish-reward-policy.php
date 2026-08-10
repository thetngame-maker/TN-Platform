<?php
/**
 * TN Game Start / Finish Reward Policy
 * Keeps Trail Start and Trail Finish as rewarded 25 XP checkpoints by default.
 */
if (!defined('ABSPATH')) exit;

final class TNG_Start_Finish_Reward_Policy {
    public static function boot(): void {
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue'], 22);
    }

    public static function enqueue(string $hook): void {
        if (!in_array($hook, ['post.php','post-new.php'], true)) return;
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'tng_game') return;
        wp_register_script('tng-start-finish-reward-policy', '', [], TNG_OS_VERSION, true);
        wp_enqueue_script('tng-start-finish-reward-policy');
        wp_add_inline_script('tng-start-finish-reward-policy', <<<'JS'
(()=>{
 const rows=()=>[...(document.querySelectorAll('#tng-checkpoint-rows .tng-cp-row')||[])];
 const field=(row,key)=>row?.querySelector(`[name$="[${key}]"]`);
 const isEndpoint=row=>{
   const title=(field(row,'title')?.value||'').trim().toLowerCase();
   return /trail start|start point|trailhead|trail finish|finish point|route finish/.test(title);
 };
 const applyRow=row=>{
   if(!row||!isEndpoint(row))return;
   const xp=field(row,'xp');
   if(xp && (xp.value==='' || Number(xp.value)===0)){
     xp.value='25';
     xp.dispatchEvent(new Event('input',{bubbles:true}));
     xp.dispatchEvent(new Event('change',{bubbles:true}));
   }
   const role=row.querySelector('.tng-cp-role-select');
   if(role&&!role.dataset.manual){
     const title=(field(row,'title')?.value||'').toLowerCase();
     role.value=/finish/.test(title)?'trail_finish':'trail_start';
   }
 };
 const fixButtons=()=>{
   const start=document.querySelector('.tng-add-start-point');
   const finish=document.querySelector('.tng-add-finish-point');
   if(start&&!/Added/.test(start.textContent||''))start.textContent='Add Trail Start · 25 XP';
   if(finish&&!/Added/.test(finish.textContent||''))finish.textContent='Add Trail Finish · 25 XP';
 };
 const scan=()=>{rows().forEach(applyRow);fixButtons();};
 let timer=null;
 const schedule=()=>{clearTimeout(timer);timer=setTimeout(scan,20);};
 const watch=()=>{
   scan();
   new MutationObserver(schedule).observe(document.body,{childList:true,subtree:true,characterData:true});
   document.addEventListener('tng:checkpoint-form-changed',schedule);
   document.addEventListener('input',e=>{if(e.target?.name?.endsWith('[title]'))schedule();});
 };
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',watch);else watch();
})();
JS
        ,'after');
    }
}
TNG_Start_Finish_Reward_Policy::boot();
