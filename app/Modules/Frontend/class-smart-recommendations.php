<?php
namespace TNG_OS\Modules\Frontend;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class Smart_Recommendations implements Module_Interface {
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
        wp_add_inline_style('tng-smart-recommendations', '.tng-smart-rec{margin:36px 0}.tng-smart-rec__head{display:flex;justify-content:space-between;gap:16px;align-items:end;margin-bottom:16px}.tng-smart-rec__eyebrow{display:block;font-size:12px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#b45309}.tng-smart-rec h2{margin:4px 0 0}.tng-smart-rec__groups{display:grid;gap:26px}.tng-smart-rec__group h3{margin:0 0 12px}.tng-smart-rec__grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}.tng-smart-rec__card{display:block;position:relative;background:#fff;border:1px solid #e2e8f0;border-radius:14px;overflow:hidden;text-decoration:none;color:inherit;box-shadow:0 2px 10px rgba(15,23,42,.06);transition:.18s ease}.tng-smart-rec__card:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(15,23,42,.12)}.tng-smart-rec__score{position:absolute;top:10px;right:10px;z-index:2;background:rgba(15,23,42,.9);color:#fff;border-radius:999px;padding:5px 9px;font-size:12px;font-weight:700}.tng-smart-rec__media{aspect-ratio:16/9;background:#e2e8f0;overflow:hidden}.tng-smart-rec__media img{width:100%;height:100%;object-fit:cover}.tng-smart-rec__body{padding:14px}.tng-smart-rec__type{font-size:12px;text-transform:uppercase;letter-spacing:.08em;color:#64748b}.tng-smart-rec__title{font-size:18px;font-weight:700;margin:5px 0}.tng-smart-rec__reason{font-size:13px;color:#475569}.tng-smart-rec__why{display:flex;flex-wrap:wrap;gap:6px;margin-top:10px}.tng-smart-rec__why span{background:#f1f5f9;border-radius:999px;padding:4px 8px;font-size:11px;color:#334155}.tng-smart-rec__distance{font-weight:600;color:#0f766e}@media(max-width:900px){.tng-smart-rec__grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:620px){.tng-smart-rec__grid{grid-template-columns:1fr}.tng-smart-rec__head{display:block}}');
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
        $atts = shortcode_atts([
            'entity' => '', 'post_id' => 0, 'heading' => 'Recommended for you',
            'limit' => 12, 'depth' => 2, 'types' => '',
        ], $atts, 'tng_smart_recommendations');

        $engine = $this->container->get('recommendation_engine');
        if (!$engine || !is_callable([$engine, 'recommend'])) {
            $this->rendering = false;
            return '';
        }

        $entity = sanitize_text_field((string)$atts['entity']);
        $post_id = absint($atts['post_id']) ?: get_the_ID();
        if ($entity === '' && $post_id && is_callable([$engine, 'entity_for_post'])) {
            $entity = (string)$engine->entity_for_post($post_id);
        }
        $types = array_filter(array_map('trim', explode(',', (string)$atts['types'])));
        $items = $entity !== '' ? $engine->recommend($entity, [
            'depth' => max(1, min(3, absint($atts['depth']))),
            'limit' => max(1, min(24, absint($atts['limit']))),
            'types' => $types,
            'require_url' => true,
        ]) : [];
        $this->rendering = false;
        if (!$items) return '';

        $groups = [];
        foreach ($items as $item) $groups[$this->group_label((string)$item['type'])][] = $item;
        ob_start(); ?>
        <section class="tng-smart-rec" data-root-entity="<?php echo esc_attr($entity); ?>">
            <header class="tng-smart-rec__head"><div><span class="tng-smart-rec__eyebrow">Powered by the destination graph</span><h2><?php echo esc_html((string)$atts['heading']); ?></h2></div></header>
            <div class="tng-smart-rec__groups">
                <?php foreach ($groups as $label => $group): ?>
                    <div class="tng-smart-rec__group"><h3><?php echo esc_html($label); ?></h3><div class="tng-smart-rec__grid">
                    <?php foreach ($group as $item): ?>
                        <a class="tng-smart-rec__card" href="<?php echo esc_url((string)$item['url']); ?>">
                            <span class="tng-smart-rec__score" title="Recommendation score">Score <?php echo esc_html((string)$item['score']); ?></span>
                            <div class="tng-smart-rec__media"><img src="<?php echo esc_url((string)$item['image']); ?>" alt="<?php echo esc_attr((string)$item['title']); ?>" loading="lazy"></div>
                            <div class="tng-smart-rec__body">
                                <span class="tng-smart-rec__type"><?php echo esc_html((string)$item['type']); ?></span>
                                <div class="tng-smart-rec__title"><?php echo esc_html((string)$item['title']); ?></div>
                                <div class="tng-smart-rec__reason"><?php echo esc_html((string)$item['primary_reason']); ?><?php if ($item['distance_miles'] !== null): ?> · <span class="tng-smart-rec__distance"><?php echo esc_html(number_format_i18n((float)$item['distance_miles'], 1)); ?> miles</span><?php endif; ?></div>
                                <div class="tng-smart-rec__why" aria-label="Why this is recommended">
                                    <?php foreach (array_slice((array)$item['reasons'], 0, 4) as $reason): ?><span><?php echo esc_html((string)$reason); ?></span><?php endforeach; ?>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                    </div></div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php return (string)ob_get_clean();
    }

    private function group_label(string $type): string {
        $map = [
            'restaurant'=>'Food & Drink','food'=>'Food & Drink','coffee_shop'=>'Food & Drink','brewery'=>'Food & Drink',
            'hotel'=>'Places to Stay','lodging'=>'Places to Stay','rental'=>'Places to Stay','cabin'=>'Places to Stay','campground'=>'Places to Stay',
            'trail'=>'Outdoor Adventures','waterfall'=>'Outdoor Adventures','park'=>'Outdoor Adventures','overlook'=>'Outdoor Adventures','cave'=>'Outdoor Adventures',
            'event'=>'Events','concert'=>'Events','festival'=>'Events','venue'=>'Venues','shop'=>'Shopping','store'=>'Shopping',
            'museum'=>'Things to Do','historic_site'=>'Things to Do','visitor_center'=>'Things to Do','quest'=>'TN Game Challenges','checkpoint'=>'TN Game Challenges',
        ];
        return $map[$type] ?? 'More to Explore';
    }

    public function register_wpbakery(): void {
        if (!function_exists('vc_map')) return;
        vc_map([
            'name'=>'TN Smart Recommendations','base'=>'tng_smart_recommendations','category'=>'TN Game OS',
            'icon'=>'dashicons dashicons-networking','description'=>'Scored graph-powered recommendations with explanations.',
            'params'=>[
                ['type'=>'textfield','heading'=>'Heading','param_name'=>'heading','value'=>'Recommended for you'],
                ['type'=>'textfield','heading'=>'Canonical entity ID (optional)','param_name'=>'entity'],
                ['type'=>'dropdown','heading'=>'Graph depth','param_name'=>'depth','value'=>['1 hop'=>'1','2 hops'=>'2','3 hops'=>'3']],
                ['type'=>'textfield','heading'=>'Entity types (comma separated)','param_name'=>'types','description'=>'Example: restaurant,trail,hotel'],
                ['type'=>'textfield','heading'=>'Maximum recommendations','param_name'=>'limit','value'=>'12'],
            ],
        ]);
    }
}
