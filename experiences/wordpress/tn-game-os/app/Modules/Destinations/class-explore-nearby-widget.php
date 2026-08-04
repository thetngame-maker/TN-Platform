<?php
namespace TNG_OS\Modules\Destinations;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;
use WP_Post;

if (!defined('ABSPATH')) exit;

final class Explore_Nearby_Widget implements Module_Interface {
    private const NONCE = 'tng_explore_nearby_settings';

    public function id(): string { return 'explore_nearby_widget'; }

    public function register(Container $container): void {
        $container->set('explore_nearby_widget', $this);
        add_shortcode('tng_explore_nearby', [$this, 'shortcode']);
        add_filter('the_content', [$this, 'append_to_content'], 45);
        add_action('add_meta_boxes', [$this, 'meta_boxes']);
        add_action('save_post', [$this, 'save'], 140, 2);
    }

    public function boot(Container $container): void {}

    public function meta_boxes(): void {
        foreach ($this->post_types() as $type) {
            add_meta_box('tng-explore-nearby-settings', 'Explore Nearby', [$this, 'meta_box'], $type, 'side', 'default');
        }
    }

    public function meta_box(WP_Post $post): void {
        $enabled = get_post_meta($post->ID, '_tng_explore_nearby_enabled', true);
        $enabled = $enabled === '' ? '1' : $enabled;
        $title = (string)get_post_meta($post->ID, '_tng_explore_nearby_title', true);
        $scenario = (string)get_post_meta($post->ID, '_tng_explore_nearby_scenario', true);
        if (!$scenario) $scenario = 'smart';
        wp_nonce_field(self::NONCE, 'tng_explore_nearby_nonce');
        ?>
        <p><label><input type="checkbox" name="tng_explore_nearby_enabled" value="1" <?php checked($enabled, '1'); ?>> Show automatically on this listing</label></p>
        <p><label for="tng_explore_nearby_title"><strong>Section title</strong></label><input class="widefat" id="tng_explore_nearby_title" name="tng_explore_nearby_title" value="<?php echo esc_attr($title); ?>" placeholder="Explore nearby"></p>
        <p><label for="tng_explore_nearby_scenario"><strong>Recommendation focus</strong></label><select class="widefat" id="tng_explore_nearby_scenario" name="tng_explore_nearby_scenario">
            <?php foreach ($this->scenarios() as $value => $label): ?><option value="<?php echo esc_attr($value); ?>" <?php selected($scenario, $value); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?>
        </select></p>
        <p class="description">Use <code>[tng_explore_nearby]</code> in a page builder to place the section manually.</p>
        <?php
    }

    public function save(int $post_id, WP_Post $post): void {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;
        if (!in_array($post->post_type, $this->post_types(), true)) return;
        if (!isset($_POST['tng_explore_nearby_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['tng_explore_nearby_nonce'])), self::NONCE)) return;
        if (!current_user_can('edit_post', $post_id)) return;

        update_post_meta($post_id, '_tng_explore_nearby_enabled', isset($_POST['tng_explore_nearby_enabled']) ? '1' : '0');
        update_post_meta($post_id, '_tng_explore_nearby_title', sanitize_text_field(wp_unslash($_POST['tng_explore_nearby_title'] ?? '')));
        $scenario = sanitize_key(wp_unslash($_POST['tng_explore_nearby_scenario'] ?? 'smart'));
        if (!array_key_exists($scenario, $this->scenarios())) $scenario = 'smart';
        update_post_meta($post_id, '_tng_explore_nearby_scenario', $scenario);
    }

    public function append_to_content(string $content): string {
        if (is_admin() || !is_singular($this->post_types()) || !in_the_loop() || !is_main_query()) return $content;
        $post_id = get_queried_object_id();
        if (!$post_id || get_post_meta($post_id, '_tng_graph_excluded', true)) return $content;
        if (has_shortcode($content, 'tng_explore_nearby')) return $content;
        $enabled = get_post_meta($post_id, '_tng_explore_nearby_enabled', true);
        if ($enabled === '0') return $content;
        $title = (string)get_post_meta($post_id, '_tng_explore_nearby_title', true);
        $scenario = (string)get_post_meta($post_id, '_tng_explore_nearby_scenario', true);
        return $content . $this->render($post_id, $scenario ?: 'smart', 6, $title ?: 'Explore nearby');
    }

    public function shortcode(array $atts = []): string {
        $atts = shortcode_atts([
            'id' => 0,
            'scenario' => 'smart',
            'limit' => 6,
            'title' => 'Explore nearby',
        ], $atts, 'tng_explore_nearby');
        $post_id = absint($atts['id']) ?: get_the_ID();
        if (!$post_id) return '';
        return $this->render($post_id, sanitize_key($atts['scenario']), min(12, max(1, absint($atts['limit']))), sanitize_text_field($atts['title']));
    }

    private function render(int $post_id, string $scenario, int $limit, string $title): string {
        if (!class_exists(Smart_Recommendation_Engine::class)) return '';
        $scenario = array_key_exists($scenario, $this->scenarios()) ? $scenario : 'smart';
        $rows = $scenario === 'smart' ? $this->smart_mix($post_id, $limit) : Smart_Recommendation_Engine::recommend($post_id, $scenario, $limit);
        if (!$rows) return '';

        $subtitle = $scenario === 'smart'
            ? 'Ideas selected from nearby places, destination profiles, and the TN Game knowledge graph.'
            : ($this->scenarios()[$scenario] ?? 'Recommended places near this experience.');

        ob_start();
        ?>
        <section class="tng-explore-nearby" aria-labelledby="tng-explore-nearby-<?php echo (int)$post_id; ?>">
            <style>
                .tng-explore-nearby{max-width:1180px;margin:38px auto;padding:0 18px;box-sizing:border-box}.tng-en-head{display:flex;justify-content:space-between;gap:24px;align-items:end;margin-bottom:18px}.tng-en-kicker{color:#7040c5;font-size:12px;font-weight:800;letter-spacing:.18em;text-transform:uppercase}.tng-en-head h2{font-size:clamp(28px,4vw,42px);line-height:1.05;margin:7px 0;color:#17213f}.tng-en-head p{margin:0;color:#667085;max-width:650px}.tng-en-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}.tng-en-card{display:flex;flex-direction:column;min-width:0;background:#fff;border:1px solid #e2e5ec;border-radius:20px;overflow:hidden;box-shadow:0 12px 30px rgba(23,33,63,.07);transition:transform .18s ease,box-shadow .18s ease}.tng-en-card:hover{transform:translateY(-3px);box-shadow:0 17px 36px rgba(23,33,63,.12)}.tng-en-image{position:relative;display:block;aspect-ratio:16/10;background:linear-gradient(135deg,#20294c,#7543a4);overflow:hidden}.tng-en-image img{width:100%;height:100%;object-fit:cover;display:block}.tng-en-distance{position:absolute;left:12px;bottom:12px;background:rgba(23,33,63,.9);color:#fff;border-radius:999px;padding:6px 10px;font-size:12px;font-weight:800}.tng-en-body{display:flex;flex-direction:column;flex:1;padding:17px}.tng-en-type{color:#7040c5;font-size:11px;font-weight:800;letter-spacing:.14em;text-transform:uppercase}.tng-en-card h3{font-size:21px;line-height:1.18;margin:8px 0 10px;color:#17213f}.tng-en-card h3 a{color:inherit;text-decoration:none}.tng-en-reason{color:#667085;font-size:13px;line-height:1.45;margin:0 0 17px}.tng-en-footer{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-top:auto}.tng-en-score{color:#7040c5;font-weight:800;font-size:12px}.tng-en-link{display:inline-flex;align-items:center;justify-content:center;background:#7c4ce0;color:#fff!important;text-decoration:none!important;border-radius:11px;padding:10px 14px;font-weight:800;font-size:13px}.tng-en-placeholder{display:flex;align-items:center;justify-content:center;height:100%;color:#fff;font-size:36px}@media(max-width:900px){.tng-en-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:620px){.tng-explore-nearby{margin:28px auto;padding:0 14px}.tng-en-grid{grid-template-columns:1fr}.tng-en-head{display:block}.tng-en-head p{margin-top:8px}}
            </style>
            <header class="tng-en-head">
                <div><div class="tng-en-kicker">Destination intelligence</div><h2 id="tng-explore-nearby-<?php echo (int)$post_id; ?>"><?php echo esc_html($title); ?></h2><p><?php echo esc_html($subtitle); ?></p></div>
            </header>
            <div class="tng-en-grid">
                <?php foreach ($rows as $row):
                    $url = get_permalink($row['id']);
                    $image = get_the_post_thumbnail_url($row['id'], 'medium_large');
                    ?>
                    <article class="tng-en-card">
                        <a class="tng-en-image" href="<?php echo esc_url($url); ?>">
                            <?php if ($image): ?><img loading="lazy" src="<?php echo esc_url($image); ?>" alt="<?php echo esc_attr($row['title']); ?>"><?php else: ?><span class="tng-en-placeholder" aria-hidden="true">✦</span><?php endif; ?>
                            <span class="tng-en-distance"><?php echo esc_html($row['distance_label']); ?></span>
                        </a>
                        <div class="tng-en-body">
                            <div class="tng-en-type"><?php echo esc_html($this->type_label($row['type'])); ?></div>
                            <h3><a href="<?php echo esc_url($url); ?>"><?php echo esc_html($row['title']); ?></a></h3>
                            <p class="tng-en-reason"><?php echo esc_html($this->friendly_reason($row['reason'])); ?></p>
                            <div class="tng-en-footer"><span class="tng-en-score"><?php echo (int)$row['score']; ?>% match</span><a class="tng-en-link" href="<?php echo esc_url($url); ?>">Explore</a></div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
        <?php
        return (string)ob_get_clean();
    }

    private function smart_mix(int $post_id, int $limit): array {
        $priority = $this->smart_scenarios($post_id);
        $pool = [];
        foreach ($priority as $scenario) {
            foreach (Smart_Recommendation_Engine::recommend($post_id, $scenario, max(4, $limit)) as $row) {
                $id = (int)$row['id'];
                if (!isset($pool[$id]) || $row['score'] > $pool[$id]['score']) {
                    $row['scenario'] = $scenario;
                    $pool[$id] = $row;
                }
            }
        }
        $rows = array_values($pool);
        usort($rows, static fn($a, $b) => $b['score'] <=> $a['score'] ?: $a['distance'] <=> $b['distance']);
        return array_slice($rows, 0, $limit);
    }

    private function smart_scenarios(int $post_id): array {
        $type = get_post_type($post_id);
        if ($type === 'st_hotel' || $type === 'st_rental') return ['similar','family','food_after','adventure'];
        $profile = class_exists(Destination_AI_Profiles::class) ? Destination_AI_Profiles::profile($post_id) : [];
        $traits = strtolower((string)($profile['traits'] ?? ''));
        if (preg_match('/restaurant|food|bakery|coffee|cafe|dining/', $traits)) return ['similar','family','photography','adventure'];
        if (preg_match('/concert|music|event/', $traits)) return ['food_after','lodging','rainy_day','similar'];
        return ['similar','food_after','family','photography','rainy_day','lodging'];
    }

    private function friendly_reason(string $reason): string {
        $parts = array_filter(array_map('trim', explode('·', $reason)));
        $map = [
            'very close' => 'Very close to this stop',
            'nearby' => 'Conveniently nearby',
            'same experience type' => 'A similar kind of experience',
            'food or dining match' => 'A good food or drink pairing',
            'lodging match' => 'A nearby place to stay',
        ];
        $friendly = [];
        foreach ($parts as $part) {
            if (isset($map[$part])) $friendly[] = $map[$part];
            elseif (preg_match('/family score ([0-5])\/5/', $part, $m)) $friendly[] = 'Family suitability '.$m[1].'/5';
            elseif (preg_match('/rainy-day score ([0-5])\/5/', $part, $m)) $friendly[] = 'Rainy-day suitability '.$m[1].'/5';
            elseif (preg_match('/photography score ([0-5])\/5/', $part, $m)) $friendly[] = 'Photography potential '.$m[1].'/5';
            elseif (preg_match('/adventure score ([0-5])\/5/', $part, $m)) $friendly[] = 'Adventure level '.$m[1].'/5';
            elseif (preg_match('/shared trait/', $part)) $friendly[] = 'Matches this experience’s character';
        }
        return implode(' · ', array_slice(array_unique($friendly), 0, 3)) ?: 'Recommended from the TN Game destination network.';
    }

    private function type_label(string $type): string {
        $labels = ['activity'=>'Experience','lodging'=>'Lodging','tour'=>'Tour','rental'=>'Rental','sight'=>'Top sight','destination'=>'Destination'];
        return $labels[$type] ?? ucwords(str_replace('_', ' ', $type));
    }

    private function scenarios(): array {
        return ['smart'=>'Smart mix','similar'=>'Similar experiences','family'=>'Family-friendly','rainy_day'=>'Rainy-day alternatives','food_after'=>'Food & drink','lodging'=>'Nearby lodging','photography'=>'Photography','adventure'=>'More adventure'];
    }

    private function post_types(): array {
        return array_values(array_filter(['tng_destination','st_activity','st_hotel','st_tours','st_rental','top_sight'], 'post_type_exists'));
    }
}
