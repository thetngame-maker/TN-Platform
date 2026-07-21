<?php
namespace TNG_OS\Modules\Frontend;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;
use WP_Query;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) exit;

final class Discovery_Search implements Module_Interface {
    private Container $container;

    private array $categories = [
        'all' => ['label' => 'Everything', 'icon' => '✦'],
        'trails' => ['label' => 'Trails', 'icon' => '🥾', 'terms' => ['hiking-trails', 'trails']],
        'waterfalls' => ['label' => 'Waterfalls', 'icon' => '💧', 'terms' => ['waterfalls']],
        'food' => ['label' => 'Food & Drink', 'icon' => '🍽', 'terms' => ['food-and-drink', 'restaurants']],
        'events' => ['label' => 'Events', 'icon' => '📅', 'terms' => ['events', 'concerts']],
        'shops' => ['label' => 'Shops', 'icon' => '🛍', 'terms' => ['shops', 'shopping']],
        'history' => ['label' => 'History', 'icon' => '🏛', 'terms' => ['historic-sites', 'history']],
        'camping' => ['label' => 'Camping', 'icon' => '⛺', 'terms' => ['campgrounds', 'camping']],
        'scenic' => ['label' => 'Scenic', 'icon' => '🌄', 'terms' => ['scenic-views', 'scenic']],
        'lodging' => ['label' => 'Lodging', 'icon' => '🏡', 'post_types' => ['st_hotel', 'st_rental']],
    ];

    public function id(): string {
        return 'discovery_search';
    }

    public function register(Container $container): void {
        $this->container = $container;
        $container->set('discovery_search', $this);

        add_action('wp_enqueue_scripts', [$this, 'assets'], 65);
        add_shortcode('tng_discovery_search', [$this, 'shortcode']);
        add_shortcode('tn_game_search', [$this, 'shortcode']);
        add_action('rest_api_init', [$this, 'routes']);
        add_filter('body_class', [$this, 'body_class']);
    }

    public function boot(Container $container): void {}

    public function body_class(array $classes): array {
        if ($this->auto_replace_enabled()) $classes[] = 'tng-discovery-auto-replace';
        return $classes;
    }

    private function auto_replace_enabled(): bool {
        $settings = get_option('tng_os_settings', []);
        return !array_key_exists('discovery_auto_replace', $settings) || !empty($settings['discovery_auto_replace']);
    }

    public function assets(): void {
        if (is_admin()) return;

        wp_enqueue_style(
            'tng-discovery-search',
            TNG_OS_URL . 'assets/frontend/discovery-search.css',
            [],
            TNG_OS_VERSION
        );

        wp_enqueue_script(
            'tng-discovery-search',
            TNG_OS_URL . 'assets/frontend/discovery-search.js',
            [],
            TNG_OS_VERSION,
            true
        );

        wp_localize_script('tng-discovery-search', 'TNGDiscoverySearch', [
            'rest' => esc_url_raw(rest_url('tng-os/v1/discovery/')),
            'nonce' => wp_create_nonce('wp_rest'),
            'autoReplace' => $this->auto_replace_enabled(),
            'shortcodeHtml' => $this->shortcode(['compact' => '1', 'auto' => '1']),
            'tripUrl' => $this->trip_url(),
            'loginUrl' => wp_login_url($this->trip_url()),
        ]);
    }

    public function routes(): void {
        register_rest_route('tng-os/v1', '/discovery/search', [
            'methods' => 'GET',
            'permission_callback' => '__return_true',
            'callback' => [$this, 'rest_search'],
            'args' => [
                'q' => ['sanitize_callback' => 'sanitize_text_field'],
                'category' => ['sanitize_callback' => 'sanitize_key'],
                'destination' => ['sanitize_callback' => 'absint'],
                'when' => ['sanitize_callback' => 'sanitize_text_field'],
                'limit' => ['sanitize_callback' => 'absint'],
            ],
        ]);

        register_rest_route('tng-os/v1', '/discovery/surprise', [
            'methods' => 'GET',
            'permission_callback' => '__return_true',
            'callback' => [$this, 'rest_surprise'],
        ]);
    }

    private function destinations(): array {
        return get_posts([
            'post_type' => 'tng_destination',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
        ]);
    }

    private function activity_taxonomy(): string {
        $registry = $this->container->get('services');
        if ($registry && is_callable([$registry, 'taxonomy'])) {
            return (string)$registry->taxonomy();
        }

        foreach (['st_activity_type', 'activity_type', 'st_activity_types'] as $taxonomy) {
            if (taxonomy_exists($taxonomy)) return $taxonomy;
        }

        return '';
    }

    private function destination_term(int $destination_id): int {
        if (!$destination_id) return 0;
        $term_id = absint(get_post_meta($destination_id, '_tng_destination_term_id', true));
        if ($term_id) return $term_id;

        $term = get_term_by('slug', 'destination-' . $destination_id, 'tng_destination_ref');
        return $term && !is_wp_error($term) ? absint($term->term_id) : 0;
    }

    private function query_args(string $query, string $category, int $destination_id, string $when, int $limit): array {
        $definition = $this->categories[$category] ?? $this->categories['all'];
        $post_types = $definition['post_types'] ?? [
            'tng_destination',
            'st_activity',
            'st_hotel',
            'st_tours',
            'st_rental',
            'top_sight',
        ];
        $post_types = array_values(array_filter($post_types, 'post_type_exists'));
        if (!$post_types) $post_types = ['post'];

        $args = [
            'post_type' => $post_types,
            'post_status' => 'publish',
            'posts_per_page' => max(1, min(30, $limit)),
            'orderby' => $query ? 'relevance date' : 'menu_order date',
            'order' => 'DESC',
            'ignore_sticky_posts' => true,
        ];

        if ($query) $args['s'] = $query;

        $tax_query = [];

        if ($destination_id) {
            $term_id = $this->destination_term($destination_id);
            if ($term_id && taxonomy_exists('tng_destination_ref')) {
                $tax_query[] = [
                    'taxonomy' => 'tng_destination_ref',
                    'field' => 'term_id',
                    'terms' => [$term_id],
                ];
            }
        }

        if (!empty($definition['terms'])) {
            $taxonomy = $this->activity_taxonomy();
            if ($taxonomy) {
                $term_ids = [];
                foreach ($definition['terms'] as $slug) {
                    $term = get_term_by('slug', $slug, $taxonomy);
                    if ($term && !is_wp_error($term)) $term_ids[] = absint($term->term_id);
                }
                if ($term_ids) {
                    $tax_query[] = [
                        'taxonomy' => $taxonomy,
                        'field' => 'term_id',
                        'terms' => $term_ids,
                    ];
                    $args['post_type'] = ['st_activity'];
                }
            }
        }

        if ($tax_query) {
            if (count($tax_query) > 1) $tax_query['relation'] = 'AND';
            $args['tax_query'] = $tax_query;
        }

        if ($when && in_array($category, ['events', 'all'], true)) {
            $timestamp = strtotime($when);
            if ($timestamp) {
                $date = gmdate('Y-m-d', $timestamp);
                $args['meta_query'] = [
                    'relation' => 'OR',
                    [
                        'key' => '_tng_event_date',
                        'value' => $date,
                        'compare' => '>=',
                        'type' => 'DATE',
                    ],
                    [
                        'key' => 'start_date',
                        'value' => strtotime($date),
                        'compare' => '>=',
                        'type' => 'NUMERIC',
                    ],
                    [
                        'key' => 'event_start',
                        'value' => $date,
                        'compare' => '>=',
                        'type' => 'DATE',
                    ],
                ];
            }
        }

        return $args;
    }

    private function clean_display_text(string $value): string {
        $value = wp_strip_all_tags($value);
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset') ?: 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $value) ?: $value);
    }

    private function result_type(int $post_id): string {
        $type = get_post_type($post_id);
        if ($type === 'tng_destination') return 'Destination';

        $object = get_post_type_object($type);
        $label = $object ? $object->labels->singular_name : 'Place';

        if ($type === 'st_activity') {
            $taxonomy = $this->activity_taxonomy();
            if ($taxonomy) {
                $terms = wp_get_post_terms($post_id, $taxonomy);
                if ($terms && !is_wp_error($terms)) return $this->clean_display_text((string)$terms[0]->name);
            }
        }

        return $this->clean_display_text((string)$label);
    }

    private function result_destination(int $post_id): string {
        if (get_post_type($post_id) === 'tng_destination') return '';

        $destination_id = absint(get_post_meta($post_id, '_tng_destination_id', true));
        return $destination_id ? $this->clean_display_text((string)get_the_title($destination_id)) : '';
    }

    private function serialize_result(int $post_id): array {
        $image = get_the_post_thumbnail_url($post_id, 'medium_large');
        $excerpt = get_the_excerpt($post_id);

        if (!$excerpt) {
            $post = get_post($post_id);
            $excerpt = $post ? (string)$post->post_content : '';
        }

        return [
            'id' => $post_id,
            'title' => $this->clean_display_text((string)get_the_title($post_id)),
            'url' => get_permalink($post_id),
            'type' => $this->clean_display_text($this->result_type($post_id)),
            'destination' => $this->clean_display_text($this->result_destination($post_id)),
            'excerpt' => wp_trim_words($this->clean_display_text((string)$excerpt), 19),
            'image' => $image ?: '',
        ];
    }

    public function rest_search(WP_REST_Request $request): WP_REST_Response {
        $query = sanitize_text_field((string)$request->get_param('q'));
        $category = sanitize_key((string)$request->get_param('category')) ?: 'all';
        $destination_id = absint($request->get_param('destination'));
        $when = sanitize_text_field((string)$request->get_param('when'));
        $limit = absint($request->get_param('limit')) ?: 12;

        if (!isset($this->categories[$category])) $category = 'all';

        $wp_query = new WP_Query(
            $this->query_args($query, $category, $destination_id, $when, $limit)
        );

        $items = array_map(
            fn($post): array => $this->serialize_result((int)$post->ID),
            $wp_query->posts
        );

        if ($destination_id) {
            $destination = get_post($destination_id);
            if ($destination && $destination->post_type === 'tng_destination' && $destination->post_status === 'publish') {
                $matches_query = !$query || stripos($destination->post_title, $query) !== false;
                if ($category === 'all' && $matches_query) {
                    array_unshift($items, $this->serialize_result($destination_id));
                    $items = array_slice($items, 0, $limit);
                }
            }
        }

        return new WP_REST_Response([
            'items' => $items,
            'count' => count($items),
            'total' => (int)$wp_query->found_posts,
            'query' => $query,
            'category' => $category,
        ]);
    }

    public function rest_surprise(): WP_REST_Response {
        $post_types = array_values(array_filter(
            ['tng_destination', 'st_activity', 'st_hotel', 'st_tours', 'st_rental', 'top_sight'],
            'post_type_exists'
        ));

        $posts = get_posts([
            'post_type' => $post_types ?: ['post'],
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'orderby' => 'rand',
        ]);

        return new WP_REST_Response([
            'url' => $posts ? get_permalink($posts[0]) : home_url('/'),
        ]);
    }

    private function trip_url(): string {
        $page_id = absint(get_option('tng_trip_planner_page_id'));
        if ($page_id) return get_permalink($page_id);

        $pages = get_posts([
            'post_type' => 'page',
            'post_status' => 'publish',
            'posts_per_page' => 1,
            's' => 'Trip Planner',
        ]);

        return $pages ? get_permalink($pages[0]) : home_url('/trip-planner/');
    }

    private function search_instance_id(): string {
        static $count = 0;
        $count++;
        return 'tng-discovery-' . $count;
    }

    public function shortcode($atts = []): string {
        $atts = shortcode_atts([
            'title' => 'What do you want to discover?',
            'subtitle' => 'Search destinations, trails, waterfalls, food, events, shops, and local experiences.',
            'compact' => '0',
            'auto' => '0',
            'show_categories' => '1',
        ], (array)$atts, 'tng_discovery_search');

        $id = $this->search_instance_id();
        $destinations = $this->destinations();
        $compact = $atts['compact'] === '1';

        ob_start();
        ?>
        <section class="tng-discovery-search <?php echo $compact ? 'is-compact' : ''; ?>" data-tng-discovery-search id="<?php echo esc_attr($id); ?>">
            <div class="tng-discovery-shell">
                <?php if (!$compact): ?>
                    <header class="tng-discovery-heading">
                        <span>TN GAME DISCOVERY</span>
                        <h2><?php echo esc_html($atts['title']); ?></h2>
                        <p><?php echo esc_html($atts['subtitle']); ?></p>
                    </header>
                <?php endif; ?>

                <form class="tng-discovery-form" data-tng-discovery-form>
                    <div class="tng-discovery-field tng-discovery-query">
                        <span class="tng-discovery-field-icon">⌕</span>
                        <label for="<?php echo esc_attr($id); ?>-query">What</label>
                        <input
                            id="<?php echo esc_attr($id); ?>-query"
                            type="search"
                            name="q"
                            placeholder="Waterfalls, hiking, coffee, concerts…"
                            autocomplete="off"
                            data-tng-discovery-query
                        >
                        <div class="tng-discovery-suggestions" data-tng-discovery-suggestions hidden></div>
                    </div>

                    <div class="tng-discovery-field">
                        <span class="tng-discovery-field-icon">⌖</span>
                        <label for="<?php echo esc_attr($id); ?>-destination">Where</label>
                        <select id="<?php echo esc_attr($id); ?>-destination" name="destination">
                            <option value="">Anywhere in South Cumberland</option>
                            <?php foreach ($destinations as $destination): ?>
                                <option value="<?php echo absint($destination->ID); ?>"><?php echo esc_html($destination->post_title); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="tng-discovery-field tng-discovery-when">
                        <span class="tng-discovery-field-icon">◷</span>
                        <label for="<?php echo esc_attr($id); ?>-when">When</label>
                        <input id="<?php echo esc_attr($id); ?>-when" type="date" name="when">
                    </div>

                    <button type="submit" class="tng-discovery-submit">
                        <span>Explore</span>
                        <b>→</b>
                    </button>
                </form>

                <?php if ($atts['show_categories'] === '1'): ?>
                    <div class="tng-discovery-categories" data-tng-discovery-categories>
                        <?php foreach ($this->categories as $key => $category): ?>
                            <button type="button" class="<?php echo $key === 'all' ? 'is-active' : ''; ?>" data-tng-category="<?php echo esc_attr($key); ?>">
                                <span><?php echo esc_html($category['icon']); ?></span>
                                <?php echo esc_html($category['label']); ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="tng-discovery-quick-actions">
                    <button type="button" data-tng-near-search><span>◎</span> Near me</button>
                    <button type="button" data-tng-surprise><span>✦</span> Surprise me</button>
                    <a href="<?php echo esc_url($this->trip_url()); ?>"><span>♡</span> My trip</a>
                </div>

                <div class="tng-discovery-results" data-tng-discovery-results hidden>
                    <div class="tng-discovery-results-header">
                        <div>
                            <span>DISCOVERY RESULTS</span>
                            <h3 data-tng-results-title>Explore South Cumberland</h3>
                        </div>
                        <button type="button" data-tng-close-results aria-label="Close results">×</button>
                    </div>
                    <div class="tng-discovery-results-grid" data-tng-results-grid></div>
                    <div class="tng-discovery-empty" data-tng-results-empty hidden>
                        <strong>No matching places yet.</strong>
                        <p>Try another word, category, or destination.</p>
                    </div>
                </div>
            </div>
        </section>
        <?php
        return (string)ob_get_clean();
    }
}
