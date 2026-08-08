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
        add_submenu_page('tng-content-studio', 'Discovery Inbox', 'Discovery Inbox', 'edit_posts', 'tng-social-discovery', [__CLASS__, 'inbox']);
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
            $result = self::discover_reddit($watch_id, $value, $type);
            update_post_meta($watch_id, '_tng_watch_last_results', (int) $result['added']);
            update_post_meta($watch_id, '_tng_watch_last_fetched', (int) $result['fetched']);
            update_post_meta($watch_id, '_tng_watch_last_duplicates', (int) $result['duplicates']);
            update_post_meta($watch_id, '_tng_watch_source_status', (string) $result['status']);
            update_post_meta($watch_id, '_tng_watch_source_http', (int) $result['http_code']);
            update_post_meta($watch_id, '_tng_watch_source_error', (string) $result['error']);
            update_post_meta($watch_id, '_tng_watch_source_ms', (int) $result['elapsed_ms']);
            update_post_meta($watch_id, '_tng_watch_last_query', (string) $result['query']);
            return ['added' => $result['added'], 'message' => $result['message']];
        }

        update_post_meta($watch_id, '_tng_watch_last_results', 0);
        update_post_meta($watch_id, '_tng_watch_source_status', 'manual');
        return ['added' => 0, 'message' => 'This platform currently uses review-first platform search. Open the Watchlist search link and add promising post URLs to the Inbox.'];
    }

    private static function reddit_query(string $value, string $type): string {
        $value = ltrim(trim($value), '#@');
        if ($type === 'account') return 'author:' . $value;
        return $value;
    }

    private static function discover_reddit(int $watch_id, string $value, string $type): array {
        $started = microtime(true);
        $query = self::reddit_query($value, $type);
        $attempts = [
            ['sort' => 'new', 'limit' => 50],
            ['sort' => 'relevance', 'limit' => 50],
        ];
        $records = [];
        $http_code = 0;
        $error = '';
        $status = 'ok';

        foreach ($attempts as $attempt) {
            $url = add_query_arg([
                'q' => $query, 'sort' => $attempt['sort'], 'limit' => $attempt['limit'],
                'restrict_sr' => 0, 'include_over_18' => 'off', 'raw_json' => 1,
            ], 'https://www.reddit.com/search.json');
            $response = wp_remote_get($url, [
                'timeout' => 15,
                'redirection' => 3,
                'headers' => [
                    'User-Agent' => 'TNGameSocialIntelligence/0.3 (+https://thetngame.com)',
                    'Accept' => 'application/json',
                ],
            ]);
            if (is_wp_error($response)) {
                $status = 'error';
                $error = $response->get_error_message();
                continue;
            }
            $http_code = wp_remote_retrieve_response_code($response);
            if ($http_code !== 200) {
                $status = $http_code === 403 || $http_code === 429 ? 'blocked' : 'http_error';
                $error = 'Reddit returned HTTP ' . $http_code . '.';
                continue;
            }
            $body = json_decode((string) wp_remote_retrieve_body($response), true);
            if (!is_array($body)) {
                $status = 'invalid_response';
                $error = 'Reddit returned an unreadable response.';
                continue;
            }
            $children = $body['data']['children'] ?? [];
            if (!is_array($children)) continue;
            foreach ($children as $child) {
                $d = $child['data'] ?? [];
                if (!is_array($d) || empty($d['permalink'])) continue;
                $key = (string) ($d['name'] ?? $d['permalink']);
                $records[$key] = $d;
            }
            if ($records) break;
        }

        $added = 0; $duplicates = 0;
        foreach ($records as $d) {
            $source = 'https://www.reddit.com' . $d['permalink'];
            $title = sanitize_text_field((string) ($d['title'] ?? 'Reddit discovery'));
            $creator = sanitize_text_field((string) ($d['author'] ?? ''));
            $excerpt = sanitize_textarea_field(wp_strip_all_tags((string) ($d['selftext'] ?? '')));
            $reddit_score = absint($d['score'] ?? 0);
            $comments = absint($d['num_comments'] ?? 0);
            $published_ts = (int) ($d['created_utc'] ?? 0);
            $published = $published_ts ? gmdate('Y-m-d H:i:s', $published_ts) : '';
            $subreddit = sanitize_text_field((string) ($d['subreddit_name_prefixed'] ?? $d['subreddit'] ?? ''));
            if (self::candidate_exists($source)) { $duplicates++; continue; }

            $signals = self::score_candidate($value, $type, $title, $excerpt, $creator, $subreddit, $reddit_score, $comments, $published_ts);
            $id = wp_insert_post([
                'post_type' => self::CANDIDATE, 'post_status' => 'publish',
                'post_title' => $title ?: 'Reddit discovery', 'post_content' => mb_substr($excerpt, 0, 3000),
            ], true);
            if (is_wp_error($id) || !$id) continue;
            update_post_meta($id, '_tng_candidate_source_url', esc_url_raw($source));
            update_post_meta($id, '_tng_candidate_creator', $creator ? 'u/' . $creator : '');
            update_post_meta($id, '_tng_candidate_platform', 'reddit');
            update_post_meta($id, '_tng_candidate_watch_id', $watch_id);
            update_post_meta($id, '_tng_candidate_score', $reddit_score);
            update_post_meta($id, '_tng_candidate_comments', $comments);
            update_post_meta($id, '_tng_candidate_published', $published);
            update_post_meta($id, '_tng_candidate_subreddit', $subreddit);
            update_post_meta($id, '_tng_candidate_relevance', $signals['relevance']);
            update_post_meta($id, '_tng_candidate_engagement', $signals['engagement']);
            update_post_meta($id, '_tng_candidate_freshness', $signals['freshness']);
            update_post_meta($id, '_tng_candidate_trend_score', $signals['opportunity']);
            update_post_meta($id, '_tng_candidate_status', 'new');
            $added++;
        }

        $fetched = count($records);
        $elapsed = (int) round((microtime(true) - $started) * 1000);
        if ($status === 'ok' && !$fetched) $status = 'zero_results';
        if ($added) {
            $message = sprintf('%d Reddit candidate%s added from %d fetched result%s.', $added, $added === 1 ? '' : 's', $fetched, $fetched === 1 ? '' : 's');
        } elseif ($status === 'blocked') {
            $message = 'Reddit discovery is being blocked from this server (HTTP ' . $http_code . '). Use Search ↗ while we connect another approved discovery source.';
        } elseif ($status === 'error' || $status === 'http_error' || $status === 'invalid_response') {
            $message = 'Reddit discovery could not complete: ' . ($error ?: 'unknown source error');
        } elseif ($duplicates && $fetched) {
            $message = sprintf('Reddit returned %d result%s, but all useful URLs were already in your Inbox or Inspiration library.', $fetched, $fetched === 1 ? '' : 's');
        } else {
            $message = 'Reddit responded successfully but returned no matches for this watch. Try a broader watch phrase or use Search ↗.';
        }

        return [
            'added' => $added, 'fetched' => $fetched, 'duplicates' => $duplicates,
            'status' => $status, 'http_code' => $http_code, 'error' => $error,
            'elapsed_ms' => $elapsed, 'query' => $query, 'message' => $message,
        ];
    }

    private static function score_candidate(string $watch_value, string $type, string $title, string $excerpt, string $creator, string $subreddit, int $score, int $comments, int $published_ts): array {
        $needle = strtolower(ltrim(trim($watch_value), '#@'));
        $haystack = strtolower(trim($title . ' ' . $excerpt . ' ' . $creator . ' ' . $subreddit));
        $tokens = array_values(array_filter(preg_split('/[^a-z0-9]+/i', $needle) ?: [], static fn($t) => strlen($t) >= 3));
        $matched = 0;
        foreach ($tokens as $token) if (str_contains($haystack, strtolower($token))) $matched++;
        $relevance = $tokens ? (int) round(($matched / count($tokens)) * 78) : 35;
        if ($needle && str_contains(strtolower($title), $needle)) $relevance += 18;
        if ($type === 'account' && $needle && str_contains(strtolower($creator), $needle)) $relevance = 100;
        $relevance = min(100, max(0, $relevance));

        $engagement_raw = max(0, $score) + ($comments * 2.5);
        $engagement = $engagement_raw > 0 ? (int) round(min(100, log10($engagement_raw + 1) * 31)) : 0;

        $age_hours = $published_ts ? max(0, (time() - $published_ts) / HOUR_IN_SECONDS) : 9999;
        if ($age_hours <= 24) $freshness = 100;
        elseif ($age_hours <= 72) $freshness = 88;
        elseif ($age_hours <= 168) $freshness = 72;
        elseif ($age_hours <= 336) $freshness = 55;
        elseif ($age_hours <= 720) $freshness = 38;
        else $freshness = 18;

        $opportunity = (int) round(($relevance * .50) + ($engagement * .30) + ($freshness * .20));
        return ['relevance' => $relevance, 'engagement' => $engagement, 'freshness' => $freshness, 'opportunity' => min(100, max(0, $opportunity))];
    }

    private static function candidate_exists(string $url): bool {
        $found = get_posts([
            'post_type' => [self::CANDIDATE, self::ITEM], 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids',
            'meta_query' => ['relation' => 'OR', ['key' => '_tng_candidate_source_url', 'value' => $url], ['key' => '_tng_source_url', 'value' => $url]],
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
            update_post_meta($id, '_tng_candidate_trend_score', 0);
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
            update_post_meta($inspiration_id, '_tng_source_opportunity_score', (int) get_post_meta($id, '_tng_candidate_trend_score', true));
            update_post_meta($inspiration_id, '_tng_source_relevance', (int) get_post_meta($id, '_tng_candidate_relevance', true));
            update_post_meta($inspiration_id, '_tng_source_engagement', (int) get_post_meta($id, '_tng_candidate_engagement', true));
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

    private static function status_label(string $status): array {
        return match ($status) {
            'ok' => ['Healthy', 'good'],
            'zero_results' => ['Healthy · 0 matches', 'neutral'],
            'blocked' => ['Blocked', 'bad'],
            'error', 'http_error', 'invalid_response' => ['Source error', 'bad'],
            'manual' => ['Manual search', 'neutral'],
            default => ['Not tested', 'neutral'],
        };
    }

    public static function inbox(): void {
        if (!current_user_can('edit_posts')) return;
        $candidates = get_posts(['post_type' => self::CANDIDATE, 'post_status' => 'publish', 'numberposts' => 100, 'orderby' => 'date', 'order' => 'DESC']);
        usort($candidates, static fn($a, $b) => ((int) get_post_meta($b->ID, '_tng_candidate_trend_score', true)) <=> ((int) get_post_meta($a->ID, '_tng_candidate_trend_score', true)));
        $watches = get_posts(['post_type' => self::WATCH, 'post_status' => ['publish','draft','private'], 'numberposts' => 50, 'orderby' => 'modified', 'order' => 'DESC']);
        $notice = isset($_GET['tng_notice']) ? sanitize_text_field(wp_unslash($_GET['tng_notice'])) : '';

        echo '<div class="wrap tng-sd-wrap"><div class="tng-sd-hero"><div><p class="eyebrow">SOCIAL INTELLIGENCE</p><h1>Discovery Inbox</h1><p>Review public social signals before they become permanent inspiration. The strongest opportunities rise to the top automatically.</p></div><a class="button button-primary" href="' . esc_url(self::action_url('tng_social_run_all')) . '">Run all active watches</a></div>';
        if ($notice) echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(rawurldecode($notice)) . '</p></div>';

        echo '<section class="tng-sd-panel tng-sd-health"><div class="tng-sd-head"><div><p class="eyebrow">SOURCE HEALTH</p><h2>Discovery diagnostics</h2></div><span class="description">Last run for each watch</span></div><div class="tng-sd-health-grid">';
        if (!$watches) echo '<div class="tng-sd-empty">No watch diagnostics yet.</div>';
        foreach ($watches as $watch) {
            $status = (string) get_post_meta($watch->ID, '_tng_watch_source_status', true);
            [$label, $tone] = self::status_label($status);
            $http = (int) get_post_meta($watch->ID, '_tng_watch_source_http', true);
            $fetched = (int) get_post_meta($watch->ID, '_tng_watch_last_fetched', true);
            $new = (int) get_post_meta($watch->ID, '_tng_watch_last_results', true);
            $dupes = (int) get_post_meta($watch->ID, '_tng_watch_last_duplicates', true);
            $ms = (int) get_post_meta($watch->ID, '_tng_watch_source_ms', true);
            $err = (string) get_post_meta($watch->ID, '_tng_watch_source_error', true);
            echo '<div class="tng-sd-health-card"><div class="tng-sd-health-title"><strong>' . esc_html(get_the_title($watch)) . '</strong><span class="tng-sd-status ' . esc_attr($tone) . '">' . esc_html($label) . '</span></div><div class="tng-sd-health-metrics"><span><b>' . number_format_i18n($fetched) . '</b> fetched</span><span><b>' . number_format_i18n($new) . '</b> new</span><span><b>' . number_format_i18n($dupes) . '</b> duplicates</span></div><p class="tng-sd-meta">' . ($http ? 'HTTP ' . esc_html((string) $http) . ' · ' : '') . ($ms ? esc_html((string) $ms) . ' ms' : 'Not run yet') . ($err ? ' · ' . esc_html($err) : '') . '</p></div>';
        }
        echo '</div></section>';

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

        echo '<section class="tng-sd-panel tng-sd-inbox"><div class="tng-sd-head"><div><p class="eyebrow">RANKED REVIEW QUEUE</p><h2>' . number_format_i18n(count($candidates)) . ' candidates</h2></div><span class="description">Opportunity score = relevance + engagement + freshness</span></div>';
        if (!$candidates) echo '<div class="tng-sd-empty">Your Inbox is clear. Run an active watch or paste a public post URL above.</div>';
        foreach ($candidates as $candidate) {
            $source = (string) get_post_meta($candidate->ID, '_tng_candidate_source_url', true);
            $creator = (string) get_post_meta($candidate->ID, '_tng_candidate_creator', true);
            $platform = (string) get_post_meta($candidate->ID, '_tng_candidate_platform', true);
            $score = (int) get_post_meta($candidate->ID, '_tng_candidate_score', true);
            $comments = (int) get_post_meta($candidate->ID, '_tng_candidate_comments', true);
            $watch_id = (int) get_post_meta($candidate->ID, '_tng_candidate_watch_id', true);
            $watch_name = $watch_id ? get_the_title($watch_id) : '';
            $opportunity = (int) get_post_meta($candidate->ID, '_tng_candidate_trend_score', true);
            $relevance = (int) get_post_meta($candidate->ID, '_tng_candidate_relevance', true);
            $engagement = (int) get_post_meta($candidate->ID, '_tng_candidate_engagement', true);
            $freshness = (int) get_post_meta($candidate->ID, '_tng_candidate_freshness', true);
            $subreddit = (string) get_post_meta($candidate->ID, '_tng_candidate_subreddit', true);
            $tone = $opportunity >= 75 ? 'hot' : ($opportunity >= 50 ? 'warm' : 'cool');
            echo '<article class="tng-sd-candidate"><div><div class="tng-sd-badge">' . esc_html(strtoupper($platform ?: 'WEB')) . '</div><div class="tng-sd-opportunity ' . esc_attr($tone) . '"><strong>' . number_format_i18n($opportunity) . '</strong><span>OPPORTUNITY</span></div></div><div class="tng-sd-body"><h3>' . esc_html($candidate->post_title) . '</h3><p class="tng-sd-meta">' . esc_html($creator ?: 'Public post') . ($subreddit ? ' · ' . esc_html($subreddit) : '') . ($watch_name ? ' · from ' . esc_html($watch_name) : '') . (($score || $comments) ? ' · ' . number_format_i18n($score) . ' score · ' . number_format_i18n($comments) . ' comments' : '') . '</p>';
            echo '<div class="tng-sd-signal-row"><span>Relevance <b>' . number_format_i18n($relevance) . '</b></span><span>Engagement <b>' . number_format_i18n($engagement) . '</b></span><span>Freshness <b>' . number_format_i18n($freshness) . '</b></span></div>';
            if ($candidate->post_content) echo '<p>' . esc_html(wp_trim_words($candidate->post_content, 34)) . '</p>';
            echo '<div class="tng-sd-actions"><a class="button" href="' . esc_url($source) . '" target="_blank" rel="noopener">View source ↗</a><a class="button button-primary" href="' . esc_url(self::action_url('tng_social_save_candidate', ['candidate_id' => $candidate->ID])) . '">Save to Inspiration</a><a class="button" href="' . esc_url(self::action_url('tng_social_dismiss_candidate', ['candidate_id' => $candidate->ID])) . '">Dismiss</a></div></div></article>';
        }
        echo '</section></div>';
    }

    public static function admin_assets(string $hook): void {
        if (strpos($hook, 'tng-social-discovery') === false) return;
        wp_register_style('tng-social-discovery-admin', false, [], '0.3.0');
        wp_enqueue_style('tng-social-discovery-admin');
        wp_add_inline_style('tng-social-discovery-admin', '.tng-sd-wrap{max-width:1280px}.tng-sd-hero{margin:20px 0;background:linear-gradient(135deg,#0b422b,#17633d);color:#fff;padding:30px 34px;border-radius:22px;display:flex;justify-content:space-between;align-items:center;gap:20px}.tng-sd-hero h1{color:#fff;font-size:38px;margin:4px 0 8px}.tng-sd-hero p{max-width:760px}.tng-sd-wrap .eyebrow{font-size:11px;font-weight:800;letter-spacing:.12em;color:#f26322;margin:0 0 6px}.tng-sd-hero .eyebrow{color:#ff9b63}.tng-sd-grid{display:grid;grid-template-columns:1.25fr .75fr;gap:18px;margin:18px 0}.tng-sd-panel{background:#fff;border:1px solid #dfe7e1;border-radius:18px;padding:22px}.tng-sd-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;margin-bottom:14px}.tng-sd-head h2{margin:0;font-size:23px;color:#132c21}.tng-sd-watch{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:13px 2px;border-top:1px solid #edf1ee}.tng-sd-watch strong,.tng-sd-watch span{display:block}.tng-sd-watch span,.tng-sd-meta{font-size:12px;color:#728078;margin-top:3px}.tng-sd-actions{display:flex;gap:8px;flex-wrap:wrap}.tng-sd-form{display:grid;gap:10px}.tng-sd-form input{width:100%}.tng-sd-inbox{margin-bottom:30px}.tng-sd-candidate{position:relative;display:grid;grid-template-columns:100px 1fr;gap:18px;padding:18px 2px;border-top:1px solid #edf1ee}.tng-sd-badge{background:#edf6ef;color:#27633e;border-radius:12px;height:42px;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;letter-spacing:.08em}.tng-sd-opportunity{margin-top:8px;border-radius:12px;padding:10px;text-align:center;background:#f3f5f3}.tng-sd-opportunity strong,.tng-sd-opportunity span{display:block}.tng-sd-opportunity strong{font-size:24px;color:#173b2b}.tng-sd-opportunity span{font-size:8px;font-weight:800;letter-spacing:.08em;color:#708078}.tng-sd-opportunity.hot{background:#fff0e8}.tng-sd-opportunity.hot strong{color:#d94e0f}.tng-sd-opportunity.warm{background:#fff8df}.tng-sd-body h3{font-size:19px;margin:0 0 4px}.tng-sd-body>p{max-width:880px}.tng-sd-signal-row{display:flex;gap:8px;flex-wrap:wrap;margin:9px 0}.tng-sd-signal-row span{background:#f4f7f5;border-radius:999px;padding:5px 9px;font-size:11px;color:#5f7067}.tng-sd-health{margin:18px 0}.tng-sd-health-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.tng-sd-health-card{border:1px solid #e4ebe6;border-radius:14px;padding:14px}.tng-sd-health-title{display:flex;justify-content:space-between;gap:10px;align-items:center}.tng-sd-status{font-size:10px;font-weight:800;border-radius:999px;padding:4px 7px;background:#f2f4f2}.tng-sd-status.good{background:#e5f5e9;color:#24623b}.tng-sd-status.bad{background:#ffebe6;color:#a53a1a}.tng-sd-health-metrics{display:flex;gap:14px;margin-top:12px;color:#67766e;font-size:11px}.tng-sd-health-metrics b{display:block;font-size:18px;color:#193b2c}.tng-sd-empty{padding:24px;border:1px dashed #cad8cf;border-radius:14px;color:#64756b;background:#f8faf8}@media(max-width:900px){.tng-sd-hero{align-items:flex-start;flex-direction:column}.tng-sd-grid,.tng-sd-health-grid{grid-template-columns:1fr}.tng-sd-candidate{grid-template-columns:1fr}.tng-sd-badge,.tng-sd-opportunity{width:90px}}');
    }
}

TNG_Social_Discovery::boot();
