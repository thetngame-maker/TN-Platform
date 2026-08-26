<?php
namespace TNG_OS\Modules\Frontend;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;
use WP_Query;

if (!defined('ABSPATH')) exit;

final class Food_Service implements Module_Interface {
    private Container $container;
    private string $food_term_slug = 'food-and-drink';

    public function id(): string {
        return 'frontend_food_service';
    }

    public function register(Container $container): void {
        $this->container = $container;

        add_action('init', [$this, 'register_rewrite'], 25);
        add_filter('query_vars', [$this, 'query_vars']);
        add_action('template_redirect', [$this, 'render_food_archive']);

        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets'], 50);
        add_action('wp_ajax_tng_os_food_cards', [$this, 'ajax_food_cards']);
        add_action('wp_ajax_nopriv_tng_os_food_cards', [$this, 'ajax_food_cards']);
        add_action('wp_ajax_tng_os_food_links', [$this, 'ajax_food_links']);
        add_action('wp_ajax_nopriv_tng_os_food_links', [$this, 'ajax_food_links']);
        add_action('wp_ajax_tng_os_destination_food_counts', [$this, 'ajax_destination_counts']);
        add_action('wp_ajax_nopriv_tng_os_destination_food_counts', [$this, 'ajax_destination_counts']);

        add_filter('wp_nav_menu_items', [$this, 'add_menu_item'], 40, 2);
        add_filter('body_class', [$this, 'body_class']);
    }

    public function boot(Container $container): void {}

    public function register_rewrite(): void {
        add_rewrite_rule(
            '^food-drink/?$',
            'index.php?tng_food_archive=1',
            'top'
        );
    }

    public function query_vars(array $vars): array {
        $vars[] = 'tng_food_archive';
        return $vars;
    }

    public function body_class(array $classes): array {
        if ((int)get_query_var('tng_food_archive') === 1) {
            $classes[] = 'tng-food-service-archive';
        }
        return $classes;
    }

    public function enqueue_assets(): void {
        if (is_admin()) return;

        /*
         * The unified Recommendations module now owns the homepage service
         * widget. Keep this stylesheet for the dedicated Food & Drink archive,
         * but do not load the older script that injected a tab into Traveler.
         */
        wp_enqueue_style(
            'tng-os-food-service',
            TNG_OS_URL . 'assets/frontend/food-service.css',
            [],
            TNG_OS_VERSION
        );
    }

    private function activity_type_taxonomy(): string {
        $registry = $this->container->get('services');
        if ($registry && method_exists($registry, 'taxonomy')) {
            return (string)$registry->taxonomy();
        }

        foreach (['st_activity_type', 'activity_type', 'st_activity_types'] as $taxonomy) {
            if (taxonomy_exists($taxonomy)) return $taxonomy;
        }

        foreach (get_object_taxonomies('st_activity', 'objects') as $taxonomy) {
            if (!empty($taxonomy->hierarchical)) return $taxonomy->name;
        }

        return '';
    }

    private function food_term_id(): int {
        $taxonomy = $this->activity_type_taxonomy();
        if (!$taxonomy) return 0;

        $term = get_term_by('slug', $this->food_term_slug, $taxonomy);
        if (!$term) {
            $term = get_term_by('name', 'Food & Drink', $taxonomy);
        }

        return $term && !is_wp_error($term) ? (int)$term->term_id : 0;
    }

    private function food_query_args(int $limit = 12): array {
        $taxonomy = $this->activity_type_taxonomy();
        $term_id = $this->food_term_id();

        $args = [
            'post_type' => 'st_activity',
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'orderby' => 'menu_order date',
            'order' => 'DESC',
        ];

        if ($taxonomy && $term_id) {
            $args['tax_query'] = [[
                'taxonomy' => $taxonomy,
                'field' => 'term_id',
                'terms' => [$term_id],
            ]];
        } else {
            $args['meta_query'] = [
                'relation' => 'OR',
                [
                    'key' => '_tng_food_enabled',
                    'value' => '1',
                ],
                [
                    'key' => '_tng_content_service',
                    'value' => 'food',
                ],
            ];
        }

        return $args;
    }

    private function food_posts(int $limit = 12): array {
        $query = new WP_Query($this->food_query_args($limit));
        return $query->posts;
    }

    private function card_data(\WP_Post $post): array {
        $post_id = (int)$post->ID;
        $image = get_the_post_thumbnail_url($post_id, 'large');
        if (!$image) {
            $image = TNG_OS_URL . 'assets/frontend/food-placeholder.svg';
        }

        $location = get_post_meta($post_id, '_tng_content_community', true);
        if (!$location) {
            $location = get_post_meta($post_id, '_tng_food_address', true);
        }
        if (!$location) {
            $location = get_post_meta($post_id, 'address', true);
        }

        $rating = get_post_meta($post_id, '_tng_food_rating', true);
        if ($rating === '') {
            $rating = get_post_meta($post_id, 'rate_review', true);
        }

        $reviews = get_post_meta($post_id, '_tng_food_review_count', true);
        if ($reviews === '') {
            $reviews = get_comments_number($post_id);
        }

        $price = get_post_meta($post_id, '_tng_food_price_range', true);
        if (!$price) $price = '$$';

        $cuisine = get_post_meta($post_id, '_tng_food_cuisine', true);
        if (!$cuisine) $cuisine = 'Food & Drink';

        return [
            'id' => $post_id,
            'title' => get_the_title($post_id),
            'url' => get_permalink($post_id),
            'image' => $image,
            'location' => wp_strip_all_tags((string)$location),
            'rating' => $rating !== '' ? (float)$rating : 0,
            'reviews' => (int)$reviews,
            'price' => wp_strip_all_tags((string)$price),
            'cuisine' => wp_strip_all_tags((string)$cuisine),
        ];
    }

    private function render_cards_html(array $posts): string {
        ob_start();

        if (!$posts) {
            echo '<div class="tng-food-empty">No Food &amp; Drink listings are available yet.</div>';
        } else {
            echo '<div class="tng-food-card-grid">';
            foreach ($posts as $post) {
                $data = $this->card_data($post);
                ?>
                <article class="tng-food-card" data-food-activity-id="<?php echo absint($data['id']); ?>">
                    <a class="tng-food-card-image" href="<?php echo esc_url($data['url']); ?>">
                        <img src="<?php echo esc_url($data['image']); ?>" alt="<?php echo esc_attr($data['title']); ?>" loading="lazy">
                        <span class="tng-food-card-type"><?php echo esc_html($data['cuisine']); ?></span>
                        <span class="tng-food-card-favorite" aria-hidden="true">♡</span>
                    </a>
                    <div class="tng-food-card-body">
                        <h3><a href="<?php echo esc_url($data['url']); ?>"><?php echo esc_html($data['title']); ?></a></h3>
                        <?php if ($data['location']): ?>
                            <p class="tng-food-card-location"><?php echo esc_html($data['location']); ?></p>
                        <?php endif; ?>
                        <div class="tng-food-card-meta">
                            <span class="tng-food-rating">★ <?php echo esc_html($data['rating'] ? number_format_i18n($data['rating'], 1) : 'New'); ?></span>
                            <span><?php echo esc_html($data['reviews']); ?> reviews</span>
                        </div>
                        <div class="tng-food-card-footer">
                            <strong><?php echo esc_html($data['price']); ?></strong>
                            <span>View restaurant</span>
                        </div>
                    </div>
                </article>
                <?php
            }
            echo '</div>';
        }

        return (string)ob_get_clean();
    }

    public function ajax_food_cards(): void {
        $limit = isset($_POST['limit']) ? min(30, max(1, absint($_POST['limit']))) : 6;
        $posts = $this->food_posts($limit);

        wp_send_json_success([
            'html' => $this->render_cards_html($posts),
            'count' => count($posts),
            'archiveUrl' => home_url('/food-drink/'),
        ]);
    }

    public function ajax_food_links(): void {
        $posts = $this->food_posts(500);
        $links = [];

        foreach ($posts as $post) {
            $links[] = [
                'id' => (int)$post->ID,
                'url' => untrailingslashit(get_permalink($post)),
            ];
        }

        wp_send_json_success(['links' => $links]);
    }

    private function location_taxonomies(): array {
        $taxonomies = [];
        foreach (get_object_taxonomies('st_activity', 'objects') as $taxonomy) {
            $name = strtolower($taxonomy->name . ' ' . $taxonomy->label);
            if (
                strpos($name, 'location') !== false ||
                strpos($name, 'destination') !== false ||
                strpos($name, 'city') !== false
            ) {
                $taxonomies[] = $taxonomy->name;
            }
        }
        return array_values(array_unique($taxonomies));
    }

    private function count_for_location(string $location_name, bool $food): int {
        $location_name = trim(wp_strip_all_tags($location_name));
        if ($location_name === '') return 0;

        $location_tax_query = [];
        foreach ($this->location_taxonomies() as $taxonomy) {
            $term = get_term_by('name', $location_name, $taxonomy);
            if (!$term) {
                $term = get_term_by('slug', sanitize_title($location_name), $taxonomy);
            }
            if ($term && !is_wp_error($term)) {
                $location_tax_query[] = [
                    'taxonomy' => $taxonomy,
                    'field' => 'term_id',
                    'terms' => [(int)$term->term_id],
                ];
            }
        }

        if (!$location_tax_query) return 0;

        $taxonomy = $this->activity_type_taxonomy();
        $food_term = $this->food_term_id();

        $tax_query = [
            'relation' => 'AND',
            array_merge(['relation' => 'OR'], $location_tax_query),
        ];

        if ($taxonomy && $food_term) {
            $tax_query[] = [
                'taxonomy' => $taxonomy,
                'field' => 'term_id',
                'terms' => [$food_term],
                'operator' => $food ? 'IN' : 'NOT IN',
            ];
        }

        $query = new WP_Query([
            'post_type' => 'st_activity',
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'tax_query' => $tax_query,
        ]);

        return (int)$query->found_posts;
    }

    public function ajax_destination_counts(): void {
        $names = isset($_POST['names']) ? (array)wp_unslash($_POST['names']) : [];
        $results = [];

        foreach (array_slice($names, 0, 30) as $name) {
            $clean = sanitize_text_field($name);
            if ($clean === '') continue;

            $results[$clean] = [
                'food' => $this->count_for_location($clean, true),
                'activities' => $this->count_for_location($clean, false),
            ];
        }

        wp_send_json_success(['counts' => $results]);
    }

    public function add_menu_item(string $items, $args): string {
        if (is_admin() || stripos($items, 'Food &amp; Drink') !== false || stripos($items, 'Food & Drink') !== false) {
            return $items;
        }

        $menu_location = isset($args->theme_location) ? (string)$args->theme_location : '';
        $menu_id = isset($args->menu_id) ? (string)$args->menu_id : '';
        $is_primary = (
            stripos($menu_location, 'primary') !== false ||
            stripos($menu_location, 'main') !== false ||
            stripos($menu_id, 'main') !== false
        );

        if (!$is_primary) return $items;

        $item = sprintf(
            '<li class="menu-item tng-food-menu-item"><a href="%s">Food &amp; Drink</a></li>',
            esc_url(home_url('/food-drink/'))
        );

        $activity_position = stripos($items, '>Activity<');
        if ($activity_position !== false) {
            $closing_li = stripos($items, '</li>', $activity_position);
            if ($closing_li !== false) {
                $closing_li += 5;
                return substr($items, 0, $closing_li) . $item . substr($items, $closing_li);
            }
        }

        return $items . $item;
    }

    public function render_food_archive(): void {
        if ((int)get_query_var('tng_food_archive') !== 1) return;

        status_header(200);
        nocache_headers();

        get_header();
        $posts = $this->food_posts(100);
        ?>
        <main class="tng-food-archive">
            <section class="tng-food-archive-hero">
                <div class="container">
                    <span>EXPLORE LOCAL FLAVOR</span>
                    <h1>Food &amp; Drink</h1>
                    <p>Discover restaurants, cafés, bakeries, breweries, and local favorites throughout Tennessee South Cumberland.</p>
                </div>
            </section>
            <section class="tng-food-archive-content">
                <div class="container">
                    <div class="tng-food-archive-heading">
                        <div>
                            <h2>Places to eat and drink</h2>
                            <p><?php echo esc_html(number_format_i18n(count($posts))); ?> listings</p>
                        </div>
                    </div>
                    <?php echo $this->render_cards_html($posts); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                </div>
            </section>
        </main>
        <?php
        get_footer();
        exit;
    }
}
