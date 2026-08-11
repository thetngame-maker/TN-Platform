<?php
namespace TNG_OS\Modules\Sources;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;
use WP_Error;

if (!defined('ABSPATH')) exit;

final class Google_Maps_Discovery implements Module_Interface {
    private const OPTION_TOKEN = 'tng_os_apify_token';
    private const CPT = 'tng_discovery_item';
    private const ACTOR = 'compass~crawler-google-places';
    private const MAX_RESULTS = 100;

    private Container $container;

    public function id(): string { return 'google_maps_discovery'; }

    public function register(Container $container): void {
        $this->container = $container;
        add_action('init', [$this, 'register_candidate_type']);
        add_action('admin_menu', [$this, 'menu'], 22);
        add_action('admin_post_tng_discovery_save_settings', [$this, 'save_settings']);
        add_action('admin_post_tng_discovery_run', [$this, 'run']);
        add_action('admin_post_tng_discovery_action', [$this, 'candidate_action']);
    }

    public function boot(Container $container): void {}

    public function register_candidate_type(): void {
        register_post_type(self::CPT, [
            'labels' => ['name' => 'Discovery Candidates', 'singular_name' => 'Discovery Candidate'],
            'public' => false,
            'show_ui' => false,
            'show_in_menu' => false,
            'supports' => ['title'],
            'capability_type' => 'post',
            'map_meta_cap' => true,
        ]);
    }

    public function menu(): void {
        add_submenu_page(
            'tn-game-os',
            'Discovery Engine',
            'Discovery Engine',
            'manage_options',
            'tng-os-discovery-engine',
            [$this, 'page']
        );
    }

    public function save_settings(): void {
        if (!current_user_can('manage_options')) wp_die('Permission denied.');
        check_admin_referer('tng_discovery_save_settings');
        $token = trim((string) wp_unslash($_POST['apify_token'] ?? ''));
        if ($token !== '') update_option(self::OPTION, sanitize_text_field($token), false);
        if (!empty($_POST['clear_token'])) delete_option(self::OPTION);
        wp_safe_redirect(admin_url('admin.php?page=tng-os-discovery-engine&settings=1'));
        exit;
    }

    public function run(): void {
        if (!current_user_can('manage_options')) wp_die('Permission denied.');
        check_admin_referer('tng_discovery_run');

        $query = sanitize_text_field(wp_unslash($_POST['query'] ?? ''));
        $location = sanitize_text_field(wp_unslash($_POST['location'] ?? ''));
        $limit = max(1, min(self::MAX_RESULTS, absint($_POST['limit'] ?? 20)));
        $token = (string) get_option(self::OPTION, '');

        if ($token === '' || $query === '' || $location === '') {
            $this->redirect_with_notice('error', 'Add an Apify token, search term, and location first.');
        }

        $items = $this->fetch_apify($token, $query, $location, $limit);
        if (is_wp_error($items)) $this->redirect_with_notice('error', $items->get_error_message());

        $counts = ['new' => 0, 'existing' => 0, 'possible' => 0, 'ignored' => 0, 'total' => 0];
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $normalized = $this->normalize($item, $query, $location);
            if ($normalized['name'] === '') continue;
            $candidate_id = $this->upsert_candidate($normalized);
            if (!$candidate_id) continue;
            $status = (string) get_post_meta($candidate_id, '_tng_discovery_match_status', true);
            if (isset($counts[$status])) $counts[$status]++;
            $counts['total']++;
        }

        set_transient('tng_discovery_last_run_' . get_current_user_id(), [
            'query' => $query,
            'location' => $location,
            'limit' => $limit,
            'counts' => $counts,
            'time' => current_time('mysql'),
        ], HOUR_IN_SECONDS);

        $this->redirect_with_notice('success', sprintf(
            'Discovery finished: %d results, %d new, %d existing, %d possible matches.',
            $counts['total'], $counts['new'], $counts['existing'], $counts['possible']
        ));
    }

    private function fetch_apify(string $token, string $query, string $location, int $limit) {
        $url = 'https://api.apify.com/v2/acts/' . self::ACTOR . '/run-sync-get-dataset-items?token=' . rawurlencode($token) . '&clean=true';
        $payload = [
            'searchStringsArray' => [$query],
            'locationQuery' => $location,
            'maxCrawledPlacesPerSearch' => $limit,
            'language' => 'en',
            'maximumLeadsEnrichmentRecords' => 0,
            'maxImages' => 0,
            'maxReviews' => 0,
            'scrapeSocialMediaProfiles' => [
                'facebooks' => false,
                'instagrams' => false,
                'youtubes' => false,
                'tiktoks' => false,
                'twitters' => false,
            ],
            'maxCompetitorsToAnalyze' => 0,
        ];

        $response = wp_remote_post($url, [
            'timeout' => 180,
            'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            'body' => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) return $response;
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300 || !is_array($body)) {
            $message = is_array($body) ? (string)($body['error']['message'] ?? $body['message'] ?? '') : '';
            return new WP_Error('apify_error', $message ?: ('Apify returned HTTP ' . $code . '.'));
        }
        return array_slice($body, 0, $limit);
    }

    private function normalize(array $item, string $query, string $location): array {
        $loc = is_array($item['location'] ?? null) ? $item['location'] : [];
        $place_id = (string)($item['placeId'] ?? '');
        if ($place_id === '' && !empty($item['url']) && preg_match('/query_place_id=([^&]+)/', (string)$item['url'], $m)) {
            $place_id = rawurldecode($m[1]);
        }
        $address_parts = array_filter([
            $item['street'] ?? '', $item['city'] ?? '', $item['state'] ?? '', $item['postalCode'] ?? ''
        ]);
        $address = (string)($item['address'] ?? $item['formattedAddress'] ?? implode(', ', $address_parts));
        return [
            'provider' => 'apify_google_maps',
            'actor' => 'compass/crawler-google-places',
            'name' => sanitize_text_field((string)($item['title'] ?? $item['name'] ?? '')),
            'place_id' => sanitize_text_field($place_id),
            'maps_url' => esc_url_raw((string)($item['url'] ?? '')),
            'website' => esc_url_raw((string)($item['website'] ?? '')),
            'phone' => sanitize_text_field((string)($item['phone'] ?? '')),
            'address' => sanitize_text_field($address),
            'city' => sanitize_text_field((string)($item['city'] ?? '')),
            'state' => sanitize_text_field((string)($item['state'] ?? '')),
            'category' => sanitize_text_field((string)($item['categoryName'] ?? $item['category'] ?? '')),
            'rating' => isset($item['totalScore']) ? (float)$item['totalScore'] : null,
            'rating_count' => isset($item['reviewsCount']) ? absint($item['reviewsCount']) : 0,
            'latitude' => isset($loc['lat']) ? (float)$loc['lat'] : (isset($item['lat']) ? (float)$item['lat'] : null),
            'longitude' => isset($loc['lng']) ? (float)$loc['lng'] : (isset($item['lng']) ? (float)$item['lng'] : null),
            'temporarily_closed' => !empty($item['temporarilyClosed']),
            'permanently_closed' => !empty($item['permanentlyClosed']),
            'search_query' => $query,
            'search_location' => $location,
            'discovered_at' => current_time('mysql'),
        ];
    }

    private function upsert_candidate(array $data): int {
        $existing_candidate = 0;
        if ($data['place_id'] !== '') {
            $ids = get_posts([
                'post_type' => self::CPT,
                'post_status' => 'any',
                'posts_per_page' => 1,
                'fields' => 'ids',
                'meta_key' => '_tng_discovery_place_id',
                'meta_value' => $data['place_id'],
            ]);
            $existing_candidate = $ids ? (int)$ids[0] : 0;
        }

        $match = $this->find_existing_listing($data);
        $match_status = $match['status'];
        $matched_post = (int)$match['post_id'];

        if ($existing_candidate) {
            $candidate_id = $existing_candidate;
            wp_update_post(['ID' => $candidate_id, 'post_title' => $data['name']]);
            $prior_review = (string)get_post_meta($candidate_id, '_tng_discovery_review_status', true);
            if ($prior_review === 'ignored') $match_status = 'ignored';
        } else {
            $candidate_id = wp_insert_post([
                'post_type' => self::CPT,
                'post_status' => 'publish',
                'post_title' => $data['name'],
            ], true);
            if (is_wp_error($candidate_id)) return 0;
            $candidate_id = (int)$candidate_id;
        }

        update_post_meta($candidate_id, '_tng_discovery_data', $data);
        update_post_meta($candidate_id, '_tng_discovery_place_id', $data['place_id']);
        update_post_meta($candidate_id, '_tng_discovery_match_status', $match_status);
        update_post_meta($candidate_id, '_tng_discovery_matched_post', $matched_post);
        update_post_meta($candidate_id, '_tng_discovery_last_seen', current_time('mysql'));
        if (!get_post_meta($candidate_id, '_tng_discovery_review_status', true)) {
            update_post_meta($candidate_id, '_tng_discovery_review_status', 'pending');
        }
        return $candidate_id;
    }

    private function find_existing_listing(array $data): array {
        if ($data['place_id'] !== '') {
            foreach (['_tng_food_google_place_id'] as $key) {
                $ids = get_posts([
                    'post_type' => 'st_activity',
                    'post_status' => ['publish','draft','pending','private'],
                    'posts_per_page' => 1,
                    'fields' => 'ids',
                    'meta_key' => $key,
                    'meta_value' => $data['place_id'],
                ]);
                if ($ids) return ['status' => 'existing', 'post_id' => (int)$ids[0]];
            }
        }

        $title_match = get_page_by_title($data['name'], OBJECT, 'st_activity');
        if ($title_match instanceof \WP_Post) return ['status' => 'possible', 'post_id' => (int)$title_match->ID];

        return ['status' => 'new', 'post_id' => 0];
    }

    public function candidate_action(): void {
        if (!current_user_can('manage_options')) wp_die('Permission denied.');
        check_admin_referer('tng_discovery_action');
        $candidate_id = absint($_GET['candidate'] ?? 0);
        $action = sanitize_key($_GET['do'] ?? '');
        if (!$candidate_id || get_post_type($candidate_id) !== self::CPT) $this->redirect_with_notice('error', 'Invalid candidate.');

        if ($action === 'ignore') {
            update_post_meta($candidate_id, '_tng_discovery_review_status', 'ignored');
            update_post_meta($candidate_id, '_tng_discovery_match_status', 'ignored');
            $this->redirect_with_notice('success', 'Candidate ignored.');
        }

        if ($action === 'add') {
            $data = (array)get_post_meta($candidate_id, '_tng_discovery_data', true);
            $matched = absint(get_post_meta($candidate_id, '_tng_discovery_matched_post', true));
            if ($matched) $this->redirect_with_notice('error', 'This candidate already has a possible or confirmed TN Game match. Review that listing instead.');

            $post_id = wp_insert_post([
                'post_type' => 'st_activity',
                'post_status' => 'draft',
                'post_title' => sanitize_text_field((string)($data['name'] ?? 'Discovered Place')),
                'post_content' => '',
            ], true);
            if (is_wp_error($post_id)) $this->redirect_with_notice('error', $post_id->get_error_message());

            $post_id = (int)$post_id;
            $place_id = sanitize_text_field((string)($data['place_id'] ?? ''));
            if ($place_id !== '') {
                update_post_meta($post_id, '_tng_food_google_place_id', $place_id);
                update_post_meta($post_id, Content_Sources::META, [
                    'google_places' => ['external_id' => $place_id, 'status' => 'pending'],
                ]);
            }
            foreach ([
                '_tng_source_address' => 'address',
                '_tng_source_latitude' => 'latitude',
                '_tng_source_longitude' => 'longitude',
                '_tng_source_phone' => 'phone',
                '_tng_source_website' => 'website',
                '_tng_source_maps_url' => 'maps_url',
                '_tng_source_rating' => 'rating',
                '_tng_source_rating_count' => 'rating_count',
                '_tng_source_primary_type_label' => 'category',
            ] as $meta => $field) {
                if (isset($data[$field]) && $data[$field] !== null && $data[$field] !== '') update_post_meta($post_id, $meta, $data[$field]);
            }
            update_post_meta($candidate_id, '_tng_discovery_review_status', 'added');
            update_post_meta($candidate_id, '_tng_discovery_match_status', 'existing');
            update_post_meta($candidate_id, '_tng_discovery_matched_post', $post_id);
            wp_safe_redirect(get_edit_post_link($post_id, 'url'));
            exit;
        }

        $this->redirect_with_notice('error', 'Unknown action.');
    }

    private function redirect_with_notice(string $type, string $message): void {
        set_transient('tng_discovery_notice_' . get_current_user_id(), ['type' => $type, 'message' => $message], 60);
        wp_safe_redirect(admin_url('admin.php?page=tng-os-discovery-engine'));
        exit;
    }

    public function page(): void {
        if (!current_user_can('manage_options')) wp_die('Permission denied.');
        $token_set = (string)get_option(self::OPTION, '') !== '';
        $notice = get_transient('tng_discovery_notice_' . get_current_user_id());
        delete_transient('tng_discovery_notice_' . get_current_user_id());
        $last = get_transient('tng_discovery_last_run_' . get_current_user_id());
        $status_filter = sanitize_key($_GET['status'] ?? 'all');
        $meta_query = [];
        if ($status_filter !== 'all') $meta_query[] = ['key' => '_tng_discovery_match_status', 'value' => $status_filter];
        $q = new \WP_Query([
            'post_type' => self::CPT,
            'post_status' => 'publish',
            'posts_per_page' => 100,
            'orderby' => 'modified',
            'order' => 'DESC',
            'meta_query' => $meta_query,
        ]);
        ?>
        <div class="wrap tng-os-wrap">
          <h1>Google Maps Discovery Engine</h1>
          <p>Controlled pilot: discover places with Apify, compare them with TN Game, and manually add only the candidates you approve. Nothing is auto-published.</p>
          <?php if (is_array($notice)): ?><div class="notice notice-<?php echo $notice['type']==='success'?'success':'error'; ?> is-dismissible"><p><?php echo esc_html($notice['message']); ?></p></div><?php endif; ?>

          <div style="display:grid;grid-template-columns:minmax(320px,1fr) minmax(320px,1fr);gap:20px;max-width:1200px;">
            <div class="card" style="max-width:none;padding:20px;">
              <h2>1. Apify connection</h2>
              <p>Status: <strong><?php echo $token_set ? 'Configured' : 'Not configured'; ?></strong></p>
              <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('tng_discovery_save_settings'); ?>
                <input type="hidden" name="action" value="tng_discovery_save_settings">
                <p><label>Apify API token<br><input type="password" name="apify_token" value="" placeholder="<?php echo $token_set?'Token saved — enter a new value to replace it':'apify_api_...'; ?>" style="width:100%"></label></p>
                <p><button class="button button-primary">Save token</button> <?php if($token_set): ?><label style="margin-left:12px"><input type="checkbox" name="clear_token" value="1"> Clear saved token</label><?php endif; ?></p>
              </form>
            </div>

            <div class="card" style="max-width:none;padding:20px;">
              <h2>2. Run discovery</h2>
              <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('tng_discovery_run'); ?>
                <input type="hidden" name="action" value="tng_discovery_run">
                <p><label>Search term<br><input name="query" value="restaurants" style="width:100%" required></label></p>
                <p><label>Location<br><input name="location" value="Tracy City, Tennessee" style="width:100%" required></label></p>
                <p><label>Maximum results<br><input name="limit" type="number" min="1" max="<?php echo self::MAX_RESULTS; ?>" value="20" style="width:120px"></label></p>
                <p><button class="button button-primary" <?php disabled(!$token_set); ?>>Run Google Maps Discovery</button></p>
                <p class="description">Pilot is capped at <?php echo self::MAX_RESULTS; ?> results per run. Social, lead, review, image and competitor enrichment are disabled to keep cost and data collection minimal.</p>
              </form>
            </div>
          </div>

          <?php if (is_array($last)): ?>
          <div class="notice notice-info inline"><p><strong>Last run:</strong> <?php echo esc_html($last['query'].' — '.$last['location']); ?> · <?php echo esc_html($last['time']); ?> · <?php echo esc_html(wp_json_encode($last['counts'])); ?></p></div>
          <?php endif; ?>

          <h2 style="margin-top:28px;">Discovery candidates</h2>
          <p>
            <?php foreach (['all'=>'All','new'=>'New','possible'=>'Possible match','existing'=>'Existing','ignored'=>'Ignored'] as $key=>$label): ?>
              <a class="button <?php echo $status_filter===$key?'button-primary':''; ?>" href="<?php echo esc_url(admin_url('admin.php?page=tng-os-discovery-engine&status='.$key)); ?>"><?php echo esc_html($label); ?></a>
            <?php endforeach; ?>
          </p>
          <table class="widefat striped">
            <thead><tr><th>Place</th><th>Category</th><th>Rating</th><th>Address</th><th>Match</th><th>Actions</th></tr></thead>
            <tbody>
            <?php if ($q->have_posts()): while ($q->have_posts()): $q->the_post(); $id=get_the_ID(); $d=(array)get_post_meta($id,'_tng_discovery_data',true); $match=(string)get_post_meta($id,'_tng_discovery_match_status',true); $matched=absint(get_post_meta($id,'_tng_discovery_matched_post',true)); ?>
              <tr>
                <td><strong><?php the_title(); ?></strong><br><small><?php echo esc_html($d['place_id']??''); ?></small></td>
                <td><?php echo esc_html($d['category']??'—'); ?></td>
                <td><?php echo esc_html(($d['rating']??'—').' · '.($d['rating_count']??0)); ?></td>
                <td><?php echo esc_html($d['address']??'—'); ?></td>
                <td><strong><?php echo esc_html(ucfirst($match?:'new')); ?></strong><?php if($matched): ?><br><a href="<?php echo esc_url(get_edit_post_link($matched)); ?>">Open TN Game listing</a><?php endif; ?></td>
                <td>
                  <?php if ($match==='new'): ?>
                    <a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tng_discovery_action&do=add&candidate='.$id),'tng_discovery_action')); ?>">Add as Draft</a>
                  <?php endif; ?>
                  <?php if (!in_array($match,['ignored','existing'],true)): ?>
                    <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tng_discovery_action&do=ignore&candidate='.$id),'tng_discovery_action')); ?>">Ignore</a>
                  <?php endif; ?>
                  <?php if (!empty($d['maps_url'])): ?><a class="button" target="_blank" rel="noopener" href="<?php echo esc_url($d['maps_url']); ?>">Google Maps</a><?php endif; ?>
                </td>
              </tr>
            <?php endwhile; wp_reset_postdata(); else: ?>
              <tr><td colspan="6">No discovery candidates yet. Run a small search above.</td></tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
        <?php
    }
}
