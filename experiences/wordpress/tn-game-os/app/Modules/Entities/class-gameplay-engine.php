<?php
namespace TNG_OS\Modules\Entities;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class Gameplay_Engine implements Module_Interface {
    private const QUEST_TYPE = 'tng_quest';
    private Container $container;

    public function id(): string { return 'gameplay_engine'; }

    public function register(Container $container): void {
        $this->container = $container;
        $container->set('gameplay_engine', $this);
        add_action('admin_menu', [$this, 'menu'], 34);
        add_action('admin_post_tng_ge_save', [$this, 'save']);
    }

    public function boot(Container $container): void {}

    public function menu(): void {
        add_submenu_page('tn-game-os', 'Gameplay Engine', 'Gameplay Engine', 'manage_options', 'tng-gameplay-engine', [$this, 'page']);
    }

    public function page(): void {
        if (!current_user_can('manage_options')) return;
        $quests = get_posts(['post_type'=>self::QUEST_TYPE,'post_status'=>['draft','private','publish'],'posts_per_page'=>100,'orderby'=>'modified','order'=>'DESC']);
        $selected_id = absint($_GET['quest'] ?? 0);
        if (!$selected_id && $quests) $selected_id = (int)$quests[0]->ID;
        $quest = $selected_id ? get_post($selected_id) : null;
        if ($quest && $quest->post_type !== self::QUEST_TYPE) $quest = null;
        $entities = $this->entities();
        $stops = $quest ? $this->stops($quest->ID, $entities) : [];
        $reward_xp = $quest ? absint(get_post_meta($quest->ID, '_tng_quest_xp', true) ?: get_post_meta($quest->ID, '_tng_quest_estimated_xp', true)) : 0;
        $completion = $quest ? max(1, absint(get_post_meta($quest->ID, '_tng_game_completion_count', true) ?: count($stops))) : 1;
        $mode = $quest ? sanitize_key((string)get_post_meta($quest->ID, '_tng_game_completion_mode', true)) : 'all';
        ?>
        <div class="wrap tng-ge">
        <style>
        .tng-ge{max-width:1500px}.tng-ge-hero{background:linear-gradient(135deg,#10253f,#1f5570);color:#fff;border-radius:18px;padding:30px;margin:18px 0}.tng-ge-hero h1{color:#fff;margin:0 0 7px}.tng-ge-toolbar,.tng-ge-card{background:#fff;border:1px solid #dcdcde;border-radius:14px;padding:18px}.tng-ge-toolbar form{display:flex;gap:10px;align-items:end;flex-wrap:wrap}.tng-ge-toolbar label{display:grid;gap:5px;font-weight:700}.tng-ge-toolbar select{min-width:360px}.tng-ge-layout{display:grid;grid-template-columns:minmax(0,1fr) 430px;gap:18px;margin-top:18px}.tng-ge-fields{display:grid;grid-template-columns:2fr 1fr 1fr;gap:12px}.tng-ge-fields label,.tng-ge-checkpoint label{display:grid;gap:5px;font-weight:700}.tng-ge-fields input,.tng-ge-fields select,.tng-ge-checkpoint input,.tng-ge-checkpoint select,.tng-ge-checkpoint textarea{width:100%}.tng-ge-list{display:grid;gap:12px;margin-top:16px}.tng-ge-checkpoint{border:1px solid #e3e8ef;background:#f8fafc;border-radius:13px;padding:14px}.tng-ge-checkpoint-head{display:flex;justify-content:space-between;gap:12px;align-items:center}.tng-ge-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-top:12px}.tng-ge-full{grid-column:1/-1}.tng-ge-toggle{display:flex!important;grid-template-columns:auto 1fr!important;align-items:center;gap:8px!important}.tng-ge-toggle input{width:auto}.tng-ge-preview-head{display:flex;justify-content:space-between;gap:12px;align-items:center}.tng-ge-badge{display:inline-flex;border-radius:999px;background:#e9f5ff;color:#175cd3;padding:5px 10px;font-size:12px;font-weight:700}.tng-ge-progress{height:10px;background:#e8edf2;border-radius:999px;overflow:hidden;margin:14px 0}.tng-ge-progress span{display:block;height:100%;background:#12b76a;width:0;transition:.2s}.tng-ge-sim-list{display:grid;gap:9px}.tng-ge-sim-step{border:1px solid #e5e7eb;border-radius:11px;padding:12px;display:grid;grid-template-columns:30px 1fr auto;gap:10px;align-items:center}.tng-ge-sim-num{width:28px;height:28px;border-radius:50%;background:#10253f;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800}.tng-ge-sim-step.done{background:#ecfdf3;border-color:#abefc6}.tng-ge-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:16px}.tng-ge-empty{text-align:center;padding:45px}@media(max-width:1050px){.tng-ge-layout{grid-template-columns:1fr}.tng-ge-fields,.tng-ge-grid{grid-template-columns:1fr}.tng-ge-toolbar select{min-width:240px}}
        </style>
        <div class="tng-ge-hero"><p style="text-transform:uppercase;letter-spacing:.12em;margin:0 0 8px;color:#f6bd3b;font-weight:700">TN Platform · Player Runtime</p><h1>Gameplay Engine</h1><p>Configure checkpoint mechanics, completion rules, rewards, and simulate the complete player journey before publishing.</p></div>
        <?php if(isset($_GET['saved'])):?><div class="notice notice-success inline"><p>Gameplay configuration saved.</p></div><?php endif;?>
        <div class="tng-ge-toolbar"><form method="get"><input type="hidden" name="page" value="tng-gameplay-engine"><label>Quest<select name="quest"><?php foreach($quests as $item):?><option value="<?php echo esc_attr((string)$item->ID);?>" <?php selected($selected_id,$item->ID);?>><?php echo esc_html($item->post_title.' · #'.$item->ID);?></option><?php endforeach;?></select></label><button class="button button-primary">Open quest</button><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=tng-quest-blueprint-studio'));?>">Blueprint Studio</a></form></div>
        <?php if(!$quest):?><div class="tng-ge-card tng-ge-empty"><h2>No quest draft selected</h2><p>Create a TN Game quest draft from Quest Blueprint Studio first.</p></div><?php else:?>
        <div class="tng-ge-layout"><main class="tng-ge-card"><form method="post" action="<?php echo esc_url(admin_url('admin-post.php'));?>" id="tng-ge-form"><input type="hidden" name="action" value="tng_ge_save"><input type="hidden" name="quest_id" value="<?php echo esc_attr((string)$quest->ID);?>"><?php wp_nonce_field('tng_ge_save');?>
        <div class="tng-ge-fields"><label>Quest title<input type="text" name="quest_title" value="<?php echo esc_attr($quest->post_title);?>" required></label><label>Reward XP<input type="number" name="reward_xp" min="0" max="100000" value="<?php echo esc_attr((string)$reward_xp);?>"></label><label>Completion rule<select name="completion_mode"><option value="all" <?php selected($mode,'all');?>>Complete all</option><option value="count" <?php selected($mode,'count');?>>Complete a number</option></select></label></div>
        <p><label><strong>Required checkpoints</strong><br><input type="number" name="completion_count" min="1" max="999" value="<?php echo esc_attr((string)$completion);?>" style="width:120px"></label></p>
        <h2>Checkpoint mechanics</h2><p>Each stop can combine GPS arrival, trivia, photo proof, QR verification, bonus XP, hints, and arrival messages.</p>
        <div class="tng-ge-list" id="tng-ge-list">
        <?php foreach($stops as $index=>$stop): $m=$stop['mechanics']; ?>
        <section class="tng-ge-checkpoint" data-title="<?php echo esc_attr($stop['title']);?>"><div class="tng-ge-checkpoint-head"><div><strong>#<?php echo esc_html((string)($index+1));?> <?php echo esc_html($stop['title']);?></strong><br><small><?php echo esc_html($stop['type']);?></small></div><span class="tng-ge-badge"><?php echo esc_html((string)($m['xp'] ?? 0));?> XP</span></div><input type="hidden" name="entity_ids[]" value="<?php echo esc_attr($stop['id']);?>">
        <div class="tng-ge-grid"><label>Checkpoint type<select name="checkpoint_type[]"><option value="gps" <?php selected($m['type']??'gps','gps');?>>GPS arrival</option><option value="trivia" <?php selected($m['type']??'','trivia');?>>Trivia</option><option value="photo" <?php selected($m['type']??'','photo');?>>Photo challenge</option><option value="qr" <?php selected($m['type']??'','qr');?>>QR code</option><option value="manual" <?php selected($m['type']??'','manual');?>>Manual claim</option></select></label><label>GPS radius (feet)<input type="number" name="radius[]" min="5" max="5000" value="<?php echo esc_attr((string)($m['radius']??30));?>"></label><label>Checkpoint XP<input type="number" name="checkpoint_xp[]" min="0" max="10000" value="<?php echo esc_attr((string)($m['xp']??25));?>"></label><label class="tng-ge-full">Arrival message<input type="text" name="arrival_message[]" value="<?php echo esc_attr((string)($m['arrival_message']??''));?>" placeholder="You reached the checkpoint!"></label><label class="tng-ge-full">Challenge or question<textarea name="challenge[]" rows="2" placeholder="Question, photo instruction, QR clue, or completion task."><?php echo esc_textarea((string)($m['challenge']??$stop['instruction']));?></textarea></label><label>Correct answer / QR value<input type="text" name="answer[]" value="<?php echo esc_attr((string)($m['answer']??''));?>"></label><label>Hint<input type="text" name="hint[]" value="<?php echo esc_attr((string)($m['hint']??''));?>"></label><label class="tng-ge-toggle"><input type="checkbox" name="photo_required[<?php echo esc_attr((string)$index);?>]" value="1" <?php checked(!empty($m['photo_required']));?>> Require photo</label></div></section>
        <?php endforeach;?></div><div class="tng-ge-actions"><button class="button button-primary">Save gameplay</button><button type="button" class="button" id="tng-ge-reset">Reset simulator</button></div></form></main>
        <aside class="tng-ge-card"><div class="tng-ge-preview-head"><div><h2 style="margin:0">Live player simulator</h2><p style="margin:5px 0;color:#667085">Claims are simulated locally and do not award real XP.</p></div><span class="tng-ge-badge" id="tng-ge-state">Ready</span></div><h3 id="tng-ge-title"><?php echo esc_html($quest->post_title);?></h3><div class="tng-ge-progress"><span id="tng-ge-progress"></span></div><p><strong id="tng-ge-complete">0</strong> of <strong id="tng-ge-required"><?php echo esc_html((string)$completion);?></strong> required checkpoints completed · <strong id="tng-ge-xp">0</strong> XP earned</p><div class="tng-ge-sim-list" id="tng-ge-sim-list"></div></aside></div>
        <script>(()=>{const form=document.getElementById('tng-ge-form'),rows=[...document.querySelectorAll('.tng-ge-checkpoint')],list=document.getElementById('tng-ge-sim-list');if(!form||!list)return;let done=new Set();const render=()=>{const mode=form.querySelector('[name=completion_mode]').value;const configured=parseInt(form.querySelector('[name=completion_count]').value||1,10);const required=mode==='all'?rows.length:Math.min(rows.length,Math.max(1,configured));let xp=0;done.forEach(i=>xp+=parseInt(rows[i].querySelector('[name="checkpoint_xp[]"]').value||0,10));document.getElementById('tng-ge-required').textContent=required;document.getElementById('tng-ge-complete').textContent=done.size;document.getElementById('tng-ge-xp').textContent=xp;document.getElementById('tng-ge-progress').style.width=(required?Math.min(100,done.size/required*100):0)+'%';document.getElementById('tng-ge-state').textContent=done.size>=required?'Quest complete':'In progress';document.getElementById('tng-ge-title').textContent=form.querySelector('[name=quest_title]').value||'Untitled quest';list.innerHTML=rows.map((row,i)=>{const type=row.querySelector('[name="checkpoint_type[]"]').value;const msg=row.querySelector('[name="arrival_message[]"]').value||'Checkpoint reached';return '<div class="tng-ge-sim-step '+(done.has(i)?'done':'')+'"><span class="tng-ge-sim-num">'+(i+1)+'</span><span><strong>'+row.dataset.title+'</strong><br><small>'+type+' · '+msg+'</small></span><button type="button" class="button" data-claim="'+i+'">'+(done.has(i)?'Claimed':'Simulate claim')+'</button></div>';}).join('');};list.addEventListener('click',e=>{const b=e.target.closest('[data-claim]');if(!b)return;const i=parseInt(b.dataset.claim,10);done.has(i)?done.delete(i):done.add(i);render();});form.addEventListener('input',render);form.addEventListener('change',render);document.getElementById('tng-ge-reset').addEventListener('click',()=>{done.clear();render();});render();})();</script>
        <?php endif;?></div><?php
    }

    public function save(): void {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        check_admin_referer('tng_ge_save');
        $id=absint($_POST['quest_id']??0); if(!$id||get_post_type($id)!==self::QUEST_TYPE)$this->redirect(['invalid'=>'1']);
        $entity_ids=array_map('sanitize_text_field',(array)($_POST['entity_ids']??[]));
        $types=array_map('sanitize_key',(array)($_POST['checkpoint_type']??[]));
        $radii=array_map('absint',(array)($_POST['radius']??[]));
        $xp=array_map('absint',(array)($_POST['checkpoint_xp']??[]));
        $arrival=array_map('sanitize_text_field',(array)($_POST['arrival_message']??[]));
        $challenge=array_map('sanitize_textarea_field',(array)($_POST['challenge']??[]));
        $answer=array_map('sanitize_text_field',(array)($_POST['answer']??[]));
        $hint=array_map('sanitize_text_field',(array)($_POST['hint']??[]));
        $photos=(array)($_POST['photo_required']??[]); $mechanics=[];
        foreach($entity_ids as $i=>$entity_id){if($entity_id==='')continue;$mechanics[$entity_id]=['type'=>in_array($types[$i]??'gps',['gps','trivia','photo','qr','manual'],true)?$types[$i]:'gps','radius'=>max(5,min(5000,$radii[$i]??30)),'xp'=>min(10000,$xp[$i]??25),'arrival_message'=>$arrival[$i]??'','challenge'=>$challenge[$i]??'','answer'=>$answer[$i]??'','hint'=>$hint[$i]??'','photo_required'=>!empty($photos[$i])];}
        wp_update_post(['ID'=>$id,'post_title'=>sanitize_text_field(wp_unslash($_POST['quest_title']??get_the_title($id)))]);
        update_post_meta($id,'_tng_game_checkpoint_mechanics',$mechanics);
        update_post_meta($id,'_tng_quest_xp',absint($_POST['reward_xp']??0));
        update_post_meta($id,'_tng_game_completion_mode',in_array(sanitize_key($_POST['completion_mode']??'all'),['all','count'],true)?sanitize_key($_POST['completion_mode']):'all');
        update_post_meta($id,'_tng_game_completion_count',max(1,absint($_POST['completion_count']??count($entity_ids))));
        update_post_meta($id,'_tng_gameplay_status','configured');
        $this->redirect(['saved'=>'1','quest'=>$id]);
    }

    private function entities(): array { $engine=$this->container->get('recommendation_engine'); return $engine&&is_callable([$engine,'entities'])?$engine->entities():[]; }
    private function stops(int $quest_id,array $entities): array { $ids=(array)get_post_meta($quest_id,'_tng_quest_entity_ids',true);$instructions=(array)get_post_meta($quest_id,'_tng_quest_checkpoint_instructions',true);$mechanics=(array)get_post_meta($quest_id,'_tng_game_checkpoint_mechanics',true);$out=[];foreach($ids as $id){if(!isset($entities[$id]))continue;$out[]=['id'=>$id,'title'=>$entities[$id]['title'],'type'=>$entities[$id]['type'],'instruction'=>(string)($instructions[$id]??''),'mechanics'=>(array)($mechanics[$id]??[])];}return$out; }
    private function redirect(array $args): void { wp_safe_redirect(add_query_arg(array_merge(['page'=>'tng-gameplay-engine'],$args),admin_url('admin.php')));exit; }
}
