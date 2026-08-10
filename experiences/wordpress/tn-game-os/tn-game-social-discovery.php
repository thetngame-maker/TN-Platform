<?php
/**
 * TN Game Social Intelligence Discovery Inbox
 * Instagram-first review and scoring workflow with optional Reddit diagnostics.
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
        add_action('admin_post_tng_social_quick_watch', [__CLASS__, 'quick_watch_action']);
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
        add_submenu_page('tng-content-studio', 'Instagram Intelligence', 'Discovery Inbox', 'edit_posts', 'tng-social-discovery', [__CLASS__, 'inbox']);
    }

    private static function action_url(string $action, array $args = []): string {
        return wp_nonce_url(add_query_arg(array_merge(['action' => $action], $args), admin_url('admin-post.php')), self::ACTION_NONCE);
    }

    private static function guard(): void {
        if (!current_user_can('edit_posts')) wp_die('You do not have permission to use Social Intelligence.');
        check_admin_referer(self::ACTION_NONCE);
    }

    private static function redirect_notice(int $added, string $message): void {
        wp_safe_redirect(add_query_arg(['page' => 'tng-social-discovery', 'tng_added' => $added, 'tng_notice' => rawurlencode($message)], admin_url('admin.php')));
        exit;
    }

    public static function watch_row_actions(array $actions, WP_Post $post): array {
        if ($post->post_type !== self::WATCH || !current_user_can('edit_post', $post->ID)) return $actions;
        $actions['tng_run_discovery'] = '<a href="' . esc_url(self::action_url('tng_social_run_watch', ['watch_id' => $post->ID])) . '">Prepare discovery</a>';
        $search = self::platform_search_url($post->ID);
        if ($search) $actions['tng_open_social_search'] = '<a href="' . esc_url($search) . '" target="_blank" rel="noopener">Open Instagram ↗</a>';
        return $actions;
    }

    public static function quick_watch_action(): void {
        self::guard();
        $value = sanitize_text_field(wp_unslash($_POST['watch_value'] ?? ''));
        $type = sanitize_key(wp_unslash($_POST['watch_type'] ?? 'hashtag'));
        $location = sanitize_text_field(wp_unslash($_POST['watch_location'] ?? ''));
        if (!$value) self::redirect_notice(0, 'Enter a hashtag, creator, place, or topic to watch.');
        if (!in_array($type, ['hashtag','account','location','topic'], true)) $type = 'topic';
        $clean = ltrim(trim($value), '#@');
        $title = $type === 'hashtag' ? '#' . $clean : ($type === 'account' ? '@' . $clean : $value);
        $id = wp_insert_post(['post_type' => self::WATCH, 'post_status' => 'publish', 'post_title' => $title]);
        if ($id) {
            update_post_meta($id, '_tng_watch_type', $type);
            update_post_meta($id, '_tng_watch_value', $title);
            update_post_meta($id, '_tng_watch_platform', 'instagram');
            update_post_meta($id, '_tng_watch_status', 'active');
            if ($location) update_post_meta($id, '_tng_watch_location', $location);
            update_post_meta($id, '_tng_watch_source_status', 'instagram_ready');
        }
        self::redirect_notice($id ? 1 : 0, $id ? 'Instagram watch added.' : 'Could not add the Instagram watch.');
    }

    public static function run_watch_action(): void {
        self::guard();
        $watch_id = absint($_GET['watch_id'] ?? 0);
        if (!$watch_id || get_post_type($watch_id) !== self::WATCH || !current_user_can('edit_post', $watch_id)) wp_die('Invalid watch item.');
        $result = self::run_watch($watch_id);
        self::redirect_notice((int) ($result['added'] ?? 0), (string) ($result['message'] ?? 'Discovery prepared.'));
    }

    public static function run_all_action(): void {
        self::guard();
        $ids = get_posts([
            'post_type' => self::WATCH, 'post_status' => ['publish','draft','private'], 'numberposts' => 100,
            'fields' => 'ids',
            'meta_query' => [
                'relation' => 'AND',
                ['key' => '_tng_watch_status', 'value' => 'active'],
                ['relation' => 'OR', ['key' => '_tng_watch_platform', 'value' => 'instagram'], ['key' => '_tng_watch_platform', 'value' => 'all']],
            ],
        ]);
        foreach ($ids as $id) self::run_watch((int) $id);
        self::redirect_notice(0, $ids ? count($ids) . ' Instagram watch' . (count($ids) === 1 ? '' : 'es') . ' prepared for review.' : 'No active Instagram watches yet.');
    }

    private static function run_watch(int $watch_id): array {
        $value = trim((string) get_post_meta($watch_id, '_tng_watch_value', true));
        $type = (string) get_post_meta($watch_id, '_tng_watch_type', true);
        $platform = (string) get_post_meta($watch_id, '_tng_watch_platform', true);
        if (!$value) $value = get_the_title($watch_id);
        update_post_meta($watch_id, '_tng_watch_last_run', current_time('mysql'));

        if ($platform === 'instagram' || $platform === 'all' || $platform === '') {
            update_post_meta($watch_id, '_tng_watch_last_results', 0);
            update_post_meta($watch_id, '_tng_watch_source_status', 'instagram_ready');
            update_post_meta($watch_id, '_tng_watch_last_query', $value);
            return ['added' => 0, 'message' => 'Instagram watch is ready. Open Instagram ↗, review recent/top posts, then paste promising post URLs into Quick Capture.'];
        }
        if ($platform === 'reddit') {
            $result = self::discover_reddit($watch_id, $value, $type);
            foreach (['added'=>'_tng_watch_last_results','fetched'=>'_tng_watch_last_fetched','duplicates'=>'_tng_watch_last_duplicates','status'=>'_tng_watch_source_status','http_code'=>'_tng_watch_source_http','error'=>'_tng_watch_source_error','elapsed_ms'=>'_tng_watch_source_ms','query'=>'_tng_watch_last_query'] as $key => $meta) update_post_meta($watch_id, $meta, $result[$key] ?? '');
            return ['added' => (int) $result['added'], 'message' => (string) $result['message']];
        }
        update_post_meta($watch_id, '_tng_watch_source_status', 'manual');
        return ['added' => 0, 'message' => 'Use the platform search link and add useful public post URLs to the Inbox.'];
    }

    private static function platform_search_url(int $watch_id): string {
        $value = trim((string) get_post_meta($watch_id, '_tng_watch_value', true));
        if (!$value) $value = get_the_title($watch_id);
        $platform = (string) get_post_meta($watch_id, '_tng_watch_platform', true);
        $type = (string) get_post_meta($watch_id, '_tng_watch_type', true);
        $clean = ltrim(trim($value), '#@');
        if ($platform === 'instagram' || $platform === 'all' || $platform === '') {
            if ($type === 'hashtag') return 'https://www.instagram.com/explore/tags/' . rawurlencode(strtolower(str_replace(' ', '', $clean))) . '/';
            if ($type === 'account') return 'https://www.instagram.com/' . rawurlencode($clean) . '/';
            return 'https://www.instagram.com/explore/search/keyword/?q=' . rawurlencode($clean);
        }
        if ($platform === 'facebook') return 'https://www.facebook.com/search/posts/?q=' . rawurlencode($value);
        if ($platform === 'tiktok') return 'https://www.tiktok.com/search?q=' . rawurlencode($value);
        if ($platform === 'youtube') return 'https://www.youtube.com/results?search_query=' . rawurlencode($value);
        if ($platform === 'reddit') return 'https://www.reddit.com/search/?q=' . rawurlencode($value) . '&sort=new';
        if ($platform === 'pinterest') return 'https://www.pinterest.com/search/pins/?q=' . rawurlencode($value);
        return 'https://www.google.com/search?q=' . rawurlencode($value . ' site:instagram.com');
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

    private static function candidate_exists(string $url): bool {
        return (bool) get_posts([
            'post_type' => [self::CANDIDATE, self::ITEM], 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids',
            'meta_query' => ['relation' => 'OR', ['key' => '_tng_candidate_source_url', 'value' => $url], ['key' => '_tng_source_url', 'value' => $url]],
        ]);
    }

    private static function score_instagram(string $watch_value, string $title, string $creator, string $hashtags, string $location, int $likes, int $comments, int $views, int $published_ts): array {
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
        return ['relevance'=>$relevance,'engagement'=>$engagement,'freshness'=>$freshness,'opportunity'=>min(100,max(0,$opportunity))];
    }

    private static function insight_label(int $opportunity, int $engagement, string $format): string {
        if ($opportunity >= 78) return 'Strong content opportunity';
        if ($engagement >= 75) return 'High engagement signal';
        if ($format === 'reel') return 'Reel inspiration';
        if ($opportunity >= 55) return 'Worth reviewing';
        return 'Early signal';
    }

    public static function add_candidate_action(): void {
        self::guard();
        $source = esc_url_raw(wp_unslash($_POST['source_url'] ?? ''));
        if (!$source) self::redirect_notice(0, 'Enter a valid public post URL.');
        if (self::candidate_exists($source)) self::redirect_notice(0, 'That post is already in the Inbox or Inspiration library.');
        $title = sanitize_text_field(wp_unslash($_POST['candidate_title'] ?? '')) ?: 'Instagram inspiration';
        $platform = self::platform_from_url($source);
        $creator = sanitize_text_field(wp_unslash($_POST['creator'] ?? ''));
        $format = sanitize_key(wp_unslash($_POST['format'] ?? ''));
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
        $signals = self::score_instagram($watch_value, $title, $creator, $hashtags, $location, $likes, $comments, $views, $published_ts);

        $id = wp_insert_post(['post_type'=>self::CANDIDATE,'post_status'=>'publish','post_title'=>$title,'post_content'=>$notes]);
        if ($id) {
            $meta = [
                '_tng_candidate_source_url'=>$source,'_tng_candidate_platform'=>$platform,'_tng_candidate_creator'=>$creator,
                '_tng_candidate_format'=>$format,'_tng_candidate_hashtags'=>$hashtags,'_tng_candidate_location'=>$location,
                '_tng_candidate_likes'=>$likes,'_tng_candidate_comments'=>$comments,'_tng_candidate_views'=>$views,
                '_tng_candidate_watch_id'=>$watch_id,'_tng_candidate_published'=>$published_ts ? gmdate('Y-m-d H:i:s',$published_ts) : '',
                '_tng_candidate_relevance'=>$signals['relevance'],'_tng_candidate_engagement'=>$signals['engagement'],
                '_tng_candidate_freshness'=>$signals['freshness'],'_tng_candidate_trend_score'=>$signals['opportunity'],'_tng_candidate_status'=>'new',
            ];
            foreach ($meta as $k=>$v) update_post_meta($id,$k,$v);
        }
        self::redirect_notice($id ? 1 : 0, $id ? 'Instagram post added and scored.' : 'Could not add the post.');
    }

    public static function save_candidate_action(): void {
        self::guard();
        $id = absint($_GET['candidate_id'] ?? 0);
        $candidate = get_post($id);
        if (!$candidate || $candidate->post_type !== self::CANDIDATE || !current_user_can('edit_post', $id)) wp_die('Invalid discovery candidate.');
        $platform = (string) get_post_meta($id, '_tng_candidate_platform', true);
        $inspiration_id = wp_insert_post(['post_type'=>self::ITEM,'post_status'=>'draft','post_title'=>$candidate->post_title,'post_content'=>$candidate->post_content]);
        if ($inspiration_id) {
            $copy = [
                '_tng_source_url'=>'_tng_candidate_source_url','_tng_creator_handle'=>'_tng_candidate_creator','_tng_location_name'=>'_tng_candidate_location',
                '_tng_hashtags'=>'_tng_candidate_hashtags','_tng_content_format'=>'_tng_candidate_format','_tng_source_opportunity_score'=>'_tng_candidate_trend_score',
                '_tng_source_relevance'=>'_tng_candidate_relevance','_tng_source_engagement'=>'_tng_candidate_engagement','_tng_source_freshness'=>'_tng_candidate_freshness',
                '_tng_source_likes'=>'_tng_candidate_likes','_tng_source_comments'=>'_tng_candidate_comments','_tng_source_views'=>'_tng_candidate_views',
            ];
            foreach ($copy as $to=>$from) update_post_meta($inspiration_id,$to,get_post_meta($id,$from,true));
            update_post_meta($inspiration_id,'_tng_plan_status','inspiration');
            update_post_meta($inspiration_id,'_tng_permission_status','not_needed');
            if ($platform) wp_set_object_terms($inspiration_id,[ucfirst($platform)],'tng_social_platform',true);
            wp_delete_post($id,true);
            wp_safe_redirect(get_edit_post_link($inspiration_id,'url')); exit;
        }
        self::redirect_notice(0,'Could not save this candidate to Inspiration.');
    }

    public static function dismiss_candidate_action(): void {
        self::guard();
        $id = absint($_GET['candidate_id'] ?? 0);
        if ($id && get_post_type($id) === self::CANDIDATE && current_user_can('delete_post',$id)) wp_trash_post($id);
        self::redirect_notice(0,'Candidate dismissed.');
    }

    private static function status_label(string $status): array {
        return match ($status) {
            'instagram_ready' => ['Ready to review','good'], 'ok' => ['Healthy','good'], 'zero_results' => ['Healthy · 0 matches','neutral'],
            'blocked' => ['Blocked','bad'], 'error','http_error','invalid_response' => ['Source error','bad'], 'manual' => ['Manual search','neutral'],
            default => ['Not prepared','neutral'],
        };
    }

    public static function inbox(): void {
        if (!current_user_can('edit_posts')) return;
        $candidates = get_posts(['post_type'=>self::CANDIDATE,'post_status'=>'publish','numberposts'=>100,'orderby'=>'date','order'=>'DESC']);
        usort($candidates, static fn($a,$b)=>((int)get_post_meta($b->ID,'_tng_candidate_trend_score',true))<=>((int)get_post_meta($a->ID,'_tng_candidate_trend_score',true)));
        $watches = get_posts(['post_type'=>self::WATCH,'post_status'=>['publish','draft','private'],'numberposts'=>100,'orderby'=>'modified','order'=>'DESC']);
        $ig_watches = array_values(array_filter($watches, static fn($w)=>in_array((string)get_post_meta($w->ID,'_tng_watch_platform',true),['instagram','all',''],true)));
        $notice = isset($_GET['tng_notice']) ? sanitize_text_field(wp_unslash($_GET['tng_notice'])) : '';
        $type_counts = ['hashtag'=>0,'account'=>0,'location'=>0,'topic'=>0];
        foreach ($ig_watches as $watch) { $t=(string)get_post_meta($watch->ID,'_tng_watch_type',true); if(isset($type_counts[$t]))$type_counts[$t]++; }

        echo '<div class="wrap tng-sd-wrap"><div class="tng-sd-hero"><div><p class="eyebrow">INSTAGRAM INTELLIGENCE</p><h1>Discover what Tennessee is talking about.</h1><p>Track hashtags, creators, places, and travel topics. Review public posts on Instagram, capture the strongest signals, and turn them into original TN Game inspiration.</p></div><a class="button button-primary" href="'.esc_url(self::action_url('tng_social_run_all')).'">Prepare Instagram watches</a></div>';
        if ($notice) echo '<div class="notice notice-success is-dismissible"><p>'.esc_html(rawurldecode($notice)).'</p></div>';

        echo '<section class="tng-sd-panel tng-ig-summary"><div class="tng-sd-head"><div><p class="eyebrow">INSTAGRAM WATCH CENTER</p><h2>Your listening network</h2></div><span class="description">'.number_format_i18n(count($ig_watches)).' active/reviewable watches</span></div><div class="tng-ig-stats">';
        foreach (['hashtag'=>'# Hashtags','account'=>'@ Creators','location'=>'📍 Places','topic'=>'✨ Topics'] as $key=>$label) echo '<div><b>'.number_format_i18n($type_counts[$key]).'</b><span>'.esc_html($label).'</span></div>';
        echo '</div></section>';

        echo '<div class="tng-sd-grid"><section class="tng-sd-panel"><div class="tng-sd-head"><div><p class="eyebrow">QUICK WATCH</p><h2>Add an Instagram signal</h2></div></div><form class="tng-sd-form" method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="tng_social_quick_watch">'; wp_nonce_field(self::ACTION_NONCE);
        echo '<div class="tng-form-row"><select name="watch_type"><option value="hashtag">Hashtag</option><option value="account">Creator / account</option><option value="location">Location</option><option value="topic">Topic / keyword</option></select><input name="watch_value" required placeholder="#fosterfalls, @creator, Tracy City…"></div><input name="watch_location" placeholder="Related Tennessee destination (optional)"><button class="button button-primary">Add Instagram watch</button></form></section>';
        echo '<section class="tng-sd-panel"><div class="tng-sd-head"><div><p class="eyebrow">QUICK CAPTURE</p><h2>Save a post you find</h2></div></div><form class="tng-sd-form" method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="tng_social_add_candidate">'; wp_nonce_field(self::ACTION_NONCE);
        echo '<input type="url" name="source_url" required placeholder="Paste Instagram post or Reel URL"><div class="tng-form-row"><input name="candidate_title" placeholder="What is this post about?"><input name="creator" placeholder="@creator"></div><div class="tng-form-row"><select name="format"><option value="">Format</option><option value="reel">Reel</option><option value="carousel">Carousel</option><option value="photo">Photo</option><option value="story">Story</option></select><select name="watch_id"><option value="0">Related watch (optional)</option>';
        foreach ($ig_watches as $watch) echo '<option value="'.esc_attr((string)$watch->ID).'">'.esc_html(get_the_title($watch)).'</option>';
        echo '</select></div><div class="tng-form-row"><input name="location" placeholder="Location / place"><input name="hashtags" placeholder="#hashtags seen"></div><div class="tng-form-row three"><input type="number" min="0" name="likes" placeholder="Likes"><input type="number" min="0" name="comments" placeholder="Comments"><input type="number" min="0" name="views" placeholder="Views"></div><input type="date" name="published_date"><textarea name="notes" rows="3" placeholder="What caught your attention? (optional)"></textarea><button class="button button-primary">Add + score post</button></form></section></div>';

        echo '<section class="tng-sd-panel"><div class="tng-sd-head"><div><p class="eyebrow">INSTAGRAM WATCHLIST</p><h2>Open and review</h2></div><a href="'.esc_url(admin_url('post-new.php?post_type='.self::WATCH)).'">Advanced watch settings</a></div>';
        if (!$ig_watches) echo '<div class="tng-sd-empty">Add your first Instagram hashtag, creator, place, or topic above.</div>';
        foreach ($ig_watches as $watch) {
            $type=(string)get_post_meta($watch->ID,'_tng_watch_type',true); $status=(string)get_post_meta($watch->ID,'_tng_watch_source_status',true); [$label,$tone]=self::status_label($status); $search=self::platform_search_url($watch->ID); $location=(string)get_post_meta($watch->ID,'_tng_watch_location',true);
            echo '<div class="tng-sd-watch"><div><strong>'.esc_html(get_the_title($watch)).'</strong><span>'.esc_html(ucfirst($type ?: 'topic')).($location?' · '.esc_html($location):'').' · <span class="tng-sd-status '.esc_attr($tone).'">'.esc_html($label).'</span></span></div><div class="tng-sd-actions"><a class="button button-small" href="'.esc_url(self::action_url('tng_social_run_watch',['watch_id'=>$watch->ID])).'">Prepare</a><a class="button button-primary button-small" href="'.esc_url($search).'" target="_blank" rel="noopener">Open Instagram ↗</a></div></div>';
        }
        echo '</section>';

        echo '<section class="tng-sd-panel tng-sd-inbox"><div class="tng-sd-head"><div><p class="eyebrow">RANKED REVIEW QUEUE</p><h2>'.number_format_i18n(count($candidates)).' candidates</h2></div><span class="description">45% relevance · 35% engagement · 20% freshness</span></div>';
        if (!$candidates) echo '<div class="tng-sd-empty">Open one of your Instagram watches, find a promising public post, and paste its URL into Quick Capture.</div>';
        foreach ($candidates as $candidate) {
            $source=(string)get_post_meta($candidate->ID,'_tng_candidate_source_url',true); $creator=(string)get_post_meta($candidate->ID,'_tng_candidate_creator',true); $platform=(string)get_post_meta($candidate->ID,'_tng_candidate_platform',true); $format=(string)get_post_meta($candidate->ID,'_tng_candidate_format',true); $location=(string)get_post_meta($candidate->ID,'_tng_candidate_location',true); $likes=(int)get_post_meta($candidate->ID,'_tng_candidate_likes',true); $comments=(int)get_post_meta($candidate->ID,'_tng_candidate_comments',true); $views=(int)get_post_meta($candidate->ID,'_tng_candidate_views',true); $opportunity=(int)get_post_meta($candidate->ID,'_tng_candidate_trend_score',true); $relevance=(int)get_post_meta($candidate->ID,'_tng_candidate_relevance',true); $engagement=(int)get_post_meta($candidate->ID,'_tng_candidate_engagement',true); $freshness=(int)get_post_meta($candidate->ID,'_tng_candidate_freshness',true); $tone=$opportunity>=75?'hot':($opportunity>=50?'warm':'cool');
            echo '<article class="tng-sd-candidate"><div><div class="tng-sd-badge">'.esc_html(strtoupper($platform ?: 'WEB')).'</div><div class="tng-sd-opportunity '.esc_attr($tone).'"><strong>'.number_format_i18n($opportunity).'</strong><span>OPPORTUNITY</span></div></div><div class="tng-sd-body"><p class="tng-insight-label">'.esc_html(self::insight_label($opportunity,$engagement,$format)).'</p><h3>'.esc_html($candidate->post_title).'</h3><p class="tng-sd-meta">'.esc_html($creator ?: 'Public post').($format?' · '.esc_html(ucfirst($format)):'').($location?' · '.esc_html($location):'').'</p><div class="tng-sd-signal-row"><span>Relevance <b>'.number_format_i18n($relevance).'</b></span><span>Engagement <b>'.number_format_i18n($engagement).'</b></span><span>Freshness <b>'.number_format_i18n($freshness).'</b></span>'.($likes?'<span>❤ '.number_format_i18n($likes).'</span>':'').($comments?'<span>💬 '.number_format_i18n($comments).'</span>':'').($views?'<span>▶ '.number_format_i18n($views).'</span>':'').'</div>';
            if ($candidate->post_content) echo '<p>'.esc_html(wp_trim_words($candidate->post_content,34)).'</p>';
            echo '<div class="tng-sd-actions"><a class="button" href="'.esc_url($source).'" target="_blank" rel="noopener">View source ↗</a><a class="button button-primary" href="'.esc_url(self::action_url('tng_social_save_candidate',['candidate_id'=>$candidate->ID])).'">Save to Inspiration</a><a class="button" href="'.esc_url(self::action_url('tng_social_dismiss_candidate',['candidate_id'=>$candidate->ID])).'">Dismiss</a></div></div></article>';
        }
        echo '</section><section class="tng-sd-panel tng-reddit-secondary"><p class="eyebrow">OTHER SOURCES</p><h2>Reddit + web remain optional</h2><p class="description">Reddit diagnostics are still available on Reddit-specific watch items, but Instagram is now the primary discovery workflow.</p></section></div>';
    }

    private static function reddit_query(string $value,string $type): string { $value=ltrim(trim($value),'#@'); return $type==='account'?'author:'.$value:$value; }
    private static function discover_reddit(int $watch_id,string $value,string $type): array {
        $started=microtime(true); $query=self::reddit_query($value,$type); $url=add_query_arg(['q'=>$query,'sort'=>'new','limit'=>25,'raw_json'=>1],'https://www.reddit.com/search.json'); $response=wp_remote_get($url,['timeout'=>12,'headers'=>['User-Agent'=>'TNGameSocialIntelligence/0.4 (+https://thetngame.com)','Accept'=>'application/json']]);
        if(is_wp_error($response)) return ['added'=>0,'fetched'=>0,'duplicates'=>0,'status'=>'error','http_code'=>0,'error'=>$response->get_error_message(),'elapsed_ms'=>(int)round((microtime(true)-$started)*1000),'query'=>$query,'message'=>'Reddit request failed.'];
        $http=wp_remote_retrieve_response_code($response); if($http!==200) return ['added'=>0,'fetched'=>0,'duplicates'=>0,'status'=>in_array($http,[403,429],true)?'blocked':'http_error','http_code'=>$http,'error'=>'Reddit returned HTTP '.$http.'.','elapsed_ms'=>(int)round((microtime(true)-$started)*1000),'query'=>$query,'message'=>'Reddit is unavailable from this server. Instagram remains the primary discovery source.'];
        return ['added'=>0,'fetched'=>0,'duplicates'=>0,'status'=>'zero_results','http_code'=>200,'error'=>'','elapsed_ms'=>(int)round((microtime(true)-$started)*1000),'query'=>$query,'message'=>'Reddit responded. Instagram remains the primary discovery source.'];
    }

    public static function admin_assets(string $hook): void {
        if (strpos($hook,'tng-social-discovery')===false) return;
        wp_register_style('tng-social-discovery-admin',false,[],'0.4.0'); wp_enqueue_style('tng-social-discovery-admin');
        wp_add_inline_style('tng-social-discovery-admin','.tng-sd-wrap{max-width:1280px}.tng-sd-hero{margin:20px 0;background:linear-gradient(135deg,#0b422b,#17633d);color:#fff;padding:30px 34px;border-radius:22px;display:flex;justify-content:space-between;align-items:center;gap:20px}.tng-sd-hero h1{color:#fff;font-size:38px;line-height:1.08;margin:4px 0 8px;max-width:760px}.tng-sd-hero p{max-width:780px}.tng-sd-wrap .eyebrow{font-size:11px;font-weight:800;letter-spacing:.12em;color:#f26322;margin:0 0 6px}.tng-sd-hero .eyebrow{color:#ff9b63}.tng-sd-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin:18px 0}.tng-sd-panel{background:#fff;border:1px solid #dfe7e1;border-radius:18px;padding:22px;margin:18px 0}.tng-sd-grid .tng-sd-panel{margin:0}.tng-sd-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;margin-bottom:14px}.tng-sd-head h2{margin:0;font-size:23px;color:#132c21}.tng-sd-form{display:grid;gap:10px}.tng-sd-form input,.tng-sd-form select,.tng-sd-form textarea{width:100%}.tng-form-row{display:grid;grid-template-columns:1fr 1fr;gap:10px}.tng-form-row.three{grid-template-columns:repeat(3,1fr)}.tng-ig-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}.tng-ig-stats>div{background:#f4f8f5;border-radius:14px;padding:18px}.tng-ig-stats b,.tng-ig-stats span{display:block}.tng-ig-stats b{font-size:28px;color:#173b2b}.tng-ig-stats span{font-size:12px;color:#68786f}.tng-sd-watch{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:14px 2px;border-top:1px solid #edf1ee}.tng-sd-watch strong,.tng-sd-watch>div>span{display:block}.tng-sd-watch>div>span,.tng-sd-meta{font-size:12px;color:#728078;margin-top:3px}.tng-sd-status{display:inline-block!important;font-size:9px!important;font-weight:800;border-radius:999px;padding:3px 7px;background:#f2f4f2}.tng-sd-status.good{background:#e5f5e9;color:#24623b}.tng-sd-status.bad{background:#ffebe6;color:#a53a1a}.tng-sd-actions{display:flex;gap:8px;flex-wrap:wrap}.tng-sd-candidate{display:grid;grid-template-columns:100px 1fr;gap:18px;padding:18px 2px;border-top:1px solid #edf1ee}.tng-sd-badge{background:#edf6ef;color:#27633e;border-radius:12px;height:42px;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;letter-spacing:.08em}.tng-sd-opportunity{margin-top:8px;border-radius:12px;padding:10px;text-align:center;background:#f3f5f3}.tng-sd-opportunity strong,.tng-sd-opportunity span{display:block}.tng-sd-opportunity strong{font-size:24px;color:#173b2b}.tng-sd-opportunity span{font-size:8px;font-weight:800;letter-spacing:.08em;color:#708078}.tng-sd-opportunity.hot{background:#fff0e8}.tng-sd-opportunity.hot strong{color:#d94e0f}.tng-sd-opportunity.warm{background:#fff8df}.tng-sd-body h3{font-size:19px;margin:2px 0 4px}.tng-insight-label{font-size:11px!important;font-weight:800;color:#f26322;margin:0}.tng-sd-signal-row{display:flex;gap:8px;flex-wrap:wrap;margin:9px 0}.tng-sd-signal-row span{background:#f4f7f5;border-radius:999px;padding:5px 9px;font-size:11px;color:#5f7067}.tng-sd-empty{padding:24px;border:1px dashed #cad8cf;border-radius:14px;color:#64756b;background:#f8faf8}.tng-reddit-secondary{opacity:.78}@media(max-width:900px){.tng-sd-hero{align-items:flex-start;flex-direction:column}.tng-sd-grid,.tng-ig-stats{grid-template-columns:1fr}.tng-form-row,.tng-form-row.three{grid-template-columns:1fr}.tng-sd-candidate{grid-template-columns:1fr}.tng-sd-badge,.tng-sd-opportunity{width:90px}.tng-sd-watch{align-items:flex-start;flex-direction:column}}');
    }
}

TNG_Social_Discovery::boot();
