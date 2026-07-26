<?php
namespace TNG_OS\Modules\Entities;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class Quest_Intelligence implements Module_Interface {
    private const POST_TYPE = 'tng_quest_blueprint';
    private const CACHE_OPTION = 'tng_quest_intelligence_candidates';
    private const DISMISSED_OPTION = 'tng_quest_intelligence_dismissed';
    private Container $container;

    public function id(): string { return 'quest_intelligence'; }

    public function register(Container $container): void {
        $this->container = $container;
        $container->set('quest_intelligence', $this);
        add_action('init', [$this, 'register_post_type']);
        add_action('admin_menu', [$this, 'menu'], 32);
        add_action('admin_post_tng_qi_rescan', [$this, 'rescan']);
        add_action('admin_post_tng_qi_create', [$this, 'create_blueprint']);
        add_action('admin_post_tng_qi_dismiss', [$this, 'dismiss']);
        add_action('tng_os_daily', [$this, 'scheduled_scan']);
    }

    public function boot(Container $container): void {}

    public function register_post_type(): void {
        register_post_type(self::POST_TYPE, [
            'labels' => ['name'=>'Quest Blueprints','singular_name'=>'Quest Blueprint'],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => false,
            'supports' => ['title','editor','custom-fields'],
            'capability_type' => 'post',
            'map_meta_cap' => true,
        ]);
    }

    public function menu(): void {
        add_submenu_page('tn-game-os', 'Quest Intelligence', 'Quest Intelligence', 'manage_options', 'tng-quest-intelligence', [$this, 'page']);
    }

    public function scheduled_scan(): void {
        $this->store_candidates($this->generate_candidates());
    }

    public function rescan(): void {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        check_admin_referer('tng_qi_rescan');
        $this->store_candidates($this->generate_candidates());
        $this->redirect(['rescanned'=>'1']);
    }

    public function dismiss(): void {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        check_admin_referer('tng_qi_dismiss');
        $key = sanitize_text_field(wp_unslash($_POST['candidate_key'] ?? ''));
        if ($key !== '') {
            $dismissed = get_option(self::DISMISSED_OPTION, []);
            $dismissed = is_array($dismissed) ? $dismissed : [];
            $dismissed[$key] = current_time('mysql', true);
            update_option(self::DISMISSED_OPTION, $dismissed, false);
        }
        $this->redirect(['dismissed'=>'1']);
    }

    public function create_blueprint(): void {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        check_admin_referer('tng_qi_create');
        $key = sanitize_text_field(wp_unslash($_POST['candidate_key'] ?? ''));
        $candidate = $this->candidate_by_key($key);
        if (!$candidate) $this->redirect(['invalid'=>'1']);

        $post_id = wp_insert_post([
            'post_type'=>self::POST_TYPE,
            'post_status'=>'draft',
            'post_title'=>$candidate['title'],
            'post_content'=>$this->blueprint_content($candidate),
        ], true);
        if (is_wp_error($post_id)) $this->redirect(['invalid'=>'1']);

        update_post_meta($post_id, '_tng_quest_candidate_key', $candidate['key']);
        update_post_meta($post_id, '_tng_quest_template', $candidate['template']);
        update_post_meta($post_id, '_tng_quest_entity_ids', $candidate['entity_ids']);
        update_post_meta($post_id, '_tng_quest_quality_score', $candidate['quality']);
        update_post_meta($post_id, '_tng_quest_estimated_xp', $candidate['xp']);
        update_post_meta($post_id, '_tng_quest_estimated_minutes', $candidate['minutes']);
        update_post_meta($post_id, '_tng_quest_status', 'blueprint');

        $this->redirect(['created'=>'1','blueprint'=>$post_id]);
    }

    public function page(): void {
        if (!current_user_can('manage_options')) return;
        $candidates = $this->visible_candidates();
        if (!$candidates && get_option(self::CACHE_OPTION, null) === null) {
            $this->store_candidates($this->generate_candidates());
            $candidates = $this->visible_candidates();
        }
        $blueprints = wp_count_posts(self::POST_TYPE);
        $draft_count = isset($blueprints->draft) ? (int)$blueprints->draft : 0;
        $top_quality = $candidates ? max(array_column($candidates, 'quality')) : 0;
        ?>
        <div class="wrap tng-qi">
            <style>
                .tng-qi{max-width:1500px}.tng-qi-hero{background:linear-gradient(135deg,#18213d,#4b2f68);color:#fff;border-radius:18px;padding:30px;margin:18px 0;box-shadow:0 12px 35px rgba(24,33,61,.2)}.tng-qi-hero h1{color:#fff;margin:0 0 7px;font-size:31px}.tng-qi-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}.tng-qi-card{background:#fff;border:1px solid #dcdcde;border-radius:14px;padding:18px}.tng-qi-stat strong{display:block;font-size:29px;color:#18213d;margin-top:4px}.tng-qi-toolbar{display:flex;justify-content:space-between;align-items:center;gap:12px;margin:20px 0}.tng-qi-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.tng-qi-candidate{display:grid;gap:14px}.tng-qi-head{display:flex;justify-content:space-between;gap:14px}.tng-qi-score{min-width:66px;height:66px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:#18213d;color:#fff;font-size:22px;font-weight:800}.tng-qi-badges{display:flex;gap:7px;flex-wrap:wrap}.tng-qi-badge{display:inline-flex;padding:5px 10px;border-radius:999px;background:#f0edff;color:#53389e;font-size:12px;font-weight:700}.tng-qi-steps{display:grid;gap:7px}.tng-qi-step{display:flex;align-items:center;gap:9px;padding:9px 11px;background:#f8fafc;border-radius:9px}.tng-qi-step-num{width:24px;height:24px;border-radius:50%;background:#7f56d9;color:#fff;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800}.tng-qi-reasons{margin:0;padding-left:19px;color:#475467}.tng-qi-actions{display:flex;gap:8px;flex-wrap:wrap}.tng-qi-empty{text-align:center;padding:48px 20px}.tng-qi-meter{height:8px;background:#eef1f5;border-radius:999px;overflow:hidden}.tng-qi-meter span{display:block;height:100%;background:#7f56d9}.tng-qi-template{font-size:12px;text-transform:uppercase;letter-spacing:.08em;color:#6941c6;font-weight:800}@media(max-width:1000px){.tng-qi-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.tng-qi-list{grid-template-columns:1fr}}@media(max-width:650px){.tng-qi-grid{grid-template-columns:1fr}}
            </style>
            <div class="tng-qi-hero"><p style="text-transform:uppercase;letter-spacing:.12em;margin:0 0 8px;color:#f6bd3b;font-weight:700">TN Platform · Gameplay Intelligence</p><h1>Quest Intelligence</h1><p>Turn destination graph patterns into reviewable quest blueprints, checkpoint collections, XP estimates, and publish-ready gameplay concepts.</p></div>
            <?php if(isset($_GET['rescanned'])):?><div class="notice notice-success inline"><p>Quest candidates regenerated from the current destination graph.</p></div><?php endif;?>
            <?php if(isset($_GET['created'])):?><div class="notice notice-success inline"><p>Draft quest blueprint created. <a href="<?php echo esc_url(get_edit_post_link(absint($_GET['blueprint'] ?? 0))); ?>">Open the blueprint</a>.</p></div><?php endif;?>
            <?php if(isset($_GET['dismissed'])):?><div class="notice notice-success inline"><p>Quest candidate dismissed.</p></div><?php endif;?>
            <div class="tng-qi-grid">
                <div class="tng-qi-card tng-qi-stat"><span>Quest candidates</span><strong><?php echo esc_html(number_format_i18n(count($candidates))); ?></strong></div>
                <div class="tng-qi-card tng-qi-stat"><span>Top quality</span><strong><?php echo esc_html((string)$top_quality); ?>%</strong></div>
                <div class="tng-qi-card tng-qi-stat"><span>Draft blueprints</span><strong><?php echo esc_html(number_format_i18n($draft_count)); ?></strong></div>
                <div class="tng-qi-card tng-qi-stat"><span>Generation mode</span><strong>Graph</strong></div>
            </div>
            <div class="tng-qi-toolbar"><div><h2 style="margin:0">Generated quest candidates</h2><p style="margin:5px 0 0;color:#646970">Candidates are suggestions only. Creating one produces a private draft blueprint for editorial review.</p></div><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="tng_qi_rescan"><?php wp_nonce_field('tng_qi_rescan'); ?><button class="button button-primary">Regenerate candidates</button></form></div>
            <div class="tng-qi-list">
                <?php if(!$candidates):?><div class="tng-qi-card tng-qi-empty" style="grid-column:1/-1"><span class="tng-qi-badge">Graph needs more variety</span><h2>No quest candidates yet</h2><p>Add more trails, waterfalls, historic sites, restaurants, lodging, events, or destination relationships. Quest Intelligence will discover collections and journey patterns automatically.</p></div><?php endif;?>
                <?php foreach($candidates as $candidate):?>
                    <article class="tng-qi-card tng-qi-candidate"><div class="tng-qi-head"><div><div class="tng-qi-template"><?php echo esc_html($candidate['template_label']); ?></div><h2 style="margin:5px 0"><?php echo esc_html($candidate['title']); ?></h2><p style="margin:0;color:#475467"><?php echo esc_html($candidate['summary']); ?></p></div><div class="tng-qi-score" title="Quest quality score"><?php echo esc_html((string)$candidate['quality']); ?></div></div><div class="tng-qi-meter"><span style="width:<?php echo esc_attr((string)$candidate['quality']); ?>%"></span></div><div class="tng-qi-badges"><span class="tng-qi-badge"><?php echo esc_html(number_format_i18n(count($candidate['entities']))); ?> stops</span><span class="tng-qi-badge"><?php echo esc_html(number_format_i18n($candidate['xp'])); ?> XP</span><span class="tng-qi-badge"><?php echo esc_html($this->duration_label($candidate['minutes'])); ?></span></div><div class="tng-qi-steps"><?php foreach(array_slice($candidate['entities'],0,8) as $index=>$entity):?><div class="tng-qi-step"><span class="tng-qi-step-num"><?php echo esc_html((string)($index+1)); ?></span><span><strong><?php echo esc_html($entity['title']); ?></strong> <small>· <?php echo esc_html($entity['type']); ?></small></span></div><?php endforeach;?></div><ul class="tng-qi-reasons"><?php foreach($candidate['reasons'] as $reason):?><li><?php echo esc_html($reason); ?></li><?php endforeach;?></ul><div class="tng-qi-actions"><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="tng_qi_create"><?php wp_nonce_field('tng_qi_create'); ?><input type="hidden" name="candidate_key" value="<?php echo esc_attr($candidate['key']); ?>"><button class="button button-primary">Create draft blueprint</button></form><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="tng_qi_dismiss"><?php wp_nonce_field('tng_qi_dismiss'); ?><input type="hidden" name="candidate_key" value="<?php echo esc_attr($candidate['key']); ?>"><button class="button">Dismiss</button></form></div></article>
                <?php endforeach;?>
            </div>
        </div>
        <?php
    }

    private function generate_candidates(): array {
        $engine = $this->container->get('recommendation_engine');
        if (!$engine || !is_callable([$engine, 'entities'])) return [];
        $entities = $engine->entities();
        $candidates = [];

        $by_type = [];
        foreach ($entities as $entity) $by_type[sanitize_key((string)$entity['type'])][] = $entity;

        $collections = [
            'waterfall'=>['label'=>'Waterfall Challenge','suffix'=>'Waterfall Challenge','min'=>3,'xp'=>150,'minutes'=>45],
            'trail'=>['label'=>'Trail Collection','suffix'=>'Trail Explorer','min'=>3,'xp'=>225,'minutes'=>120],
            'historic_site'=>['label'=>'History Trail','suffix'=>'Historic Discovery','min'=>3,'xp'=>125,'minutes'=>35],
            'museum'=>['label'=>'Culture Collection','suffix'=>'Culture Quest','min'=>3,'xp'=>125,'minutes'=>50],
            'coffee_shop'=>['label'=>'Coffee Crawl','suffix'=>'Coffee Crawl','min'=>3,'xp'=>75,'minutes'=>30],
            'restaurant'=>['label'=>'Local Flavor Trail','suffix'=>'Local Flavor Quest','min'=>3,'xp'=>100,'minutes'=>60],
            'checkpoint'=>['label'=>'Checkpoint Challenge','suffix'=>'Explorer Challenge','min'=>3,'xp'=>100,'minutes'=>30],
        ];
        foreach ($collections as $type=>$template) {
            $items = $by_type[$type] ?? [];
            if (count($items) < $template['min']) continue;
            $title = $this->location_prefix($items) . $template['suffix'];
            $candidates[] = $this->candidate('collection_'.$type, $title, $template['label'], 'collection', array_slice($items,0,10), $template['xp'], $template['minutes'], ['Enough matching entities for a focused challenge.','All stops use the same destination entity type.']);
        }

        foreach ($entities as $venue) {
            if (!in_array(sanitize_key((string)$venue['type']), ['venue','theater','amphitheater'], true)) continue;
            $events = [];
            foreach ($entities as $entity) {
                if (!in_array(sanitize_key((string)$entity['type']), ['event','concert','festival'], true)) continue;
                if ($this->connected($entity, $venue['id'], 'held_at') || $this->title_mentions($entity, $venue['title'])) $events[] = $entity;
            }
            if (!$events) continue;
            $stops = array_merge([$venue], array_slice($events,0,7));
            $candidates[] = $this->candidate('venue_events_'.$venue['id'], $venue['title'].' Live Experience', 'Venue Event Challenge', 'venue_events', $stops, 175, 90, ['Events are connected to the same venue.','The venue provides a natural anchor for the quest.']);
        }

        $location_groups = [];
        foreach ($entities as $entity) {
            $location = $this->entity_location($entity);
            if ($location !== '') $location_groups[$location][] = $entity;
        }
        foreach ($location_groups as $location=>$items) {
            $types = array_unique(array_map(static fn(array $e): string => sanitize_key((string)$e['type']), $items));
            if (count($items) < 4 || count($types) < 3) continue;
            $candidates[] = $this->candidate('destination_'.md5($location), ucwords($location).' Discovery Day', 'Destination Sampler', 'destination_sampler', array_slice($items,0,8), 150, 75, ['Stops share the same destination or city metadata.','Multiple entity types create a varied visitor journey.']);
        }

        usort($candidates, static fn(array $a,array $b): int => $b['quality'] <=> $a['quality']);
        return array_slice($candidates, 0, 50);
    }

    private function candidate(string $seed, string $title, string $label, string $template, array $entities, int $xp_each, int $minutes_each, array $reasons): array {
        $count = count($entities);
        $image_count = 0; $description_count = 0; $coordinate_count = 0;
        foreach ($entities as $entity) {
            $payload = (array)$entity['payload'];
            if (!empty($payload['image']) || !empty($payload['image_url']) || !empty($payload['featured_image'])) $image_count++;
            if (!empty($payload['description']) || !empty($payload['summary']) || !empty($payload['excerpt'])) $description_count++;
            if ($this->has_coordinates($payload)) $coordinate_count++;
        }
        $quality = 45 + min(20, $count * 4);
        if ($image_count >= ceil($count / 2)) { $quality += 10; $reasons[] = 'Most stops have imagery.'; }
        if ($description_count >= ceil($count / 2)) { $quality += 10; $reasons[] = 'Most stops have descriptions.'; }
        if ($coordinate_count >= ceil($count / 2)) { $quality += 10; $reasons[] = 'Most stops support distance-aware routing.'; }
        $quality = min(100, $quality);
        $ids = array_values(array_map(static fn(array $entity): string => (string)$entity['id'], $entities));
        return [
            'key'=>'qi_'.md5($seed.'|'.implode('|',$ids)),
            'title'=>$title,
            'template'=>$template,
            'template_label'=>$label,
            'summary'=>$this->summary_for_template($template, $count),
            'entities'=>array_values(array_map(static fn(array $entity): array => ['id'=>$entity['id'],'title'=>$entity['title'],'type'=>$entity['type']], $entities)),
            'entity_ids'=>$ids,
            'quality'=>$quality,
            'xp'=>$count * $xp_each,
            'minutes'=>max(30, $count * $minutes_each),
            'reasons'=>array_values(array_unique($reasons)),
        ];
    }

    private function visible_candidates(): array {
        $candidates = get_option(self::CACHE_OPTION, []);
        $candidates = is_array($candidates) ? $candidates : [];
        $dismissed = get_option(self::DISMISSED_OPTION, []);
        $dismissed = is_array($dismissed) ? $dismissed : [];
        return array_values(array_filter($candidates, static fn(array $candidate): bool => empty($dismissed[$candidate['key']]  ?? null)));
    }

    private function store_candidates(array $candidates): void {
        update_option(self::CACHE_OPTION, $candidates, false);
        update_option('tng_quest_intelligence_last_scan', current_time('mysql', true), false);
    }

    private function candidate_by_key(string $key): ?array {
        foreach ($this->visible_candidates() as $candidate) if (($candidate['key'] ?? '') === $key) return $candidate;
        return null;
    }

    private function connected(array $entity, string $target_id, string $type): bool {
        foreach ((array)$entity['relationships'] as $relationship) {
            if (!is_array($relationship)) continue;
            if ((string)($relationship['target_entity_id'] ?? '') === $target_id && sanitize_key((string)($relationship['type'] ?? '')) === $type) return true;
        }
        return false;
    }

    private function title_mentions(array $entity, string $title): bool {
        $needle = $this->normalized($title);
        $haystack = $this->normalized((string)$entity['title'].' '.implode(' ', array_filter([(string)($entity['payload']['venue'] ?? ''),(string)($entity['payload']['venue_name'] ?? '')])));
        return $needle !== '' && str_contains($haystack, $needle);
    }

    private function entity_location(array $entity): string {
        $payload = (array)$entity['payload'];
        foreach (['destination','location','city'] as $key) if (!empty($payload[$key]) && !is_array($payload[$key])) return $this->normalized((string)$payload[$key]);
        return '';
    }

    private function location_prefix(array $items): string {
        $locations = [];
        foreach ($items as $item) { $loc = $this->entity_location($item); if ($loc !== '') $locations[$loc] = ($locations[$loc] ?? 0) + 1; }
        if (!$locations) return '';
        arsort($locations);
        return ucwords((string)array_key_first($locations)).' ';
    }

    private function summary_for_template(string $template, int $count): string {
        $map = [
            'collection'=>'Complete a themed collection of destination checkpoints.',
            'venue_events'=>'Build player loyalty around a venue and its connected events.',
            'destination_sampler'=>'Combine several experience types into a varied destination journey.',
        ];
        return ($map[$template] ?? 'A graph-generated quest concept.').' '.$count.' eligible stops were found.';
    }

    private function blueprint_content(array $candidate): string {
        $lines = ['Quest generated by TN Game OS Quest Intelligence.','', 'Template: '.$candidate['template_label'], 'Quality score: '.$candidate['quality'].'/100', 'Estimated reward: '.$candidate['xp'].' XP', 'Estimated duration: '.$this->duration_label($candidate['minutes']), '', 'Proposed checkpoints:'];
        foreach ($candidate['entities'] as $index=>$entity) $lines[] = ($index+1).'. '.$entity['title'].' ('.$entity['type'].')';
        $lines[] = ''; $lines[] = 'Editorial notes:';
        foreach ($candidate['reasons'] as $reason) $lines[] = '- '.$reason;
        return implode("\n", $lines);
    }

    private function duration_label(int $minutes): string {
        if ($minutes < 60) return $minutes.' min';
        $hours = round($minutes / 60, 1);
        return rtrim(rtrim(number_format($hours,1,'.',''),'0'),'.').' hr';
    }

    private function has_coordinates(array $payload): bool {
        $lat=$payload['latitude']??$payload['lat']??null; $lng=$payload['longitude']??$payload['lng']??$payload['lon']??null;
        if (is_numeric($lat) && is_numeric($lng)) return true;
        if (!empty($payload['coordinates']) && is_array($payload['coordinates'])) return is_numeric($payload['coordinates']['lat']??$payload['coordinates']['latitude']??null) && is_numeric($payload['coordinates']['lng']??$payload['coordinates']['longitude']??null);
        return false;
    }

    private function normalized(string $value): string { return trim(preg_replace('/[^a-z0-9]+/',' ',strtolower(remove_accents($value))) ?? ''); }

    private function redirect(array $args): void {
        wp_safe_redirect(add_query_arg(array_merge(['page'=>'tng-quest-intelligence'], $args), admin_url('admin.php')));
        exit;
    }
}
