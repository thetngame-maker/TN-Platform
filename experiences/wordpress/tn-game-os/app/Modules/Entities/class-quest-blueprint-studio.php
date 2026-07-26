<?php
namespace TNG_OS\Modules\Entities;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class Quest_Blueprint_Studio implements Module_Interface {
    private const BLUEPRINT_TYPE = 'tng_quest_blueprint';
    private const QUEST_TYPE = 'tng_quest';
    private Container $container;

    public function id(): string { return 'quest_blueprint_studio'; }

    public function register(Container $container): void {
        $this->container = $container;
        $container->set('quest_blueprint_studio', $this);
        add_action('init', [$this, 'register_quest_type']);
        add_action('admin_menu', [$this, 'menu'], 33);
        add_action('admin_post_tng_qbs_save', [$this, 'save']);
        add_action('admin_post_tng_qbs_convert', [$this, 'convert']);
    }

    public function boot(Container $container): void {}

    public function register_quest_type(): void {
        register_post_type(self::QUEST_TYPE, [
            'labels'=>['name'=>'TN Game Quests','singular_name'=>'TN Game Quest'],
            'public'=>false,
            'show_ui'=>true,
            'show_in_menu'=>false,
            'supports'=>['title','editor','thumbnail','custom-fields'],
            'capability_type'=>'post',
            'map_meta_cap'=>true,
        ]);
    }

    public function menu(): void {
        add_submenu_page('tn-game-os', 'Quest Blueprint Studio', 'Quest Blueprint Studio', 'manage_options', 'tng-quest-blueprint-studio', [$this, 'page']);
    }

    public function page(): void {
        if (!current_user_can('manage_options')) return;
        $blueprints = get_posts(['post_type'=>self::BLUEPRINT_TYPE,'post_status'=>['draft','private','publish'],'posts_per_page'=>100,'orderby'=>'modified','order'=>'DESC']);
        $selected_id = absint($_GET['blueprint'] ?? 0);
        if (!$selected_id && $blueprints) $selected_id = (int)$blueprints[0]->ID;
        $blueprint = $selected_id ? get_post($selected_id) : null;
        if ($blueprint && $blueprint->post_type !== self::BLUEPRINT_TYPE) $blueprint = null;
        $entities = $this->entities();
        $stops = $blueprint ? $this->stops($blueprint->ID, $entities) : [];
        $xp = $blueprint ? absint(get_post_meta($blueprint->ID, '_tng_quest_estimated_xp', true)) : 0;
        $minutes = $blueprint ? absint(get_post_meta($blueprint->ID, '_tng_quest_estimated_minutes', true)) : 0;
        $summary = $blueprint ? (string)get_post_meta($blueprint->ID, '_tng_quest_summary', true) : '';
        ?>
        <div class="wrap tng-qbs">
            <style>
                .tng-qbs{max-width:1500px}.tng-qbs-hero{background:linear-gradient(135deg,#1d2546,#693b78);color:#fff;border-radius:18px;padding:30px;margin:18px 0}.tng-qbs-hero h1{color:#fff;margin:0 0 7px}.tng-qbs-toolbar,.tng-qbs-card{background:#fff;border:1px solid #dcdcde;border-radius:14px;padding:18px}.tng-qbs-toolbar form{display:flex;gap:10px;align-items:end;flex-wrap:wrap}.tng-qbs-toolbar label{display:grid;gap:5px;font-weight:700}.tng-qbs-toolbar select{min-width:360px}.tng-qbs-layout{display:grid;grid-template-columns:minmax(0,1fr) 400px;gap:18px;margin-top:18px}.tng-qbs-fields{display:grid;grid-template-columns:2fr 1fr 1fr;gap:12px}.tng-qbs-fields label{display:grid;gap:6px;font-weight:700}.tng-qbs-fields input,.tng-qbs-fields textarea{width:100%}.tng-qbs-stops{display:grid;gap:10px;margin-top:16px}.tng-qbs-stop{display:grid;grid-template-columns:34px minmax(0,1fr) 110px;gap:12px;align-items:start;background:#f8fafc;border:1px solid #e5e7eb;border-radius:12px;padding:12px}.tng-qbs-handle{cursor:grab;width:30px;height:30px;border-radius:8px;background:#ede9fe;display:flex;align-items:center;justify-content:center;font-weight:900;color:#6941c6}.tng-qbs-stop textarea{width:100%;margin-top:8px}.tng-qbs-remove{display:flex;gap:6px;align-items:center;justify-content:end}.tng-qbs-add{display:flex;gap:8px;margin-top:14px}.tng-qbs-add select{min-width:320px}.tng-qbs-actions{display:flex;gap:9px;flex-wrap:wrap;margin-top:18px}.tng-qbs-preview-step{display:flex;gap:10px;padding:11px 0;border-bottom:1px solid #edf0f3}.tng-qbs-num{width:26px;height:26px;border-radius:50%;background:#7f56d9;color:#fff;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800}.tng-qbs-badges{display:flex;gap:7px;flex-wrap:wrap;margin:12px 0}.tng-qbs-badge{background:#f0edff;color:#53389e;border-radius:999px;padding:5px 10px;font-size:12px;font-weight:700}.tng-qbs-empty{text-align:center;padding:40px}@media(max-width:1050px){.tng-qbs-layout{grid-template-columns:1fr}.tng-qbs-fields{grid-template-columns:1fr}.tng-qbs-toolbar select{min-width:240px}}
            </style>
            <div class="tng-qbs-hero"><p style="text-transform:uppercase;letter-spacing:.12em;margin:0 0 8px;color:#f6bd3b;font-weight:700">TN Platform · Gameplay Studio</p><h1>Quest Blueprint Studio</h1><p>Shape graph-generated ideas into ordered checkpoints, player instructions, rewards, and implementation-ready quest drafts.</p></div>
            <?php if(isset($_GET['saved'])):?><div class="notice notice-success inline"><p>Blueprint saved.</p></div><?php endif;?>
            <?php if(isset($_GET['converted'])):?><div class="notice notice-success inline"><p>TN Game quest draft created. <a href="<?php echo esc_url(get_edit_post_link(absint($_GET['quest'] ?? 0))); ?>">Open quest draft</a>.</p></div><?php endif;?>
            <div class="tng-qbs-toolbar"><form method="get"><input type="hidden" name="page" value="tng-quest-blueprint-studio"><label>Blueprint<select name="blueprint"><?php foreach($blueprints as $item):?><option value="<?php echo esc_attr((string)$item->ID);?>" <?php selected($selected_id,$item->ID);?>><?php echo esc_html($item->post_title.' · #'.$item->ID);?></option><?php endforeach;?></select></label><button class="button button-primary">Open blueprint</button><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=tng-quest-intelligence'));?>">Quest Intelligence</a></form></div>
            <?php if(!$blueprint):?><div class="tng-qbs-card tng-qbs-empty"><h2>No blueprint selected</h2><p>Create a draft blueprint from Quest Intelligence first.</p></div><?php else:?>
            <div class="tng-qbs-layout"><main class="tng-qbs-card"><form method="post" action="<?php echo esc_url(admin_url('admin-post.php'));?>" id="tng-qbs-form"><input type="hidden" name="action" value="tng_qbs_save"><input type="hidden" name="blueprint_id" value="<?php echo esc_attr((string)$blueprint->ID);?>"><?php wp_nonce_field('tng_qbs_save');?><div class="tng-qbs-fields"><label>Quest title<input type="text" name="quest_title" value="<?php echo esc_attr($blueprint->post_title);?>" required></label><label>XP reward<input type="number" name="xp" min="0" max="100000" value="<?php echo esc_attr((string)$xp);?>"></label><label>Duration (minutes)<input type="number" name="minutes" min="0" max="10080" value="<?php echo esc_attr((string)$minutes);?>"></label></div><p><label><strong>Player-facing summary</strong><br><textarea name="summary" rows="3" style="width:100%" placeholder="Describe the experience and what players will accomplish."><?php echo esc_textarea($summary);?></textarea></label></p><h2>Checkpoint journey</h2><p>Drag stops into the preferred order. Add instructions for what the player should do at each checkpoint.</p><div class="tng-qbs-stops" id="tng-qbs-stops"><?php foreach($stops as $index=>$stop):?><div class="tng-qbs-stop" draggable="true"><div class="tng-qbs-handle">↕</div><div><strong><?php echo esc_html($stop['title']);?></strong> <small>· <?php echo esc_html($stop['type']);?></small><input type="hidden" name="entity_ids[]" value="<?php echo esc_attr($stop['id']);?>"><textarea name="instructions[]" rows="2" placeholder="Checkpoint instructions, challenge, clue, or completion requirement."><?php echo esc_textarea($stop['instruction']);?></textarea></div><label class="tng-qbs-remove"><input type="checkbox" name="remove_ids[]" value="<?php echo esc_attr($stop['id']);?>"> Remove</label></div><?php endforeach;?></div><div class="tng-qbs-add"><select id="tng-qbs-add-entity"><option value="">Add a graph entity…</option><?php foreach($entities as $entity):?><option value="<?php echo esc_attr($entity['id']);?>" data-title="<?php echo esc_attr($entity['title']);?>" data-type="<?php echo esc_attr($entity['type']);?>"><?php echo esc_html($entity['title'].' · '.$entity['type']);?></option><?php endforeach;?></select><button type="button" class="button" id="tng-qbs-add">Add stop</button></div><div class="tng-qbs-actions"><button class="button button-primary">Save blueprint</button></div></form><form method="post" action="<?php echo esc_url(admin_url('admin-post.php'));?>" style="margin-top:10px"><input type="hidden" name="action" value="tng_qbs_convert"><input type="hidden" name="blueprint_id" value="<?php echo esc_attr((string)$blueprint->ID);?>"><?php wp_nonce_field('tng_qbs_convert');?><button class="button button-secondary">Create TN Game quest draft</button></form></main><aside class="tng-qbs-card"><h2 style="margin-top:0">Player journey preview</h2><h3 id="tng-qbs-preview-title"><?php echo esc_html($blueprint->post_title);?></h3><p id="tng-qbs-preview-summary"><?php echo esc_html($summary ?: 'Add a player-facing summary to describe this quest.');?></p><div class="tng-qbs-badges"><span class="tng-qbs-badge" id="tng-qbs-preview-stops"><?php echo esc_html(number_format_i18n(count($stops)));?> stops</span><span class="tng-qbs-badge" id="tng-qbs-preview-xp"><?php echo esc_html(number_format_i18n($xp));?> XP</span><span class="tng-qbs-badge" id="tng-qbs-preview-time"><?php echo esc_html($this->duration_label($minutes));?></span></div><div id="tng-qbs-preview-list"></div></aside></div>
            <script>
            (()=>{const list=document.getElementById('tng-qbs-stops'), form=document.getElementById('tng-qbs-form'); if(!list||!form)return; let drag=null;
            const refresh=()=>{const rows=[...list.querySelectorAll('.tng-qbs-stop')].filter(r=>!r.querySelector('input[type=checkbox]')?.checked);document.getElementById('tng-qbs-preview-stops').textContent=rows.length+' stops';document.getElementById('tng-qbs-preview-title').textContent=form.querySelector('[name=quest_title]').value||'Untitled quest';document.getElementById('tng-qbs-preview-summary').textContent=form.querySelector('[name=summary]').value||'Add a player-facing summary to describe this quest.';document.getElementById('tng-qbs-preview-xp').textContent=(form.querySelector('[name=xp]').value||0)+' XP';const mins=parseInt(form.querySelector('[name=minutes]').value||0,10);document.getElementById('tng-qbs-preview-time').textContent=mins<60?mins+' min':(Math.round(mins/6)/10)+' hr';document.getElementById('tng-qbs-preview-list').innerHTML=rows.map((r,i)=>'<div class="tng-qbs-preview-step"><span class="tng-qbs-num">'+(i+1)+'</span><span><strong>'+r.querySelector('strong').textContent+'</strong><br><small>'+((r.querySelector('textarea').value)||'Instructions not added yet')+'</small></span></div>').join('');};
            list.addEventListener('dragstart',e=>{drag=e.target.closest('.tng-qbs-stop');if(drag)e.dataTransfer.effectAllowed='move';});list.addEventListener('dragover',e=>{e.preventDefault();const row=e.target.closest('.tng-qbs-stop');if(!drag||!row||row===drag)return;const box=row.getBoundingClientRect();list.insertBefore(drag,e.clientY<box.top+box.height/2?row:row.nextSibling);refresh();});list.addEventListener('change',refresh);list.addEventListener('input',refresh);form.addEventListener('input',refresh);
            document.getElementById('tng-qbs-add').addEventListener('click',()=>{const select=document.getElementById('tng-qbs-add-entity'),option=select.options[select.selectedIndex];if(!option.value)return;if([...list.querySelectorAll('input[name="entity_ids[]"]')].some(i=>i.value===option.value)){select.value='';return;}const row=document.createElement('div');row.className='tng-qbs-stop';row.draggable=true;row.innerHTML='<div class="tng-qbs-handle">↕</div><div><strong></strong> <small></small><input type="hidden" name="entity_ids[]"><textarea name="instructions[]" rows="2" placeholder="Checkpoint instructions, challenge, clue, or completion requirement."></textarea></div><label class="tng-qbs-remove"><input type="checkbox" name="remove_ids[]"> Remove</label>';row.querySelector('strong').textContent=option.dataset.title;row.querySelector('small').textContent='· '+option.dataset.type;row.querySelector('input[name="entity_ids[]"]').value=option.value;row.querySelector('input[type=checkbox]').value=option.value;list.appendChild(row);select.value='';refresh();});refresh();})();
            </script>
            <?php endif;?>
        </div><?php
    }

    public function save(): void {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        check_admin_referer('tng_qbs_save');
        $id=absint($_POST['blueprint_id']??0); if(!$id||get_post_type($id)!==self::BLUEPRINT_TYPE)$this->redirect(['invalid'=>'1']);
        $title=sanitize_text_field(wp_unslash($_POST['quest_title']??'')); $summary=sanitize_textarea_field(wp_unslash($_POST['summary']??''));
        $ids=array_map('sanitize_text_field',(array)($_POST['entity_ids']??[])); $instructions=array_map('sanitize_textarea_field',(array)($_POST['instructions']??[])); $remove=array_flip(array_map('sanitize_text_field',(array)($_POST['remove_ids']??[])));
        $clean=[];$notes=[];foreach($ids as $index=>$entity_id){if($entity_id===''||isset($remove[$entity_id])||isset($clean[$entity_id]))continue;$clean[$entity_id]=$entity_id;$notes[$entity_id]=$instructions[$index]??'';}
        wp_update_post(['ID'=>$id,'post_title'=>$title ?: get_the_title($id)]); update_post_meta($id,'_tng_quest_entity_ids',array_values($clean));update_post_meta($id,'_tng_quest_checkpoint_instructions',$notes);update_post_meta($id,'_tng_quest_estimated_xp',absint($_POST['xp']??0));update_post_meta($id,'_tng_quest_estimated_minutes',absint($_POST['minutes']??0));update_post_meta($id,'_tng_quest_summary',$summary);update_post_meta($id,'_tng_quest_status','edited');
        $this->redirect(['saved'=>'1','blueprint'=>$id]);
    }

    public function convert(): void {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        check_admin_referer('tng_qbs_convert');
        $id=absint($_POST['blueprint_id']??0); if(!$id||get_post_type($id)!==self::BLUEPRINT_TYPE)$this->redirect(['invalid'=>'1']);
        $existing=absint(get_post_meta($id,'_tng_quest_draft_id',true)); if($existing&&get_post($existing))$this->redirect(['converted'=>'1','blueprint'=>$id,'quest'=>$existing]);
        $quest_id=wp_insert_post(['post_type'=>self::QUEST_TYPE,'post_status'=>'draft','post_title'=>get_the_title($id),'post_content'=>(string)get_post_meta($id,'_tng_quest_summary',true)],true); if(is_wp_error($quest_id))$this->redirect(['invalid'=>'1','blueprint'=>$id]);
        foreach(['_tng_quest_entity_ids','_tng_quest_checkpoint_instructions','_tng_quest_estimated_xp','_tng_quest_estimated_minutes','_tng_quest_template','_tng_quest_quality_score'] as $key) update_post_meta($quest_id,$key,get_post_meta($id,$key,true));
        update_post_meta($quest_id,'_tng_quest_source_blueprint',$id);update_post_meta($quest_id,'_tng_quest_status','draft');update_post_meta($id,'_tng_quest_draft_id',$quest_id);update_post_meta($id,'_tng_quest_status','converted');
        $this->redirect(['converted'=>'1','blueprint'=>$id,'quest'=>$quest_id]);
    }

    private function entities(): array { $engine=$this->container->get('recommendation_engine');return $engine&&is_callable([$engine,'entities'])?$engine->entities():[]; }
    private function stops(int $id,array $entities): array { $ids=(array)get_post_meta($id,'_tng_quest_entity_ids',true);$notes=(array)get_post_meta($id,'_tng_quest_checkpoint_instructions',true);$out=[];foreach($ids as $entity_id)if(isset($entities[$entity_id]))$out[]=['id'=>$entity_id,'title'=>$entities[$entity_id]['title'],'type'=>$entities[$entity_id]['type'],'instruction'=>(string)($notes[$entity_id]??'')];return$out; }
    private function duration_label(int $minutes): string { if($minutes<60)return$minutes.' min';$hours=round($minutes/60,1);return rtrim(rtrim(number_format($hours,1,'.',''),'0'),'.').' hr'; }
    private function redirect(array $args): void { wp_safe_redirect(add_query_arg(array_merge(['page'=>'tng-quest-blueprint-studio'],$args),admin_url('admin.php')));exit; }
}
