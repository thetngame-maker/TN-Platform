<?php
namespace TNG_OS\Modules\Frontend;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class Smart_Recommendations implements Module_Interface {
    private const ENTITY_TYPE = 'tng_entity';
    private Container $container;
    private bool $rendering = false;

    public function id(): string { return 'smart_recommendations'; }

    public function register(Container $container): void {
        $this->container = $container;
        $container->set('smart_recommendations', $this);
        add_shortcode('tng_smart_recommendations', [$this, 'shortcode']);
        add_filter('the_content', [$this, 'append_to_content'], 40);
        add_action('wp_enqueue_scripts', [$this, 'assets']);
        add_action('vc_before_init', [$this, 'register_wpbakery']);
    }

    public function boot(Container $container): void {}

    public function assets(): void {
        if (is_admin()) return;
        wp_register_style('tng-smart-recommendations', false, [], TNG_OS_VERSION);
        wp_enqueue_style('tng-smart-recommendations');
        wp_add_inline_style('tng-smart-recommendations', '.tng-smart-rec{margin:36px 0}.tng-smart-rec__head{display:flex;justify-content:space-between;gap:16px;align-items:end;margin-bottom:16px}.tng-smart-rec__eyebrow{display:block;font-size:12px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#b45309}.tng-smart-rec h2{margin:4px 0 0}.tng-smart-rec__groups{display:grid;gap:24px}.tng-smart-rec__group h3{margin:0 0 12px}.tng-smart-rec__grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}.tng-smart-rec__card{display:block;background:#fff;border:1px solid #e2e8f0;border-radius:14px;overflow:hidden;text-decoration:none;color:inherit;box-shadow:0 2px 10px rgba(15,23,42,.06);transition:.18s ease}.tng-smart-rec__card:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(15,23,42,.12)}.tng-smart-rec__media{aspect-ratio:16/9;background:#e2e8f0;overflow:hidden}.tng-smart-rec__media img{width:100%;height:100%;object-fit:cover}.tng-smart-rec__body{padding:14px}.tng-smart-rec__type{font-size:12px;text-transform:uppercase;letter-spacing:.08em;color:#64748b}.tng-smart-rec__title{font-size:18px;font-weight:700;margin:5px 0}.tng-smart-rec__reason{font-size:13px;color:#475569}.tng-smart-rec__empty{padding:18px;border:1px dashed #cbd5e1;border-radius:12px;background:#f8fafc}@media(max-width:900px){.tng-smart-rec__grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:620px){.tng-smart-rec__grid{grid-template-columns:1fr}.tng-smart-rec__head{display:block}}');
    }

    public function append_to_content(string $content): string {
        if ($this->rendering || !is_singular() || !in_the_loop() || !is_main_query()) return $content;
        if (!(bool)apply_filters('tng_smart_recommendations_auto_append', true, get_the_ID())) return $content;
        $html = $this->render(['post_id' => get_the_ID(), 'heading' => 'Explore nearby and related']);
        return $html !== '' ? $content . $html : $content;
    }

    public function shortcode(array $atts = []): string { return $this->render($atts); }

    public function render(array $atts = []): string {
        if ($this->rendering) return '';
        $this->rendering = true;
        $atts = shortcode_atts(['entity' => '', 'post_id' => 0, 'heading' => 'Recommended for you', 'limit' => 6, 'depth' => 2], $atts, 'tng_smart_recommendations');
        $entity = sanitize_text_field((string)$atts['entity']);
        $post_id = absint($atts['post_id']) ?: get_the_ID();
        if ($entity === '' && $post_id) $entity = $this->entity_for_post($post_id);
        $items = $entity !== '' ? $this->recommend($entity, max(1, min(3, absint($atts['depth']))), max(1, min(12, absint($atts['limit'])))) : [];
        $this->rendering = false;
        if (!$items) return '';

        $groups = [];
        foreach ($items as $item) $groups[$this->group_label($item['type'])][] = $item;
        ob_start(); ?>
        <section class="tng-smart-rec" data-root-entity="<?php echo esc_attr($entity); ?>">
            <header class="tng-smart-rec__head"><div><span class="tng-smart-rec__eyebrow">Powered by the destination graph</span><h2><?php echo esc_html((string)$atts['heading']); ?></h2></div></header>
            <div class="tng-smart-rec__groups">
                <?php foreach ($groups as $label => $group): ?>
                    <div class="tng-smart-rec__group"><h3><?php echo esc_html($label); ?></h3><div class="tng-smart-rec__grid">
                    <?php foreach ($group as $item): ?><a class="tng-smart-rec__card" href="<?php echo esc_url($item['url']); ?>">
                        <div class="tng-smart-rec__media"><img src="<?php echo esc_url($item['image']); ?>" alt="<?php echo esc_attr($item['title']); ?>" loading="lazy"></div>
                        <div class="tng-smart-rec__body"><span class="tng-smart-rec__type"><?php echo esc_html($item['type']); ?></span><div class="tng-smart-rec__title"><?php echo esc_html($item['title']); ?></div><div class="tng-smart-rec__reason"><?php echo esc_html($item['reason']); ?></div></div>
                    </a><?php endforeach; ?>
                    </div></div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php return (string)ob_get_clean();
    }

    private function entity_for_post(int $post_id): string {
        $posts = get_posts(['post_type'=>self::ENTITY_TYPE,'post_status'=>['publish','draft','private'],'posts_per_page'=>1,'fields'=>'ids','meta_query'=>['relation'=>'OR',['key'=>'_tng_entity_payload','value'=>'"traveler_activity_id":'.$post_id,'compare'=>'LIKE'],['key'=>'_tng_entity_payload','value'=>'"post_id":'.$post_id,'compare'=>'LIKE']]]);
        if ($posts) return (string)get_post_meta((int)$posts[0], '_tng_entity_id', true);
        $direct = (string)get_post_meta($post_id, '_tng_entity_id', true);
        return $direct;
    }

    private function recommend(string $root, int $depth, int $limit): array {
        $entities = $this->entities();
        if (!isset($entities[$root])) return [];
        $adj = [];
        foreach ($entities as $entity) foreach ($entity['relationships'] as $rel) {
            if (!is_array($rel)) continue;
            $source = (string)($rel['source_entity_id'] ?? $entity['id']);
            $target = (string)($rel['target_entity_id'] ?? '');
            $type = sanitize_key((string)($rel['type'] ?? 'related_to'));
            if ($source === '' || $target === '') continue;
            $adj[$source][] = [$target,$type,'out']; $adj[$target][] = [$source,$type,'in'];
        }
        $seen = [$root=>true]; $queue = [[$root,0,[]]]; $results = [];
        while ($queue && count($results) < $limit) {
            [$current,$level,$path] = array_shift($queue);
            if ($level >= $depth) continue;
            foreach ($adj[$current] ?? [] as [$next,$type,$direction]) {
                if (isset($seen[$next]) || !isset($entities[$next])) continue;
                $seen[$next] = true; $next_path = array_merge($path, [$type]);
                $entity = $entities[$next]; $url = $this->entity_url($entity);
                if ($url !== '') $results[] = ['title'=>$entity['title'],'type'=>$entity['type'],'url'=>$url,'image'=>$this->entity_image($entity),'reason'=>$this->reason($next_path,$direction)];
                $queue[] = [$next,$level+1,$next_path];
                if (count($results) >= $limit) break;
            }
        }
        return $results;
    }

    private function entities(): array {
        $posts = get_posts(['post_type'=>self::ENTITY_TYPE,'post_status'=>['publish','draft','private'],'posts_per_page'=>500]); $out=[];
        foreach ($posts as $post) { $id=(string)get_post_meta($post->ID,'_tng_entity_id',true); if($id==='')continue; $out[$id]=['id'=>$id,'title'=>$post->post_title ?: $id,'type'=>(string)get_post_meta($post->ID,'_tng_entity_type',true) ?: 'place','payload'=>(array)get_post_meta($post->ID,'_tng_entity_payload',true),'relationships'=>(array)get_post_meta($post->ID,'_tng_entity_relationships',true)]; }
        return $out;
    }

    private function entity_url(array $entity): string {
        $payload=$entity['payload']; foreach(['traveler_activity_id','post_id','wp_post_id'] as $key){$id=absint($payload[$key]??0); if($id && get_post_status($id)==='publish') return (string)get_permalink($id);} $url=esc_url_raw((string)($payload['url']??$payload['permalink']??'')); return $url;
    }

    private function entity_image(array $entity): string {
        $payload=$entity['payload']; foreach(['traveler_activity_id','post_id','wp_post_id'] as $key){$id=absint($payload[$key]??0); if($id){$image=get_the_post_thumbnail_url($id,'large'); if($image)return $image;}} foreach(['image','image_url','featured_image'] as $key){if(!empty($payload[$key]))return esc_url_raw((string)$payload[$key]);} return TNG_OS_URL.'assets/frontend/recommendations-placeholder.svg';
    }

    private function reason(array $path, string $direction): string {
        $labels=['held_at'=>'At this venue','located_in'=>'In the same area','near'=>'Nearby','part_of'=>'Part of the same destination','contains'=>'Contained within this destination','starts_at'=>'Connected starting point','ends_at'=>'Connected endpoint','connects_to'=>'Connected experience','featured_in'=>'Featured together','serves'=>'Food and drink option','offers'=>'Available here','operated_by'=>'Related operator','related_to'=>'Related experience'];
        $last=(string)end($path); $base=$labels[$last]??'Connected through the destination graph'; return count($path)>1 ? $base.' · '.count($path).' graph hops away' : $base;
    }

    private function group_label(string $type): string {
        $map=['restaurant'=>'Food & Drink','food'=>'Food & Drink','coffee_shop'=>'Food & Drink','hotel'=>'Places to Stay','lodging'=>'Places to Stay','rental'=>'Places to Stay','trail'=>'Outdoor Adventures','waterfall'=>'Outdoor Adventures','park'=>'Outdoor Adventures','event'=>'Events','concert'=>'Events','venue'=>'Venues','shop'=>'Shopping','museum'=>'Things to Do','historic_site'=>'Things to Do']; return $map[$type]??'More to Explore';
    }

    public function register_wpbakery(): void {
        if (!function_exists('vc_map')) return;
        vc_map(['name'=>'TN Smart Recommendations','base'=>'tng_smart_recommendations','category'=>'TN Game OS','icon'=>'dashicons dashicons-networking','description'=>'Graph-powered related places and experiences.','params'=>[['type'=>'textfield','heading'=>'Heading','param_name'=>'heading','value'=>'Recommended for you'],['type'=>'textfield','heading'=>'Canonical entity ID (optional)','param_name'=>'entity'],['type'=>'dropdown','heading'=>'Graph depth','param_name'=>'depth','value'=>['1 hop'=>'1','2 hops'=>'2','3 hops'=>'3']]]]);
    }
}
