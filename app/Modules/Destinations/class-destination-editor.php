<?php
namespace TNG_OS\Modules\Destinations;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;
use WP_Post;
use WP_Query;

if (!defined('ABSPATH')) exit;

final class Destination_Editor implements Module_Interface {
    private Container $container;

    public function id(): string {
        return 'destination_editor';
    }

    public function register(Container $container): void {
        $this->container = $container;

        add_action('admin_menu', [$this, 'menu'], 25);
        add_action('load-post.php', [$this, 'redirect_standard_editor']);
        add_action('load-post-new.php', [$this, 'redirect_new_editor']);
        add_filter('get_edit_post_link', [$this, 'edit_link'], 20, 3);
        add_filter('post_row_actions', [$this, 'row_actions'], 20, 2);

        add_action('admin_enqueue_scripts', [$this, 'assets']);
        add_action('admin_post_tng_destination_studio_save', [$this, 'save']);
    }

    public function boot(Container $container): void {}

    public function menu(): void {
        add_submenu_page(
            null,
            'Destination Studio',
            'Destination Studio',
            'edit_posts',
            'tng-destination-studio',
            [$this, 'page']
        );
    }

    public function edit_link(string $link, int $post_id, string $context): string {
        if (get_post_type($post_id) !== 'tng_destination') return $link;
        if (!current_user_can('edit_post', $post_id)) return $link;

        return admin_url('admin.php?page=tng-destination-studio&destination_id=' . $post_id);
    }

    public function row_actions(array $actions, WP_Post $post): array {
        if ($post->post_type !== 'tng_destination') return $actions;

        $url = admin_url('admin.php?page=tng-destination-studio&destination_id=' . $post->ID);
        if (isset($actions['edit'])) {
            $actions['edit'] = '<a href="' . esc_url($url) . '">Edit in Destination Studio</a>';
        }

        return $actions;
    }

    public function redirect_standard_editor(): void {
        if (!isset($_GET['post'])) return;
        $post_id = absint($_GET['post']);
        if (!$post_id || get_post_type($post_id) !== 'tng_destination') return;
        if (isset($_GET['classic_destination_editor'])) return;
        if (!current_user_can('edit_post', $post_id)) return;

        wp_safe_redirect(admin_url('admin.php?page=tng-destination-studio&destination_id=' . $post_id));
        exit;
    }

    public function redirect_new_editor(): void {
        $post_type = isset($_GET['post_type']) ? sanitize_key(wp_unslash($_GET['post_type'])) : '';
        if ($post_type !== 'tng_destination') return;
        if (isset($_GET['classic_destination_editor'])) return;
        if (!current_user_can('edit_posts')) return;

        $post_id = wp_insert_post([
            'post_type' => 'tng_destination',
            'post_status' => 'draft',
            'post_title' => 'New Destination',
        ], true);

        if (is_wp_error($post_id)) return;

        wp_safe_redirect(admin_url('admin.php?page=tng-destination-studio&destination_id=' . absint($post_id) . '&new=1'));
        exit;
    }

    public function assets(string $hook): void {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ($page !== 'tng-destination-studio') return;

        wp_enqueue_media();
        wp_enqueue_editor();

        wp_enqueue_style(
            'tng-destination-studio',
            TNG_OS_URL . 'assets/admin/destination-editor.css',
            [],
            TNG_OS_VERSION
        );

        wp_enqueue_script(
            'tng-destination-studio',
            TNG_OS_URL . 'assets/admin/destination-editor.js',
            ['jquery', 'wp-editor'],
            TNG_OS_VERSION,
            true
        );

        wp_localize_script('tng-destination-studio', 'TNGDestinationStudio', [
            'confirmLeave' => 'You have unsaved destination changes.',
            'mediaTitle' => 'Choose a destination image',
            'mediaButton' => 'Use this image',
        ]);
    }

    private function destination_id(): int {
        return isset($_GET['destination_id']) ? absint($_GET['destination_id']) : 0;
    }

    private function fields(int $post_id): array {
        return [
            'tagline' => (string)get_post_meta($post_id, '_tng_destination_tagline', true),
            'lat' => (string)get_post_meta($post_id, '_tng_destination_lat', true),
            'lng' => (string)get_post_meta($post_id, '_tng_destination_lng', true),
            'radius' => (string)(get_post_meta($post_id, '_tng_destination_radius', true) ?: '15'),
            'weather' => (string)get_post_meta($post_id, '_tng_destination_weather_location', true),
            'season' => (string)get_post_meta($post_id, '_tng_destination_season', true),
            'crowd' => (string)(get_post_meta($post_id, '_tng_destination_crowd_baseline', true) ?: '2'),
            'county' => (string)get_post_meta($post_id, '_tng_destination_county', true),
            'region' => (string)get_post_meta($post_id, '_tng_destination_region', true),
            'featured' => (bool)get_post_meta($post_id, '_tng_destination_featured', true),
            'history' => (string)get_post_meta($post_id, '_tng_destination_history', true),
            'why_visit' => (string)get_post_meta($post_id, '_tng_destination_why_visit', true),
            'local_tips' => (string)get_post_meta($post_id, '_tng_destination_local_tips', true),
            'passport_stamp' => (string)get_post_meta($post_id, '_tng_destination_passport_stamp', true),
            'xp_bonus' => (string)(get_post_meta($post_id, '_tng_destination_xp_bonus', true) ?: '0'),
            'seasonal_challenge' => (string)get_post_meta($post_id, '_tng_destination_seasonal_challenge', true),
            'seo_title' => (string)get_post_meta($post_id, '_tng_destination_seo_title', true),
            'seo_description' => (string)get_post_meta($post_id, '_tng_destination_seo_description', true),
            'analytics_note' => (string)get_post_meta($post_id, '_tng_destination_analytics_note', true),
        ];
    }

    private function destination_term_id(int $post_id): int {
        $term_id = absint(get_post_meta($post_id, '_tng_destination_term_id', true));
        if ($term_id) return $term_id;

        $term = get_term_by('slug', 'destination-' . $post_id, 'tng_destination_ref');
        return $term && !is_wp_error($term) ? absint($term->term_id) : 0;
    }

    private function linked_query(int $post_id, array $post_types = [], int $limit = 50): WP_Query {
        $term_id = $this->destination_term_id($post_id);
        if (!$post_types) {
            $post_types = $this->supported_post_types();
        }

        $args = [
            'post_type' => $post_types ?: 'any',
            'post_status' => ['publish', 'draft', 'pending', 'private'],
            'posts_per_page' => $limit,
            'orderby' => 'modified',
            'order' => 'DESC',
        ];

        if ($term_id) {
            $args['tax_query'] = [[
                'taxonomy' => 'tng_destination_ref',
                'field' => 'term_id',
                'terms' => [$term_id],
            ]];
        } else {
            $args['meta_query'] = [[
                'key' => '_tng_destination_id',
                'value' => $post_id,
                'compare' => '=',
                'type' => 'NUMERIC',
            ]];
        }

        return new WP_Query($args);
    }

    private function supported_post_types(): array {
        $destination_module = $this->container->get('destinations');

        if ($destination_module && is_callable([$destination_module, 'supported_post_types'])) {
            return array_values(array_filter(
                $destination_module->supported_post_types(),
                static fn(string $type): bool => !in_array($type, ['post', 'page'], true)
            ));
        }

        return array_values(array_filter(
            ['st_activity', 'st_hotel', 'st_tours', 'st_rental', 'st_cars', 'top_sight'],
            'post_type_exists'
        ));
    }

    private function counts(int $post_id): array {
        $definitions = [
            'Activities' => ['st_activity'],
            'Hotels' => ['st_hotel'],
            'Tours' => ['st_tours', 'st_tour'],
            'Rentals' => ['st_rental'],
            'Cars' => ['st_cars', 'st_car'],
            'Top Sights' => ['top_sight', 'tng_top_sight'],
        ];

        $counts = [];
        foreach ($definitions as $label => $types) {
            $types = array_values(array_filter($types, 'post_type_exists'));
            if (!$types) continue;
            $counts[$label] = (int)$this->linked_query($post_id, $types, 1)->found_posts;
        }

        return $counts;
    }

    private function analytics(int $post_id): array {
        $views = 0;
        $events = 0;

        for ($i = 0; $i < 30; $i++) {
            $date = gmdate('Ymd', strtotime("-{$i} days"));
            $data = get_option('tng_destination_analytics_' . $date, []);
            $record = $data[$post_id] ?? [];
            $views += absint($record['view'] ?? 0);
            $events += array_sum(array_map('absint', is_array($record) ? $record : []));
        }

        return [
            'views' => $views,
            'events' => $events,
            'trip_saves' => absint(get_post_meta($post_id, '_tng_destination_trip_saves', true)),
            'searches' => absint(get_post_meta($post_id, '_tng_destination_searches', true)),
        ];
    }

    private function status_label(WP_Post $post): string {
        $object = get_post_status_object($post->post_status);
        return $object ? $object->label : ucfirst($post->post_status);
    }

    public function page(): void {
        $post_id = $this->destination_id();
        $post = $post_id ? get_post($post_id) : null;

        if (!$post || $post->post_type !== 'tng_destination') {
            wp_die('Destination not found.');
        }

        if (!current_user_can('edit_post', $post_id)) {
            wp_die('You are not allowed to edit this destination.');
        }

        $fields = $this->fields($post_id);
        $counts = $this->counts($post_id);
        $analytics = $this->analytics($post_id);
        $thumbnail_id = get_post_thumbnail_id($post_id);
        $thumbnail_url = $thumbnail_id ? wp_get_attachment_image_url($thumbnail_id, 'large') : '';
        $linked = $this->linked_query($post_id, [], 100)->posts;
        $preview_url = get_permalink($post_id);
        $classic_url = admin_url('post.php?post=' . $post_id . '&action=edit&classic_destination_editor=1');
        ?>
        <div class="wrap tng-destination-studio" data-destination-studio>
            <?php if (!empty($_GET['updated'])): ?>
                <div class="notice notice-success is-dismissible"><p>Destination saved.</p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-destination-form>
                <input type="hidden" name="action" value="tng_destination_studio_save">
                <input type="hidden" name="destination_id" value="<?php echo absint($post_id); ?>">
                <input type="hidden" name="thumbnail_id" value="<?php echo absint($thumbnail_id); ?>" data-thumbnail-id>
                <?php wp_nonce_field('tng_destination_studio_save_' . $post_id, 'tng_destination_studio_nonce'); ?>

                <header class="tng-ds-header">
                    <div class="tng-ds-title">
                        <a class="tng-ds-back" href="<?php echo esc_url(admin_url('edit.php?post_type=tng_destination')); ?>">← Destinations</a>
                        <div>
                            <span>DESTINATION STUDIO</span>
                            <input type="text" name="post_title" value="<?php echo esc_attr($post->post_title); ?>" aria-label="Destination name" required>
                            <p><?php echo esc_html($this->status_label($post)); ?> · Last saved <?php echo esc_html(human_time_diff(get_post_modified_time('U', true, $post), current_time('timestamp'))); ?> ago</p>
                        </div>
                    </div>

                    <div class="tng-ds-header-actions">
                        <a class="button" href="<?php echo esc_url($classic_url); ?>">Classic editor</a>
                        <?php if ($post->post_status === 'publish'): ?><a class="button" target="_blank" href="<?php echo esc_url($preview_url); ?>">View destination ↗</a><?php endif; ?>
                        <select name="post_status" aria-label="Publishing status">
                            <?php foreach (['draft' => 'Draft', 'pending' => 'Pending Review', 'publish' => 'Published', 'private' => 'Private'] as $status => $label): ?>
                                <option value="<?php echo esc_attr($status); ?>" <?php selected($post->post_status, $status); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="button button-primary button-hero">Save Destination</button>
                    </div>
                </header>

                <nav class="tng-ds-tabs" aria-label="Destination editor sections">
                    <?php
                    $tabs = [
                        'overview' => 'Overview',
                        'discovery' => 'Discovery',
                        'businesses' => 'Businesses',
                        'explorer' => 'Explorer',
                        'analytics' => 'Analytics',
                        'settings' => 'Settings',
                    ];
                    foreach ($tabs as $id => $label):
                    ?>
                        <button type="button" class="<?php echo $id === 'overview' ? 'is-active' : ''; ?>" data-ds-tab="<?php echo esc_attr($id); ?>"><?php echo esc_html($label); ?></button>
                    <?php endforeach; ?>
                </nav>

                <div class="tng-ds-layout">
                    <main class="tng-ds-main">
                        <section class="tng-ds-panel is-active" data-ds-panel="overview">
                            <div class="tng-ds-section-heading">
                                <div><span>ESSENTIALS</span><h2>Destination overview</h2><p>The primary identity and public introduction for this destination.</p></div>
                            </div>

                            <div class="tng-ds-hero-editor">
                                <div class="tng-ds-image-picker <?php echo $thumbnail_url ? 'has-image' : ''; ?>" data-image-picker>
                                    <img src="<?php echo esc_url($thumbnail_url); ?>" alt="" data-image-preview <?php echo $thumbnail_url ? '' : 'hidden'; ?>>
                                    <div data-image-empty <?php echo $thumbnail_url ? 'hidden' : ''; ?>>
                                        <span class="dashicons dashicons-format-image"></span>
                                        <strong>Add destination hero image</strong>
                                        <small>Recommended: 1600 × 900 or larger</small>
                                    </div>
                                    <button type="button" class="button" data-select-image><?php echo $thumbnail_url ? 'Change image' : 'Choose image'; ?></button>
                                    <button type="button" class="button-link-delete" data-remove-image <?php echo $thumbnail_url ? '' : 'hidden'; ?>>Remove</button>
                                </div>

                                <div class="tng-ds-fields">
                                    <label><span>Tagline</span><input type="text" name="tagline" value="<?php echo esc_attr($fields['tagline']); ?>" placeholder="The heart of Tennessee South Cumberland"></label>
                                    <label><span>Short summary</span><textarea name="post_excerpt" rows="4" placeholder="A short destination summary used in cards and search results."><?php echo esc_textarea($post->post_excerpt); ?></textarea></label>
                                    <label class="tng-ds-check"><input type="checkbox" name="featured" value="1" <?php checked($fields['featured']); ?>><span>Feature this destination throughout TN Game OS</span></label>
                                </div>
                            </div>

                            <div class="tng-ds-editor-card">
                                <label><span>Destination overview</span></label>
                                <?php
                                wp_editor(
                                    $post->post_content,
                                    'tng_destination_overview',
                                    [
                                        'textarea_name' => 'post_content',
                                        'textarea_rows' => 12,
                                        'media_buttons' => true,
                                        'teeny' => false,
                                        'quicktags' => true,
                                    ]
                                );
                                ?>
                            </div>

                            <div class="tng-ds-content-grid">
                                <label><span>Why visit</span><textarea name="why_visit" rows="7" placeholder="What makes this destination special?"><?php echo esc_textarea($fields['why_visit']); ?></textarea></label>
                                <label><span>Local tips</span><textarea name="local_tips" rows="7" placeholder="Parking, timing, local knowledge, and practical advice."><?php echo esc_textarea($fields['local_tips']); ?></textarea></label>
                                <label class="is-wide"><span>History and character</span><textarea name="history" rows="7" placeholder="History, identity, stories, and cultural context."><?php echo esc_textarea($fields['history']); ?></textarea></label>
                            </div>
                        </section>

                        <section class="tng-ds-panel" data-ds-panel="discovery">
                            <div class="tng-ds-section-heading">
                                <div><span>LOCATION INTELLIGENCE</span><h2>Discovery and maps</h2><p>Coordinates, map behavior, weather, nearby search, and regional organization.</p></div>
                            </div>

                            <div class="tng-ds-map-card">
                                <div class="tng-ds-map-preview" data-map-preview>
                                    <span class="dashicons dashicons-location-alt"></span>
                                    <strong><?php echo esc_html($post->post_title); ?></strong>
                                    <small data-map-coordinate><?php echo esc_html(($fields['lat'] && $fields['lng']) ? $fields['lat'] . ', ' . $fields['lng'] : 'Coordinates not set'); ?></small>
                                    <?php if ($fields['lat'] && $fields['lng']): ?>
                                        <a target="_blank" href="https://www.google.com/maps/search/?api=1&query=<?php echo rawurlencode($fields['lat'] . ',' . $fields['lng']); ?>">Open map ↗</a>
                                    <?php endif; ?>
                                </div>

                                <div class="tng-ds-form-grid">
                                    <label><span>Latitude</span><input type="text" name="lat" value="<?php echo esc_attr($fields['lat']); ?>" data-lat></label>
                                    <label><span>Longitude</span><input type="text" name="lng" value="<?php echo esc_attr($fields['lng']); ?>" data-lng></label>
                                    <label><span>Near Me radius</span><div class="tng-ds-input-unit"><input type="number" step="0.1" min="0" name="radius" value="<?php echo esc_attr($fields['radius']); ?>"><i>miles</i></div></label>
                                    <label><span>Weather location</span><input type="text" name="weather" value="<?php echo esc_attr($fields['weather']); ?>" placeholder="Tracy City, TN"></label>
                                    <label><span>County</span><input type="text" name="county" value="<?php echo esc_attr($fields['county']); ?>" placeholder="Grundy County"></label>
                                    <label><span>Region</span><input type="text" name="region" value="<?php echo esc_attr($fields['region']); ?>" placeholder="South Cumberland"></label>
                                    <label><span>Best season</span><input type="text" name="season" value="<?php echo esc_attr($fields['season']); ?>" placeholder="Spring and fall"></label>
                                    <label><span>Typical crowd level</span><select name="crowd">
                                        <?php foreach ([1 => 'Very light', 2 => 'Light', 3 => 'Moderate', 4 => 'Busy', 5 => 'Very busy'] as $value => $label): ?>
                                            <option value="<?php echo absint($value); ?>" <?php selected((int)$fields['crowd'], $value); ?>><?php echo esc_html($label); ?></option>
                                        <?php endforeach; ?>
                                    </select></label>
                                </div>
                            </div>

                            <div class="tng-ds-action-grid">
                                <article><span class="dashicons dashicons-location"></span><div><strong>Near Me</strong><p>Uses the saved coordinates and radius to surface this destination.</p></div><i><?php echo ($fields['lat'] && $fields['lng']) ? 'Ready' : 'Needs coordinates'; ?></i></article>
                                <article><span class="dashicons dashicons-cloud"></span><div><strong>Weather</strong><p>Connects destination forecasts and seasonal recommendations.</p></div><i><?php echo $fields['weather'] ? 'Ready' : 'Needs location'; ?></i></article>
                                <article><span class="dashicons dashicons-admin-site-alt3"></span><div><strong>Regional discovery</strong><p>County and region organize nearby towns and trip planning.</p></div><i><?php echo ($fields['county'] || $fields['region']) ? 'Ready' : 'Optional'; ?></i></article>
                            </div>
                        </section>

                        <section class="tng-ds-panel" data-ds-panel="businesses">
                            <div class="tng-ds-section-heading">
                                <div><span>CONNECTED CONTENT</span><h2>Businesses and experiences</h2><p>Everything connected to this destination appears here automatically.</p></div>
                                <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=tn-game-content-wizard&destination_id=' . $post_id)); ?>">+ Create connected content</a>
                            </div>

                            <div class="tng-ds-stat-grid">
                                <?php foreach ($counts as $label => $count): ?>
                                    <article><strong><?php echo number_format_i18n($count); ?></strong><span><?php echo esc_html($label); ?></span></article>
                                <?php endforeach; ?>
                                <article><strong><?php echo number_format_i18n(array_sum($counts)); ?></strong><span>Total connected</span></article>
                            </div>

                            <div class="tng-ds-linked-toolbar">
                                <input type="search" placeholder="Filter connected content…" data-linked-filter>
                                <select data-linked-type>
                                    <option value="">All content types</option>
                                    <?php
                                    $seen_types = [];
                                    foreach ($linked as $linked_post):
                                        if (isset($seen_types[$linked_post->post_type])) continue;
                                        $seen_types[$linked_post->post_type] = true;
                                        $object = get_post_type_object($linked_post->post_type);
                                    ?>
                                        <option value="<?php echo esc_attr($linked_post->post_type); ?>"><?php echo esc_html($object ? $object->labels->name : $linked_post->post_type); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="tng-ds-linked-list" data-linked-list>
                                <?php if (!$linked): ?>
                                    <div class="tng-ds-empty"><span class="dashicons dashicons-admin-links"></span><strong>No connected content yet</strong><p>Edit a trail, restaurant, hotel, attraction, or event and select this Destination.</p></div>
                                <?php else: ?>
                                    <?php foreach ($linked as $linked_post):
                                        $object = get_post_type_object($linked_post->post_type);
                                        $image = get_the_post_thumbnail_url($linked_post->ID, 'thumbnail');
                                    ?>
                                        <article data-linked-item data-title="<?php echo esc_attr(strtolower($linked_post->post_title)); ?>" data-type="<?php echo esc_attr($linked_post->post_type); ?>">
                                            <div class="tng-ds-linked-image">
                                                <?php if ($image): ?><img src="<?php echo esc_url($image); ?>" alt=""><?php else: ?><span class="dashicons dashicons-admin-post"></span><?php endif; ?>
                                            </div>
                                            <div><strong><?php echo esc_html($linked_post->post_title ?: '(Untitled)'); ?></strong><small><?php echo esc_html($object ? $object->labels->singular_name : $linked_post->post_type); ?> · <?php echo esc_html(ucfirst($linked_post->post_status)); ?></small></div>
                                            <a class="button" href="<?php echo esc_url(get_edit_post_link($linked_post->ID)); ?>">Edit</a>
                                        </article>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </section>

                        <section class="tng-ds-panel" data-ds-panel="explorer">
                            <div class="tng-ds-section-heading">
                                <div><span>GAMIFICATION</span><h2>Explorer experience</h2><p>Passport identity, XP bonuses, challenges, and destination progression.</p></div>
                            </div>

                            <div class="tng-ds-explorer-layout">
                                <div class="tng-ds-passport-preview">
                                    <div class="tng-ds-stamp"><?php echo esc_html($fields['passport_stamp'] ?: '📍'); ?></div>
                                    <span>PASSPORT DESTINATION</span>
                                    <strong><?php echo esc_html($post->post_title); ?></strong>
                                    <small><?php echo esc_html($fields['region'] ?: 'TN Game Explorer'); ?></small>
                                </div>

                                <div class="tng-ds-form-stack">
                                    <label><span>Passport stamp or emoji</span><input type="text" name="passport_stamp" value="<?php echo esc_attr($fields['passport_stamp']); ?>" placeholder="📍"></label>
                                    <label><span>Destination XP bonus</span><div class="tng-ds-input-unit"><input type="number" min="0" step="1" name="xp_bonus" value="<?php echo esc_attr($fields['xp_bonus']); ?>"><i>XP</i></div></label>
                                    <label><span>Seasonal challenge</span><textarea name="seasonal_challenge" rows="6" placeholder="Describe a seasonal challenge or destination mission."><?php echo esc_textarea($fields['seasonal_challenge']); ?></textarea></label>
                                </div>
                            </div>

                            <div class="tng-ds-action-grid">
                                <article><span class="dashicons dashicons-awards"></span><div><strong>Passport progress</strong><p>Tracks discoveries connected to this destination.</p></div><i>Automatic</i></article>
                                <article><span class="dashicons dashicons-chart-line"></span><div><strong>Leaderboard</strong><p>Destination explorers rank through existing Explorer XP.</p></div><i>Connected</i></article>
                                <article><span class="dashicons dashicons-groups"></span><div><strong>Challenges</strong><p>Seasonal and destination-specific missions can be promoted here.</p></div><i><?php echo $fields['seasonal_challenge'] ? 'Configured' : 'Optional'; ?></i></article>
                            </div>
                        </section>

                        <section class="tng-ds-panel" data-ds-panel="analytics">
                            <div class="tng-ds-section-heading">
                                <div><span>PERFORMANCE</span><h2>Destination analytics</h2><p>A practical overview of destination interest and connected inventory.</p></div>
                            </div>

                            <div class="tng-ds-analytics-grid">
                                <article><span>Last 30 days</span><strong><?php echo number_format_i18n($analytics['views']); ?></strong><small>Destination page views</small></article>
                                <article><span>Recorded events</span><strong><?php echo number_format_i18n($analytics['events']); ?></strong><small>All destination interactions</small></article>
                                <article><span>Trip saves</span><strong><?php echo number_format_i18n($analytics['trip_saves']); ?></strong><small>Saved to trip plans</small></article>
                                <article><span>Searches</span><strong><?php echo number_format_i18n($analytics['searches']); ?></strong><small>Discovery searches</small></article>
                                <article><span>Inventory</span><strong><?php echo number_format_i18n(array_sum($counts)); ?></strong><small>Connected listings</small></article>
                                <article><span>Readiness</span><strong><?php echo ($fields['lat'] && $fields['lng'] && $fields['weather']) ? 'Ready' : 'Setup'; ?></strong><small>Discovery system status</small></article>
                            </div>

                            <label class="tng-ds-analytics-note"><span>Internal analytics note</span><textarea name="analytics_note" rows="7" placeholder="Campaign notes, destination goals, reporting context, or seasonal observations."><?php echo esc_textarea($fields['analytics_note']); ?></textarea></label>
                        </section>

                        <section class="tng-ds-panel" data-ds-panel="settings">
                            <div class="tng-ds-section-heading">
                                <div><span>PUBLISHING AND SEARCH</span><h2>Destination settings</h2><p>Search presentation, permanent links, and advanced publishing controls.</p></div>
                            </div>

                            <div class="tng-ds-form-stack">
                                <label><span>SEO title</span><input type="text" name="seo_title" value="<?php echo esc_attr($fields['seo_title']); ?>" placeholder="<?php echo esc_attr($post->post_title); ?>"></label>
                                <label><span>SEO description</span><textarea name="seo_description" rows="5" maxlength="320" placeholder="Describe the destination for search results."><?php echo esc_textarea($fields['seo_description']); ?></textarea></label>
                                <label><span>Destination slug</span><div class="tng-ds-slug"><code><?php echo esc_html(home_url('/destinations/')); ?></code><input type="text" name="post_name" value="<?php echo esc_attr($post->post_name); ?>"></div></label>
                            </div>

                            <div class="tng-ds-danger-zone">
                                <div><strong>Advanced WordPress editor</strong><p>Open the underlying post editor for troubleshooting or plugin compatibility.</p></div>
                                <a class="button" href="<?php echo esc_url($classic_url); ?>">Open classic destination editor</a>
                            </div>
                        </section>
                    </main>

                    <aside class="tng-ds-sidebar">
                        <section class="tng-ds-summary-card">
                            <div class="tng-ds-summary-image">
                                <?php if ($thumbnail_url): ?><img src="<?php echo esc_url($thumbnail_url); ?>" alt="" data-sidebar-image><?php else: ?><div data-sidebar-empty><span class="dashicons dashicons-location-alt"></span></div><?php endif; ?>
                            </div>
                            <div>
                                <span>DESTINATION</span>
                                <h2 data-sidebar-title><?php echo esc_html($post->post_title); ?></h2>
                                <p><?php echo esc_html($fields['tagline'] ?: $fields['region'] ?: 'TN Game Destination'); ?></p>
                            </div>
                        </section>

                        <section class="tng-ds-readiness">
                            <h3>Destination readiness</h3>
                            <?php
                            $checks = [
                                'Hero image' => (bool)$thumbnail_id,
                                'Overview' => (bool)trim(wp_strip_all_tags($post->post_content)),
                                'Coordinates' => (bool)($fields['lat'] && $fields['lng']),
                                'Weather' => (bool)$fields['weather'],
                                'Connected content' => array_sum($counts) > 0,
                                'Explorer setup' => (bool)($fields['passport_stamp'] || (int)$fields['xp_bonus'] > 0),
                            ];
                            $ready_count = count(array_filter($checks));
                            $percent = (int)round(($ready_count / count($checks)) * 100);
                            ?>
                            <div class="tng-ds-progress"><span style="width:<?php echo absint($percent); ?>%"></span></div>
                            <strong><?php echo absint($percent); ?>% ready</strong>
                            <ul>
                                <?php foreach ($checks as $label => $ready): ?>
                                    <li class="<?php echo $ready ? 'is-ready' : ''; ?>"><span><?php echo $ready ? '✓' : '○'; ?></span><?php echo esc_html($label); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </section>

                        <section class="tng-ds-live-stats">
                            <h3>Live inventory</h3>
                            <?php foreach ($counts as $label => $count): ?>
                                <div><span><?php echo esc_html($label); ?></span><strong><?php echo number_format_i18n($count); ?></strong></div>
                            <?php endforeach; ?>
                            <div class="is-total"><span>Total connected</span><strong><?php echo number_format_i18n(array_sum($counts)); ?></strong></div>
                        </section>

                        <section class="tng-ds-sidebar-actions">
                            <button type="submit" class="button button-primary button-hero">Save Destination</button>
                            <?php if ($post->post_status === 'publish'): ?><a class="button" target="_blank" href="<?php echo esc_url($preview_url); ?>">Preview public page</a><?php endif; ?>
                        </section>
                    </aside>
                </div>
            </form>
        </div>
        <?php
    }

    public function save(): void {
        $post_id = isset($_POST['destination_id']) ? absint($_POST['destination_id']) : 0;

        if (!$post_id || get_post_type($post_id) !== 'tng_destination') {
            wp_die('Invalid destination.');
        }

        if (!current_user_can('edit_post', $post_id)) {
            wp_die('You are not allowed to edit this destination.');
        }

        check_admin_referer('tng_destination_studio_save_' . $post_id, 'tng_destination_studio_nonce');

        $status = isset($_POST['post_status']) ? sanitize_key($_POST['post_status']) : 'draft';
        if (!in_array($status, ['draft', 'pending', 'publish', 'private'], true)) $status = 'draft';
        if ($status === 'publish' && !current_user_can('publish_posts')) $status = 'pending';

        $title = isset($_POST['post_title']) ? sanitize_text_field(wp_unslash($_POST['post_title'])) : '';
        $slug = isset($_POST['post_name']) ? sanitize_title(wp_unslash($_POST['post_name'])) : '';
        $excerpt = isset($_POST['post_excerpt']) ? sanitize_textarea_field(wp_unslash($_POST['post_excerpt'])) : '';
        $content = isset($_POST['post_content']) ? wp_kses_post(wp_unslash($_POST['post_content'])) : '';

        wp_update_post([
            'ID' => $post_id,
            'post_title' => $title ?: 'Untitled Destination',
            'post_name' => $slug,
            'post_excerpt' => $excerpt,
            'post_content' => $content,
            'post_status' => $status,
        ]);

        $text_fields = [
            'tagline' => '_tng_destination_tagline',
            'lat' => '_tng_destination_lat',
            'lng' => '_tng_destination_lng',
            'radius' => '_tng_destination_radius',
            'weather' => '_tng_destination_weather_location',
            'season' => '_tng_destination_season',
            'crowd' => '_tng_destination_crowd_baseline',
            'county' => '_tng_destination_county',
            'region' => '_tng_destination_region',
            'passport_stamp' => '_tng_destination_passport_stamp',
            'xp_bonus' => '_tng_destination_xp_bonus',
            'seo_title' => '_tng_destination_seo_title',
            'seo_description' => '_tng_destination_seo_description',
        ];

        foreach ($text_fields as $request_key => $meta_key) {
            $value = isset($_POST[$request_key]) ? sanitize_text_field(wp_unslash($_POST[$request_key])) : '';
            update_post_meta($post_id, $meta_key, $value);
        }

        $textarea_fields = [
            'history' => '_tng_destination_history',
            'why_visit' => '_tng_destination_why_visit',
            'local_tips' => '_tng_destination_local_tips',
            'seasonal_challenge' => '_tng_destination_seasonal_challenge',
            'analytics_note' => '_tng_destination_analytics_note',
        ];

        foreach ($textarea_fields as $request_key => $meta_key) {
            $value = isset($_POST[$request_key]) ? wp_kses_post(wp_unslash($_POST[$request_key])) : '';
            update_post_meta($post_id, $meta_key, $value);
        }

        update_post_meta($post_id, '_tng_destination_featured', !empty($_POST['featured']) ? 1 : 0);

        $thumbnail_id = isset($_POST['thumbnail_id']) ? absint($_POST['thumbnail_id']) : 0;
        if ($thumbnail_id) {
            set_post_thumbnail($post_id, $thumbnail_id);
        } else {
            delete_post_thumbnail($post_id);
        }

        update_option('tng_os_rewrite_flush_needed', 1, false);

        wp_safe_redirect(admin_url('admin.php?page=tng-destination-studio&destination_id=' . $post_id . '&updated=1'));
        exit;
    }
}
