<?php
/**
 * TN Game Social Intelligence Discovery Inbox
 * Review-first intake for watchlist discovery candidates.
 */
if (!defined('ABSPATH')) exit;

final class TNG_Social_Discovery {
    private const CANDIDATE = 'tng_social_candidate';
    private const WATCH = 'tng_social_watch';
    private const ITEM = 'tng_social_item';
    private const ACTION_NONCE = 'tng_social_discovery_action';

    public static function boot(): void {
        static $booted = false;
        if ($booted) return;
        $booted = true;
        add_action('init', [__CLASS__, 'register_candidate']);
        add_action('admin_menu', [__CLASS__, 'admin_menu'], 24);
        add_filter('post_row_actions', [__CLASS__, 'watch_row_actions'], 20, 2);
        add_action('admin_post_tng_social_run_watch', [__CLASS__, 'run_watch_action']);
        add_action('admin_post_tng_social_run_all', [__CLASS__, 'run_all_action']);
        add_action('admin_post_tng_social_save_candidate', [__CLASS__, 'save_candidate_action']);
        add_action('admin_post_tng_social_dismiss_candidate', [__CLASS__, 'dismiss_candidate_action']);
        add_action('admin_post_tng_social_add_candidate', [__CLASS__, 'add_candidate_action']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'admin_assets']);
    }

    public static function register_candidate(): void {
        register_post_type(self::CANDIDATE, [
            'labels' => ['name' => 'Discovery candidates', 'singular_name' => 'Discovery candidate'],
            'public' => false, 'show_ui' => false, 'supports' => ['title','editor'],
            'capability_type' => 'post', 'map_meta_cap' => true,
        ]);
    }

    public static function admin_menu(): void {
        add_submenu_page(
            'tng-content-studio', 'Discovery Inbox', 'Discovery Inbox', 'edit_posts',
            'tng-social-discovery', [__CLASS__, 'inbox']
        );
    }

    private static function action_url(string $action, array $args = []): string {
        $url = add_query_arg(array_merge(['action' => $action], $args), admin_url('admin-post.php'));
        return wp_nonce_url($url, self::ACTION_NONCE);
    }

    public static function watch_row_actions(array $actions, WP_Post $post): array {
        if ($post->post_type !== self::WATCH || !current_user_can('edit_post', $post->ID)) return $actions;
        $actions['tng_run_discovery'] = '<a href="' . esc_url(self::action_url('tng_social_run_watch', ['watch_id' => $post->ID])) . '">Run discovery</a>';
        $search = self::platform_search_url($post->ID);
        if ($search) $actions['tng_open_social_search'] = '<a href="' . esc_url($search) . '" target="_blank" rel="noopener">Open platform search ↗</a>';
        return $actions;
    }

    public static function run_watch_action(): void {
        self::guard();
        $watch_id = absint($_GET['watch_id'] ?? 0);
        if (!$watch_id || get_post_type($watch_id) !== self::WATCH || !current_user_can('edit_post', $watch_id)) wp_die('Invalid watch item.');
        $result = self::run_watch($watch_id);
        self::redirect_notice($result['added'] ?? 0, $result['message'] ?? 'Discovery finished.');
    }

    public static function run_all_action(): void {
        self::guard();
        $ids = get_posts([
            'post_type' => self::WATCH, 'post_status' => ['publish','draft','private'], 'numberposts' => 50,
            'fields' => 'ids', 'meta_query' => [['key' => '_tng_watch_status', 'value' => 'active']],
        ]);
        $added = 0; $messages = [];
        foreach ($ids as $id) {
            $result = self::run_watch((int) $id);
            $added += (int) ($result['added'] ?? 0);
            if (!empty($result['message'])) $messages[] = $result['message'];
        }
        self::redirect_notice($added, $ids ? implode(' ', array_unique($messages)) : 'No active Watchlist items yet.');
    }

    private static function run_watch(int $watch_id): array {
        $value = trim((string) get_post_meta($watch_id, '_tng_watch_value', true));
        $type = (string) get_post_meta($watch_id, '_tng_watch_type', true);
        $platform = (string) get_post_meta($watch_id, '_tng_watch_platform', true);
        if (!$value) $value = get_the_title($watch_id);
        update_post_meta($watch_id, '_tng_watch_last_run', current_time('mysql'));

        if ($platform === 'reddit' || $platform === 'all' || $platform === '') {
            $added = self::discover_reddit($watch_id, $value, $type);
            update_post_meta($watch_id, '_tng_watch_last_results', $added);
            return ['added' => $added, 'message' => $added ? 'Recent Reddit matches were added to the Inbox.' : 'No new Reddit matches were found. Platform search links are still available for manual review.'];
        }
        update_post_meta($watch_id, '_tng_watch_last_results', 0);
        return ['added' => 0, 'message' => 'This platform currently uses review-first platform search. Open the Watchlist search link and add promising post URLs to the Inbox.'];
    }

    private static function reddit_query(string $value, string $type): string {
        $value = ltrim(trim($value), '#@');
        if ($type === 'account') return 'author:' . $value;
        return $value;
    }

    private static function discover_reddit(int $watch_id, string $value, string $type): int {
        $query = self::reddit_query($value, $type);
        $url = add_query_arg(['q' => $query, 'sort' => 'new', 'limit' => 25, 'raw_json' => 1], 'https://www.reddit.com/search.json');
        $response = wp_remote_get($url, [
            'timeout' => 12,
            'headers' => ['User-Agent' => 'TNGameSocialIntelligence/0.2 (+https://thetngame.com)'],
        ]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) return 0;
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        $children = $body['data']['children'] ?? [];
        if (!is_array($children)) return 0;
        $added = 0;
        foreach ($children as $child) {
            $d = $child['data'] ?? [];
            if (!is_array($d) || empty($d['permalink'])) continue;
            $source = 'https://www.reddit.com' . $d['permalink'];
            $title = sanitize_text_field((string) ($d['title'] ?? 'Reddit discovery'));
            $creator = sanitize_text_field((string) ($d['author'] ?? ''));
            $excerpt = sanitize_textarea_field(wp_strip_all_tags((string) ($d['selftext'] ?? '')));
            $score = absint($d['score'] ?? 0);
            $comments = absint($d['num_comments'] ?? 0);
            $published = !empty($d['created_utc']) ? gmdate('Y-m-d H:i:s', (int) $d['created_utc']) : '';
            if (self::candidate_exists($source)) continue;
            $id = wp_insert_post([
                'post_type' => self::CANDIDATE, 'post_status' => 'publish',
                'post_title' => $title ?: 'Reddit discovery', 'post_content' => mb_substr($excerpt, 0, 3000),
            ], true);
            if (is_wp_error($id) || !$id) continue;
            update_post_meta($id, '_tng_candidate_source_url', esc_url_raw($source));
            update_post_meta($id, '_tng_candidate_creator', $creator ? 'u/' . $creator : '');
            update_post_meta($id, '_tng_candidate_platform', 'reddit');
            update_post_meta($id, '_tng_candidate_watch_id', $watch_id);
            update_post_meta($id, '_tng_candidate_score', $score);
            update_post_meta($id, '_tng_candidate_comments', $comments);
            update_post_meta($id, '_tng_candidate_published', $published);
            update_post_meta($id, '_tng_candidate_status', 'new');
            $added++;
        }
        return $added;
    }

    private static function candidate_exists(string $url): bool {
        $found = get_posts([
            'post_type' => [self::CANDIDATE, self::ITEM], 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids',
            'meta_query' => ['relation' => 'OR',
                ['key' => '_tng_candidate_source_url', 'value' => $url],
                ['key' => '_tng_source_url', 'value' => $url],
            ],
        ]);
        return !empty($found);
    }

    public static function add_candidate_action(): void {
        self::guard();
        $source = esc_url_raw(wp_unslash($_POST['source_url'] ?? ''));
        if (!$source) self::redirect_notice(0, 'Enter a valid public post URL.');
        if (self::candidate_exists($source)) self::redirect_notice(0, 'That post is already in the Inbox or Inspiration library.');
        $title = sanitize_text_field(wp_unslash($_POST['candidate_title'] ?? '')) ?: 'Manual discovery';
        $platform = self::platform_from_url($source);
        $id = wp_insert_post(['post_type' => self::CANDIDATE, 'post_status' => 'publish', 'post_title' => $title]);
        if ($id) {
            update_post_meta($id, '_tng_candidate_source_url', $source);
            update_post_meta($id, '_tng_candidate_platform', $platform);
            update_post_meta($id, '_tng_candidate_status', 'new');
        }
        self::redirect_notice($id ? 1 : 0, $id ? 'Post added to the Discovery Inbox.' : 'Could not add the post.');
    }

    public static function save_candidate_action(): void {
        self::guard();
        $id = absint($_GET['candidate_id'] ?? 0);
        $candidate = get_post($id);
        if (!$candidate || $candidate->post_type !== self::CANDIDATE || !current_user_can('edit_post', $id)) wp_die('Invalid discovery candidate.');
        $source = (string) get_post_meta($id, '_tng_candidate_source_url', true);
        $creator = (string) get_post_meta($id, '_tng_candidate_creator', true);
        $platform = (string) get_post_meta($id, '_tng_candidate_platform', true);
        $inspiration_id = wp_insert_post([
            'post_type' => self::ITEM, 'post_status' => 'draft',
            'post_title' => $candidate->post_title, 'post_content' => $candidate->post_content,
        ]);
        if ($inspiration_id) {
            update_post_meta($inspiration_id, '_tng_source_url', $source);
            update_post_meta($inspiration_id, '_tng_creator_handle', $creator);
            update_post_meta($inspiration_id, '_tng_plan_status', 'inspiration');
            update_post_meta($inspiration_id, '_tng_permission_status', 'not_needed');
            if ($platform) wp_set_object_terms($inspiration_id, [ucfirst($platform)], 'tng_social_platform', true);
            wp_delete_post($id, true);
            wp_safe_redirect(get_edit_post_link($inspiration_id, 'url'));
            exit;
        }
        self::redirect_notice(0, 'Could not save this candidate to Inspiration.');
    }

    public static function dismiss_candidate_action(): void {
        self::guard();
        $id = absint($_GET['candidate_id'] ?? 0);
        if ($id && get_post_type($id) === self::CANDIDATE && current_user_can('delete_post', $id)) wp_trash_post($id);
        self::redirect_notice(0, 'Candidate dismissed.');
    }

    private static function guard(): void {
        if (!current_user_can('edit_posts')) wp_die('You do not have permission to use Social Intelligence.');
        check_admin_referer(self::ACTION_NONCE);
    }

    private static function redirect_notice(int $added, string $message): void {
        $url = add_query_arg(['page' => 'tng-social-discovery', 'tng_added' => $added, 'tng_notice' => rawurlencode($message)], admin_url('admin.php'));
        wp_safe_redirect($url); exit;
    }

    private static function platform_from_url(string $url): string {
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        if (str_contains($host, 'instagram.com')) return 'instagram';
        if (str_contains($host, 'facebook.com') || str_contains($host, 'fb.com')) return 'facebook';
        if (str_contains($host, 'tiktok.com')) return 'tiktok';
        if (str_contains($host, 'youtube.com') || str_contains($host, 'youtu.be')) return 'youtube';
        if (str_contains($host, 'reddit.com')) return 'reddit';
        if (str_contains($host, 'pinterest.')) return 'pinterest';
        return 'web';
    }

    private static function platform_search_url(int $watch_id): string {
        $value = trim((string) get_post_meta($watch_id, '_tng_watch_value', true));
        if (!$value) $value = get_the_title($watch_id);
        $platform = (string) get_post_meta($watch_id, '_tng_watch_platform', true);
        $clean = ltrim($value, '#@');
        $encoded = rawurlencode($clean);
        switch ($platform) {
            case 'instagram': return 'https://www.instagram.com/explore/search/keyword/?q=' . rawurlencode($value);
            case 'facebook': return 'https://www.facebook.com/search/posts/?q=' . rawurlencode($value);
            case 'tiktok': return 'https://www.tiktok.com/search?q=' . rawurlencode($value);
            case 'youtube': return 'https://www.youtube.com/results?search_query=' . rawurlencode($value);
            case 'reddit': return 'https://www.reddit.com/search/?q=' . rawurlencode($value) . '&sort=new';
            case 'pinterest': return 'https://www.pinterest.com/search/pins/?q=' . rawurlencode($value);
            default: return 'https://www.google.com/search?q=' . rawurlencode($value . ' site:instagram.com OR site:tiktok.com OR site:reddit.com OR site:youtube.com');
        }
    }

    public static function inbox(): void {
        if (!current_user_can('edit_posts')) return;
        $candidates = get_posts([
            'post_type' => self::CANDIDATE, 'post_status' => 'publish', 'numberposts' => 100,
            'orderby' => 'date', 'order' => 'DESC',
        ]);
        $watches = get_posts([
            'post_type' => self::WATCH, 'post_status' => ['publish','draft','private'], 'numberposts' => 50,
            'orderby' => 'modified', 'order' => 'DESC',
        ]);
        $notice = isset($_GET['tng_notice']) ? sanitize_text_field(wp_unslash($_GET['tng_notice'])) : '';
        echo '<div class="wrap tng-sd-wrap"><div class="tng-sd-hero"><div><p class="eyebrow">SOCIAL INTELLIGENCE</p><h1>Discovery Inbox</h1><p>Review public social signals before they become permanent inspiration. Save what is useful; dismiss the rest.</p></div><a class="button button-primary" href="' . esc_url(self::action_url('tng_social_run_all')) . '">Run all active watches</a></div>';
        if ($notice) echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(rawurldecode($notice)) . '</p></div>';
        echo '<div class="tng-sd-grid"><section class="tng-sd-panel"><div class="tng-sd-head"><div><p class="eyebrow">WATCHLIST</p><h2>Run discovery</h2></div><a href="' . esc_url(admin_url('post-new.php?post_type=' . self::WATCH)) . '">+ Add watch</a></div>';
        if (!$watches) echo '<div class="tng-sd-empty">Add a hashtag, account, location, or topic to start watching.</div>';
        foreach ($watches as $watch) {
            $platform = (string) get_post_meta($watch->ID, '_tng_watch_platform', true);
            $status = (string) get_post_meta($watch->ID, '_tng_watch_status', true);
            $last = (string) get_post_meta($watch->ID, '_tng_watch_last_run', true);
            echo '<div class="tng-sd-watch"><div><strong>' . esc_html(get_the_title($watch)) . '</strong><span>' . esc_html(ucfirst($platform ?: 'all')) . ' · ' . esc_html(ucfirst($status ?: 'active')) . ($last ? ' · last run ' . esc_html($last) : '') . '</span></div><div class="tng-sd-actions"><a class="button button-small" href="' . esc_url(self::action_url('tng_social_run_watch', ['watch_id' => $watch->ID])) . '">Run</a>';
            $search = self::platform_search_url($watch->ID);
            if ($search) echo '<a class="button button-small" href="' . esc_url($search) . '" target="_blank" rel="noopener">Search ↗</a>';
            echo '</div></div>';
        }
        echo '</section><section class="tng-sd-panel"><div class="tng-sd-head"><div><p class="eyebrow">MANUAL INTAKE</p><h2>Add a post you find</h2></div></div><form class="tng-sd-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="tng_social_add_candidate">';
        wp_nonce_field(self::ACTION_NONCE);
        echo '<input type="url" name="source_url" required placeholder="Paste Instagram, Facebook, TikTok, YouTube, Reddit… URL"><input type="text" name="candidate_title" placeholder="Short label (optional)"><button class="button button-primary">Add to Inbox</button></form><p class="description">Use this while reviewing platform-native searches. We save the link and your notes—not copied media.</p></section></div>';

        echo '<section class="tng-sd-panel tng-sd-inbox"><div class="tng-sd-head"><div><p class="eyebrow">REVIEW QUEUE</p><h2>' . number_format_i18n(count($candidates)) . ' candidates</h2></div></div>';
        if (!$candidates) echo '<div class="tng-sd-empty">Your Inbox is clear. Run an active watch or paste a public post URL above.</div>';
        foreach ($candidates as $candidate) {
            $source = (string) get_post_meta($candidate->ID, '_tng_candidate_source_url', true);
            $creator = (string) get_post_meta($candidate->ID, '_tng_candidate_creator', true);
            $platform = (string) get_post_meta($candidate->ID, '_tng_candidate_platform', true);
            $score = (int) get_post_meta($candidate->ID, '_tng_candidate_score', true);
            $comments = (int) get_post_meta($candidate->ID, '_tng_candidate_comments', true);
            $watch_id = (int) get_post_meta($candidate->ID, '_tng_candidate_watch_id', true);
            $watch_name = $watch_id ? get_the_title($watch_id) : '';
            echo '<article class="tng-sd-candidate"><div class="tng-sd-badge">' . esc_html(strtoupper($platform ?: 'WEB')) . '</div><div class="tng-sd-body"><h3>' . esc_html($candidate->post_title) . '</h3><p class="tng-sd-meta">' . esc_html($creator ?: 'Public post') . ($watch_name ? ' · from ' . esc_html($watch_name) : '') . (($score || $comments) ? ' · ' . number_format_i18n($score) . ' score · ' . number_format_i18n($comments) . ' comments' : '') . '</p>';
            if ($candidate->post_content) echo '<p>' . esc_html(wp_trim_words($candidate->post_content, 34)) . '</p>';
            echo '<div class="tng-sd-actions"><a class="button" href="' . esc_url($source) . '" target="_blank" rel="noopener">View source ↗</a><a class="button button-primary" href="' . esc_url(self::action_url('tng_social_save_candidate', ['candidate_id' => $candidate->ID])) . '">Save to Inspiration</a><a class="button" href="' . esc_url(self::action_url('tng_social_dismiss_candidate', ['candidate_id' => $candidate->ID])) . '">Dismiss</a></div></div></article>';
        }
        echo '</section></div>';
    }

    public static function admin_assets(string $hook): void {
        if (strpos($hook, 'tng-social-discovery') === false) return;
        wp_register_style('tng-social-discovery-admin', false, [], '0.2.0');
        wp_enqueue_style('tng-social-discovery-admin');
        wp_add_inline_style('tng-social-discovery-admin', '.tng-sd-wrap{max-width:1280px}.tng-sd-hero{margin:20px 0;background:linear-gradient(135deg,#0b422b,#17633d);color:#fff;padding:30px 34px;border-radius:22px;display:flex;justify-content:space-between;align-items:center;gap:20px}.tng-sd-hero h1{color:#fff;font-size:38px;margin:4px 0 8px}.tng-sd-hero p{max-width:760px}.tng-sd-wrap .eyebrow{font-size:11px;font-weight:800;letter-spacing:.12em;color:#f26322;margin:0 0 6px}.tng-sd-hero .eyebrow{color:#ff9b63}.tng-sd-grid{display:grid;grid-template-columns:1.25fr .75fr;gap:18px;margin:18px 0}.tng-sd-panel{background:#fff;border:1px solid #dfe7e1;border-radius:18px;padding:22px}.tng-sd-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;margin-bottom:14px}.tng-sd-head h2{margin:0;font-size:23px;color:#132c21}.tng-sd-watch{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:13px 2px;border-top:1px solid #edf1ee}.tng-sd-watch strong,.tng-sd-watch span{display:block}.tng-sd-watch span,.tng-sd-meta{font-size:12px;color:#728078;margin-top:3px}.tng-sd-actions{display:flex;gap:8px;flex-wrap:wrap}.tng-sd-form{display:grid;gap:10px}.tng-sd-form input{width:100%}.tng-sd-inbox{margin-bottom:30px}.tng-sd-candidate{position:relative;display:grid;grid-template-columns:88px 1fr;gap:18px;padding:18px 2px;border-top:1px solid #edf1ee}.tng-sd-badge{background:#edf6ef;color:#27633e;border-radius:12px;height:58px;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;letter-spacing:.08em}.tng-sd-body h3{font-size:19px;margin:0 0 4px}.tng-sd-body>p{max-width:880px}.tng-sd-empty{padding:24px;border:1px dashed #cad8cf;border-radius:14px;color:#64756b;background:#f8faf8}@media(max-width:900px){.tng-sd-hero{align-items:flex-start;flex-direction:column}.tng-sd-grid{grid-template-columns:1fr}.tng-sd-candidate{grid-template-columns:1fr}.tng-sd-badge{width:90px}}');
    }
}

TNG_Social_Discovery::boot();
