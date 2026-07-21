<?php
namespace TNG_OS\Modules\Destinations;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;
use WP_Post;
use WP_Query;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) exit;

final class Destination_Platform implements Module_Interface {
    private Container $container;
    private array $service_types = [
        'trails' => ['post_type' => 'st_activity', 'terms' => ['hiking-trails', 'trails'], 'label' => 'Trails', 'icon' => '🥾'],
        'food' => ['post_type' => 'st_activity', 'terms' => ['food-and-drink', 'restaurants'], 'label' => 'Food & Drink', 'icon' => '🍽'],
        'concerts' => ['post_type' => 'st_activity', 'terms' => ['concerts'], 'label' => 'Concerts', 'icon' => '🎵'],
        'shops' => ['post_type' => 'st_activity', 'terms' => ['shops', 'shopping'], 'label' => 'Shops', 'icon' => '🛍'],
        'history' => ['post_type' => 'st_activity', 'terms' => ['historic-sites', 'history'], 'label' => 'Historic Sites', 'icon' => '🏛'],
        'campgrounds' => ['post_type' => 'st_activity', 'terms' => ['campgrounds', 'camping'], 'label' => 'Campgrounds', 'icon' => '⛺'],
        'waterfalls' => ['post_type' => 'st_activity', 'terms' => ['waterfalls'], 'label' => 'Waterfalls', 'icon' => '💧'],
        'scenic' => ['post_type' => 'st_activity', 'terms' => ['scenic-views', 'scenic'], 'label' => 'Scenic Views', 'icon' => '🌄'],
        'activities' => ['post_type' => 'st_activity', 'terms' => [], 'label' => 'Activities', 'icon' => '🧭'],
        'hotels' => ['post_type' => 'st_hotel', 'terms' => [], 'label' => 'Hotels', 'icon' => '🏨'],
        'tours' => ['post_type' => 'st_tours', 'terms' => [], 'label' => 'Tours', 'icon' => '🚌'],
        'rentals' => ['post_type' => 'st_rental', 'terms' => [], 'label' => 'Rentals', 'icon' => '🏡'],
        'cars' => ['post_type' => 'st_cars', 'terms' => [], 'label' => 'Cars', 'icon' => '🚗'],
    ];

    public function id(): string { return 'destination_platform'; }

    public function register(Container $container): void {
        $this->container = $container;
        $container->set('destination_platform', $this);

        add_action('init', [$this, 'sync_destination_terms'], 30);
        add_action('add_meta_boxes', [$this, 'meta_boxes']);
        add_action('save_post', [$this, 'save_meta'], 20, 2);
        add_action('save_post_tng_destination', [$this, 'sync_one_destination'], 25, 2);

        add_filter('the_content', [$this, 'destination_content'], 35);
        add_filter('body_class', [$this, 'body_class']);
        add_action('wp_enqueue_scripts', [$this, 'assets']);

        add_shortcode('tng_destinations', [$this, 'destinations_shortcode']);
        add_shortcode('tng_near_me', [$this, 'near_me_shortcode']);
        add_shortcode('tng_trip_planner', [$this, 'trip_planner_shortcode']);
        add_shortcode('tng_destination_map', [$this, 'map_shortcode']);
        add_shortcode('tng_destination_leaderboard', [$this, 'leaderboard_shortcode']);
        add_shortcode('tng_local_alerts', [$this, 'alerts_shortcode']);

        add_action('rest_api_init', [$this, 'rest_routes']);
        add_action('wp_ajax_tng_destination_quick_create', [$this, 'quick_create']);
    }

    public function boot(Container $container): void {}

    public function body_class(array $classes): array {
        if (is_singular('tng_destination')) $classes[] = 'tng-destination-page';
        return $classes;
    }

    public function assets(): void {
        if (is_admin()) return;
        wp_enqueue_style('tng-destination-platform', TNG_OS_URL . 'assets/frontend/destination-platform.css', [], TNG_OS_VERSION);
        wp_enqueue_script('tng-destination-platform', TNG_OS_URL . 'assets/frontend/destination-platform.js', [], TNG_OS_VERSION, true);
        wp_localize_script('tng-destination-platform', 'TNGDestinationPlatform', [
            'rest' => esc_url_raw(rest_url('tng-os/v1/')),
            'nonce' => wp_create_nonce('wp_rest'),
            'loggedIn' => is_user_logged_in(),
        ]);
    }

    public function supported_post_types(): array {
        $module = $this->container->get('destinations');
        return $module && is_callable([$module, 'supported_post_types'])
            ? $module->supported_post_types()
            : ['st_activity', 'st_hotel', 'st_tours', 'st_rental', 'st_cars', 'post'];
    }

    public function sync_destination_terms(): void {
        $destinations = get_posts([
            'post_type' => 'tng_destination',
            'post_status' => ['publish', 'draft', 'private'],
            'posts_per_page' => -1,
            'fields' => 'ids',
        ]);

        foreach ($destinations as $id) $this->ensure_term((int)$id);
    }

    public function sync_one_destination(int $post_id, WP_Post $post): void {
        if (wp_is_post_revision($post_id) || $post->post_type !== 'tng_destination') return;
        $this->ensure_term($post_id);
    }

    private function ensure_term(int $destination_id): int {
        $slug = 'destination-' . $destination_id;
        $term = get_term_by('slug', $slug, 'tng_destination_ref');

        if (!$term) {
            $created = wp_insert_term(get_the_title($destination_id), 'tng_destination_ref', ['slug' => $slug]);
            if (is_wp_error($created)) return 0;
            $term_id = (int)$created['term_id'];
        } else {
            $term_id = (int)$term->term_id;
            if ($term->name !== get_the_title($destination_id)) {
                wp_update_term($term_id, 'tng_destination_ref', ['name' => get_the_title($destination_id)]);
            }
        }

        update_term_meta($term_id, 'tng_destination_id', $destination_id);
        update_post_meta($destination_id, '_tng_destination_term_id', $term_id);
        return $term_id;
    }

    public function meta_boxes(): void {
        add_meta_box('tng_destination_details', 'TN Game Destination Details', [$this, 'destination_box'], 'tng_destination', 'normal', 'high');

        foreach ($this->supported_post_types() as $post_type) {
            if ($post_type === 'tng_destination') continue;
            add_meta_box('tng_destination_relationship', 'TN Game Destination', [$this, 'relationship_box'], $post_type, 'side', 'high');
        }

        add_meta_box('tng_alert_destination', 'Alert Destination', [$this, 'alert_box'], 'tng_local_alert', 'side', 'high');
    }

    public function destination_box(WP_Post $post): void {
        wp_nonce_field('tng_destination_save', 'tng_destination_nonce');
        $fields = [
            '_tng_destination_lat' => ['Latitude', 'text'],
            '_tng_destination_lng' => ['Longitude', 'text'],
            '_tng_destination_radius' => ['Near Me radius (miles)', 'number'],
            '_tng_destination_weather_location' => ['Weather location', 'text'],
            '_tng_destination_season' => ['Best season', 'text'],
            '_tng_destination_crowd_baseline' => ['Typical crowd level (1–5)', 'number'],
            '_tng_destination_tagline' => ['Short tagline', 'text'],
        ];

        echo '<div class="tng-destination-admin-grid">';
        foreach ($fields as $key => [$label, $type]) {
            printf(
                '<p><label><strong>%s</strong><input class="widefat" type="%s" name="%s" value="%s"></label></p>',
                esc_html($label), esc_attr($type), esc_attr($key), esc_attr((string)get_post_meta($post->ID, $key, true))
            );
        }
        echo '</div><p>All destination features—maps, nearby results, weather, recommendations, alerts, itineraries, analytics and listing counts—use this record.</p>';
    }

    public function relationship_box(WP_Post $post): void {
        wp_nonce_field('tng_destination_link_save', 'tng_destination_link_nonce');
        $current = (int)get_post_meta($post->ID, '_tng_destination_id', true);
        $destinations = get_posts(['post_type' => 'tng_destination', 'post_status' => ['publish','draft'], 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC']);

        echo '<select class="widefat" name="_tng_destination_id"><option value="0">— Not assigned —</option>';
        foreach ($destinations as $destination) {
            printf('<option value="%d" %s>%s</option>', $destination->ID, selected($current, $destination->ID, false), esc_html($destination->post_title));
        }
        echo '</select><p class="description">Connect this listing to its primary TN Game destination.</p>';
    }

    public function alert_box(WP_Post $post): void {
        wp_nonce_field('tng_alert_save', 'tng_alert_nonce');
        $current = (int)get_post_meta($post->ID, '_tng_destination_id', true);
        $severity = get_post_meta($post->ID, '_tng_alert_severity', true) ?: 'info';
        $expires = get_post_meta($post->ID, '_tng_alert_expires', true);

        $destinations = get_posts(['post_type' => 'tng_destination', 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC']);
        echo '<p><select class="widefat" name="_tng_destination_id"><option value="0">All destinations</option>';
        foreach ($destinations as $destination) printf('<option value="%d" %s>%s</option>', $destination->ID, selected($current, $destination->ID, false), esc_html($destination->post_title));
        echo '</select></p>';
        echo '<p><label>Severity<select class="widefat" name="_tng_alert_severity">';
        foreach (['info'=>'Information','advisory'=>'Advisory','warning'=>'Warning','closure'=>'Closure'] as $value=>$label) printf('<option value="%s" %s>%s</option>', esc_attr($value), selected($severity,$value,false), esc_html($label));
        echo '</select></label></p>';
        printf('<p><label>Expires<input class="widefat" type="datetime-local" name="_tng_alert_expires" value="%s"></label></p>', esc_attr((string)$expires));
    }

    public function save_meta(int $post_id, WP_Post $post): void {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        if ($post->post_type === 'tng_destination' && isset($_POST['tng_destination_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['tng_destination_nonce'])), 'tng_destination_save')) {
            foreach (['_tng_destination_lat','_tng_destination_lng','_tng_destination_radius','_tng_destination_weather_location','_tng_destination_season','_tng_destination_crowd_baseline','_tng_destination_tagline'] as $key) {
                if (isset($_POST[$key])) update_post_meta($post_id, $key, sanitize_text_field(wp_unslash($_POST[$key])));
            }
        }

        if (in_array($post->post_type, $this->supported_post_types(), true) && isset($_POST['tng_destination_link_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['tng_destination_link_nonce'])), 'tng_destination_link_save')) {
            $destination_id = isset($_POST['_tng_destination_id']) ? absint($_POST['_tng_destination_id']) : 0;
            update_post_meta($post_id, '_tng_destination_id', $destination_id);
            if ($destination_id) {
                $term_id = $this->ensure_term($destination_id);
                if ($term_id) wp_set_object_terms($post_id, [$term_id], 'tng_destination_ref', false);
            } else {
                wp_set_object_terms($post_id, [], 'tng_destination_ref', false);
            }
        }

        if ($post->post_type === 'tng_local_alert' && isset($_POST['tng_alert_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['tng_alert_nonce'])), 'tng_alert_save')) {
            update_post_meta($post_id, '_tng_destination_id', isset($_POST['_tng_destination_id']) ? absint($_POST['_tng_destination_id']) : 0);
            update_post_meta($post_id, '_tng_alert_severity', isset($_POST['_tng_alert_severity']) ? sanitize_key($_POST['_tng_alert_severity']) : 'info');
            update_post_meta($post_id, '_tng_alert_expires', isset($_POST['_tng_alert_expires']) ? sanitize_text_field(wp_unslash($_POST['_tng_alert_expires'])) : '');
        }
    }

    private function destination_term(int $destination_id): int {
        $term_id = (int)get_post_meta($destination_id, '_tng_destination_term_id', true);
        return $term_id ?: $this->ensure_term($destination_id);
    }

    private function listing_query(int $destination_id, string $service = '', int $limit = 6): WP_Query {
        $term_id = $this->destination_term($destination_id);
        $definition = $this->service_types[$service] ?? null;
        $post_type = $definition['post_type'] ?? ['st_activity','st_hotel','st_tours','st_rental','st_cars'];
        if (!post_type_exists(is_array($post_type) ? reset($post_type) : $post_type)) $post_type = 'any';

        $args = [
            'post_type' => $post_type,
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'orderby' => 'menu_order date',
            'order' => 'DESC',
            'tax_query' => [[
                'taxonomy' => 'tng_destination_ref',
                'field' => 'term_id',
                'terms' => [$term_id],
            ]],
        ];

        if ($definition && $definition['terms'] && $post_type === 'st_activity') {
            $taxonomy = $this->activity_taxonomy();
            $ids = $this->term_ids($taxonomy, $definition['terms']);
            if ($taxonomy && $ids) {
                $args['tax_query'][] = [
                    'taxonomy' => $taxonomy,
                    'field' => 'term_id',
                    'terms' => $ids,
                ];
            }
        }

        return new WP_Query($args);
    }

    private function activity_taxonomy(): string {
        $registry = $this->container->get('services');
        if ($registry && is_callable([$registry, 'taxonomy'])) return (string)$registry->taxonomy();
        foreach (['st_activity_type','activity_type','st_activity_types'] as $taxonomy) if (taxonomy_exists($taxonomy)) return $taxonomy;
        return '';
    }

    private function term_ids(string $taxonomy, array $slugs): array {
        if (!$taxonomy) return [];
        $ids = [];
        foreach ($slugs as $slug) {
            $term = get_term_by('slug', $slug, $taxonomy);
            if ($term && !is_wp_error($term)) $ids[] = (int)$term->term_id;
        }
        return $ids;
    }

    private function service_counts(int $destination_id): array {
        $counts = [];
        foreach ($this->service_types as $key => $definition) {
            $counts[$key] = (int)$this->listing_query($destination_id, $key, 1)->found_posts;
        }
        return $counts;
    }

    private function card(WP_Post $post): string {
        $image = get_the_post_thumbnail_url($post->ID, 'large') ?: TNG_OS_URL . 'assets/frontend/destination-placeholder.svg';
        $type = get_post_type_object($post->post_type);
        ob_start(); ?>
        <article class="tng-dp-listing-card" data-listing-id="<?php echo absint($post->ID); ?>">
            <a class="tng-dp-listing-image" href="<?php echo esc_url(get_permalink($post)); ?>"><img src="<?php echo esc_url($image); ?>" alt="<?php echo esc_attr($post->post_title); ?>" loading="lazy"></a>
            <div>
                <small><?php echo esc_html($type ? $type->labels->singular_name : 'Experience'); ?></small>
                <h3><a href="<?php echo esc_url(get_permalink($post)); ?>"><?php echo esc_html($post->post_title); ?></a></h3>
                <button
                    type="button"
                    class="tng-dp-add-trip"
                    data-tng-add-trip
                    data-listing="<?php echo absint($post->ID); ?>"
                    data-title="<?php echo esc_attr($post->post_title); ?>"
                    data-url="<?php echo esc_url(get_permalink($post)); ?>"
                >+ Add to trip</button>
            </div>
        </article>
        <?php return (string)ob_get_clean();
    }

    public function destination_content(string $content): string {
        if (!is_singular('tng_destination') || !in_the_loop() || !is_main_query()) return $content;
        $destination_id = get_the_ID();
        $counts = $this->service_counts($destination_id);
        $lat = get_post_meta($destination_id, '_tng_destination_lat', true);
        $lng = get_post_meta($destination_id, '_tng_destination_lng', true);
        $tagline = get_post_meta($destination_id, '_tng_destination_tagline', true);
        $season = get_post_meta($destination_id, '_tng_destination_season', true);
        $crowd = $this->crowd_estimate($destination_id);

        ob_start(); ?>
        <div class="tng-dp-page" data-destination-id="<?php echo absint($destination_id); ?>">
            <?php echo $this->alerts_shortcode(['destination' => $destination_id]); ?>
            <section class="tng-dp-intro">
                <?php if ($tagline): ?><p class="tng-dp-tagline"><?php echo esc_html($tagline); ?></p><?php endif; ?>
                <div class="tng-dp-copy"><?php echo $content; ?></div>
                <div class="tng-dp-metrics">
                    <div><strong><?php echo number_format_i18n(array_sum($counts)); ?></strong><span>Places & experiences</span></div>
                    <div><strong><?php echo esc_html($season ?: 'Year-round'); ?></strong><span>Best season</span></div>
                    <div><strong><?php echo esc_html($crowd['label']); ?></strong><span>Crowd estimate</span></div>
                </div>
            </section>
            <?php echo $this->map_shortcode(['destination' => $destination_id]); ?>
            <section class="tng-dp-service-grid">
                <?php foreach ($counts as $key => $count): if (!$count) continue; $definition = $this->service_types[$key]; ?>
                    <button type="button" class="tng-dp-service-chip" data-service="<?php echo esc_attr($key); ?>"><span><?php echo esc_html($definition['icon']); ?></span><strong><?php echo number_format_i18n($count); ?></strong><?php echo esc_html($definition['label']); ?></button>
                <?php endforeach; ?>
            </section>
            <section class="tng-dp-featured">
                <header><h2>Local recommendations</h2><p>Featured experiences connected to <?php echo esc_html(get_the_title($destination_id)); ?>.</p></header>
                <div class="tng-dp-listing-grid">
                    <?php foreach ($this->listing_query($destination_id, '', 6)->posts as $post) echo $this->card($post); ?>
                </div>
            </section>
            <section class="tng-dp-tools">
                <div><?php echo $this->trip_planner_shortcode(['destination' => $destination_id]); ?></div>
                <div><?php echo $this->leaderboard_shortcode(['destination' => $destination_id]); ?></div>
            </section>
            <section class="tng-dp-sections">
                <div><h2>Passport progress</h2><?php echo $this->passport_panel($destination_id); ?></div>
                <div><h2>Plan an itinerary</h2><?php echo $this->itinerary_panel($destination_id); ?></div>
                <div><h2>Weather</h2><?php echo $this->weather_panel($destination_id); ?></div>
                <div><h2>Seasonal recommendations</h2><?php echo $this->seasonal_panel($destination_id); ?></div>
            </section>
        </div>
        <?php
        $this->record_analytics($destination_id, 'view');
        return (string)ob_get_clean();
    }

    public function map_shortcode(array $atts = []): string {
        $atts = shortcode_atts(['destination' => get_the_ID()], $atts);
        $id = absint($atts['destination']);
        $lat = get_post_meta($id, '_tng_destination_lat', true);
        $lng = get_post_meta($id, '_tng_destination_lng', true);
        if ($lat === '' || $lng === '') return current_user_can('edit_post', $id) ? '<div class="tng-dp-notice">Add latitude and longitude to enable the destination map.</div>' : '';
        return sprintf('<section class="tng-dp-map" data-lat="%s" data-lng="%s" data-title="%s"><div class="tng-dp-map-canvas"><span>Map of %s</span></div><a target="_blank" rel="noopener" href="https://www.google.com/maps/search/?api=1&query=%s,%s">Open directions</a></section>', esc_attr($lat), esc_attr($lng), esc_attr(get_the_title($id)), esc_html(get_the_title($id)), rawurlencode($lat), rawurlencode($lng));
    }

    public function destinations_shortcode(array $atts = []): string {
        $atts = shortcode_atts([
            'limit' => 6,
            'columns' => 3,
            'heading' => 'Top destinations',
            'eyebrow' => 'EXPLORE THE REGION',
        ], $atts, 'tng_destinations');

        $posts = get_posts([
            'post_type' => 'tng_destination',
            'post_status' => 'publish',
            'posts_per_page' => min(24, max(1, absint($atts['limit']))),
            'orderby' => 'menu_order title',
            'order' => 'ASC',
        ]);

        if (!$posts) {
            return current_user_can('edit_pages')
                ? '<div class="tng-dp-notice">Create and publish TN Game Destinations to populate this widget.</div>'
                : '';
        }

        ob_start(); ?>
        <section class="tng-dp-destinations" style="--columns:<?php echo esc_attr(min(4, max(1, absint($atts['columns'])))); ?>">
            <header class="tng-dp-destinations-heading">
                <?php if (!empty($atts['eyebrow'])): ?>
                    <span><?php echo esc_html($atts['eyebrow']); ?></span>
                <?php endif; ?>
                <h2><?php echo esc_html($atts['heading']); ?></h2>
            </header>

            <div class="tng-dp-destination-grid">
                <?php foreach ($posts as $post):
                    $counts = $this->service_counts($post->ID);
                    $total = array_sum($counts);
                    $image = get_the_post_thumbnail_url($post->ID, 'large');
                    $has_image = (bool)$image;
                    if (!$image) $image = TNG_OS_URL . 'assets/frontend/destination-placeholder.svg';

                    $visible = [];
                    foreach ($counts as $key => $count) {
                        if (!$count || empty($this->service_types[$key])) continue;
                        $visible[$key] = $count;
                        if (count($visible) >= 3) break;
                    }
                ?>
                    <article class="tng-dp-destination-card <?php echo $has_image ? 'has-photo' : 'has-placeholder'; ?>">
                        <a class="tng-dp-destination-image" href="<?php echo esc_url(get_permalink($post)); ?>">
                            <img src="<?php echo esc_url($image); ?>" alt="<?php echo esc_attr($post->post_title); ?>" loading="lazy">
                            <span class="tng-dp-destination-shade"></span>

                            <div class="tng-dp-destination-copy">
                                <span class="tng-dp-destination-label">DESTINATION</span>
                                <h3><?php echo esc_html($post->post_title); ?></h3>
                                <p><?php echo esc_html(number_format_i18n($total)); ?> places &amp; experiences</p>

                                <?php if ($visible): ?>
                                    <div class="tng-dp-destination-pills">
                                        <?php foreach ($visible as $key => $count): ?>
                                            <span>
                                                <?php echo esc_html($this->service_types[$key]['icon']); ?>
                                                <?php echo esc_html(number_format_i18n($count)); ?>
                                                <?php echo esc_html($this->service_types[$key]['label']); ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <span class="tng-dp-destination-coming">New experiences coming soon</span>
                                <?php endif; ?>
                            </div>

                            <span class="tng-dp-destination-arrow" aria-hidden="true">→</span>
                        </a>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
        <?php
        return (string)ob_get_clean();
    }

    public function near_me_shortcode(array $atts = []): string {
        $coordinate_count = (int)(new WP_Query([
            'post_type' => 'tng_destination',
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_query' => [
                'relation' => 'AND',
                ['key' => '_tng_destination_lat', 'compare' => 'EXISTS'],
                ['key' => '_tng_destination_lng', 'compare' => 'EXISTS'],
            ],
        ]))->found_posts;

        ob_start(); ?>
        <section class="tng-dp-near-me">
            <div class="tng-dp-panel-heading">
                <div><span>LOCATION DISCOVERY</span><h2>Near Me</h2></div>
                <span class="tng-dp-status"><?php echo number_format_i18n($coordinate_count); ?> mapped destinations</span>
            </div>
            <p>Find the nearest destinations and connected experiences using your current location.</p>
            <?php if ($coordinate_count): ?>
                <button type="button" data-tng-near-me>Find places near me</button>
            <?php elseif (current_user_can('edit_posts')): ?>
                <div class="tng-dp-notice">Add latitude and longitude to at least one published Destination before using Near Me.</div>
            <?php else: ?>
                <p>Nearby destinations are being mapped now.</p>
            <?php endif; ?>
            <div class="tng-dp-near-output" aria-live="polite" data-tng-near-results></div>
        </section>
        <?php return (string)ob_get_clean();
    }

    public function trip_planner_shortcode(array $atts = []): string {
        $destination = absint($atts['destination'] ?? get_the_ID());
        $plan = is_user_logged_in() ? $this->get_trip_plan(get_current_user_id()) : ['items' => []];
        $count = count($plan['items'] ?? []);

        ob_start(); ?>
        <section class="tng-dp-trip" data-trip-destination="<?php echo absint($destination); ?>">
            <div class="tng-dp-panel-heading">
                <div><span>PERSONAL ITINERARY</span><h2>Trip Planner</h2></div>
                <span class="tng-dp-status" data-tng-trip-count><?php echo number_format_i18n($count); ?> saved</span>
            </div>
            <p>Save trails, restaurants, attractions, lodging, and events while browsing the site.</p>
            <?php if (is_user_logged_in()): ?>
                <div data-tng-trip-panel>
                    <?php echo $this->trip_plan_html($plan); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                </div>
                <div class="tng-dp-trip-actions">
                    <button type="button" data-tng-trip-refresh>Refresh plan</button>
                    <?php if ($count): ?><button type="button" class="is-secondary" data-tng-trip-clear>Clear plan</button><?php endif; ?>
                </div>
            <?php else: ?>
                <p><a class="tng-dp-primary-link" href="<?php echo esc_url(wp_login_url(get_permalink())); ?>">Sign in to save a trip plan</a></p>
            <?php endif; ?>
        </section>
        <?php return (string)ob_get_clean();
    }

    public function leaderboard_shortcode(array $atts = []): string {
        $atts = shortcode_atts(['limit' => 10], $atts, 'tng_destination_leaderboard');
        $limit = max(3, min(50, absint($atts['limit'])));
        $points_type = $this->points_type();
        $ranked = [];

        foreach (get_users(['fields' => ['ID', 'display_name']]) as $user) {
            $xp = $this->user_xp((int)$user->ID, $points_type);
            if ($xp <= 0 && (int)$user->ID !== get_current_user_id()) continue;

            $ranked[] = [
                'user' => $user,
                'xp' => $xp,
                'miles' => (float)get_user_meta($user->ID, 'tng_odometer_miles', true),
                'checkpoints' => (int)get_user_meta($user->ID, 'tng_checkpoint_count', true),
            ];
        }

        usort($ranked, static fn(array $a, array $b): int => $b['xp'] <=> $a['xp']);
        $ranked = array_slice($ranked, 0, $limit);

        ob_start(); ?>
        <section class="tng-dp-leaderboard">
            <div class="tng-dp-panel-heading">
                <div><span>TOP EXPLORERS</span><h2>Explorer leaderboard</h2></div>
                <span class="tng-dp-status"><?php echo esc_html($points_type); ?></span>
            </div>
            <?php if ($ranked): ?>
                <ol>
                    <?php foreach ($ranked as $index => $entry): ?>
                        <li class="<?php echo (int)$entry['user']->ID === get_current_user_id() ? 'is-current-user' : ''; ?>">
                            <b class="tng-dp-rank"><?php echo absint($index + 1); ?></b>
                            <span class="tng-dp-player"><?php echo get_avatar($entry['user']->ID, 40); ?><span><strong><?php echo esc_html($entry['user']->display_name); ?></strong><small><?php echo number_format_i18n($entry['miles'], 2); ?> miles · <?php echo number_format_i18n($entry['checkpoints']); ?> checkpoints</small></span></span>
                            <strong><?php echo number_format_i18n($entry['xp']); ?> XP</strong>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php else: ?>
                <div class="tng-dp-empty"><strong>No ranked explorers yet.</strong><p>Players will appear after earning Explorer XP.</p></div>
            <?php endif; ?>
        </section>
        <?php return (string)ob_get_clean();
    }

    public function alerts_shortcode(array $atts = []): string {
        $destination = absint($atts['destination'] ?? get_the_ID());
        $alerts = get_posts(['post_type'=>'tng_local_alert','post_status'=>'publish','posts_per_page'=>10,'meta_query'=>['relation'=>'OR',['key'=>'_tng_destination_id','value'=>$destination],['key'=>'_tng_destination_id','value'=>0]]]);
        if (!$alerts) return '';
        ob_start(); ?><section class="tng-dp-alerts">
        <?php foreach ($alerts as $alert): $expires=get_post_meta($alert->ID,'_tng_alert_expires',true); if($expires && strtotime($expires)<time())continue; $severity=get_post_meta($alert->ID,'_tng_alert_severity',true)?:'info'; ?><article data-severity="<?php echo esc_attr($severity); ?>"><strong><?php echo esc_html($alert->post_title); ?></strong><div><?php echo wp_kses_post(wpautop($alert->post_content)); ?></div></article><?php endforeach; ?>
        </section><?php return (string)ob_get_clean();
    }

    private function passport_panel(int $destination_id): string {
        if (!is_user_logged_in()) return '<p>Sign in to track destination passport progress.</p>';
        $term_id = $this->destination_term($destination_id);
        $total = (int)(new WP_Query(['post_type'=>$this->supported_post_types(),'post_status'=>'publish','posts_per_page'=>1,'fields'=>'ids','tax_query'=>[['taxonomy'=>'tng_destination_ref','field'=>'term_id','terms'=>[$term_id]]]]))->found_posts;
        $completed = (int)get_user_meta(get_current_user_id(), 'tng_destination_' . $destination_id . '_completed', true);
        $percent = $total ? min(100, round(($completed/$total)*100)) : 0;
        return sprintf('<div class="tng-dp-passport"><div><span style="width:%d%%"></span></div><strong>%d%% complete</strong><p>%d of %d connected discoveries completed.</p></div>', $percent, $percent, $completed, $total);
    }

    private function itinerary_panel(int $destination_id): string {
        return sprintf('<div class="tng-dp-itinerary"><p>Create a balanced day using trails, food, sights and events connected to this destination.</p><button type="button" data-tng-itinerary data-destination="%d">Build itinerary</button><div aria-live="polite" data-tng-itinerary-results></div></div>', $destination_id);
    }

    private function weather_panel(int $destination_id): string {
        $location = get_post_meta($destination_id, '_tng_destination_weather_location', true);
        if (!$location) return '<p>Add a weather location in the destination editor to connect the existing trail weather system.</p>';
        return '<div class="tng-dp-weather-location" data-weather-location="' . esc_attr($location) . '"><strong>' . esc_html($location) . '</strong><p>Weather-ready destination. The existing TN Game weather provider can render here.</p></div>';
    }

    private function seasonal_panel(int $destination_id): string {
        $season = strtolower((string)get_post_meta($destination_id, '_tng_destination_season', true));
        $month = (int)current_time('n');
        $current = in_array($month,[12,1,2],true)?'Winter':(in_array($month,[3,4,5],true)?'Spring':(in_array($month,[6,7,8],true)?'Summer':'Fall'));
        return sprintf('<p><strong>%s picks:</strong> Recommendations automatically prioritize listings connected to the current season. Destination preference: %s.</p>', esc_html($current), esc_html($season ?: 'year-round'));
    }

    private function crowd_estimate(int $destination_id): array {
        $baseline = max(1,min(5,(int)get_post_meta($destination_id,'_tng_destination_crowd_baseline',true) ?: 2));
        $day = (int)current_time('N');
        $hour = (int)current_time('G');
        $score = $baseline + ($day>=6?1:0) + (($hour>=10&&$hour<=16)?1:0);
        $score = max(1,min(5,$score));
        $labels=[1=>'Very light',2=>'Light',3=>'Moderate',4=>'Busy',5=>'Very busy'];
        return ['score'=>$score,'label'=>$labels[$score]];
    }

    private function record_analytics(int $destination_id, string $event): void {
        $key = 'tng_destination_analytics_' . gmdate('Ymd');
        $data = get_option($key, []);
        if (!isset($data[$destination_id])) $data[$destination_id] = [];
        $data[$destination_id][$event] = (int)($data[$destination_id][$event] ?? 0) + 1;
        update_option($key, $data, false);
    }


    private function points_type(): string {
        $settings = get_option('tng_progression_settings', []);
        return sanitize_key($settings['points_type'] ?? 'explorer-xp');
    }

    private function user_xp(int $user_id, string $points_type = ''): int {
        $points_type = $points_type ?: $this->points_type();

        if (function_exists('gamipress_get_user_points')) {
            return absint(gamipress_get_user_points($user_id, $points_type));
        }

        foreach ([
            '_gamipress_' . $points_type . '_points',
            $points_type,
            'tng_total_xp',
        ] as $key) {
            $value = get_user_meta($user_id, $key, true);
            if (is_numeric($value)) return absint($value);
        }

        return 0;
    }

    private function normalize_trip_plan($plan): array {
        if (!is_array($plan)) $plan = [];
        $items = [];

        foreach ((array)($plan['items'] ?? []) as $item) {
            $post_id = absint(is_array($item) ? ($item['id'] ?? 0) : $item);
            if (!$post_id || get_post_status($post_id) !== 'publish') continue;

            $items[$post_id] = [
                'id' => $post_id,
                'title' => get_the_title($post_id),
                'url' => get_permalink($post_id),
                'type' => get_post_type($post_id),
                'image' => get_the_post_thumbnail_url($post_id, 'thumbnail') ?: '',
                'destination' => absint(get_post_meta($post_id, '_tng_destination_id', true)),
            ];
        }

        return [
            'items' => array_values($items),
            'updated' => sanitize_text_field((string)($plan['updated'] ?? '')),
        ];
    }

    private function get_trip_plan(int $user_id): array {
        return $this->normalize_trip_plan(get_user_meta($user_id, 'tng_trip_plan', true));
    }

    private function trip_plan_html(array $plan): string {
        $items = $plan['items'] ?? [];
        ob_start();

        if (!$items) {
            echo '<div class="tng-dp-empty"><strong>Your plan is empty.</strong><p>Use “Add to trip” on destination recommendations and listing cards.</p></div>';
        } else {
            echo '<ol class="tng-dp-trip-list">';
            foreach ($items as $index => $item) {
                ?>
                <li>
                    <b><?php echo absint($index + 1); ?></b>
                    <?php if (!empty($item['image'])): ?><img src="<?php echo esc_url($item['image']); ?>" alt=""><?php endif; ?>
                    <span><a href="<?php echo esc_url($item['url']); ?>"><?php echo esc_html($item['title']); ?></a><small><?php echo esc_html(get_post_type_object($item['type'])->labels->singular_name ?? 'Experience'); ?></small></span>
                    <button type="button" aria-label="Remove <?php echo esc_attr($item['title']); ?>" data-tng-trip-remove="<?php echo absint($item['id']); ?>">×</button>
                </li>
                <?php
            }
            echo '</ol>';
        }

        return (string)ob_get_clean();
    }

    private function listing_coordinates(int $post_id): array {
        $lat_keys = ['_tng_lat', 'map_lat', 'latitude', 'lat'];
        $lng_keys = ['_tng_lng', 'map_lng', 'longitude', 'lng'];

        $lat = 0.0;
        $lng = 0.0;

        foreach ($lat_keys as $key) {
            $value = get_post_meta($post_id, $key, true);
            if (is_numeric($value)) { $lat = (float)$value; break; }
        }
        foreach ($lng_keys as $key) {
            $value = get_post_meta($post_id, $key, true);
            if (is_numeric($value)) { $lng = (float)$value; break; }
        }

        if (!$lat || !$lng) {
            foreach (['st_google_map', 'map_data', 'location'] as $key) {
                $value = get_post_meta($post_id, $key, true);
                if (is_string($value) && strpos(trim($value), '{') === 0) {
                    $decoded = json_decode($value, true);
                    if (is_array($decoded)) {
                        $lat = (float)($decoded['lat'] ?? $decoded['latitude'] ?? $lat);
                        $lng = (float)($decoded['lng'] ?? $decoded['longitude'] ?? $lng);
                    }
                } elseif (is_array($value)) {
                    $lat = (float)($value['lat'] ?? $value['latitude'] ?? $lat);
                    $lng = (float)($value['lng'] ?? $value['longitude'] ?? $lng);
                }
            }
        }

        return [$lat, $lng];
    }

    public function rest_routes(): void {
        register_rest_route('tng-os/v1','/destinations/nearby',[
            'methods'=>'GET','permission_callback'=>'__return_true','callback'=>[$this,'rest_nearby'],
            'args'=>['lat'=>['required'=>true],'lng'=>['required'=>true]],
        ]);
        register_rest_route('tng-os/v1','/destinations/(?P<id>\d+)/itinerary',[
            'methods'=>'POST','permission_callback'=>'__return_true','callback'=>[$this,'rest_itinerary'],
        ]);
        register_rest_route('tng-os/v1','/trip-plan',[
            'methods'=>['GET','POST'],'permission_callback'=>fn()=>is_user_logged_in(),'callback'=>[$this,'rest_trip_plan'],
        ]);
        register_rest_route('tng-os/v1','/destinations/(?P<id>\d+)/analytics',[
            'methods'=>'GET','permission_callback'=>fn()=>current_user_can('manage_options'),'callback'=>[$this,'rest_analytics'],
        ]);
    }

    public function rest_nearby(WP_REST_Request $request): WP_REST_Response {
        $lat = (float)$request['lat'];
        $lng = (float)$request['lng'];
        $destinations = [];
        $experiences = [];

        foreach (get_posts(['post_type' => 'tng_destination', 'post_status' => 'publish', 'posts_per_page' => -1]) as $post) {
            $dlat = (float)get_post_meta($post->ID, '_tng_destination_lat', true);
            $dlng = (float)get_post_meta($post->ID, '_tng_destination_lng', true);
            if (!$dlat || !$dlng) continue;

            $distance = $this->distance($lat, $lng, $dlat, $dlng);
            $destinations[] = [
                'id' => $post->ID,
                'title' => $post->post_title,
                'url' => get_permalink($post),
                'distance' => round($distance, 1),
                'type' => 'Destination',
            ];
        }

        foreach ($this->supported_post_types() as $post_type) {
            if (!post_type_exists($post_type) || in_array($post_type, ['post', 'page'], true)) continue;

            foreach (get_posts([
                'post_type' => $post_type,
                'post_status' => 'publish',
                'posts_per_page' => 150,
                'fields' => 'ids',
            ]) as $post_id) {
                [$plat, $plng] = $this->listing_coordinates((int)$post_id);
                if (!$plat || !$plng) continue;

                $distance = $this->distance($lat, $lng, $plat, $plng);
                if ($distance > 30) continue;

                $object = get_post_type_object($post_type);
                $experiences[] = [
                    'id' => (int)$post_id,
                    'title' => get_the_title($post_id),
                    'url' => get_permalink($post_id),
                    'distance' => round($distance, 1),
                    'type' => $object ? $object->labels->singular_name : 'Experience',
                ];
            }
        }

        usort($destinations, static fn(array $a, array $b): int => $a['distance'] <=> $b['distance']);
        usort($experiences, static fn(array $a, array $b): int => $a['distance'] <=> $b['distance']);

        return new WP_REST_Response([
            'destinations' => array_slice($destinations, 0, 6),
            'experiences' => array_slice($experiences, 0, 12),
        ]);
    }

    private function distance(float $lat1,float $lng1,float $lat2,float $lng2): float {
        $earth=3958.8;$dlat=deg2rad($lat2-$lat1);$dlng=deg2rad($lng2-$lng1);
        $a=sin($dlat/2)**2+cos(deg2rad($lat1))*cos(deg2rad($lat2))*sin($dlng/2)**2;
        return $earth*2*atan2(sqrt($a),sqrt(1-$a));
    }

    public function rest_itinerary(WP_REST_Request $request): WP_REST_Response {
        $id=absint($request['id']);$items=[];
        foreach(['trails','food','history','scenic','concerts'] as $service){
            $posts=$this->listing_query($id,$service,2)->posts;
            if($posts)$items[]=['time'=>$service==='food'?'12:30 PM':(count($items)?'2:00 PM':'9:00 AM'),'type'=>$this->service_types[$service]['label'],'title'=>$posts[0]->post_title,'url'=>get_permalink($posts[0])];
        }
        $items=apply_filters('tng_destination_itinerary_items',$items,$id,$request);
        return new WP_REST_Response(['destination'=>get_the_title($id),'items'=>$items,'mode'=>'smart-rule-based','ai_ready'=>true]);
    }

    public function rest_trip_plan(WP_REST_Request $request): WP_REST_Response {
        $user_id = get_current_user_id();
        $key = 'tng_trip_plan';
        $plan = $this->get_trip_plan($user_id);

        if ($request->get_method() === 'POST') {
            $payload = (array)$request->get_json_params();
            $action = sanitize_key($payload['action'] ?? 'replace');
            $post_id = absint($payload['post_id'] ?? 0);
            $ids = array_map('absint', wp_list_pluck($plan['items'], 'id'));

            if ($action === 'add' && $post_id && get_post_status($post_id) === 'publish') {
                $ids[] = $post_id;
            } elseif ($action === 'remove' && $post_id) {
                $ids = array_values(array_diff($ids, [$post_id]));
            } elseif ($action === 'clear') {
                $ids = [];
            } elseif ($action === 'replace') {
                $ids = array_map('absint', (array)($payload['items'] ?? []));
            }

            $plan = $this->normalize_trip_plan([
                'items' => array_values(array_unique($ids)),
                'updated' => current_time('mysql'),
            ]);
            update_user_meta($user_id, $key, $plan);
        }

        return new WP_REST_Response([
            'plan' => $plan,
            'html' => $this->trip_plan_html($plan),
            'count' => count($plan['items']),
        ]);
    }

    public function rest_analytics(WP_REST_Request $request): WP_REST_Response {
        $id=absint($request['id']);$days=[];
        for($i=0;$i<30;$i++){$date=gmdate('Ymd',strtotime("-$i days"));$data=get_option('tng_destination_analytics_'.$date,[]);$days[$date]=$data[$id]??[];}
        return new WP_REST_Response($days);
    }

    public function quick_create(): void { wp_send_json_error(['message'=>'Use Destinations → Add Destination.']); }
}
