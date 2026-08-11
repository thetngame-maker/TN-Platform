<?php
namespace TNG_OS\Modules\Destinations;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;
use WP_Post;

if (!defined('ABSPATH')) exit;

final class Destination_AI_Profiles implements Module_Interface {
    private const PAGE = 'tng-destination-ai-profiles';
    private const NONCE = 'tng_destination_ai_profile';

    public function id(): string { return 'destination_ai_profiles'; }

    public function register(Container $container): void {
        $container->set('destination_ai_profiles', $this);
        add_action('admin_menu', [$this, 'menu'], 27);
        add_action('add_meta_boxes', [$this, 'meta_boxes']);
        add_action('save_post', [$this, 'save'], 130, 2);
        add_action('admin_post_tng_generate_destination_profiles', [$this, 'generate_all']);
    }

    public function boot(Container $container): void {}

    public function menu(): void {
        add_submenu_page('tn-game-os', 'Destination AI Profiles', 'AI Profiles', 'manage_options', self::PAGE, [$this, 'page']);
    }

    public function meta_boxes(): void {
        foreach ($this->post_types() as $type) {
            add_meta_box('tng-destination-ai-profile', 'Destination AI Profile', [$this, 'meta_box'], $type, 'normal', 'default');
        }
    }

    public function meta_box(WP_Post $post): void {
        $p = self::profile($post->ID);
        wp_nonce_field(self::NONCE, 'tng_destination_ai_nonce');
        ?>
        <style>
            .tng-ai-grid{display:grid;grid-template-columns:repeat(4,minmax(120px,1fr));gap:12px}.tng-ai-field{background:#faf9ff;border:1px solid #e4def5;border-radius:12px;padding:12px}.tng-ai-field label{display:block;font-weight:700;margin-bottom:6px}.tng-ai-field input,.tng-ai-field select,.tng-ai-field textarea{width:100%}.tng-ai-wide{grid-column:1/-1}.tng-ai-help{color:#667085;font-size:12px;margin-top:5px}@media(max-width:900px){.tng-ai-grid{grid-template-columns:repeat(2,1fr)}}
        </style>
        <div class="tng-ai-grid">
            <?php foreach ($this->score_fields() as $key => $label): ?>
                <div class="tng-ai-field"><label><?php echo esc_html($label); ?></label><select name="tng_ai[<?php echo esc_attr($key); ?>]"><?php for($i=0;$i<=5;$i++): ?><option value="<?php echo $i; ?>" <?php selected((int)($p[$key] ?? 0),$i); ?>><?php echo $i ? $i . ' / 5' : 'Not set'; ?></option><?php endfor; ?></select></div>
            <?php endforeach; ?>
            <div class="tng-ai-field"><label>Estimated visit</label><input type="number" min="0" step="15" name="tng_ai[visit_minutes]" value="<?php echo esc_attr($p['visit_minutes'] ?? ''); ?>"><div class="tng-ai-help">Minutes</div></div>
            <div class="tng-ai-field"><label>Typical cost</label><select name="tng_ai[cost]"><?php foreach([''=>'Not set','free'=>'Free','$'=>'Budget','$$'=>'Moderate','$$$'=>'Premium'] as $v=>$l): ?><option value="<?php echo esc_attr($v); ?>" <?php selected($p['cost'] ?? '',$v); ?>><?php echo esc_html($l); ?></option><?php endforeach; ?></select></div>
            <div class="tng-ai-field"><label>Best seasons</label><input type="text" name="tng_ai[seasons]" value="<?php echo esc_attr($p['seasons'] ?? ''); ?>"><div class="tng-ai-help">Comma-separated</div></div>
            <div class="tng-ai-field"><label>Traits</label><input type="text" name="tng_ai[traits]" value="<?php echo esc_attr($p['traits'] ?? ''); ?>"><div class="tng-ai-help">Examples: waterfall, music, family, historic</div></div>
            <div class="tng-ai-field tng-ai-wide"><label>AI-ready summary</label><textarea rows="3" name="tng_ai[summary]"><?php echo esc_textarea($p['summary'] ?? ''); ?></textarea></div>
            <div class="tng-ai-field"><label>Profile confidence</label><input type="number" min="0" max="100" name="tng_ai[confidence]" value="<?php echo esc_attr($p['confidence'] ?? ''); ?>"><div class="tng-ai-help">0–100</div></div>
            <div class="tng-ai-field"><label>Profile source</label><input type="text" readonly value="<?php echo esc_attr($p['source'] ?? 'Manual'); ?>"></div>
        </div>
        <?php
    }

    public function save(int $post_id, WP_Post $post): void {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;
        if (!in_array($post->post_type, $this->post_types(), true)) return;
        if (!isset($_POST['tng_destination_ai_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['tng_destination_ai_nonce'])), self::NONCE)) return;
        if (!current_user_can('edit_post', $post_id)) return;
        $raw = isset($_POST['tng_ai']) && is_array($_POST['tng_ai']) ? wp_unslash($_POST['tng_ai']) : [];
        $profile = [];
        foreach ($this->score_fields() as $key => $label) $profile[$key] = min(5, max(0, absint($raw[$key] ?? 0)));
        $profile['visit_minutes'] = absint($raw['visit_minutes'] ?? 0);
        $profile['cost'] = in_array($raw['cost'] ?? '', ['free','$','$$','$$$'], true) ? $raw['cost'] : '';
        $profile['seasons'] = sanitize_text_field($raw['seasons'] ?? '');
        $profile['traits'] = sanitize_text_field($raw['traits'] ?? '');
        $profile['summary'] = sanitize_textarea_field($raw['summary'] ?? '');
        $profile['confidence'] = min(100, max(0, absint($raw['confidence'] ?? 0)));
        $profile['source'] = 'Manual';
        $profile['updated_at'] = current_time('mysql', true);
        update_post_meta($post_id, '_tng_destination_ai_profile', $profile);
    }

    public function page(): void {
        if (!current_user_can('manage_options')) wp_die('Unauthorized.');
        $rows=[];$complete=$partial=$missing=0;
        foreach ($this->node_ids() as $id) {
            $p=self::profile($id); $score=self::completeness($p);
            if ($score>=80) $complete++; elseif ($score>0) $partial++; else $missing++;
            $rows[]=['id'=>$id,'title'=>get_the_title($id) ?: '#'.$id,'type'=>get_post_type($id),'profile'=>$p,'score'=>$score];
        }
        usort($rows,static fn($a,$b)=>$a['score']<=>$b['score'] ?: strcasecmp($a['title'],$b['title']));
        $notice=isset($_GET['tng_ai_notice'])?sanitize_text_field(wp_unslash($_GET['tng_ai_notice'])):'';
        ?>
        <div class="wrap tng-ai-profiles"><h1>Destination AI Profiles</h1><p>Structured destination traits for recommendations, semantic search, and itinerary building.</p>
        <?php if($notice): ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>
        <style>.tng-aip-stats{display:grid;grid-template-columns:repeat(4,minmax(150px,1fr));gap:14px;margin:20px 0;max-width:1100px}.tng-aip-card{background:#fff;border:1px solid #dcdcde;border-radius:16px;padding:18px}.tng-aip-card strong{display:block;font-size:30px;color:#6538b5}.tng-aip-pill{display:inline-block;border-radius:999px;background:#f0ebff;color:#6538b5;padding:4px 9px;font-size:11px;font-weight:700}.tng-aip-bar{height:8px;background:#e8eaf0;border-radius:999px;overflow:hidden;min-width:120px}.tng-aip-bar span{display:block;height:100%;background:linear-gradient(90deg,#8b5cf6,#34d399)}@media(max-width:800px){.tng-aip-stats{grid-template-columns:repeat(2,1fr)}}</style>
        <div class="tng-aip-stats"><div class="tng-aip-card"><strong><?php echo count($rows); ?></strong>Eligible listings</div><div class="tng-aip-card"><strong><?php echo $complete; ?></strong>AI ready</div><div class="tng-aip-card"><strong><?php echo $partial; ?></strong>Partial profiles</div><div class="tng-aip-card"><strong><?php echo $missing; ?></strong>Missing profiles</div></div>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Generate starter profiles for listings without a complete profile? Existing manual values will be preserved.');"><?php wp_nonce_field('tng_generate_destination_profiles'); ?><input type="hidden" name="action" value="tng_generate_destination_profiles"><button class="button button-primary button-large">Generate starter profiles</button></form>
        <h2>Profile readiness</h2><table class="widefat striped"><thead><tr><th>Listing</th><th>Readiness</th><th>Top traits</th><th>Visit</th><th>Confidence</th></tr></thead><tbody>
        <?php foreach($rows as $row): $p=$row['profile']; ?><tr><td><a href="<?php echo esc_url(get_edit_post_link($row['id'])); ?>"><strong><?php echo esc_html($row['title']); ?></strong></a><br><span class="tng-aip-pill"><?php echo esc_html($row['type']); ?></span></td><td><strong><?php echo $row['score']; ?>%</strong><div class="tng-aip-bar"><span style="width:<?php echo $row['score']; ?>%"></span></div></td><td><?php echo esc_html($p['traits'] ?? '—'); ?></td><td><?php echo !empty($p['visit_minutes']) ? esc_html($p['visit_minutes'].' min') : '—'; ?></td><td><?php echo isset($p['confidence']) && $p['confidence'] !== '' ? esc_html($p['confidence'].'%') : '—'; ?></td></tr><?php endforeach; ?>
        </tbody></table></div><?php
    }

    public function generate_all(): void {
        if (!current_user_can('manage_options')) wp_die('Unauthorized.');
        check_admin_referer('tng_generate_destination_profiles');
        $generated=0;
        foreach($this->node_ids() as $id) {
            $current=self::profile($id);
            if (self::completeness($current)>=80) continue;
            $suggested=$this->suggest($id);
            foreach($suggested as $key=>$value) if (!isset($current[$key]) || $current[$key]==='' || $current[$key]===0) $current[$key]=$value;
            $current['source']='Rule-based starter profile'; $current['updated_at']=current_time('mysql',true);
            update_post_meta($id,'_tng_destination_ai_profile',$current); $generated++;
        }
        wp_safe_redirect(admin_url('admin.php?page='.self::PAGE.'&tng_ai_notice='.rawurlencode(sprintf('Generated or completed %d starter AI profiles.',$generated)))); exit;
    }

    public static function profile(int $post_id): array {
        $value=get_post_meta($post_id,'_tng_destination_ai_profile',true);
        return is_array($value)?$value:[];
    }

    public static function completeness(array $p): int {
        $keys=['family','adventure','history','accessibility','rainy_day','photography','visit_minutes','cost','seasons','traits','summary','confidence'];
        $filled=0; foreach($keys as $key) if(isset($p[$key]) && $p[$key]!=='' && $p[$key]!==0) $filled++;
        return (int)round($filled/count($keys)*100);
    }

    private function suggest(int $id): array {
        $text=strtolower(get_the_title($id).' '.wp_strip_all_tags(get_post_field('post_content',$id)).' '.implode(' ',wp_get_post_terms($id,get_object_taxonomies(get_post_type($id)) ?: [],['fields'=>'names'])));
        $has=static fn(array $words)=>array_reduce($words,static fn($carry,$word)=>$carry || str_contains($text,$word),false);
        $type=get_post_type($id);
        $adventure=$has(['trail','hike','waterfall','cave','climb','outdoor'])?5:($type==='st_activity'?3:1);
        $family=$has(['family','kids','playground','easy','bakery','restaurant'])?5:3;
        $history=$has(['historic','museum','heritage','coal','depot','history'])?5:1;
        $rain=$has(['indoor','museum','restaurant','bakery','shop','caverns','concert'])?4:1;
        $photo=$has(['waterfall','overlook','scenic','mural','cave','view'])?5:3;
        $access=$has(['accessible','wheelchair','paved','restaurant','hotel'])?4:2;
        $minutes=$has(['trail','hike'])?120:($has(['concert','tour'])?180:60);
        $cost=$has(['trail','waterfall','park','overlook'])?'free':($has(['hotel','lodging'])?'$$$':'$$');
        $traits=[]; foreach(['waterfall','trail','music','concert','family','historic','restaurant','bakery','lodging','cave','scenic','shopping'] as $trait) if(str_contains($text,$trait)) $traits[]=$trait;
        if(!$traits) $traits[]=$type==='top_sight'?'sight':'experience';
        $summary=wp_trim_words(wp_strip_all_tags(get_post_field('post_content',$id)),28,'');
        if(!$summary) $summary=get_the_title($id).' is a '.str_replace(['st_','_'],['',' '],$type).' in the TN Game destination network.';
        return ['family'=>$family,'adventure'=>$adventure,'history'=>$history,'accessibility'=>$access,'rainy_day'=>$rain,'photography'=>$photo,'visit_minutes'=>$minutes,'cost'=>$cost,'seasons'=>'Spring, Summer, Fall','traits'=>implode(', ',array_unique($traits)),'summary'=>$summary,'confidence'=>55];
    }

    private function score_fields(): array { return ['family'=>'Family friendly','adventure'=>'Adventure','history'=>'History','accessibility'=>'Accessibility','rainy_day'=>'Rainy-day suitability','photography'=>'Photography']; }
    private function node_ids(): array { return get_posts(['post_type'=>$this->post_types(),'post_status'=>'publish','posts_per_page'=>-1,'fields'=>'ids','orderby'=>'title','order'=>'ASC']); }
    private function post_types(): array { return array_values(array_filter(['tng_destination','st_activity','st_hotel','st_tours','st_rental','top_sight'],'post_type_exists')); }
}
