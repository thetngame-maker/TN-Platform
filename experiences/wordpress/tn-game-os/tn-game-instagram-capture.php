<?php
/**
 * TN Game Instagram Capture
 * Bookmarklet-assisted one-click intake for public Instagram inspiration.
 */
if (!defined('ABSPATH')) exit;

final class TNG_Instagram_Capture {
    private const WATCH = 'tng_social_watch';
    private const CANDIDATE = 'tng_social_candidate';
    private const NONCE = 'tng_instagram_capture_nonce';

    public static function boot(): void {
        static $booted = false;
        if ($booted) return;
        $booted = true;
        add_action('admin_menu', [__CLASS__, 'admin_menu'], 26);
        add_action('admin_post_tng_instagram_capture_save', [__CLASS__, 'save']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);
    }

    public static function admin_menu(): void {
        add_submenu_page(
            'tng-content-studio',
            'Instagram Capture Tool',
            'Capture Tool',
            'edit_posts',
            'tng-instagram-capture',
            [__CLASS__, 'render']
        );
    }

    private static function watches(): array {
        return get_posts([
            'post_type' => self::WATCH,
            'post_status' => ['publish','draft','private'],
            'posts_per_page' => 100,
            'orderby' => 'title',
            'order' => 'ASC',
            'meta_query' => [
                'relation' => 'AND',
                ['key' => '_tng_watch_status', 'value' => 'active'],
                ['relation' => 'OR',
                    ['key' => '_tng_watch_platform', 'value' => 'instagram'],
                    ['key' => '_tng_watch_platform', 'value' => 'all'],
                ],
            ],
        ]);
    }

    private static function candidate_exists(string $url): bool {
        return (bool) get_posts([
            'post_type' => ['tng_social_candidate','tng_social_item'],
            'post_status' => 'any',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_query' => [
                'relation' => 'OR',
                ['key' => '_tng_candidate_source_url', 'value' => $url],
                ['key' => '_tng_source_url', 'value' => $url],
            ],
        ]);
    }

    private static function score(string $watch_value, string $title, string $creator, string $hashtags, string $location, int $likes, int $comments, int $views, int $published_ts): array {
        $needle = strtolower(ltrim(trim($watch_value), '#@'));
        $haystack = strtolower(trim($title . ' ' . $creator . ' ' . $hashtags . ' ' . $location));
        $tokens = array_values(array_filter(preg_split('/[^a-z0-9]+/i', $needle) ?: [], static fn($t) => strlen($t) >= 3));
        $matched = 0;
        foreach ($tokens as $token) if (str_contains($haystack, strtolower($token))) $matched++;
        $relevance = $tokens ? (int) round(($matched / count($tokens)) * 80) : 45;
        if ($needle && str_contains($haystack, $needle)) $relevance += 20;
        $relevance = min(100, max(0, $relevance));

        $engagement_raw = max(0, $likes) + ($comments * 4) + ($views * .018);
        $engagement = $engagement_raw > 0 ? (int) round(min(100, log10($engagement_raw + 1) * 27)) : 25;
        $age_hours = $published_ts ? max(0, (time() - $published_ts) / HOUR_IN_SECONDS) : 9999;
        if ($age_hours <= 24) $freshness = 100;
        elseif ($age_hours <= 72) $freshness = 90;
        elseif ($age_hours <= 168) $freshness = 76;
        elseif ($age_hours <= 336) $freshness = 60;
        elseif ($age_hours <= 720) $freshness = 42;
        else $freshness = $published_ts ? 22 : 45;

        $opportunity = (int) round(($relevance * .45) + ($engagement * .35) + ($freshness * .20));
        return [
            'relevance' => $relevance,
            'engagement' => $engagement,
            'freshness' => $freshness,
            'opportunity' => min(100, max(0, $opportunity)),
        ];
    }

    public static function save(): void {
        if (!current_user_can('edit_posts')) wp_die('Not allowed.');
        check_admin_referer(self::NONCE);

        $source = esc_url_raw(wp_unslash($_POST['source_url'] ?? ''));
        if (!$source || !str_contains(strtolower((string) wp_parse_url($source, PHP_URL_HOST)), 'instagram.com')) {
            wp_die('Enter a valid Instagram URL.');
        }
        if (self::candidate_exists($source)) {
            wp_safe_redirect(add_query_arg(['page'=>'tng-instagram-browser','tng_notice'=>'That Instagram post is already in Content Studio.'],admin_url('admin.php')));
            exit;
        }

        $title = sanitize_text_field(wp_unslash($_POST['candidate_title'] ?? '')) ?: 'Instagram inspiration';
        $creator = sanitize_text_field(wp_unslash($_POST['creator'] ?? ''));
        $format = sanitize_key(wp_unslash($_POST['format'] ?? 'reel'));
        if (!in_array($format, ['reel','carousel','photo','story','long_video','post'], true)) $format = 'post';
        $hashtags = sanitize_text_field(wp_unslash($_POST['hashtags'] ?? ''));
        $location = sanitize_text_field(wp_unslash($_POST['location'] ?? ''));
        $notes = sanitize_textarea_field(wp_unslash($_POST['notes'] ?? ''));
        $likes = absint($_POST['likes'] ?? 0);
        $comments = absint($_POST['comments'] ?? 0);
        $views = absint($_POST['views'] ?? 0);
        $watch_id = absint($_POST['watch_id'] ?? 0);
        $published_raw = sanitize_text_field(wp_unslash($_POST['published_date'] ?? ''));
        $published_ts = $published_raw ? (int) strtotime($published_raw . ' 12:00:00') : 0;
        $watch_value = $watch_id ? (string) get_post_meta($watch_id, '_tng_watch_value', true) : ($hashtags ?: ($location ?: $title));
        $signals = self::score($watch_value, $title, $creator, $hashtags, $location, $likes, $comments, $views, $published_ts);

        $id = wp_insert_post([
            'post_type' => self::CANDIDATE,
            'post_status' => 'publish',
            'post_title' => $title,
            'post_content' => $notes,
        ]);
        if (is_wp_error($id) || !$id) wp_die('Could not save the Instagram post.');

        $meta = [
            '_tng_candidate_source_url' => $source,
            '_tng_candidate_platform' => 'instagram',
            '_tng_candidate_creator' => $creator,
            '_tng_candidate_format' => $format,
            '_tng_candidate_hashtags' => $hashtags,
            '_tng_candidate_location' => $location,
            '_tng_candidate_likes' => $likes,
            '_tng_candidate_comments' => $comments,
            '_tng_candidate_views' => $views,
            '_tng_candidate_watch_id' => $watch_id,
            '_tng_candidate_published' => $published_ts ? gmdate('Y-m-d H:i:s', $published_ts) : '',
            '_tng_candidate_relevance' => $signals['relevance'],
            '_tng_candidate_engagement' => $signals['engagement'],
            '_tng_candidate_freshness' => $signals['freshness'],
            '_tng_candidate_trend_score' => $signals['opportunity'],
            '_tng_candidate_captured_via' => 'bookmarklet',
            '_tng_candidate_captured_at' => current_time('mysql'),
        ];
        foreach ($meta as $key => $value) if ($value !== '' && $value !== 0) update_post_meta($id, $key, $value);

        $next = sanitize_key(wp_unslash($_POST['next_action'] ?? 'browse'));
        if ($next === 'capture') {
            wp_safe_redirect(add_query_arg(['page'=>'tng-instagram-capture','saved'=>1],admin_url('admin.php')));
        } else {
            wp_safe_redirect(add_query_arg(['page'=>'tng-instagram-browser','tng_notice'=>'Instagram post captured.'],admin_url('admin.php')));
        }
        exit;
    }

    private static function bookmarklet(): string {
        $base = admin_url('admin.php?page=tng-instagram-capture');
        $js = "javascript:(()=>{const u='" . esc_js($base) . "&source='+encodeURIComponent(location.href)+'&title='+encodeURIComponent(document.title);window.open(u,'_blank','noopener');})();";
        return $js;
    }

    public static function render(): void {
        if (!current_user_can('edit_posts')) return;
        $source = esc_url_raw(wp_unslash($_GET['source'] ?? ''));
        $title = sanitize_text_field(wp_unslash($_GET['title'] ?? ''));
        $watches = self::watches();
        $is_capture = $source !== '';

        echo '<div class="wrap tng-ic"><section class="tng-ic-hero"><div><p class="eyebrow">INSTAGRAM CAPTURE</p><h1>' . ($is_capture ? 'Save this Instagram post.' : 'Capture Instagram inspiration in one click.') . '</h1><p>' . ($is_capture ? 'The source link is already here. Add only the signals that are useful, then send it straight to Browse.' : 'Install the bookmark below once. After that, use it while viewing any Instagram post to send that post into Content Studio.') . '</p></div><div><a class="button" href="' . esc_url(admin_url('admin.php?page=tng-instagram-browser')) . '">Browse Instagram</a></div></section>';

        if (isset($_GET['saved'])) echo '<div class="notice notice-success inline"><p>Instagram post saved. Capture another whenever you are ready.</p></div>';

        if (!$is_capture) {
            echo '<section class="tng-ic-install"><div><p class="eyebrow">ONE-TIME SETUP</p><h2>Add Save to TN Game to your browser</h2><p>Drag the orange button below to your Safari Favorites bar or bookmarks bar. Then open an Instagram post and click it.</p></div><a class="tng-ic-bookmarklet" href="' . esc_attr(self::bookmarklet()) . '">＋ Save to TN Game</a><ol><li>Open an Instagram post or Reel.</li><li>Click <strong>Save to TN Game</strong> in your bookmarks bar.</li><li>TN Game opens with the post URL prefilled.</li><li>Add any useful metrics or notes and save.</li></ol></section>';
        }

        echo '<section class="tng-ic-form"><div class="tng-ic-form-head"><div><p class="eyebrow">QUICK CAPTURE</p><h2>' . ($is_capture ? 'Review and save' : 'Manual capture') . '</h2></div>' . ($source ? '<a href="' . esc_url($source) . '" target="_blank" rel="noopener">View Instagram ↗</a>' : '') . '</div>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(self::NONCE);
        echo '<input type="hidden" name="action" value="tng_instagram_capture_save">';
        echo '<label>INSTAGRAM POST URL</label><input type="url" required name="source_url" value="' . esc_attr($source) . '" placeholder="https://www.instagram.com/p/...">';
        echo '<div class="tng-ic-grid"><div><label>SHORT LABEL</label><input name="candidate_title" value="' . esc_attr($title) . '" placeholder="Foster Falls Reel"></div><div><label>CREATOR</label><input name="creator" placeholder="@creator"></div><div><label>FORMAT</label><select name="format"><option value="reel">Reel</option><option value="carousel">Carousel</option><option value="photo">Photo</option><option value="story">Story</option><option value="long_video">Long video</option><option value="post">Post</option></select></div><div><label>RELATED WATCH</label><select name="watch_id"><option value="0">No watch</option>';
        foreach ($watches as $watch) echo '<option value="' . (int)$watch->ID . '">' . esc_html($watch->post_title) . '</option>';
        echo '</select></div><div><label>PLACE</label><input name="location" placeholder="Foster Falls"></div><div><label>PUBLISHED</label><input type="date" name="published_date"></div></div>';
        echo '<label>HASHTAGS</label><input name="hashtags" placeholder="#fosterfalls #tennesseehiking">';
        echo '<div class="tng-ic-metrics"><div><label>LIKES</label><input type="number" min="0" name="likes" value="0"></div><div><label>COMMENTS</label><input type="number" min="0" name="comments" value="0"></div><div><label>VIEWS</label><input type="number" min="0" name="views" value="0"></div></div>';
        echo '<label>WHY DID THIS CATCH YOUR ATTENTION?</label><textarea name="notes" rows="4" placeholder="Strong opening hook, waterfall reveal, useful trail detail, creator style, comments asking for directions…"></textarea>';
        echo '<div class="tng-ic-actions"><button class="button button-primary button-hero" name="next_action" value="browse">Save to Browse</button><button class="button" name="next_action" value="capture">Save + capture another</button></div></form></section></div>';
    }

    public static function assets(): void {
        if (!isset($_GET['page']) || $_GET['page'] !== 'tng-instagram-capture') return;
        wp_register_style('tng-instagram-capture', false, [], defined('TNG_OS_VERSION') ? TNG_OS_VERSION : null);
        wp_enqueue_style('tng-instagram-capture');
        wp_add_inline_style('tng-instagram-capture', '.tng-ic{max-width:1120px}.tng-ic-hero{margin:20px 0;background:linear-gradient(135deg,#073c2b,#176b45);color:#fff;border-radius:24px;padding:32px;display:flex;justify-content:space-between;gap:24px;align-items:flex-start}.tng-ic-hero h1{color:#fff;font-size:34px;margin:5px 0 8px}.tng-ic-hero p{max-width:720px;font-size:15px}.eyebrow,.tng-ic label{font-size:11px;font-weight:800;letter-spacing:.12em;color:#f05b25}.tng-ic-install,.tng-ic-form{background:#fff;border:1px solid #dde4df;border-radius:20px;padding:24px;margin:18px 0}.tng-ic-install{display:grid;grid-template-columns:1.3fr .8fr;gap:22px;align-items:center}.tng-ic-install ol{grid-column:1/-1;display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin:4px 0 0;padding:0;list-style:none;counter-reset:steps}.tng-ic-install li{background:#f4f8f5;border-radius:12px;padding:14px;color:#4f6258;counter-increment:steps}.tng-ic-install li:before{content:counter(steps);display:inline-grid;place-items:center;width:24px;height:24px;border-radius:50%;background:#e85f24;color:#fff;font-weight:800;margin-right:7px}.tng-ic-bookmarklet{display:flex;align-items:center;justify-content:center;min-height:62px;background:#ef6126;color:#fff!important;text-decoration:none;font-size:17px;font-weight:800;border-radius:14px;box-shadow:0 8px 22px rgba(239,97,38,.2)}.tng-ic-form-head{display:flex;justify-content:space-between;align-items:flex-start}.tng-ic-form h2,.tng-ic-install h2{font-size:27px;margin:4px 0 8px;color:#15382a}.tng-ic-form input,.tng-ic-form select,.tng-ic-form textarea{box-sizing:border-box;width:100%;margin:6px 0 16px;border:1px solid #d7dfda;border-radius:9px;padding:10px}.tng-ic-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:12px}.tng-ic-metrics{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}.tng-ic-actions{display:flex;gap:10px;border-top:1px solid #edf1ee;padding-top:16px}.tng-ic-actions .button-hero{min-width:180px}@media(max-width:800px){.tng-ic-hero{display:block}.tng-ic-install{grid-template-columns:1fr}.tng-ic-install ol{grid-template-columns:1fr}.tng-ic-grid,.tng-ic-metrics{grid-template-columns:1fr}}');
    }
}
TNG_Instagram_Capture::boot();
