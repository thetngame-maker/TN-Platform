<?php
namespace TNG_OS\Modules\Destinations;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;
use WP_Post;

if (!defined('ABSPATH')) exit;

final class Smart_Recommendation_Engine implements Module_Interface {
    private const PAGE = 'tng-smart-recommendations';

    public function id(): string { return 'smart_recommendation_engine'; }

    public function register(Container $container): void {
        $container->set('smart_recommendation_engine', $this);
        add_action('admin_menu', [$this, 'menu'], 29);
        add_action('add_meta_boxes', [$this, 'meta_boxes']);
    }

    public function boot(Container $container): void {}

    public function menu(): void {
        add_submenu_page('tn-game-os', 'Smart Recommendations', 'Recommendations', 'manage_options', self::PAGE, [$this, 'page']);
    }

    public function meta_boxes(): void {
        foreach ($this->post_types() as $type) {
            add_meta_box('tng-smart-recommendations', 'Smart Recommendations', [$this, 'meta_box'], $type, 'normal', 'default');
        }
    }

    public function meta_box(WP_Post $post): void {
        $groups = [
            'similar' => 'Similar experiences',
            'family' => 'Family-friendly nearby',
            'rainy_day' => 'Rainy-day alternatives',
            'food_after' => 'Food nearby',
        ];
        ?>
        <style>.tng-rec-grid{display:grid;grid-template-columns:repeat(2,minmax(250px,1fr));gap:14px}.tng-rec-group{border:1px solid #e2dcef;background:#fbfaff;border-radius:14px;padding:14px}.tng-rec-group h4{margin:0 0 10px}.tng-rec-item{display:flex;justify-content:space-between;gap:12px;padding:9px 0;border-top:1px solid #ece8f5}.tng-rec-item:first-of-type{border-top:0}.tng-rec-score{font-weight:800;color:#673ab7;white-space:nowrap}.tng-rec-meta{font-size:12px;color:#667085;margin-top:3px}@media(max-width:850px){.tng-rec-grid{grid-template-columns:1fr}}</style>
        <p>Recommendations combine Knowledge Graph distance with structured Destination AI Profiles. They update automatically as profiles and coordinates improve.</p>
        <div class="tng-rec-grid">
            <?php foreach ($groups as $scenario => $label): $rows = self::recommend($post->ID, $scenario, 4); ?>
                <section class="tng-rec-group"><h4><?php echo esc_html($label); ?></h4>
                    <?php if (!$rows): ?><p class="description">No qualified recommendations yet.</p><?php endif; ?>
                    <?php foreach ($rows as $row): ?>
                        <div class="tng-rec-item"><div><a href="<?php echo esc_url(get_edit_post_link($row['id'])); ?>"><strong><?php echo esc_html($row['title']); ?></strong></a><div class="tng-rec-meta"><?php echo esc_html($row['distance_label'] . ' · ' . $row['reason']); ?></div></div><span class="tng-rec-score"><?php echo (int)$row['score']; ?></span></div>
                    <?php endforeach; ?>
                </section>
            <?php endforeach; ?>
        </div>
        <?php
    }

    public function page(): void {
        if (!current_user_can('manage_options')) wp_die('Unauthorized.');
        $ids = $this->node_ids();
        $selected = isset($_GET['source_id']) ? absint($_GET['source_id']) : (int)($ids[0] ?? 0);
        if ($selected && !in_array($selected, $ids, true)) $selected = (int)($ids[0] ?? 0);
        $scenarios = $this->scenarios();
        ?>
        <div class="wrap tng-smart-rec"><h1>Smart Recommendation Engine</h1><p>Preview explainable recommendations generated from graph proximity, content type, and Destination AI Profile compatibility.</p>
        <style>.tng-sr-controls{background:#fff;border:1px solid #dcdcde;border-radius:16px;padding:18px;margin:20px 0;display:flex;gap:12px;align-items:end;flex-wrap:wrap}.tng-sr-controls label{display:block;font-weight:700;margin-bottom:5px}.tng-sr-controls select{min-width:340px}.tng-sr-grid{display:grid;grid-template-columns:repeat(2,minmax(300px,1fr));gap:16px}.tng-sr-card{background:#fff;border:1px solid #dcdcde;border-radius:16px;padding:18px}.tng-sr-card h2{margin-top:0}.tng-sr-row{display:grid;grid-template-columns:1fr auto;gap:14px;padding:13px 0;border-top:1px solid #eceef2}.tng-sr-row:first-of-type{border-top:0}.tng-sr-score{font-size:24px;font-weight:800;color:#673ab7}.tng-sr-reason{font-size:12px;color:#667085;margin-top:4px}.tng-sr-pill{display:inline-block;background:#f0ebff;color:#6336ae;border-radius:999px;padding:4px 8px;font-size:11px;font-weight:700;margin-right:5px}@media(max-width:900px){.tng-sr-grid{grid-template-columns:1fr}.tng-sr-controls select{min-width:240px;max-width:100%}}</style>
        <form class="tng-sr-controls" method="get"><input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE); ?>"><div><label for="source_id">Preview listing</label><select id="source_id" name="source_id"><?php foreach($ids as $id): ?><option value="<?php echo (int)$id; ?>" <?php selected($selected,$id); ?>><?php echo esc_html(get_the_title($id).' ('.get_post_type($id).')'); ?></option><?php endforeach; ?></select></div><button class="button button-primary">Build recommendations</button></form>
        <?php if (!$selected): ?><div class="notice notice-warning"><p>No eligible destination listings found.</p></div><?php else: ?>
        <h2><?php echo esc_html(get_the_title($selected)); ?></h2><div class="tng-sr-grid">
        <?php foreach($scenarios as $scenario=>$label): $rows=self::recommend($selected,$scenario,8); ?>
            <section class="tng-sr-card"><h2><?php echo esc_html($label); ?></h2>
                <?php if(!$rows): ?><p>No qualified results. Improve AI profiles or rebuild the Knowledge Graph.</p><?php endif; ?>
                <?php foreach($rows as $row): ?><div class="tng-sr-row"><div><a href="<?php echo esc_url(get_edit_post_link($row['id'])); ?>"><strong><?php echo esc_html($row['title']); ?></strong></a><div><span class="tng-sr-pill"><?php echo esc_html($row['type']); ?></span><span class="tng-sr-pill"><?php echo esc_html($row['distance_label']); ?></span></div><div class="tng-sr-reason"><?php echo esc_html($row['reason']); ?></div></div><div class="tng-sr-score"><?php echo (int)$row['score']; ?></div></div><?php endforeach; ?>
            </section>
        <?php endforeach; ?></div><?php endif; ?></div><?php
    }

    public static function recommend(int $source_id, string $scenario = 'similar', int $limit = 6): array {
        $allowed = ['similar','family','rainy_day','food_after','lodging','photography','adventure'];
        if (!in_array($scenario, $allowed, true) || $source_id < 1) return [];
        global $wpdb;
        $table = $wpdb->prefix . 'tng_knowledge_graph';
        if ((string)$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) return [];
        $edges = $wpdb->get_results($wpdb->prepare("SELECT target_id,distance_miles,score,target_type FROM {$table} WHERE source_id=%d ORDER BY score DESC,distance_miles ASC LIMIT 150", $source_id), ARRAY_A);
        if (!$edges) return [];
        $source_profile = class_exists(Destination_AI_Profiles::class) ? Destination_AI_Profiles::profile($source_id) : [];
        $source_traits = self::tokens($source_profile['traits'] ?? '');
        $results = [];
        foreach ($edges as $edge) {
            $id = (int)$edge['target_id'];
            if (!$id || get_post_status($id) !== 'publish' || get_post_meta($id,'_tng_graph_excluded',true)) continue;
            $profile = class_exists(Destination_AI_Profiles::class) ? Destination_AI_Profiles::profile($id) : [];
            $type = sanitize_key($edge['target_type'] ?? get_post_type($id));
            $score = (float)$edge['score'] * .55;
            $reasons = [];
            $target_traits = self::tokens($profile['traits'] ?? '');
            $overlap = count(array_intersect($source_traits, $target_traits));
            if ($overlap) { $score += min(18, $overlap * 6); $reasons[] = $overlap.' shared trait'.($overlap===1?'':'s'); }
            $profile_conf = min(100, max(0, (int)($profile['confidence'] ?? 0)));
            $score += $profile_conf * .08;

            if ($scenario === 'similar') {
                if ($type === self::type($source_id)) { $score += 15; $reasons[]='same experience type'; }
            } elseif ($scenario === 'family') {
                $value=(int)($profile['family'] ?? 0); if($value<3) continue; $score += $value*7; $reasons[]='family score '.$value.'/5';
            } elseif ($scenario === 'rainy_day') {
                $value=(int)($profile['rainy_day'] ?? 0); if($value<3) continue; $score += $value*7; $reasons[]='rainy-day score '.$value.'/5';
            } elseif ($scenario === 'food_after') {
                if (!self::is_food($id,$profile)) continue; $score += 30; $reasons[]='food or dining match';
            } elseif ($scenario === 'lodging') {
                if (!in_array($type,['lodging','rental'],true) && !array_intersect($target_traits,['lodging','hotel','camping','cabin'])) continue; $score += 30; $reasons[]='lodging match';
            } elseif ($scenario === 'photography') {
                $value=(int)($profile['photography'] ?? 0); if($value<3) continue; $score += $value*7; $reasons[]='photography score '.$value.'/5';
            } elseif ($scenario === 'adventure') {
                $value=(int)($profile['adventure'] ?? 0); if($value<3) continue; $score += $value*7; $reasons[]='adventure score '.$value.'/5';
            }
            $distance=(float)$edge['distance_miles'];
            if ($distance <= 5) { $score += 8; $reasons[]='very close'; }
            elseif ($distance <= 12) { $score += 4; $reasons[]='nearby'; }
            $results[]=['id'=>$id,'title'=>get_the_title($id) ?: '#'.$id,'type'=>$type,'distance'=>$distance,'distance_label'=>number_format_i18n($distance,1).' mi','score'=>(int)round(min(100,$score)),'reason'=>implode(' · ',array_unique($reasons)) ?: 'strong graph connection'];
        }
        usort($results,static fn($a,$b)=>$b['score']<=>$a['score'] ?: $a['distance']<=>$b['distance']);
        return array_slice($results,0,max(1,$limit));
    }

    private static function tokens(string $value): array { return array_values(array_unique(array_filter(array_map('sanitize_key',preg_split('/[,|]+/',$value) ?: [])))); }
    private static function type(int $id): string { $map=['st_activity'=>'activity','st_hotel'=>'lodging','st_tours'=>'tour','st_rental'=>'rental','top_sight'=>'sight','tng_destination'=>'destination']; return $map[get_post_type($id)] ?? sanitize_key(get_post_type($id)); }
    private static function is_food(int $id, array $profile): bool {
        $traits=self::tokens($profile['traits'] ?? '');
        if(array_intersect($traits,['restaurant','food','bakery','coffee','dining','cafe'])) return true;
        $text=strtolower(get_the_title($id).' '.wp_strip_all_tags(get_post_field('post_content',$id)));
        foreach(['restaurant','grill','bakery','coffee','cafe','diner','pizza','food','kitchen'] as $word) if(str_contains($text,$word)) return true;
        $terms=wp_get_post_terms($id,get_object_taxonomies(get_post_type($id)) ?: [],['fields'=>'names']);
        return is_array($terms) && (bool)preg_match('/restaurant|food|drink|bakery|coffee|cafe/i',implode(' ',$terms));
    }
    private function scenarios(): array { return ['similar'=>'Similar experiences','family'=>'Family-friendly nearby','rainy_day'=>'Rainy-day alternatives','food_after'=>'Food after this stop','lodging'=>'Nearby lodging','photography'=>'Photography pairings','adventure'=>'More adventure']; }
    private function node_ids(): array { return get_posts(['post_type'=>$this->post_types(),'post_status'=>'publish','posts_per_page'=>-1,'fields'=>'ids','orderby'=>'title','order'=>'ASC','meta_query'=>[['key'=>'_tng_graph_excluded','compare'=>'NOT EXISTS']]]); }
    private function post_types(): array { return array_values(array_filter(['tng_destination','st_activity','st_hotel','st_tours','st_rental','top_sight'],'post_type_exists')); }
}
