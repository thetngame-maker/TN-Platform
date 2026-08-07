<?php
namespace TNG_OS\Modules\SocialIntelligence;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;
use WP_Query;

if (!defined('ABSPATH')) exit;

final class Social_Intelligence implements Module_Interface {
    const POST_TYPE = 'tng_social_item';
    const NONCE = 'tng_si_nonce';

    private Container $container;

    public function id(): string { return 'social_intelligence'; }

    public function register(Container $container): void {
        $this->container = $container;

        add_action('init', [$this, 'register_content']);
        add_action('admin_menu', [$this, 'admin_menu'], 40);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
        add_action('admin_post_tng_si_add_item', [$this, 'handle_add_item']);
        add_action('admin_post_tng_si_update_item', [$this, 'handle_update_item']);
        add_action('admin_post_tng_si_delete_item', [$this, 'handle_delete_item']);
        add_action('wp_ajax_tng_si_generate_idea', [$this, 'ajax_generate_idea']);
        add_filter('manage_' . self::POST_TYPE . '_posts_columns', [$this, 'columns']);
        add_action('manage_' . self::POST_TYPE . '_posts_custom_column', [$this, 'column_content'], 10, 2);
    }

    public function boot(Container $container): void {}

    public function register_content(): void {
        register_post_type(self::POST_TYPE, [
            'labels' => [
                'name' => 'Social Inspiration',
                'singular_name' => 'Social Item',
                'add_new_item' => 'Add Social Item',
                'edit_item' => 'Edit Social Item',
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => false,
            'supports' => ['title', 'editor', 'thumbnail'],
            'capability_type' => 'post',
            'map_meta_cap' => true,
        ]);

        register_taxonomy('tng_si_platform', self::POST_TYPE, [
            'labels' => ['name' => 'Platforms', 'singular_name' => 'Platform'],
            'public' => false,
            'show_ui' => true,
            'show_admin_column' => true,
            'hierarchical' => false,
        ]);
        register_taxonomy('tng_si_topic', self::POST_TYPE, [
            'labels' => ['name' => 'Topics', 'singular_name' => 'Topic'],
            'public' => false,
            'show_ui' => true,
            'show_admin_column' => true,
            'hierarchical' => true,
        ]);
        register_taxonomy('tng_si_collection', self::POST_TYPE, [
            'labels' => ['name' => 'Collections', 'singular_name' => 'Collection'],
            'public' => false,
            'show_ui' => true,
            'show_admin_column' => false,
            'hierarchical' => true,
        ]);
    }

    public function admin_menu(): void {
        $cap = 'manage_options';
        $parent = 'tn-game-os';

        add_submenu_page($parent, 'Inspiration Feed', 'Social Intelligence', $cap, 'tn-game-social-intelligence', [$this, 'page_feed']);
        add_submenu_page($parent, 'Add Inspiration', 'Add Inspiration', $cap, 'tn-game-social-add', [$this, 'page_add']);
        add_submenu_page($parent, 'Content Calendar', 'Content Calendar', $cap, 'tn-game-social-calendar', [$this, 'page_calendar']);
        add_submenu_page($parent, 'Creator Permissions', 'Creator Permissions', $cap, 'tn-game-social-permissions', [$this, 'page_permissions']);
    }

    public function assets(string $hook): void {
        if (strpos($hook, 'tn-game-social') === false) return;
        wp_enqueue_style('tng-si-admin', TNG_OS_URL . 'app/Modules/SocialIntelligence/assets/admin.css', [], TNG_OS_VERSION);
        wp_enqueue_script('tng-si-admin', TNG_OS_URL . 'app/Modules/SocialIntelligence/assets/admin.js', ['jquery'], TNG_OS_VERSION, true);
        wp_localize_script('tng-si-admin', 'TNGSI', [
            'ajax' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE),
        ]);
    }

    private function require_admin(): void {
        if (!current_user_can('manage_options')) wp_die('You do not have permission to access this page.');
    }

    private function meta(int $id, string $key, $default = '') {
        $value = get_post_meta($id, '_tng_si_' . $key, true);
        return $value === '' ? $default : $value;
    }

    private function save_meta(int $id, string $key, $value): void {
        update_post_meta($id, '_tng_si_' . $key, $value);
    }

    private function platform_from_url(string $url): string {
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        if (strpos($host, 'instagram.com') !== false) return 'Instagram';
        if (strpos($host, 'facebook.com') !== false || strpos($host, 'fb.watch') !== false) return 'Facebook';
        if (strpos($host, 'youtube.com') !== false || strpos($host, 'youtu.be') !== false) return 'YouTube';
        if (strpos($host, 'tiktok.com') !== false) return 'TikTok';
        if (strpos($host, 'reddit.com') !== false) return 'Reddit';
        return 'Other';
    }

    private function analyze_hook(string $title, string $notes, string $format): array {
        $text = strtolower($title . ' ' . $notes);
        $hook = 'Useful destination information';
        $why = 'It gives viewers a clear reason to consider or save the destination.';
        if (preg_match('/\b(hidden|secret|unknown|few know|mystery)\b/', $text)) {
            $hook = 'Curiosity and hidden discovery';
            $why = 'It delays the reveal and makes viewers stay to learn what is hidden.';
        } elseif (strpos($text, '?') !== false || preg_match('/\b(would you|which|can you|do you)\b/', $text)) {
            $hook = 'Direct audience question';
            $why = 'It creates a low-effort reason to comment, tag a friend, or choose a side.';
        } elseif (preg_match('/\b(\d+|three|five|best|top|bucket list)\b/', $text)) {
            $hook = 'List or high-value promise';
            $why = 'It packages several useful ideas into a format people are likely to save.';
        } elseif (preg_match('/\b(today|this morning|condition|flow|parking|crowd|weather)\b/', $text)) {
            $hook = 'Timely conditions update';
            $why = 'It helps people make an immediate travel decision and builds trust.';
        } elseif (preg_match('/\b(before|after|then|now|season)\b/', $text)) {
            $hook = 'Transformation or comparison';
            $why = 'The contrast creates a visual payoff and encourages repeat viewing.';
        }
        if ($format === 'Live') {
            $hook = 'Live participation and immediacy';
            $why = 'Viewers can influence or witness the experience as it happens.';
        }
        return compact('hook', 'why');
    }

    private function generate_idea_text(array $data): string {
        $location = $data['location'] ?: 'a Tennessee destination';
        $topic = $data['topic'] ?: 'outdoor adventure';
        $format = $data['format'] ?: 'Reel';
        $hook = $data['hook'] ?: 'curiosity';
        $creator = $data['creator'] ?: 'the original creator';

        $openers = [
            'Curiosity and hidden discovery' => "There is something unexpected hiding at {$location}—and most visitors walk right past it.",
            'Direct audience question' => "Would you take this route at {$location} if you did not know what was waiting at the end?",
            'List or high-value promise' => "Three reasons {$location} deserves a place on your Tennessee adventure list.",
            'Timely conditions update' => "Here is what {$location} looks like right now before you make the drive.",
            'Transformation or comparison' => "The same view at {$location}, before and after Tennessee weather changed everything.",
            'Live participation and immediacy' => "You control our next move during a live quest at {$location}.",
            'Useful destination information' => "Planning a visit to {$location}? Here is what is actually worth knowing.",
        ];
        $opener = $openers[$hook] ?? $openers['Useful destination information'];

        return $opener . "\n\nTN Game version: Create an original {$format} using your own footage and facts about {$topic}. Connect it to the relevant trail, checkpoint, or XP challenge, then end with one simple action: save the guide, choose between two options, or accept the challenge.\n\nInspiration credit record: {$creator}. Do not reuse their media unless the permission status explicitly allows it.";
    }

    public function handle_add_item(): void {
        $this->require_admin();
        check_admin_referer(self::NONCE);

        $url = esc_url_raw(wp_unslash($_POST['source_url'] ?? ''));
        $creator = sanitize_text_field(wp_unslash($_POST['creator'] ?? ''));
        $location = sanitize_text_field(wp_unslash($_POST['location'] ?? ''));
        $format = sanitize_text_field(wp_unslash($_POST['format'] ?? 'Reel'));
        $notes = sanitize_textarea_field(wp_unslash($_POST['notes'] ?? ''));
        $title = sanitize_text_field(wp_unslash($_POST['title'] ?? ''));
        if (!$title) $title = $location ? $location . ' inspiration' : 'Social inspiration';

        $id = wp_insert_post([
            'post_type' => self::POST_TYPE,
            'post_status' => 'publish',
            'post_title' => $title,
            'post_content' => $notes,
        ], true);
        if (is_wp_error($id)) wp_die(esc_html($id->get_error_message()));

        $platform = sanitize_text_field(wp_unslash($_POST['platform'] ?? '')) ?: $this->platform_from_url($url);
        wp_set_object_terms($id, $platform, 'tng_si_platform');
        $topic = sanitize_text_field(wp_unslash($_POST['topic'] ?? ''));
        if ($topic) wp_set_object_terms($id, $topic, 'tng_si_topic');

        $analysis = $this->analyze_hook($title, $notes, $format);
        foreach ([
            'source_url' => $url,
            'creator' => $creator,
            'location' => $location,
            'format' => $format,
            'permission' => sanitize_text_field(wp_unslash($_POST['permission'] ?? 'inspiration_only')),
            'hook' => $analysis['hook'],
            'why' => $analysis['why'],
            'thumbnail_url' => esc_url_raw(wp_unslash($_POST['thumbnail_url'] ?? '')),
            'planned_date' => sanitize_text_field(wp_unslash($_POST['planned_date'] ?? '')),
            'idea_status' => 'saved',
        ] as $key => $value) $this->save_meta((int)$id, $key, $value);

        $idea = $this->generate_idea_text([
            'location' => $location,
            'topic' => $topic,
            'format' => $format,
            'hook' => $analysis['hook'],
            'creator' => $creator,
        ]);
        $this->save_meta((int)$id, 'generated_idea', $idea);

        wp_safe_redirect(add_query_arg(['page' => 'tn-game-social-intelligence', 'added' => 1], admin_url('admin.php')));
        exit;
    }

    public function handle_update_item(): void {
        $this->require_admin();
        check_admin_referer(self::NONCE);
        $id = absint($_POST['item_id'] ?? 0);
        if (!$id || get_post_type($id) !== self::POST_TYPE) wp_die('Invalid item.');

        $fields = ['creator','location','format','permission','permission_notes','planned_date','idea_status','generated_idea','thumbnail_url'];
        foreach ($fields as $field) {
            if (!isset($_POST[$field])) continue;
            $value = $field === 'generated_idea' || $field === 'permission_notes'
                ? sanitize_textarea_field(wp_unslash($_POST[$field]))
                : ($field === 'thumbnail_url' ? esc_url_raw(wp_unslash($_POST[$field])) : sanitize_text_field(wp_unslash($_POST[$field])));
            $this->save_meta($id, $field, $value);
        }
        wp_safe_redirect(wp_get_referer() ?: admin_url('admin.php?page=tn-game-social-intelligence'));
        exit;
    }

    public function handle_delete_item(): void {
        $this->require_admin();
        $id = absint($_GET['item_id'] ?? 0);
        check_admin_referer('tng_si_delete_' . $id);
        if ($id && get_post_type($id) === self::POST_TYPE) wp_trash_post($id);
        wp_safe_redirect(admin_url('admin.php?page=tn-game-social-intelligence'));
        exit;
    }

    public function ajax_generate_idea(): void {
        $this->require_admin();
        check_ajax_referer(self::NONCE, 'nonce');
        $id = absint($_POST['item_id'] ?? 0);
        if (!$id || get_post_type($id) !== self::POST_TYPE) wp_send_json_error(['message' => 'Invalid item.'], 400);
        $topics = wp_get_post_terms($id, 'tng_si_topic', ['fields' => 'names']);
        $idea = $this->generate_idea_text([
            'location' => $this->meta($id, 'location'),
            'topic' => $topics ? $topics[0] : '',
            'format' => $this->meta($id, 'format'),
            'hook' => $this->meta($id, 'hook'),
            'creator' => $this->meta($id, 'creator'),
        ]);
        $this->save_meta($id, 'generated_idea', $idea);
        wp_send_json_success(['idea' => $idea]);
    }

    private function query_items(array $extra = []): WP_Query {
        $args = array_merge([
            'post_type' => self::POST_TYPE,
            'post_status' => 'publish',
            'posts_per_page' => 50,
            'orderby' => 'date',
            'order' => 'DESC',
        ], $extra);
        return new WP_Query($args);
    }

    private function header(string $title, string $description): void {
        echo '<div class="wrap tng-si"><header class="tng-si__header"><div><span class="tng-si__eyebrow">TN GAME OS</span><h1>' . esc_html($title) . '</h1><p>' . esc_html($description) . '</p></div><a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=tn-game-social-add')) . '">Add inspiration</a></header>';
    }

    public function page_feed(): void {
        $this->require_admin();
        $platform = sanitize_text_field(wp_unslash($_GET['platform'] ?? ''));
        $permission = sanitize_text_field(wp_unslash($_GET['permission'] ?? ''));
        $search = sanitize_text_field(wp_unslash($_GET['s'] ?? ''));
        $args = [];
        if ($search) $args['s'] = $search;
        if ($platform) $args['tax_query'] = [['taxonomy'=>'tng_si_platform','field'=>'slug','terms'=>$platform]];
        if ($permission) $args['meta_query'] = [['key'=>'_tng_si_permission','value'=>$permission]];
        $q = $this->query_items($args);
        $platforms = get_terms(['taxonomy'=>'tng_si_platform','hide_empty'=>false]);

        $this->header('Social Intelligence', 'A private inspiration feed for Tennessee outdoor content, creator outreach, and original TN Game ideas.');
        if (!empty($_GET['added'])) echo '<div class="notice notice-success is-dismissible"><p>Inspiration saved and analyzed.</p></div>';
        ?>
        <form class="tng-si__filters" method="get">
            <input type="hidden" name="page" value="tn-game-social-intelligence">
            <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Search places, creators, or ideas">
            <select name="platform"><option value="">All platforms</option><?php foreach ($platforms as $term): ?><option value="<?php echo esc_attr($term->slug); ?>" <?php selected($platform,$term->slug); ?>><?php echo esc_html($term->name); ?></option><?php endforeach; ?></select>
            <select name="permission"><option value="">All permission states</option><?php foreach ($this->permission_options() as $key=>$label): ?><option value="<?php echo esc_attr($key); ?>" <?php selected($permission,$key); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select>
            <button class="button">Filter</button>
        </form>
        <div class="tng-si__metrics">
            <div><strong><?php echo (int) wp_count_posts(self::POST_TYPE)->publish; ?></strong><span>Saved posts</span></div>
            <div><strong><?php echo $this->count_meta('permission','permission_received'); ?></strong><span>Permission received</span></div>
            <div><strong><?php echo $this->count_meta('idea_status','planned'); ?></strong><span>Planned ideas</span></div>
            <div><strong><?php echo $this->count_meta('idea_status','created'); ?></strong><span>Created</span></div>
        </div>
        <div class="tng-si__feed">
            <?php if (!$q->have_posts()): ?>
                <div class="tng-si__empty"><h2>Your inspiration feed is ready.</h2><p>Add a Facebook, Instagram, YouTube, TikTok, or Reddit link to create the first card.</p><a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=tn-game-social-add')); ?>">Add the first post</a></div>
            <?php endif; ?>
            <?php while ($q->have_posts()): $q->the_post(); $this->render_card(get_the_ID()); endwhile; wp_reset_postdata(); ?>
        </div></div>
        <?php
    }

    private function render_card(int $id): void {
        $url = $this->meta($id,'source_url');
        $thumb = $this->meta($id,'thumbnail_url');
        $platforms = wp_get_post_terms($id,'tng_si_platform',['fields'=>'names']);
        $topics = wp_get_post_terms($id,'tng_si_topic',['fields'=>'names']);
        $permission = $this->meta($id,'permission','inspiration_only');
        $idea = $this->meta($id,'generated_idea');
        ?>
        <article class="tng-si-card" data-item="<?php echo $id; ?>">
            <div class="tng-si-card__media">
                <?php if ($thumb): ?><img src="<?php echo esc_url($thumb); ?>" alt=""><?php else: ?><div class="tng-si-card__placeholder"><span class="dashicons dashicons-format-image"></span><small>Add an optional thumbnail URL</small></div><?php endif; ?>
                <span class="tng-si-card__platform"><?php echo esc_html($platforms ? $platforms[0] : 'Social'); ?></span>
            </div>
            <div class="tng-si-card__body">
                <div class="tng-si-card__top"><div><h2><?php echo esc_html(get_the_title($id)); ?></h2><p><?php echo esc_html($this->meta($id,'creator') ?: 'Creator not recorded'); ?> · <?php echo esc_html($this->meta($id,'location') ?: 'Location not tagged'); ?></p></div><span class="tng-si-status tng-si-status--<?php echo esc_attr($permission); ?>"><?php echo esc_html($this->permission_options()[$permission] ?? $permission); ?></span></div>
                <div class="tng-si-card__chips"><span><?php echo esc_html($this->meta($id,'format','Post')); ?></span><?php foreach ($topics as $topic): ?><span><?php echo esc_html($topic); ?></span><?php endforeach; ?></div>
                <div class="tng-si-card__analysis"><strong><?php echo esc_html($this->meta($id,'hook')); ?></strong><p><?php echo esc_html($this->meta($id,'why')); ?></p></div>
                <details><summary>Original TN Game idea</summary><textarea class="tng-si-idea" rows="6"><?php echo esc_textarea($idea); ?></textarea></details>
                <div class="tng-si-card__actions">
                    <?php if ($url): ?><a class="button" target="_blank" rel="noopener noreferrer" href="<?php echo esc_url($url); ?>">View original</a><?php endif; ?>
                    <button class="button tng-si-generate" type="button" data-id="<?php echo $id; ?>">Regenerate idea</button>
                    <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=tn-game-social-permissions&item_id='.$id)); ?>">Permission</a>
                    <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=tn-game-social-calendar&item_id='.$id)); ?>">Schedule</a>
                    <a class="button button-link-delete" onclick="return confirm('Move this inspiration item to Trash?');" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tng_si_delete_item&item_id='.$id),'tng_si_delete_'.$id)); ?>">Delete</a>
                </div>
            </div>
        </article>
        <?php
    }

    public function page_add(): void {
        $this->require_admin();
        $this->header('Add inspiration', 'Paste a public post link and record only the information you need for analysis and creator outreach.');
        ?>
        <form class="tng-si-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="tng_si_add_item">
            <?php wp_nonce_field(self::NONCE); ?>
            <div class="tng-si-form__grid">
                <label class="wide">Post URL<input required type="url" name="source_url" placeholder="https://www.instagram.com/reel/..."></label>
                <label>Internal title<input type="text" name="title" placeholder="Greeter Falls staircase Reel"></label>
                <label>Creator / handle<input type="text" name="creator" placeholder="@creatorname"></label>
                <label>Platform<select name="platform"><option value="">Detect from URL</option><option>Facebook</option><option>Instagram</option><option>YouTube</option><option>TikTok</option><option>Reddit</option><option>Other</option></select></label>
                <label>Format<select name="format"><option>Reel</option><option>Photo</option><option>Carousel</option><option>Live</option><option>Video</option><option>Text post</option><option>Story idea</option></select></label>
                <label>Location<input type="text" name="location" placeholder="Greeter Falls"></label>
                <label>Topic<input type="text" name="topic" placeholder="Waterfall, trail conditions, local history"></label>
                <label>Permission status<select name="permission"><?php foreach ($this->permission_options() as $key=>$label): ?><option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option><?php endforeach; ?></select></label>
                <label>Planned date<input type="date" name="planned_date"></label>
                <label class="wide">Optional thumbnail URL<input type="url" name="thumbnail_url" placeholder="Use only a permitted or your own preview image URL"></label>
                <label class="wide">What stood out?<textarea name="notes" rows="7" placeholder="Opening hook, visual, question, useful information, engagement pattern, why you saved it..."></textarea></label>
            </div>
            <p><button class="button button-primary button-hero">Save and analyze</button></p>
        </form></div>
        <?php
    }

    public function page_calendar(): void {
        $this->require_admin();
        $selected = absint($_GET['item_id'] ?? 0);
        $q = $this->query_items([
            'meta_key' => '_tng_si_planned_date',
            'orderby' => 'meta_value',
            'order' => 'ASC',
        ]);
        $this->header('Content Calendar', 'Move saved inspiration into a simple TN Game production queue.');
        if ($selected): $post = get_post($selected); if ($post && $post->post_type === self::POST_TYPE): ?>
            <form class="tng-si-inline-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="tng_si_update_item"><input type="hidden" name="item_id" value="<?php echo $selected; ?>"><?php wp_nonce_field(self::NONCE); ?>
                <h2><?php echo esc_html($post->post_title); ?></h2>
                <label>Planned date<input type="date" name="planned_date" value="<?php echo esc_attr($this->meta($selected,'planned_date')); ?>"></label>
                <label>Status<select name="idea_status"><?php foreach (['saved'=>'Saved','planned'=>'Planned','filming'=>'Filming / producing','created'=>'Created','published'=>'Published'] as $key=>$label): ?><option value="<?php echo esc_attr($key); ?>" <?php selected($this->meta($selected,'idea_status','saved'),$key); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></label>
                <label class="wide">TN Game idea<textarea name="generated_idea" rows="7"><?php echo esc_textarea($this->meta($selected,'generated_idea')); ?></textarea></label>
                <button class="button button-primary">Save calendar item</button>
            </form>
        <?php endif; endif; ?>
        <div class="tng-si-calendar-list">
            <?php while ($q->have_posts()): $q->the_post(); $id=get_the_ID(); $date=$this->meta($id,'planned_date'); ?>
                <div><time><?php echo $date ? esc_html(date_i18n('M j, Y',strtotime($date))) : 'Unscheduled'; ?></time><section><strong><?php the_title(); ?></strong><span><?php echo esc_html($this->meta($id,'location')); ?> · <?php echo esc_html(ucfirst($this->meta($id,'idea_status','saved'))); ?></span></section><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=tn-game-social-calendar&item_id='.$id)); ?>">Edit</a></div>
            <?php endwhile; wp_reset_postdata(); ?>
        </div></div>
        <?php
    }

    public function page_permissions(): void {
        $this->require_admin();
        $selected = absint($_GET['item_id'] ?? 0);
        $q = $this->query_items(['orderby'=>'title','order'=>'ASC']);
        $this->header('Creator Permissions', 'Keep written permission, editing rights, credit requirements, and outreach notes attached to each inspiration item.');
        if ($selected): $post=get_post($selected); if ($post && $post->post_type===self::POST_TYPE): ?>
            <form class="tng-si-inline-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="tng_si_update_item"><input type="hidden" name="item_id" value="<?php echo $selected; ?>"><?php wp_nonce_field(self::NONCE); ?>
                <h2><?php echo esc_html($post->post_title); ?></h2>
                <label>Creator<input type="text" name="creator" value="<?php echo esc_attr($this->meta($selected,'creator')); ?>"></label>
                <label>Permission status<select name="permission"><?php foreach ($this->permission_options() as $key=>$label): ?><option value="<?php echo esc_attr($key); ?>" <?php selected($this->meta($selected,'permission'),$key); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></label>
                <label class="wide">Permission notes<textarea name="permission_notes" rows="7" placeholder="Date contacted, exact approved platforms, editing/cropping rights, paid use, required credit, expiration, and where the written approval is stored."><?php echo esc_textarea($this->meta($selected,'permission_notes')); ?></textarea></label>
                <button class="button button-primary">Save permission record</button>
            </form>
        <?php endif; endif; ?>
        <table class="widefat striped tng-si-table"><thead><tr><th>Inspiration</th><th>Creator</th><th>Status</th><th>Notes</th><th></th></tr></thead><tbody>
            <?php while ($q->have_posts()): $q->the_post(); $id=get_the_ID(); $p=$this->meta($id,'permission','inspiration_only'); ?><tr><td><strong><?php the_title(); ?></strong></td><td><?php echo esc_html($this->meta($id,'creator')); ?></td><td><?php echo esc_html($this->permission_options()[$p] ?? $p); ?></td><td><?php echo esc_html(wp_trim_words($this->meta($id,'permission_notes'),18)); ?></td><td><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=tn-game-social-permissions&item_id='.$id)); ?>">Edit</a></td></tr><?php endwhile; wp_reset_postdata(); ?>
        </tbody></table></div>
        <?php
    }

    private function permission_options(): array {
        return [
            'inspiration_only' => 'Inspiration only',
            'permission_needed' => 'Permission needed',
            'contacted' => 'Creator contacted',
            'permission_received' => 'Permission received',
            'declined' => 'Permission declined',
            'partner_creator' => 'Partner creator',
        ];
    }

    private function count_meta(string $key, string $value): int {
        $q = new WP_Query(['post_type'=>self::POST_TYPE,'post_status'=>'publish','posts_per_page'=>1,'fields'=>'ids','meta_query'=>[['key'=>'_tng_si_'.$key,'value'=>$value]]]);
        return (int)$q->found_posts;
    }

    public function columns(array $columns): array {
        $columns['tng_si_creator'] = 'Creator';
        $columns['tng_si_location'] = 'Location';
        $columns['tng_si_permission'] = 'Permission';
        return $columns;
    }

    public function column_content(string $column, int $id): void {
        if ($column === 'tng_si_creator') echo esc_html($this->meta($id,'creator'));
        if ($column === 'tng_si_location') echo esc_html($this->meta($id,'location'));
        if ($column === 'tng_si_permission') { $p=$this->meta($id,'permission','inspiration_only'); echo esc_html($this->permission_options()[$p] ?? $p); }
    }
}
