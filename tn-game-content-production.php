<?php
/**
 * TN Game Content Production Builder
 * Turns Content Studio ideas into production-ready social post briefs.
 */
if (!defined('ABSPATH')) exit;

final class TNG_Content_Production_Builder {
    private const ITEM = 'tng_social_item';
    private const NONCE = 'tng_content_production_nonce';

    public static function boot(): void {
        static $booted = false;
        if ($booted) return;
        $booted = true;
        add_action('admin_menu', [__CLASS__, 'admin_menu'], 28);
        add_action('admin_post_tng_save_content_production', [__CLASS__, 'save']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);
    }

    public static function admin_menu(): void {
        add_submenu_page(
            'tng-content-studio',
            'TN Game Post Builder',
            'Post Builder',
            'edit_posts',
            'tng-content-post-builder',
            [__CLASS__, 'render']
        );
    }

    private static function meta(int $id, string $key, string $fallback = ''): string {
        $value = trim((string) get_post_meta($id, $key, true));
        return $value !== '' ? $value : $fallback;
    }

    private static function eligible_items(): array {
        return get_posts([
            'post_type' => self::ITEM,
            'post_status' => ['draft', 'publish', 'private'],
            'posts_per_page' => 100,
            'orderby' => 'modified',
            'order' => 'DESC',
            'meta_query' => [[
                'key' => '_tng_plan_status',
                'value' => ['idea', 'planned', 'creating', 'ready', 'scheduled'],
                'compare' => 'IN',
            ]],
        ]);
    }

    private static function selected_id(): int {
        $id = isset($_GET['idea']) ? absint($_GET['idea']) : 0;
        if (!$id || get_post_type($id) !== self::ITEM || !current_user_can('edit_post', $id)) return 0;
        return $id;
    }

    private static function default_caption(int $id): string {
        $hook = self::meta($id, '_tng_hook');
        $angle = self::meta($id, '_tng_original_angle');
        $place = self::meta($id, '_tng_location_name', 'Tennessee');
        $title = get_the_title($id);
        $parts = [];
        if ($hook) $parts[] = $hook;
        $parts[] = $angle ?: $title;
        $parts[] = "Save this for your next Tennessee adventure and add {$place} to your TN Game trip.";
        return implode("\n\n", array_filter($parts));
    }

    private static function default_cta(int $id): string {
        $status = self::meta($id, '_tng_campaign');
        if (stripos($status, 'coming soon') !== false) return 'Follow The TN Game and save this for launch.';
        return 'Save this adventure, share it with your Tennessee travel buddy, and build your next trip in The TN Game.';
    }

    private static function default_on_screen(int $id): string {
        $hook = self::meta($id, '_tng_hook');
        if ($hook) return $hook;
        return get_the_title($id);
    }

    private static function default_shot_list(int $id): string {
        $format = strtolower(self::meta($id, '_tng_content_format', 'reel'));
        $place = self::meta($id, '_tng_location_name', 'the location');
        if ($format === 'carousel') {
            return "1. Cover: strongest visual + short promise\n2. Where: {$place}\n3. Why stop: one useful reason\n4. Hidden detail / lesser-known angle\n5. Nearby bonus stop or local business\n6. Save-this-trip CTA + The TN Game branding";
        }
        if ($format === 'photo') {
            return "1. Capture one strong hero image of {$place}\n2. Capture one backup vertical detail image\n3. Capture one TN Game/location context image for Stories";
        }
        return "1. 0–2 sec: strongest reveal / movement shot\n2. 2–6 sec: establish {$place}\n3. 6–12 sec: useful detail or reason to visit\n4. 12–18 sec: hidden detail / second angle\n5. 18–24 sec: nearby stop, map, or Trip Mode context\n6. 24–30 sec: CTA + TN Game end frame";
    }

    private static function production_score(int $id): int {
        $fields = [
            '_tng_caption_draft', '_tng_on_screen_hook', '_tng_shot_list', '_tng_content_cta',
            '_tng_hashtags', '_tng_location_name', '_tng_content_format', '_tng_campaign',
        ];
        $filled = 0;
        foreach ($fields as $field) if (self::meta($id, $field) !== '') $filled++;
        return (int) round(($filled / count($fields)) * 100);
    }

    private static function status_label(string $status): string {
        $labels = [
            'idea' => 'Idea', 'planned' => 'Planned', 'creating' => 'Creating',
            'ready' => 'Ready', 'scheduled' => 'Scheduled', 'published' => 'Published',
        ];
        return $labels[$status] ?? ucwords(str_replace('_', ' ', $status ?: 'idea'));
    }

    public static function save(): void {
        if (!current_user_can('edit_posts')) wp_die('Not allowed.');
        check_admin_referer(self::NONCE);
        $id = absint($_POST['idea_id'] ?? 0);
        if (!$id || get_post_type($id) !== self::ITEM || !current_user_can('edit_post', $id)) wp_die('Invalid content item.');

        $text = [
            '_tng_caption_draft' => 'caption',
            '_tng_on_screen_hook' => 'on_screen',
            '_tng_shot_list' => 'shot_list',
            '_tng_content_cta' => 'cta',
            '_tng_production_notes' => 'production_notes',
            '_tng_hashtags' => 'hashtags',
            '_tng_location_name' => 'place',
            '_tng_campaign' => 'campaign',
        ];
        foreach ($text as $meta => $field) {
            $value = sanitize_textarea_field(wp_unslash($_POST[$field] ?? ''));
            if ($value === '') delete_post_meta($id, $meta); else update_post_meta($id, $meta, $value);
        }

        $format = sanitize_key(wp_unslash($_POST['format'] ?? 'reel'));
        $status = sanitize_key(wp_unslash($_POST['plan_status'] ?? 'creating'));
        $date = sanitize_text_field(wp_unslash($_POST['planned_date'] ?? ''));
        $valid_formats = ['reel','carousel','photo','story','long_video'];
        $valid_statuses = ['idea','planned','creating','ready','scheduled','published'];
        if (!in_array($format, $valid_formats, true)) $format = 'reel';
        if (!in_array($status, $valid_statuses, true)) $status = 'creating';
        update_post_meta($id, '_tng_content_format', $format);
        update_post_meta($id, '_tng_plan_status', $status);
        if ($date) update_post_meta($id, '_tng_planned_date', $date); else delete_post_meta($id, '_tng_planned_date');
        update_post_meta($id, '_tng_production_updated_at', current_time('mysql'));

        wp_safe_redirect(add_query_arg([
            'page' => 'tng-content-post-builder',
            'idea' => $id,
            'saved' => 1,
        ], admin_url('admin.php')));
        exit;
    }

    private static function queue(): void {
        $items = self::eligible_items();
        echo '<section class="tng-cpb-queue"><div class="tng-cpb-head"><div><p class="eyebrow">PRODUCTION QUEUE</p><h2>Ideas ready to build</h2></div><a class="button" href="' . esc_url(admin_url('admin.php?page=tng-content-idea-generator')) . '">+ Generate idea</a></div>';
        if (!$items) {
            echo '<div class="tng-cpb-empty">No ideas are waiting. Create one in the Idea Generator first.</div></section>';
            return;
        }
        echo '<div class="tng-cpb-items">';
        foreach ($items as $item) {
            $id = (int) $item->ID;
            $status = self::meta($id, '_tng_plan_status', 'idea');
            $format = self::meta($id, '_tng_content_format', 'reel');
            $place = self::meta($id, '_tng_location_name', 'Tennessee');
            $date = self::meta($id, '_tng_planned_date');
            $score = self::production_score($id);
            echo '<article><div class="tng-cpb-item-main"><span class="tng-cpb-status status-' . esc_attr($status) . '">' . esc_html(self::status_label($status)) . '</span><h3>' . esc_html(get_the_title($id)) . '</h3><p>' . esc_html(ucwords(str_replace('_',' ',$format))) . ' · ' . esc_html($place) . ($date ? ' · ' . esc_html($date) : '') . '</p></div><div class="tng-cpb-readiness"><strong>' . $score . '%</strong><span>brief ready</span></div><a class="button button-primary" href="' . esc_url(add_query_arg(['page'=>'tng-content-post-builder','idea'=>$id],admin_url('admin.php'))) . '">Build post</a></article>';
        }
        echo '</div></section>';
    }

    private static function builder(int $id): void {
        $post = get_post($id);
        if (!$post) return;
        $format = self::meta($id, '_tng_content_format', 'reel');
        $place = self::meta($id, '_tng_location_name', 'Tennessee');
        $hashtags = self::meta($id, '_tng_hashtags', '#Tennessee #TheTNGame');
        $campaign = self::meta($id, '_tng_campaign');
        $date = self::meta($id, '_tng_planned_date');
        $status = self::meta($id, '_tng_plan_status', 'idea');
        $caption = self::meta($id, '_tng_caption_draft', self::default_caption($id));
        $on_screen = self::meta($id, '_tng_on_screen_hook', self::default_on_screen($id));
        $shot_list = self::meta($id, '_tng_shot_list', self::default_shot_list($id));
        $cta = self::meta($id, '_tng_content_cta', self::default_cta($id));
        $notes = self::meta($id, '_tng_production_notes');
        $angle = self::meta($id, '_tng_original_angle');
        $source_notes = self::meta($id, '_tng_content_notes');

        echo '<section class="tng-cpb-builder"><div class="tng-cpb-head"><div><p class="eyebrow">POST BUILDER</p><h2>' . esc_html($post->post_title) . '</h2><p>' . esc_html($angle) . '</p></div><div class="tng-cpb-score"><strong>' . self::production_score($id) . '%</strong><span>production brief</span></div></div>';
        if (isset($_GET['saved'])) echo '<div class="notice notice-success inline"><p>Production brief saved.</p></div>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(self::NONCE);
        echo '<input type="hidden" name="action" value="tng_save_content_production"><input type="hidden" name="idea_id" value="' . $id . '">';
        echo '<div class="tng-cpb-form-grid">';

        echo '<div class="tng-cpb-column">';
        echo '<label>OPENING / ON-SCREEN HOOK</label><textarea name="on_screen" rows="3">' . esc_textarea($on_screen) . '</textarea>';
        echo '<label>CAPTION DRAFT</label><textarea name="caption" rows="11">' . esc_textarea($caption) . '</textarea>';
        echo '<label>CTA</label><textarea name="cta" rows="3">' . esc_textarea($cta) . '</textarea>';
        echo '<label>HASHTAGS</label><textarea name="hashtags" rows="3">' . esc_textarea($hashtags) . '</textarea>';
        echo '</div>';

        echo '<div class="tng-cpb-column">';
        echo '<label>SHOT LIST / CAROUSEL FRAMES</label><textarea name="shot_list" rows="12">' . esc_textarea($shot_list) . '</textarea>';
        echo '<label>PRODUCTION NOTES</label><textarea name="production_notes" rows="5" placeholder="Weather, best time to shoot, voiceover notes, B-roll, music ideas, permissions…">' . esc_textarea($notes) . '</textarea>';
        echo '<div class="tng-cpb-mini-grid"><div><label>FORMAT</label><select name="format">';
        foreach (['reel'=>'Reel','carousel'=>'Carousel','photo'=>'Photo','story'=>'Story','long_video'=>'Long video'] as $v=>$l) echo '<option value="' . esc_attr($v) . '" ' . selected($format,$v,false) . '>' . esc_html($l) . '</option>';
        echo '</select></div><div><label>PLACE</label><input name="place" value="' . esc_attr($place) . '"></div><div><label>CAMPAIGN</label><input name="campaign" value="' . esc_attr($campaign) . '" placeholder="Coming Soon"></div><div><label>CALENDAR DATE</label><input type="date" name="planned_date" value="' . esc_attr($date) . '"></div></div>';
        echo '</div></div>';

        echo '<div class="tng-cpb-footer"><div><label>WORKFLOW STATUS</label><select name="plan_status">';
        foreach (['idea'=>'Idea','planned'=>'Planned','creating'=>'Creating','ready'=>'Ready','scheduled'=>'Scheduled','published'=>'Published'] as $v=>$l) echo '<option value="' . esc_attr($v) . '" ' . selected($status,$v,false) . '>' . esc_html($l) . '</option>';
        echo '</select></div><div class="tng-cpb-actions"><a class="button" href="' . esc_url(get_edit_post_link($id)) . '">Open full record</a><a class="button" href="' . esc_url(admin_url('admin.php?page=tng-content-calendar')) . '">Content Calendar</a><button class="button button-primary button-hero">Save production brief</button></div></div>';
        echo '</form>';
        if ($source_notes) echo '<div class="tng-cpb-origin"><strong>ORIGINALITY / SOURCE NOTE</strong><p>' . esc_html($source_notes) . '</p></div>';
        echo '</section>';
    }

    public static function render(): void {
        if (!current_user_can('edit_posts')) return;
        $selected = self::selected_id();
        echo '<div class="wrap tng-cpb"><section class="tng-cpb-hero"><div><p class="eyebrow">CONTENT STUDIO</p><h1>Turn an idea into something you can shoot.</h1><p>Build the caption, opening hook, shot list, CTA, hashtags, and production plan before it reaches the calendar.</p></div><div><a class="button" href="' . esc_url(admin_url('admin.php?page=tng-content-idea-generator')) . '">Idea Generator</a><a class="button" href="' . esc_url(admin_url('admin.php?page=tng-content-calendar')) . '">Calendar</a></div></section>';
        if ($selected) self::builder($selected);
        self::queue();
        echo '</div>';
    }

    public static function assets(string $hook): void {
        if (!isset($_GET['page']) || $_GET['page'] !== 'tng-content-post-builder') return;
        wp_register_style('tng-content-post-builder', false, [], defined('TNG_OS_VERSION') ? TNG_OS_VERSION : null);
        wp_enqueue_style('tng-content-post-builder');
        wp_add_inline_style('tng-content-post-builder', '
            .tng-cpb{max-width:1220px}.tng-cpb .eyebrow{font-size:11px;font-weight:800;letter-spacing:.14em;color:#f26024;margin:0 0 8px}.tng-cpb-hero{margin:20px 0;background:linear-gradient(135deg,#073c2b,#176b45);color:#fff;border-radius:24px;padding:32px;display:flex;justify-content:space-between;gap:24px;align-items:flex-start}.tng-cpb-hero h1{color:#fff;font-size:34px;line-height:1.08;margin:4px 0 8px}.tng-cpb-hero p{font-size:15px;max-width:700px}.tng-cpb-hero>div:last-child{display:flex;gap:8px}.tng-cpb-builder,.tng-cpb-queue{background:#fff;border:1px solid #dfe6e1;border-radius:20px;padding:24px;margin:18px 0}.tng-cpb-head{display:flex;justify-content:space-between;gap:22px;align-items:flex-start}.tng-cpb-head h2{font-size:28px;margin:2px 0 6px;color:#143528}.tng-cpb-head p{max-width:760px;color:#6b7b72}.tng-cpb-score{text-align:center;background:#edf7f0;border-radius:15px;padding:14px 18px;min-width:100px}.tng-cpb-score strong,.tng-cpb-score span{display:block}.tng-cpb-score strong{font-size:28px;color:#12673f}.tng-cpb-score span{font-size:10px;text-transform:uppercase;color:#73827a}.tng-cpb-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:22px;margin-top:20px}.tng-cpb-column label,.tng-cpb-footer label{display:block;font-size:10px;font-weight:800;letter-spacing:.13em;color:#f05d25;margin:4px 0 5px}.tng-cpb-column input,.tng-cpb-column textarea,.tng-cpb-column select,.tng-cpb-footer select{width:100%;box-sizing:border-box;border:1px solid #d8e0db;border-radius:9px;padding:10px;margin-bottom:14px}.tng-cpb-column textarea{resize:vertical}.tng-cpb-mini-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}.tng-cpb-footer{border-top:1px solid #edf0ee;padding-top:17px;display:flex;justify-content:space-between;gap:20px;align-items:flex-end}.tng-cpb-footer>div:first-child{width:200px}.tng-cpb-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.tng-cpb-origin{margin-top:18px;background:#f3f7f4;border-radius:12px;padding:15px;color:#607168}.tng-cpb-origin strong{font-size:10px;letter-spacing:.12em;color:#176941}.tng-cpb-items{display:grid;gap:10px;margin-top:16px}.tng-cpb-items article{border:1px solid #e1e7e3;border-radius:14px;padding:14px;display:grid;grid-template-columns:1fr auto auto;align-items:center;gap:18px}.tng-cpb-item-main h3{margin:5px 0 4px;color:#17372a}.tng-cpb-item-main p{margin:0;color:#718078}.tng-cpb-status{display:inline-block;border-radius:999px;padding:4px 8px;background:#f0f4f1;font-size:10px;font-weight:800;text-transform:uppercase;color:#65756c}.status-creating{background:#fff0e8;color:#b84b1e}.status-ready{background:#e6f5eb;color:#14683d}.status-scheduled{background:#e9eefc;color:#3156a4}.tng-cpb-readiness{text-align:center}.tng-cpb-readiness strong,.tng-cpb-readiness span{display:block}.tng-cpb-readiness strong{font-size:20px;color:#17442f}.tng-cpb-readiness span{font-size:10px;color:#7b8881}.tng-cpb-empty{padding:22px;border:1px dashed #cdd9d2;border-radius:12px;color:#718078;margin-top:14px}@media(max-width:900px){.tng-cpb-hero,.tng-cpb-head,.tng-cpb-footer{display:block}.tng-cpb-hero>div:last-child{margin-top:16px}.tng-cpb-form-grid{grid-template-columns:1fr}.tng-cpb-items article{grid-template-columns:1fr}.tng-cpb-mini-grid{grid-template-columns:1fr}.tng-cpb-actions{margin-top:15px}.tng-cpb-footer>div:first-child{width:100%}}
        ');
    }
}

TNG_Content_Production_Builder::boot();
