<?php
/**
 * TN Game Social Intelligence + Content Studio
 * Private editorial workspace for inspiration, watchlists, permissions, and planning.
 */
if (!defined('ABSPATH')) exit;

final class TNG_Social_Intelligence {
    private const ITEM = 'tng_social_item';
    private const WATCH = 'tng_social_watch';
    private const NONCE = 'tng_social_intel_nonce';

    public static function boot(): void {
        static $booted = false;
        if ($booted) return;
        $booted = true;
        add_action('init', [__CLASS__, 'register_content']);
        add_action('admin_menu', [__CLASS__, 'admin_menu'], 20);
        add_action('add_meta_boxes', [__CLASS__, 'meta_boxes']);
        add_action('save_post_' . self::ITEM, [__CLASS__, 'save_item']);
        add_action('save_post_' . self::WATCH, [__CLASS__, 'save_watch']);
        add_filter('manage_' . self::ITEM . '_posts_columns', [__CLASS__, 'item_columns']);
        add_action('manage_' . self::ITEM . '_posts_custom_column', [__CLASS__, 'item_column'], 10, 2);
        add_filter('manage_' . self::WATCH . '_posts_columns', [__CLASS__, 'watch_columns']);
        add_action('manage_' . self::WATCH . '_posts_custom_column', [__CLASS__, 'watch_column'], 10, 2);
        add_action('admin_enqueue_scripts', [__CLASS__, 'admin_assets']);
    }

    public static function register_content(): void {
        register_post_type(self::ITEM, [
            'labels' => [
                'name' => 'Inspiration', 'singular_name' => 'Inspiration Post',
                'add_new' => 'Save inspiration', 'add_new_item' => 'Save inspiration',
                'edit_item' => 'Edit inspiration', 'menu_name' => 'Inspiration',
            ],
            'public' => false, 'show_ui' => true, 'show_in_menu' => 'tng-content-studio',
            'supports' => ['title', 'editor', 'thumbnail'], 'menu_icon' => 'dashicons-lightbulb',
            'capability_type' => 'post', 'map_meta_cap' => true,
        ]);
        register_post_type(self::WATCH, [
            'labels' => [
                'name' => 'Watchlist', 'singular_name' => 'Watch item',
                'add_new' => 'Add watch', 'add_new_item' => 'Add watch item',
                'edit_item' => 'Edit watch item', 'menu_name' => 'Watchlist',
            ],
            'public' => false, 'show_ui' => true, 'show_in_menu' => 'tng-content-studio',
            'supports' => ['title'], 'menu_icon' => 'dashicons-visibility',
            'capability_type' => 'post', 'map_meta_cap' => true,
        ]);
        foreach ([
            'tng_social_platform' => ['Platforms', 'Platform'],
            'tng_social_topic' => ['Topics', 'Topic'],
            'tng_social_collection' => ['Collections', 'Collection'],
        ] as $taxonomy => $labels) {
            register_taxonomy($taxonomy, [self::ITEM], [
                'labels' => ['name' => $labels[0], 'singular_name' => $labels[1]],
                'public' => false, 'show_ui' => true, 'show_admin_column' => true,
                'hierarchical' => $taxonomy !== 'tng_social_platform',
                'show_in_menu' => false,
            ]);
        }
    }

    public static function admin_menu(): void {
        add_menu_page(
            'TN Content Studio', 'Content Studio', 'edit_posts', 'tng-content-studio',
            [__CLASS__, 'dashboard'], 'dashicons-megaphone', 27
        );
        add_submenu_page('tng-content-studio', 'Social Intelligence', 'Overview', 'edit_posts', 'tng-content-studio', [__CLASS__, 'dashboard']);
        add_submenu_page('tng-content-studio', 'Content Calendar', 'Content Calendar', 'edit_posts', 'tng-content-calendar', [__CLASS__, 'calendar']);
        add_submenu_page('tng-content-studio', 'Platforms', 'Platforms', 'manage_categories', 'edit-tags.php?taxonomy=tng_social_platform&post_type=' . self::ITEM);
        add_submenu_page('tng-content-studio', 'Topics', 'Topics', 'manage_categories', 'edit-tags.php?taxonomy=tng_social_topic&post_type=' . self::ITEM);
        add_submenu_page('tng-content-studio', 'Collections', 'Collections', 'manage_categories', 'edit-tags.php?taxonomy=tng_social_collection&post_type=' . self::ITEM);
    }

    public static function meta_boxes(): void {
        add_meta_box('tng-social-source', 'Source + creator', [__CLASS__, 'item_source_box'], self::ITEM, 'normal', 'high');
        add_meta_box('tng-social-intelligence', 'Inspiration intelligence', [__CLASS__, 'item_intelligence_box'], self::ITEM, 'normal', 'default');
        add_meta_box('tng-social-planning', 'Content planning', [__CLASS__, 'item_planning_box'], self::ITEM, 'side', 'high');
        add_meta_box('tng-watch-details', 'Watch details', [__CLASS__, 'watch_box'], self::WATCH, 'normal', 'high');
    }

    private static function field(int $post_id, string $key, string $label, string $type = 'text', array $options = [], string $placeholder = ''): void {
        $value = (string) get_post_meta($post_id, $key, true);
        echo '<div class="tng-si-field"><label for="' . esc_attr($key) . '">' . esc_html($label) . '</label>';
        if ($type === 'textarea') {
            echo '<textarea id="' . esc_attr($key) . '" name="' . esc_attr($key) . '" rows="4" placeholder="' . esc_attr($placeholder) . '">' . esc_textarea($value) . '</textarea>';
        } elseif ($type === 'select') {
            echo '<select id="' . esc_attr($key) . '" name="' . esc_attr($key) . '">';
            foreach ($options as $option_value => $option_label) {
                echo '<option value="' . esc_attr($option_value) . '" ' . selected($value, (string) $option_value, false) . '>' . esc_html($option_label) . '</option>';
            }
            echo '</select>';
        } else {
            echo '<input type="' . esc_attr($type) . '" id="' . esc_attr($key) . '" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '" placeholder="' . esc_attr($placeholder) . '">';
        }
        echo '</div>';
    }

    public static function item_source_box(WP_Post $post): void {
        wp_nonce_field(self::NONCE, self::NONCE);
        echo '<div class="tng-si-grid">';
        self::field($post->ID, '_tng_source_url', 'Post URL', 'url', [], 'https://...');
        self::field($post->ID, '_tng_creator_handle', 'Creator / handle', 'text', [], '@creator');
        self::field($post->ID, '_tng_creator_url', 'Creator profile URL', 'url', [], 'https://...');
        self::field($post->ID, '_tng_location_name', 'Location / place', 'text', [], 'Foster Falls');
        self::field($post->ID, '_tng_hashtags', 'Hashtags seen', 'text', [], '#FosterFalls #Tennessee');
        self::field($post->ID, '_tng_content_format', 'Format', 'select', [
            '' => 'Choose…', 'photo' => 'Photo', 'carousel' => 'Carousel', 'reel' => 'Reel / short video',
            'story' => 'Story', 'long_video' => 'Long video', 'text' => 'Text post', 'other' => 'Other',
        ]);
        echo '</div>';
        echo '<div class="tng-si-grid">';
        self::field($post->ID, '_tng_permission_status', 'Permission', 'select', [
            'not_needed' => 'Inspiration only / not reposting', 'not_requested' => 'Not requested',
            'requested' => 'Requested', 'granted' => 'Granted', 'denied' => 'Denied', 'expired' => 'Expired',
        ]);
        self::field($post->ID, '_tng_credit_requirement', 'Credit requirements', 'text', [], 'Tag @creator, etc.');
        echo '</div>';
    }

    public static function item_intelligence_box(WP_Post $post): void {
        echo '<p class="description">Capture what is useful about the post without copying it. These fields will later feed the TN Game content generator.</p>';
        self::field($post->ID, '_tng_hook', 'Hook / opening idea', 'textarea', [], 'What grabs attention first?');
        self::field($post->ID, '_tng_why_it_works', 'Why it works', 'textarea', [], 'Composition, emotion, useful information, timing, novelty…');
        self::field($post->ID, '_tng_original_angle', 'Original TN Game angle', 'textarea', [], 'How can TN Game create something original from the underlying insight?');
        self::field($post->ID, '_tng_content_notes', 'Notes', 'textarea', [], 'Anything else worth remembering.');
    }

    public static function item_planning_box(WP_Post $post): void {
        self::field($post->ID, '_tng_plan_status', 'Planning status', 'select', [
            'inspiration' => 'Inspiration', 'idea' => 'Idea', 'planned' => 'Planned', 'creating' => 'Creating',
            'ready' => 'Ready', 'scheduled' => 'Scheduled', 'published' => 'Published', 'archived' => 'Archived',
        ]);
        self::field($post->ID, '_tng_planned_date', 'Planned publish date', 'date');
        self::field($post->ID, '_tng_campaign', 'Campaign', 'text', [], 'Coming Soon, Fall Trails…');
    }

    public static function watch_box(WP_Post $post): void {
        wp_nonce_field(self::NONCE, self::NONCE);
        echo '<p class="description">Use the Watchlist for hashtags, accounts, locations, or subjects you want Social Intelligence to monitor as source integrations are added.</p><div class="tng-si-grid">';
        self::field($post->ID, '_tng_watch_type', 'Watch type', 'select', [
            'hashtag' => 'Hashtag', 'account' => 'Creator / account', 'location' => 'Location', 'topic' => 'Topic / keyword',
        ]);
        self::field($post->ID, '_tng_watch_value', 'What to watch', 'text', [], '#FieryGizzard or @creator');
        self::field($post->ID, '_tng_watch_platform', 'Platform', 'select', [
            'all' => 'All / web', 'instagram' => 'Instagram', 'facebook' => 'Facebook', 'tiktok' => 'TikTok',
            'youtube' => 'YouTube', 'reddit' => 'Reddit', 'pinterest' => 'Pinterest', 'other' => 'Other',
        ]);
        self::field($post->ID, '_tng_watch_status', 'Status', 'select', ['active' => 'Active', 'paused' => 'Paused']);
        self::field($post->ID, '_tng_watch_location', 'Related TN location', 'text', [], 'Tracy City, Foster Falls…');
        echo '</div>';
        self::field($post->ID, '_tng_watch_notes', 'Notes', 'textarea', [], 'What are you hoping to learn from this watch?');
    }

    private static function can_save(int $post_id): bool {
        if (!isset($_POST[self::NONCE]) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::NONCE])), self::NONCE)) return false;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return false;
        return current_user_can('edit_post', $post_id);
    }

    private static function save_fields(int $post_id, array $fields): void {
        foreach ($fields as $key => $type) {
            if (!array_key_exists($key, $_POST)) continue;
            $raw = wp_unslash($_POST[$key]);
            if ($type === 'url') $value = esc_url_raw($raw);
            elseif ($type === 'textarea') $value = sanitize_textarea_field($raw);
            else $value = sanitize_text_field($raw);
            if ($value === '') delete_post_meta($post_id, $key); else update_post_meta($post_id, $key, $value);
        }
    }

    public static function save_item(int $post_id): void {
        if (!self::can_save($post_id)) return;
        self::save_fields($post_id, [
            '_tng_source_url' => 'url', '_tng_creator_handle' => 'text', '_tng_creator_url' => 'url',
            '_tng_location_name' => 'text', '_tng_hashtags' => 'text', '_tng_content_format' => 'text',
            '_tng_permission_status' => 'text', '_tng_credit_requirement' => 'text', '_tng_hook' => 'textarea',
            '_tng_why_it_works' => 'textarea', '_tng_original_angle' => 'textarea', '_tng_content_notes' => 'textarea',
            '_tng_plan_status' => 'text', '_tng_planned_date' => 'text', '_tng_campaign' => 'text',
        ]);
    }

    public static function save_watch(int $post_id): void {
        if (!self::can_save($post_id)) return;
        self::save_fields($post_id, [
            '_tng_watch_type' => 'text', '_tng_watch_value' => 'text', '_tng_watch_platform' => 'text',
            '_tng_watch_status' => 'text', '_tng_watch_location' => 'text', '_tng_watch_notes' => 'textarea',
        ]);
    }

    public static function item_columns(array $columns): array {
        return ['cb' => $columns['cb'], 'title' => 'Inspiration', 'tng_creator' => 'Creator', 'tng_platform' => 'Platform', 'tng_status' => 'Status', 'tng_date' => 'Planned', 'date' => 'Saved'];
    }

    public static function item_column(string $column, int $post_id): void {
        if ($column === 'tng_creator') echo esc_html((string) get_post_meta($post_id, '_tng_creator_handle', true));
        elseif ($column === 'tng_platform') { $terms = get_the_terms($post_id, 'tng_social_platform'); if ($terms && !is_wp_error($terms)) echo esc_html(implode(', ', wp_list_pluck($terms, 'name'))); }
        elseif ($column === 'tng_status') echo esc_html(ucwords(str_replace('_', ' ', (string) get_post_meta($post_id, '_tng_plan_status', true))));
        elseif ($column === 'tng_date') echo esc_html((string) get_post_meta($post_id, '_tng_planned_date', true));
    }

    public static function watch_columns(array $columns): array {
        return ['cb' => $columns['cb'], 'title' => 'Watch', 'tng_watch_type' => 'Type', 'tng_watch_platform' => 'Platform', 'tng_watch_status' => 'Status', 'date' => 'Added'];
    }

    public static function watch_column(string $column, int $post_id): void {
        if ($column === 'tng_watch_type') echo esc_html(ucfirst((string) get_post_meta($post_id, '_tng_watch_type', true)));
        elseif ($column === 'tng_watch_platform') echo esc_html(ucfirst((string) get_post_meta($post_id, '_tng_watch_platform', true)));
        elseif ($column === 'tng_watch_status') echo esc_html(ucfirst((string) get_post_meta($post_id, '_tng_watch_status', true)));
    }

    public static function dashboard(): void {
        if (!current_user_can('edit_posts')) return;
        $inspiration_count = (int) wp_count_posts(self::ITEM)->publish + (int) wp_count_posts(self::ITEM)->draft;
        $watch_count = (int) wp_count_posts(self::WATCH)->publish + (int) wp_count_posts(self::WATCH)->draft;
        $planned = self::count_meta('_tng_plan_status', ['planned','creating','ready','scheduled']);
        $published = self::count_meta('_tng_plan_status', ['published']);
        $recent = get_posts(['post_type' => self::ITEM, 'post_status' => ['publish','draft','private'], 'posts_per_page' => 8, 'orderby' => 'modified', 'order' => 'DESC']);
        $watches = get_posts(['post_type' => self::WATCH, 'post_status' => ['publish','draft','private'], 'posts_per_page' => 8, 'orderby' => 'modified', 'order' => 'DESC']);
        echo '<div class="wrap tng-si-wrap"><div class="tng-si-hero"><p class="eyebrow">SOCIAL INTELLIGENCE + CONTENT STUDIO</p><h1>See what Tennessee is talking about.</h1><p>Track creators, hashtags, places and post ideas—then turn useful signals into original TN Game content.</p><div class="tng-si-actions"><a class="button button-primary" href="' . esc_url(admin_url('post-new.php?post_type=' . self::ITEM)) . '">+ Save inspiration</a><a class="button" href="' . esc_url(admin_url('post-new.php?post_type=' . self::WATCH)) . '">+ Add to watchlist</a><a class="button" href="' . esc_url(admin_url('admin.php?page=tng-content-calendar')) . '">Open content calendar</a></div></div>';
        echo '<div class="tng-si-stats">' . self::stat('Saved inspiration', $inspiration_count) . self::stat('Active watchlist', $watch_count) . self::stat('In production', $planned) . self::stat('Published', $published) . '</div>';
        echo '<div class="tng-si-columns"><section class="tng-si-card"><div class="tng-si-cardhead"><div><p class="eyebrow">INSPIRATION</p><h2>Recently saved</h2></div><a href="' . esc_url(admin_url('edit.php?post_type=' . self::ITEM)) . '">View all</a></div>';
        if (!$recent) echo '<div class="tng-si-empty">Save a post URL or idea and Social Intelligence will start building your inspiration library.</div>';
        foreach ($recent as $item) {
            $creator = (string) get_post_meta($item->ID, '_tng_creator_handle', true);
            $status = (string) get_post_meta($item->ID, '_tng_plan_status', true);
            echo '<a class="tng-si-row" href="' . esc_url(get_edit_post_link($item->ID)) . '"><div><strong>' . esc_html(get_the_title($item)) . '</strong><span>' . esc_html($creator ?: 'Source saved') . '</span></div><em>' . esc_html($status ? ucwords(str_replace('_',' ',$status)) : 'Inspiration') . '</em></a>';
        }
        echo '</section><section class="tng-si-card"><div class="tng-si-cardhead"><div><p class="eyebrow">WATCHLIST</p><h2>What we are watching</h2></div><a href="' . esc_url(admin_url('edit.php?post_type=' . self::WATCH)) . '">View all</a></div>';
        if (!$watches) echo '<div class="tng-si-empty">Add hashtags such as #FosterFalls, accounts, locations, or topics you want the future discovery engine to monitor.</div>';
        foreach ($watches as $watch) {
            $type = (string) get_post_meta($watch->ID, '_tng_watch_type', true);
            $platform = (string) get_post_meta($watch->ID, '_tng_watch_platform', true);
            echo '<a class="tng-si-row" href="' . esc_url(get_edit_post_link($watch->ID)) . '"><div><strong>' . esc_html(get_the_title($watch)) . '</strong><span>' . esc_html(ucfirst($type ?: 'topic')) . '</span></div><em>' . esc_html(ucfirst($platform ?: 'all')) . '</em></a>';
        }
        echo '</section></div><div class="tng-si-next"><p class="eyebrow">NEXT CONNECTION</p><h2>Live discovery sources</h2><p>This foundation is ready for platform/search connectors. The next build can take active Watchlist entries and surface recent public posts and creators for review without mixing scraped copies into your content library.</p></div></div>';
    }

    private static function count_meta(string $key, array $values): int {
        $query = new WP_Query(['post_type' => self::ITEM, 'post_status' => ['publish','draft','private'], 'posts_per_page' => 1, 'fields' => 'ids', 'meta_query' => [['key' => $key, 'value' => $values, 'compare' => 'IN']]]);
        return (int) $query->found_posts;
    }

    private static function stat(string $label, int $value): string {
        return '<div class="tng-si-stat"><strong>' . number_format_i18n($value) . '</strong><span>' . esc_html($label) . '</span></div>';
    }

    public static function calendar(): void {
        if (!current_user_can('edit_posts')) return;
        $items = get_posts([
            'post_type' => self::ITEM, 'post_status' => ['publish','draft','private'], 'posts_per_page' => 100,
            'meta_key' => '_tng_planned_date', 'orderby' => 'meta_value', 'order' => 'ASC',
            'meta_query' => [['key' => '_tng_planned_date', 'value' => '', 'compare' => '!=']],
        ]);
        echo '<div class="wrap tng-si-wrap"><div class="tng-si-hero compact"><p class="eyebrow">CONTENT CREATOR CALENDAR</p><h1>Plan the story before you post it.</h1><p>Ideas move from inspiration to planned, creating, ready, scheduled and published.</p><div class="tng-si-actions"><a class="button button-primary" href="' . esc_url(admin_url('post-new.php?post_type=' . self::ITEM)) . '">+ Add content idea</a><a class="button" href="' . esc_url(admin_url('admin.php?page=tng-content-studio')) . '">Social Intelligence</a></div></div><div class="tng-si-card"><div class="tng-si-cardhead"><div><p class="eyebrow">PLANNED CONTENT</p><h2>Publishing queue</h2></div></div>';
        if (!$items) echo '<div class="tng-si-empty">Nothing has a planned publish date yet. Open an inspiration item and move it into your calendar.</div>';
        foreach ($items as $item) {
            $date = (string) get_post_meta($item->ID, '_tng_planned_date', true);
            $status = (string) get_post_meta($item->ID, '_tng_plan_status', true);
            $campaign = (string) get_post_meta($item->ID, '_tng_campaign', true);
            echo '<a class="tng-si-calendar-row" href="' . esc_url(get_edit_post_link($item->ID)) . '"><time>' . esc_html($date) . '</time><div><strong>' . esc_html(get_the_title($item)) . '</strong><span>' . esc_html($campaign ?: 'TN Game content') . '</span></div><em>' . esc_html(ucwords(str_replace('_',' ',$status ?: 'planned'))) . '</em></a>';
        }
        echo '</div></div>';
    }

    public static function admin_assets(string $hook): void {
        $screen = get_current_screen();
        if (!$screen) return;
        $is_studio = strpos($hook, 'tng-content') !== false || in_array($screen->post_type, [self::ITEM, self::WATCH], true);
        if (!$is_studio) return;
        wp_register_style('tng-social-intelligence-admin', false, [], '0.1.0');
        wp_enqueue_style('tng-social-intelligence-admin');
        wp_add_inline_style('tng-social-intelligence-admin', self::styles());
    }

    private static function styles(): string {
        return '.tng-si-wrap{max-width:1280px}.tng-si-hero{background:linear-gradient(135deg,#0b422b,#17633d);color:#fff;border-radius:22px;padding:34px 38px;margin:20px 0}.tng-si-hero.compact{padding:28px 34px}.tng-si-hero h1{font-size:40px;line-height:1.05;margin:5px 0 10px;color:#fff}.tng-si-hero p{max-width:760px;font-size:15px}.tng-si-wrap .eyebrow{font-size:11px;font-weight:800;letter-spacing:.12em;color:#f36a21;margin:0 0 8px}.tng-si-hero .eyebrow{color:#ff9b63}.tng-si-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:20px}.tng-si-actions .button{border-radius:10px;padding:4px 14px}.tng-si-actions .button-primary{background:#f26322;border-color:#f26322}.tng-si-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin:0 0 18px}.tng-si-stat,.tng-si-card,.tng-si-next{background:#fff;border:1px solid #dfe7e1;border-radius:18px}.tng-si-stat{padding:20px}.tng-si-stat strong{display:block;font-size:28px;color:#102c20}.tng-si-stat span{color:#64756b}.tng-si-columns{display:grid;grid-template-columns:1fr 1fr;gap:18px}.tng-si-card{padding:24px}.tng-si-cardhead{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:14px}.tng-si-card h2,.tng-si-next h2{margin:0;color:#132c21;font-size:24px}.tng-si-row,.tng-si-calendar-row{display:flex;align-items:center;justify-content:space-between;gap:18px;text-decoration:none;color:#17261f;border-top:1px solid #edf1ee;padding:14px 4px}.tng-si-row strong,.tng-si-row span,.tng-si-calendar-row strong,.tng-si-calendar-row span{display:block}.tng-si-row span,.tng-si-calendar-row span{color:#77847d;font-size:12px;margin-top:3px}.tng-si-row em,.tng-si-calendar-row em{font-style:normal;background:#edf6ef;color:#27633e;border-radius:999px;padding:5px 9px;font-size:11px;font-weight:700}.tng-si-calendar-row time{font-weight:800;color:#f26322;min-width:100px}.tng-si-calendar-row div{flex:1}.tng-si-empty{padding:24px;border:1px dashed #cad8cf;border-radius:14px;color:#64756b;background:#f8faf8}.tng-si-next{padding:24px;margin-top:18px}.tng-si-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.tng-si-field{margin:0 0 14px}.tng-si-field label{display:block;font-weight:700;margin:0 0 6px;color:#21372d}.tng-si-field input,.tng-si-field select,.tng-si-field textarea{width:100%;max-width:none}.tng-si-field textarea{min-height:92px}@media(max-width:900px){.tng-si-stats{grid-template-columns:1fr 1fr}.tng-si-columns,.tng-si-grid{grid-template-columns:1fr}.tng-si-hero h1{font-size:32px}}';
    }
}

TNG_Social_Intelligence::boot();
