<?php
namespace TNG_OS\Modules\Entities;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class Quest_Library implements Module_Interface {
    private const QUEST_TYPE = 'tng_quest';

    public function id(): string { return 'quest_library'; }

    public function register(Container $container): void {
        $container->set('quest_library', $this);
        add_action('admin_menu', [$this, 'menu'], 35);
        add_action('admin_post_tng_quest_library_status', [$this, 'change_status']);
        add_action('admin_post_tng_quest_library_duplicate', [$this, 'duplicate']);
    }

    public function boot(Container $container): void {}

    public function menu(): void {
        add_submenu_page('tn-game-os', 'Quest Library', 'Quest Library', 'manage_options', 'tng-quest-library', [$this, 'page']);
    }

    public function page(): void {
        if (!current_user_can('manage_options')) return;

        $status = sanitize_key((string)($_GET['status'] ?? 'all'));
        $allowed = ['all','draft','publish','private','pending','trash'];
        if (!in_array($status, $allowed, true)) $status = 'all';

        $args = [
            'post_type' => self::QUEST_TYPE,
            'post_status' => $status === 'all' ? ['draft','publish','private','pending'] : $status,
            'posts_per_page' => 200,
            'orderby' => 'modified',
            'order' => 'DESC',
        ];
        $quests = get_posts($args);
        $counts = wp_count_posts(self::QUEST_TYPE);
        $total = 0;
        foreach (['draft','publish','private','pending'] as $key) $total += (int)($counts->{$key} ?? 0);

        $published = (int)($counts->publish ?? 0);
        $drafts = (int)($counts->draft ?? 0);
        $total_xp = 0;
        foreach ($quests as $quest) $total_xp += $this->xp($quest->ID);
        ?>
        <div class="wrap tng-ql">
            <style>
                .tng-ql{max-width:1500px}.tng-ql-hero{background:linear-gradient(135deg,#18213d,#4b2f68);color:#fff;border-radius:18px;padding:30px;margin:18px 0}.tng-ql-hero h1{color:#fff;margin:0 0 7px}.tng-ql-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}.tng-ql-stat,.tng-ql-panel{background:#fff;border:1px solid #dcdcde;border-radius:14px;padding:18px}.tng-ql-stat strong{display:block;font-size:28px;color:#18213d;margin-top:4px}.tng-ql-toolbar{display:flex;justify-content:space-between;align-items:center;gap:12px;margin:20px 0}.tng-ql-filters{display:flex;gap:7px;flex-wrap:wrap}.tng-ql-table{width:100%;border-collapse:collapse}.tng-ql-table th,.tng-ql-table td{text-align:left;padding:14px 12px;border-bottom:1px solid #edf0f3;vertical-align:middle}.tng-ql-title{font-weight:800;color:#18213d}.tng-ql-sub{color:#667085;font-size:12px;margin-top:3px}.tng-ql-badge{display:inline-flex;padding:5px 9px;border-radius:999px;background:#f0edff;color:#53389e;font-size:12px;font-weight:700}.tng-ql-status-publish{background:#ecfdf3;color:#067647}.tng-ql-status-draft{background:#fff7ed;color:#b54708}.tng-ql-status-private{background:#f2f4f7;color:#344054}.tng-ql-actions{display:flex;gap:6px;flex-wrap:wrap}.tng-ql-empty{text-align:center;padding:48px 20px}.tng-ql-health{display:flex;align-items:center;gap:8px}.tng-ql-dot{width:9px;height:9px;border-radius:50%;background:#12b76a}.tng-ql-dot.warn{background:#f79009}@media(max-width:1000px){.tng-ql-stats{grid-template-columns:repeat(2,minmax(0,1fr))}.tng-ql-table thead{display:none}.tng-ql-table,.tng-ql-table tbody,.tng-ql-table tr,.tng-ql-table td{display:block;width:100%}.tng-ql-table tr{padding:12px 0;border-bottom:1px solid #edf0f3}.tng-ql-table td{border:0;padding:6px 0}}@media(max-width:650px){.tng-ql-stats{grid-template-columns:1fr}.tng-ql-toolbar{align-items:flex-start;flex-direction:column}}
            </style>
            <div class="tng-ql-hero"><p style="text-transform:uppercase;letter-spacing:.12em;margin:0 0 8px;color:#f6bd3b;font-weight:700">TN Platform · Quest Operations</p><h1>Quest Library</h1><p>Manage every quest from one place—from draft concept to configured gameplay and published player experience.</p></div>
            <?php if(isset($_GET['updated'])):?><div class="notice notice-success inline"><p>Quest status updated.</p></div><?php endif;?>
            <?php if(isset($_GET['duplicated'])):?><div class="notice notice-success inline"><p>Quest duplicated. The new copy is ready for editing.</p></div><?php endif;?>
            <div class="tng-ql-stats">
                <div class="tng-ql-stat"><span>Total quests</span><strong><?php echo esc_html(number_format_i18n($total));?></strong></div>
                <div class="tng-ql-stat"><span>Published</span><strong><?php echo esc_html(number_format_i18n($published));?></strong></div>
                <div class="tng-ql-stat"><span>Drafts</span><strong><?php echo esc_html(number_format_i18n($drafts));?></strong></div>
                <div class="tng-ql-stat"><span>Visible XP in view</span><strong><?php echo esc_html(number_format_i18n($total_xp));?></strong></div>
            </div>
            <div class="tng-ql-toolbar"><div><h2 style="margin:0">Quest catalog</h2><p style="margin:5px 0 0;color:#667085">Review content, gameplay readiness, runtime access, and publishing state.</p></div><div class="tng-ql-filters"><?php foreach(['all'=>'All','draft'=>'Drafts','publish'=>'Published','private'=>'Private','pending'=>'Pending'] as $key=>$label):?><a class="button <?php echo $status===$key?'button-primary':'';?>" href="<?php echo esc_url(add_query_arg(['page'=>'tng-quest-library','status'=>$key],admin_url('admin.php')));?>"><?php echo esc_html($label);?></a><?php endforeach;?></div></div>
            <div class="tng-ql-panel">
                <?php if(!$quests):?><div class="tng-ql-empty"><h2>No quests found</h2><p>Create a quest draft from Quest Blueprint Studio, then return here to manage it.</p><a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=tng-quest-blueprint-studio'));?>">Open Blueprint Studio</a></div><?php else:?>
                <table class="tng-ql-table"><thead><tr><th>Quest</th><th>Status</th><th>Gameplay</th><th>Checkpoints</th><th>XP</th><th>Modified</th><th>Actions</th></tr></thead><tbody>
                <?php foreach($quests as $quest):
                    $stops = count((array)get_post_meta($quest->ID, '_tng_quest_entity_ids', true));
                    $xp = $this->xp($quest->ID);
                    $mechanics = (array)get_post_meta($quest->ID, '_tng_game_checkpoint_mechanics', true);
                    $configured = $stops > 0 && count($mechanics) >= $stops;
                    $runtime_url = add_query_arg('tng_quest_runtime_id', $quest->ID, home_url('/'));
                    $source_blueprint = absint(get_post_meta($quest->ID, '_tng_quest_source_blueprint', true));
                    $next_status = $quest->post_status === 'publish' ? 'draft' : 'publish';
                    $status_url = wp_nonce_url(add_query_arg(['action'=>'tng_quest_library_status','tng_quest_id'=>$quest->ID,'new_status'=>$next_status],admin_url('admin-post.php')),'tng_quest_library_status_'.$quest->ID);
                    $duplicate_url = wp_nonce_url(add_query_arg(['action'=>'tng_quest_library_duplicate','tng_quest_id'=>$quest->ID],admin_url('admin-post.php')),'tng_quest_library_duplicate_'.$quest->ID);
                    ?>
                    <tr>
                        <td><div class="tng-ql-title"><?php echo esc_html($quest->post_title ?: 'Untitled quest');?></div><div class="tng-ql-sub">#<?php echo esc_html((string)$quest->ID);?><?php if($source_blueprint):?> · Blueprint #<?php echo esc_html((string)$source_blueprint);?><?php endif;?></div></td>
                        <td><span class="tng-ql-badge tng-ql-status-<?php echo esc_attr($quest->post_status);?>"><?php echo esc_html(ucfirst($quest->post_status));?></span></td>
                        <td><div class="tng-ql-health"><span class="tng-ql-dot <?php echo $configured?'':'warn';?>"></span><span><?php echo esc_html($configured?'Configured':'Needs setup');?></span></div></td>
                        <td><?php echo esc_html(number_format_i18n($stops));?></td>
                        <td><?php echo esc_html(number_format_i18n($xp));?></td>
                        <td><?php echo esc_html(get_the_modified_date('M j, Y', $quest));?></td>
                        <td><div class="tng-ql-actions"><a class="button button-small" href="<?php echo esc_url(add_query_arg(['page'=>'tng-gameplay-engine','tng_quest_id'=>$quest->ID],admin_url('admin.php')));?>">Gameplay</a><a class="button button-small" href="<?php echo esc_url($runtime_url);?>" target="_blank" rel="noopener">Runtime</a><a class="button button-small" href="<?php echo esc_url(get_edit_post_link($quest->ID));?>">Edit</a><a class="button button-small" href="<?php echo esc_url($duplicate_url);?>">Duplicate</a><a class="button button-small" href="<?php echo esc_url($status_url);?>"><?php echo esc_html($next_status==='publish'?'Publish':'Unpublish');?></a></div></td>
                    </tr>
                <?php endforeach;?></tbody></table><?php endif;?>
            </div>
        </div>
        <?php
    }

    public function change_status(): void {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        $id = absint($_GET['tng_quest_id'] ?? 0);
        check_admin_referer('tng_quest_library_status_'.$id);
        if (!$id || get_post_type($id) !== self::QUEST_TYPE) $this->redirect();
        $new_status = sanitize_key((string)($_GET['new_status'] ?? 'draft'));
        if (!in_array($new_status, ['draft','publish','private','pending'], true)) $new_status = 'draft';
        wp_update_post(['ID'=>$id,'post_status'=>$new_status]);
        $this->redirect(['updated'=>'1']);
    }

    public function duplicate(): void {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        $id = absint($_GET['tng_quest_id'] ?? 0);
        check_admin_referer('tng_quest_library_duplicate_'.$id);
        $source = $id ? get_post($id) : null;
        if (!$source || $source->post_type !== self::QUEST_TYPE) $this->redirect();
        $new_id = wp_insert_post([
            'post_type'=>self::QUEST_TYPE,
            'post_status'=>'draft',
            'post_title'=>$source->post_title.' — Copy',
            'post_content'=>$source->post_content,
            'post_excerpt'=>$source->post_excerpt,
        ], true, false);
        if (is_wp_error($new_id)) $this->redirect();
        foreach (get_post_meta($id) as $key=>$values) {
            if (in_array($key, ['_edit_lock','_edit_last'], true)) continue;
            foreach ($values as $value) add_post_meta($new_id, $key, maybe_unserialize($value));
        }
        update_post_meta($new_id, '_tng_quest_status', 'draft');
        update_post_meta($new_id, '_tng_quest_duplicated_from', $id);
        clean_post_cache((int)$new_id);
        $this->redirect(['duplicated'=>'1']);
    }

    private function xp(int $id): int {
        return absint(get_post_meta($id, '_tng_quest_xp', true) ?: get_post_meta($id, '_tng_quest_estimated_xp', true));
    }

    private function redirect(array $args=[]): void {
        wp_safe_redirect(add_query_arg(array_merge(['page'=>'tng-quest-library'],$args),admin_url('admin.php')));
        exit;
    }
}
