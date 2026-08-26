<?php
namespace TNG_OS\Modules\Frontend;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;
use WP_Post;
use WP_Query;

if (!defined('ABSPATH')) exit;

final class Recommendations implements Module_Interface {
    private Container $container;
    private array $service_terms = [
        'trails'      => ['hiking-trails', 'trails'],
        'food'        => ['food-and-drink', 'restaurants', 'restaurant'],
        'concerts'    => ['concerts'],
        'shops'       => ['shops', 'shopping'],
        'history'     => ['historic-sites', 'history'],
        'waterfalls'  => ['waterfalls'],
        'campgrounds' => ['campgrounds', 'camping'],
        'lodging'     => ['lodging'],
        'events'      => ['events'],
        'scenic'      => ['scenic-views', 'scenic'],
    ];

    public function id(): string {
        return 'unified_recommendations';
    }

    public function register(Container $container): void {
        $this->container = $container;

        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets'], 60);
        add_shortcode('tng_recommendations', [$this, 'shortcode']);
        add_shortcode('tn_game_recommendations', [$this, 'shortcode']);
        add_action('vc_before_init', [$this, 'register_wpbakery']);

        add_filter('body_class', [$this, 'body_class']);
    }

    public function boot(Container $container): void {}

    public function body_class(array $classes): array {
        $classes[] = 'tng-os-recommendations-enabled';
        return $classes;
    }

    public function enqueue_assets(): void {
        if (is_admin()) return;

        wp_enqueue_style(
            'tng-os-recommendations',
            TNG_OS_URL . 'assets/frontend/recommendations.css',
            [],
            TNG_OS_VERSION
        );

        wp_enqueue_script(
            'tng-os-recommendations',
            TNG_OS_URL . 'assets/frontend/recommendations.js',
            [],
            TNG_OS_VERSION,
            true
        );

        wp_localize_script('tng-os-recommendations', 'TNGRecommendations', [
            'heading' => 'Recommended for you',
            'replacementEnabled' => true,
        ]);
    }

    private function activity_taxonomy(): string {
        $registry = $this->container->get('services');

        if ($registry && is_callable([$registry, 'taxonomy'])) {
            $taxonomy = (string)$registry->taxonomy();
            if ($taxonomy !== '') return $taxonomy;
        }

        foreach (['st_activity_type', 'activity_type', 'st_activity_types'] as $taxonomy) {
            if (taxonomy_exists($taxonomy)) return $taxonomy;
        }

        foreach (get_object_taxonomies('st_activity', 'objects') as $taxonomy) {
            if (!empty($taxonomy->hierarchical)) return $taxonomy->name;
        }

        return '';
    }

    private function term_ids(array $slugs): array {
        $taxonomy = $this->activity_taxonomy();
        if (!$taxonomy) return [];

        $ids = [];
        foreach ($slugs as $slug) {
            $term = get_term_by('slug', $slug, $taxonomy);
            if ($term && !is_wp_error($term)) {
                $ids[] = (int)$term->term_id;
            }
        }

        return array_values(array_unique($ids));
    }

    private function all_virtual_term_ids(): array {
        $ids = [];
        foreach ($this->service_terms as $slugs) {
            $ids = array_merge($ids, $this->term_ids($slugs));
        }
        return array_values(array_unique(array_map('absint', $ids)));
    }

    private function activity_posts_for_service(string $service, int $limit): array {
        $taxonomy = $this->activity_taxonomy();
        $term_ids = $this->term_ids($this->service_terms[$service] ?? []);

        $args = [
            'post_type' => 'st_activity',
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'orderby' => 'menu_order date',
            'order' => 'DESC',
            'no_found_rows' => true,
        ];

        if ($taxonomy && $term_ids) {
            $args['tax_query'] = [[
                'taxonomy' => $taxonomy,
                'field' => 'term_id',
                'terms' => $term_ids,
            ]];
        } elseif ($service === 'food') {
            $args['meta_query'] = [
                'relation' => 'OR',
                ['key' => '_tng_food_enabled', 'value' => '1'],
                ['key' => '_tng_content_service', 'value' => 'food'],
            ];
        } else {
            return [];
        }

        return (new WP_Query($args))->posts;
    }

    private function regular_activity_posts(int $limit): array {
        $taxonomy = $this->activity_taxonomy();
        $exclude_terms = $this->all_virtual_term_ids();

        $args = [
            'post_type' => 'st_activity',
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'orderby' => 'menu_order date',
            'order' => 'DESC',
            'no_found_rows' => true,
        ];

        if ($taxonomy && $exclude_terms) {
            $args['tax_query'] = [[
                'taxonomy' => $taxonomy,
                'field' => 'term_id',
                'terms' => $exclude_terms,
                'operator' => 'NOT IN',
            ]];
        }

        return (new WP_Query($args))->posts;
    }

    private function traveler_posts(array $post_types, int $limit): array {
        foreach ($post_types as $post_type) {
            if (!post_type_exists($post_type)) continue;

            $posts = get_posts([
                'post_type' => $post_type,
                'post_status' => 'publish',
                'posts_per_page' => $limit,
                'orderby' => 'menu_order date',
                'order' => 'DESC',
                'suppress_filters' => false,
            ]);

            if ($posts) return $posts;
        }

        return [];
    }

    private function count_query(array $args): int {
        $args['posts_per_page']=1; $args['fields']='ids'; $args['no_found_rows']=false;
        return (int)(new WP_Query($args))->found_posts;
    }

    private function count_activity_service(string $service): int {
        $taxonomy=$this->activity_taxonomy(); $ids=$this->term_ids($this->service_terms[$service]??[]);
        $args=['post_type'=>'st_activity','post_status'=>'publish'];
        if($taxonomy&&$ids){$args['tax_query']=[['taxonomy'=>$taxonomy,'field'=>'term_id','terms'=>$ids]];}
        elseif($service==='food'){$args['meta_query']=['relation'=>'OR',['key'=>'_tng_food_enabled','value'=>'1'],['key'=>'_tng_content_service','value'=>'food']];}
        else{return 0;}
        return $this->count_query($args);
    }

    private function count_regular_activities(): int {
        $args=['post_type'=>'st_activity','post_status'=>'publish']; $taxonomy=$this->activity_taxonomy(); $exclude=$this->all_virtual_term_ids();
        if($taxonomy&&$exclude){$args['tax_query']=[['taxonomy'=>$taxonomy,'field'=>'term_id','terms'=>$exclude,'operator'=>'NOT IN']];}
        return $this->count_query($args);
    }

    private function count_traveler_posts(array $types): int {
        foreach($types as $type){if(post_type_exists($type)){ $c=wp_count_posts($type); return (int)($c->publish??0); }} return 0;
    }

    private function definitions(): array {
        return [
            'trails' => [
                'label' => 'Trails',
                'icon' => '🥾',
                'posts' => $this->activity_posts_for_service('trails', 6),
                'total' => $this->count_activity_service('trails'),
                'archive' => $this->activity_term_archive('trails'),
                'kind' => 'activity',
            ],
            'food' => [
                'label' => 'Food & Drink',
                'icon' => '🍽',
                'posts' => $this->activity_posts_for_service('food', 6),
                'total' => $this->count_activity_service('food'),
                'archive' => home_url('/food-drink/'),
                'kind' => 'food',
            ],
            'activities' => [
                'label' => 'Activities',
                'icon' => '🧭',
                'posts' => $this->regular_activity_posts(6),
                'total' => $this->count_regular_activities(),
                'archive' => $this->post_type_archive(['st_activity']),
                'kind' => 'activity',
            ],
            'hotels' => [
                'label' => 'Hotels',
                'icon' => '🏨',
                'posts' => $this->traveler_posts(['st_hotel', 'hotel'], 6),
                'total' => $this->count_traveler_posts(['st_hotel','hotel']),
                'archive' => $this->post_type_archive(['st_hotel', 'hotel']),
                'kind' => 'hotel',
            ],
            'tours' => [
                'label' => 'Tours',
                'icon' => '🚌',
                'posts' => $this->traveler_posts(['st_tours', 'st_tour', 'tour'], 6),
                'total' => $this->count_traveler_posts(['st_tours','st_tour','tour']),
                'archive' => $this->post_type_archive(['st_tours', 'st_tour', 'tour']),
                'kind' => 'tour',
            ],
            'rentals' => [
                'label' => 'Rentals',
                'icon' => '🏡',
                'posts' => $this->traveler_posts(['st_rental', 'rental'], 6),
                'total' => $this->count_traveler_posts(['st_rental','rental']),
                'archive' => $this->post_type_archive(['st_rental', 'rental']),
                'kind' => 'rental',
            ],
            'cars' => [
                'label' => 'Cars',
                'icon' => '🚗',
                'posts' => $this->traveler_posts(['st_cars', 'st_car', 'car'], 6),
                'total' => $this->count_traveler_posts(['st_cars','st_car','car']),
                'archive' => $this->post_type_archive(['st_cars', 'st_car', 'car']),
                'kind' => 'car',
            ],
            'concerts' => [
                'label' => 'Concerts',
                'icon' => '🎵',
                'posts' => $this->activity_posts_for_service('concerts', 6),
                'total' => $this->count_activity_service('concerts'),
                'archive' => $this->activity_term_archive('concerts'),
                'kind' => 'activity',
            ],
            'shops' => [
                'label' => 'Shops',
                'icon' => '🛍',
                'posts' => $this->activity_posts_for_service('shops', 6),
                'total' => $this->count_activity_service('shops'),
                'archive' => $this->activity_term_archive('shops'),
                'kind' => 'activity',
            ],
            'history' => [
                'label' => 'Historic Sites',
                'icon' => '🏛',
                'posts' => $this->activity_posts_for_service('history', 6),
                'total' => $this->count_activity_service('history'),
                'archive' => $this->activity_term_archive('history'),
                'kind' => 'activity',
            ],
            'campgrounds' => [
                'label' => 'Campgrounds',
                'icon' => '⛺',
                'posts' => $this->activity_posts_for_service('campgrounds', 6),
                'total' => $this->count_activity_service('campgrounds'),
                'archive' => $this->activity_term_archive('campgrounds'),
                'kind' => 'activity',
            ],
            'waterfalls' => [
                'label' => 'Waterfalls',
                'icon' => '💧',
                'posts' => $this->activity_posts_for_service('waterfalls', 6),
                'total' => $this->count_activity_service('waterfalls'),
                'archive' => $this->activity_term_archive('waterfalls'),
                'kind' => 'activity',
            ],
            'scenic' => [
                'label' => 'Scenic Views',
                'icon' => '🌄',
                'posts' => $this->activity_posts_for_service('scenic', 6),
                'total' => $this->count_activity_service('scenic'),
                'archive' => $this->activity_term_archive('scenic'),
                'kind' => 'activity',
            ],
        ];
    }

    private function post_type_archive(array $post_types): string {
        foreach ($post_types as $post_type) {
            if (!post_type_exists($post_type)) continue;
            $url = get_post_type_archive_link($post_type);
            if ($url) return $url;
        }
        return home_url('/');
    }

    private function activity_term_archive(string $service): string {
        $taxonomy = $this->activity_taxonomy();
        $ids = $this->term_ids($this->service_terms[$service] ?? []);

        if ($taxonomy && $ids) {
            $term = get_term($ids[0], $taxonomy);
            if ($term && !is_wp_error($term)) {
                $url = get_term_link($term);
                if (!is_wp_error($url)) return $url;
            }
        }

        return $this->post_type_archive(['st_activity']);
    }

    private function meta_first(int $post_id, array $keys, string $default = ''): string {
        foreach ($keys as $key) {
            $value = get_post_meta($post_id, $key, true);
            if (is_scalar($value) && trim((string)$value) !== '') {
                return trim((string)$value);
            }
        }
        return $default;
    }

    private function location(int $post_id): string {
        $location = $this->meta_first($post_id, [
            '_tng_content_community',
            '_tng_food_address',
            'address',
            'location',
            'st_google_map',
        ]);

        if ($location && strpos($location, '{') === 0) {
            $decoded = json_decode($location, true);
            if (is_array($decoded)) {
                $location = (string)($decoded['address'] ?? $decoded['name'] ?? '');
            }
        }

        if ($location) return wp_strip_all_tags($location);

        foreach (get_object_taxonomies(get_post_type($post_id), 'names') as $taxonomy) {
            if (
                stripos($taxonomy, 'location') === false &&
                stripos($taxonomy, 'destination') === false
            ) continue;

            $terms = get_the_terms($post_id, $taxonomy);
            if ($terms && !is_wp_error($terms)) {
                return (string)$terms[0]->name;
            }
        }

        return '';
    }

    private function rating(int $post_id): array {
        $rating = $this->meta_first($post_id, [
            '_tng_food_rating',
            'rate_review',
            'review_stars',
            'avg_rating',
        ], '0');

        $reviews = $this->meta_first($post_id, [
            '_tng_food_review_count',
            'review_count',
        ], (string)get_comments_number($post_id));

        return [(float)$rating, (int)$reviews];
    }

    private function price(int $post_id, string $kind): string {
        if ($kind === 'food') {
            return $this->meta_first($post_id, [
                '_tng_food_price_range',
                'price_range',
            ], '$$');
        }

        $price = $this->meta_first($post_id, [
            'min_price',
            'price',
            'adult_price',
            '_price',
            'sale_price',
        ]);

        if ($price === '' || (float)$price <= 0) {
            return in_array($kind, ['activity', 'tour'], true) ? 'Free' : '';
        }

        if (is_numeric($price)) {
            return html_entity_decode(strip_tags(wc_price((float)$price)));
        }

        return wp_strip_all_tags($price);
    }

    private function image(int $post_id): string {
        $image = get_the_post_thumbnail_url($post_id, 'large');
        if ($image) return $image;

        $gallery = get_post_meta($post_id, 'gallery', true);
        if (is_array($gallery) && !empty($gallery[0])) {
            $image = wp_get_attachment_image_url(absint($gallery[0]), 'large');
            if ($image) return $image;
        }

        if (is_string($gallery)) {
            $ids = array_filter(array_map('absint', preg_split('/[,\s]+/', $gallery)));
            if ($ids) {
                $image = wp_get_attachment_image_url(reset($ids), 'large');
                if ($image) return $image;
            }
        }

        return TNG_OS_URL . 'assets/frontend/recommendations-placeholder.svg';
    }

    private function subtitle(int $post_id, string $kind): string {
        if ($kind === 'food') {
            return $this->meta_first($post_id, [
                '_tng_food_cuisine',
                'cuisine_types',
            ], 'Food & Drink');
        }

        $post_type = get_post_type($post_id);
        $object = get_post_type_object($post_type);
        return $object ? (string)$object->labels->singular_name : 'Experience';
    }

    private function card(WP_Post $post, string $kind): string {
        $post_id = (int)$post->ID;
        [$rating, $reviews] = $this->rating($post_id);
        $location = $this->location($post_id);
        $price = $this->price($post_id, $kind);
        $subtitle = $this->subtitle($post_id, $kind);

        ob_start();
        ?>
        <article class="tng-rec-card">
            <a class="tng-rec-card-media" href="<?php echo esc_url(get_permalink($post_id)); ?>">
                <img src="<?php echo esc_url($this->image($post_id)); ?>" alt="<?php echo esc_attr(get_the_title($post_id)); ?>" loading="lazy">
                <span class="tng-rec-card-type"><?php echo esc_html($subtitle); ?></span>
                <span class="tng-rec-card-heart" aria-hidden="true">♡</span>
            </a>
            <div class="tng-rec-card-content">
                <h3><a href="<?php echo esc_url(get_permalink($post_id)); ?>"><?php echo esc_html(get_the_title($post_id)); ?></a></h3>

                <?php if ($location !== ''): ?>
                    <p class="tng-rec-card-location"><?php echo esc_html($location); ?></p>
                <?php endif; ?>

                <div class="tng-rec-card-rating">
                    <span>★ <?php echo esc_html($rating > 0 ? number_format_i18n($rating, 1) : 'New'); ?></span>
                    <small><?php echo esc_html($reviews); ?> <?php echo $reviews === 1 ? 'review' : 'reviews'; ?></small>
                </div>

                <div class="tng-rec-card-footer">
                    <strong><?php echo esc_html($price); ?></strong>
                    <span>View details</span>
                </div>
            </div>
        </article>
        <?php
        return (string)ob_get_clean();
    }

    public function render_widget(array $atts = []): string {
        $atts = shortcode_atts([
            'heading' => 'Recommended for you',
            'eyebrow' => 'DISCOVER THE AREA',
        ], $atts, 'tng_recommendations');
        $definitions = array_filter(
            $this->definitions(),
            static fn(array $definition): bool => !empty($definition['posts'])
        );

        if (!$definitions) return '';

        $active = array_key_first($definitions);
        $id = 'tng-rec-' . wp_generate_uuid4();

        ob_start();
        ?>
        <section class="tng-recommendations" id="<?php echo esc_attr($id); ?>" data-tng-recommendations>
            <div class="tng-recommendations-inner">
                <header class="tng-recommendations-heading">
                    <span class="tng-recommendations-eyebrow"><?php echo esc_html($atts['eyebrow']); ?></span>
                    <h2><?php echo esc_html($atts['heading']); ?></h2>
                </header>

                <div class="tng-recommendations-tabs-wrap">
                    <button class="tng-rec-scroll tng-rec-scroll-left" type="button" aria-label="Scroll categories left">‹</button>
                    <div class="tng-recommendations-tabs" role="tablist" aria-label="Recommendation categories">
                        <?php foreach ($definitions as $key => $definition): ?>
                            <button
                                type="button"
                                class="tng-recommendations-tab <?php echo $key === $active ? 'is-active' : ''; ?>"
                                role="tab"
                                aria-selected="<?php echo $key === $active ? 'true' : 'false'; ?>"
                                aria-controls="<?php echo esc_attr($id . '-panel-' . $key); ?>"
                                data-tng-rec-tab="<?php echo esc_attr($key); ?>"
                            >
                                <span aria-hidden="true"><?php echo esc_html($definition['icon']); ?></span>
                                <?php echo esc_html($definition['label']); ?>
                                <small><?php echo esc_html(number_format_i18n((int)($definition['total'] ?? count($definition['posts'])))); ?></small>
                            </button>
                        <?php endforeach; ?>
                    </div>
                    <button class="tng-rec-scroll tng-rec-scroll-right" type="button" aria-label="Scroll categories right">›</button>
                </div>

                <div class="tng-recommendations-panels">
                    <?php foreach ($definitions as $key => $definition): ?>
                        <div
                            class="tng-recommendations-panel <?php echo $key === $active ? 'is-active' : ''; ?>"
                            id="<?php echo esc_attr($id . '-panel-' . $key); ?>"
                            role="tabpanel"
                            data-tng-rec-panel="<?php echo esc_attr($key); ?>"
                            <?php echo $key === $active ? '' : 'hidden'; ?>
                        >
                            <div class="tng-recommendations-grid">
                                <?php
                                foreach ($definition['posts'] as $post) {
                                    echo $this->card($post, $definition['kind']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                                }
                                ?>
                            </div>

                            <div class="tng-recommendations-view-all">
                                <a href="<?php echo esc_url($definition['archive']); ?>">
                                    View all <?php echo esc_html($definition['label']); ?>
                                    <span aria-hidden="true">→</span>
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
        <?php
        return (string)ob_get_clean();
    }

    public function shortcode(array $atts = []): string {
        return $this->render_widget($atts);
    }


    public function register_wpbakery(): void {
        if (!function_exists('vc_map')) return;

        vc_map([
            'name' => 'TN Game Recommendations',
            'base' => 'tng_recommendations',
            'category' => 'TN Game OS',
            'icon' => 'dashicons dashicons-star-filled',
            'description' => 'Unified recommendations for trails, food, lodging, tours and more.',
            'params' => [
                [
                    'type' => 'textfield',
                    'heading' => 'Heading',
                    'param_name' => 'heading',
                    'value' => 'Recommended for you',
                ],
            ],
        ]);
    }
}
