<?php
namespace TNG_OS\Modules\Concerts;

use DOMDocument;
use DOMXPath;
use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;
use WP_Post;
use WP_Query;

if (!defined('ABSPATH')) exit;

final class Concert_Intelligence implements Module_Interface {
    private Container $container;
    private const CRON = 'tng_os_concert_sync';
    private const SOURCE_TYPE = 'tng_concert_source';
    private const VENUE_TYPE = 'tng_venue';
    private const ARTIST_TYPE = 'tng_artist';
    private const QUEUE_TYPE = 'tng_concert_import';

    public function id(): string { return 'concert_intelligence'; }

    public function register(Container $container): void {
        $this->container = $container;
        $container->set('concert_intelligence', $this);

        add_action('init', [$this, 'register_types'], 9);
        add_action('init', [$this, 'ensure_defaults'], 40);
        add_action('add_meta_boxes', [$this, 'meta_boxes']);
        add_action('save_post_' . self::SOURCE_TYPE, [$this, 'save_source'], 20, 2);
        add_action('save_post_' . self::VENUE_TYPE, [$this, 'save_venue'], 20, 2);
        add_action('admin_menu', [$this, 'menu'], 24);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);

        add_action('admin_post_tng_concert_sync_source', [$this, 'handle_sync']);
        add_action('admin_post_tng_concert_import_item', [$this, 'handle_import']);
        add_action('admin_post_tng_concert_ignore_item', [$this, 'handle_ignore']);
        add_action('admin_post_tng_concert_import_all', [$this, 'handle_import_all']);
        add_action('admin_post_tng_ci_save_api_settings', [$this, 'save_api_settings']);
        add_action('admin_post_tng_ci_test_api', [$this, 'test_api']);
        add_action(self::CRON, [$this, 'scheduled_sync']);

        add_filter('cron_schedules', [$this, 'cron_schedules']);
        add_action('init', [$this, 'schedule_cron'], 60);
        add_filter('manage_' . self::QUEUE_TYPE . '_posts_columns', [$this, 'queue_columns']);
        add_action('manage_' . self::QUEUE_TYPE . '_posts_custom_column', [$this, 'queue_column'], 10, 2);
    }

    public function boot(Container $container): void {}

    public function register_types(): void {
        register_post_type(self::SOURCE_TYPE, [
            'labels'=>[
                'name'=>'Concert Sources','singular_name'=>'Concert Source',
                'add_new_item'=>'Add Concert Source','edit_item'=>'Edit Concert Source',
            ],
            'public'=>false,'show_ui'=>true,'show_in_menu'=>false,'show_in_rest'=>false,
            'supports'=>['title'],
        ]);

        register_post_type(self::VENUE_TYPE, [
            'labels'=>[
                'name'=>'Venues','singular_name'=>'Venue',
                'add_new_item'=>'Add Venue','edit_item'=>'Edit Venue',
            ],
            'public'=>true,'show_ui'=>true,'show_in_menu'=>false,'show_in_rest'=>true,
            'has_archive'=>true,'rewrite'=>['slug'=>'venues'],
            'supports'=>['title','editor','excerpt','thumbnail','custom-fields'],
        ]);

        register_post_type(self::ARTIST_TYPE, [
            'labels'=>[
                'name'=>'Artists','singular_name'=>'Artist',
                'add_new_item'=>'Add Artist','edit_item'=>'Edit Artist',
            ],
            'public'=>true,'show_ui'=>true,'show_in_menu'=>false,'show_in_rest'=>true,
            'has_archive'=>true,'rewrite'=>['slug'=>'artists'],
            'supports'=>['title','editor','excerpt','thumbnail','custom-fields'],
        ]);

        register_post_type(self::QUEUE_TYPE, [
            'labels'=>[
                'name'=>'Concert Import Queue','singular_name'=>'Concert Import',
                'edit_item'=>'Review Concert Import',
            ],
            'public'=>false,'show_ui'=>true,'show_in_menu'=>false,'show_in_rest'=>false,
            'supports'=>['title','editor','thumbnail','custom-fields'],
        ]);
    }

    public function menu(): void {
        add_submenu_page('tn-game-os','Concert Intelligence','Concert Intelligence','edit_posts','tng-concert-intelligence',[$this,'dashboard']);
        add_submenu_page('tn-game-os','Concert Sources','Concert Sources','manage_options','edit.php?post_type='.self::SOURCE_TYPE);
        add_submenu_page('tn-game-os','Venues','Venues','edit_posts','edit.php?post_type='.self::VENUE_TYPE);
        add_submenu_page('tn-game-os','Artists','Artists','edit_posts','edit.php?post_type='.self::ARTIST_TYPE);
        add_submenu_page('tn-game-os','Concert Import Queue','Import Queue','edit_posts','edit.php?post_type='.self::QUEUE_TYPE);
        add_submenu_page('tn-game-os','Concert API Settings','API Settings','manage_options','tng-concert-api-settings',[$this,'api_settings_page']);
    }

    public function admin_assets(string $hook): void {
        if (
            strpos($hook, 'tng-concert-intelligence') !== false ||
            in_array(get_post_type(), [self::SOURCE_TYPE,self::VENUE_TYPE,self::QUEUE_TYPE], true)
        ) {
            wp_enqueue_style('tng-concert-intelligence-admin', TNG_OS_URL.'assets/admin/concert-intelligence.css', [], TNG_OS_VERSION);
        }
    }

    public function meta_boxes(): void {
        add_meta_box('tng_concert_source_settings','Source Settings',[$this,'source_box'],self::SOURCE_TYPE,'normal','high');
        add_meta_box('tng_venue_defaults','Concert Import Defaults',[$this,'venue_box'],self::VENUE_TYPE,'normal','high');
        add_meta_box('tng_concert_import_details','Imported Event Details',[$this,'queue_box'],self::QUEUE_TYPE,'normal','high');
    }

    public function source_box(WP_Post $post): void {
        wp_nonce_field('tng_concert_source_save','tng_concert_source_nonce');
        $provider = (string)get_post_meta($post->ID,'_tng_ci_provider',true) ?: 'tixr';
        $url = (string)get_post_meta($post->ID,'_tng_ci_source_url',true);
        $venue_id = absint(get_post_meta($post->ID,'_tng_ci_venue_id',true));
        $enabled = get_post_meta($post->ID,'_tng_ci_enabled',true) !== '0';
        $auto_import = get_post_meta($post->ID,'_tng_ci_auto_import',true) === '1';
        $venues = get_posts(['post_type'=>self::VENUE_TYPE,'post_status'=>['publish','draft'],'posts_per_page'=>-1,'orderby'=>'title','order'=>'ASC']);
        ?>
        <div class="tng-ci-fields">
            <label><span>Provider</span><select name="_tng_ci_provider"><option value="tixr" <?php selected($provider,'tixr'); ?>>Tixr</option></select></label>
            <label class="wide"><span>Group or events URL</span><input type="url" name="_tng_ci_source_url" value="<?php echo esc_attr($url); ?>" placeholder="https://www.tixr.com/groups/thecaverns"></label>
            <label class="wide"><span>Manual event URLs (optional fallback)</span><textarea name="_tng_ci_manual_event_urls" rows="5" placeholder="One Tixr event URL per line"><?php echo esc_textarea((string)get_post_meta($post->ID,'_tng_ci_manual_event_urls',true)); ?></textarea></label>
            <label><span>Venue defaults</span><select name="_tng_ci_venue_id"><option value="0">— Select venue —</option><?php foreach($venues as $venue): ?><option value="<?php echo (int)$venue->ID; ?>" <?php selected($venue_id,$venue->ID); ?>><?php echo esc_html($venue->post_title); ?></option><?php endforeach; ?></select></label>
            <label class="check"><input type="checkbox" name="_tng_ci_enabled" value="1" <?php checked($enabled); ?>> Enable scheduled sync</label>
            <label class="check"><input type="checkbox" name="_tng_ci_auto_import" value="1" <?php checked($auto_import); ?>> Automatically publish new events</label>
        </div>
        <p class="description">Manual review is recommended until the source has been tested. Duplicate matching uses Tixr event ID, canonical URL, and venue/date/title.</p>
        <?php
        $diagnostics = get_post_meta($post->ID,'_tng_ci_diagnostics',true);
        if (is_array($diagnostics) && $diagnostics): ?>
            <div class="tng-ci-diagnostics">
                <strong>Last adapter diagnostics</strong>
                <pre><?php echo esc_html(wp_json_encode($diagnostics, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)); ?></pre>
            </div>
        <?php endif; ?>
        <?php if ($url): ?>
        <p><a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tng_concert_sync_source&source_id='.$post->ID),'tng_concert_sync_'.$post->ID)); ?>">Sync this source now</a></p>
        <?php endif;
    }

    public function venue_box(WP_Post $post): void {
        wp_nonce_field('tng_venue_defaults_save','tng_venue_defaults_nonce');
        $primary = absint(get_post_meta($post->ID,'_tng_venue_primary_destination',true));
        $related = get_post_meta($post->ID,'_tng_venue_related_destinations',true);
        $related = is_array($related) ? array_map('absint',$related) : [];
        $activity_term = (string)get_post_meta($post->ID,'_tng_venue_activity_term',true) ?: 'concerts';
        $destinations = get_posts(['post_type'=>'tng_destination','post_status'=>['publish','draft'],'posts_per_page'=>-1,'orderby'=>'title','order'=>'ASC']);
        ?>
        <div class="tng-ci-fields">
            <label><span>Primary destination</span><select name="_tng_venue_primary_destination"><option value="0">— Select destination —</option><?php foreach($destinations as $destination): ?><option value="<?php echo (int)$destination->ID; ?>" <?php selected($primary,$destination->ID); ?>><?php echo esc_html($destination->post_title); ?></option><?php endforeach; ?></select></label>
            <label><span>Activity service tag slug</span><input type="text" name="_tng_venue_activity_term" value="<?php echo esc_attr($activity_term); ?>" placeholder="concerts"></label>
            <label class="wide"><span>Address</span><input type="text" name="_tng_venue_address" value="<?php echo esc_attr((string)get_post_meta($post->ID,'_tng_venue_address',true)); ?>"></label>
            <label><span>Latitude</span><input type="text" name="_tng_venue_lat" value="<?php echo esc_attr((string)get_post_meta($post->ID,'_tng_venue_lat',true)); ?>"></label>
            <label><span>Longitude</span><input type="text" name="_tng_venue_lng" value="<?php echo esc_attr((string)get_post_meta($post->ID,'_tng_venue_lng',true)); ?>"></label>
        </div>
        <div class="tng-ci-related">
            <strong>Related destinations</strong>
            <div><?php foreach($destinations as $destination): ?><label><input type="checkbox" name="_tng_venue_related_destinations[]" value="<?php echo (int)$destination->ID; ?>" <?php checked(in_array((int)$destination->ID,$related,true)); ?>> <?php echo esc_html($destination->post_title); ?></label><?php endforeach; ?></div>
        </div>
        <div class="tng-ci-fields">
            <label class="check"><input type="checkbox" name="_tng_venue_trip_enabled" value="1" <?php checked(get_post_meta($post->ID,'_tng_venue_trip_enabled',true) !== '0'); ?>> Generate Concert Trip Page</label>
            <label><span>Default trip length</span><select name="_tng_venue_trip_length"><?php $length=(string)get_post_meta($post->ID,'_tng_venue_trip_length',true) ?: 'overnight'; foreach(['same-day'=>'Same-day','overnight'=>'Overnight','weekend'=>'Weekend'] as $key=>$label): ?><option value="<?php echo esc_attr($key); ?>" <?php selected($length,$key); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></label>
            <label class="wide"><span>Default visitor note</span><textarea name="_tng_venue_trip_note" rows="3"><?php echo esc_textarea((string)get_post_meta($post->ID,'_tng_venue_trip_note',true)); ?></textarea></label>
        </div>
        <?php
    }

    public function queue_box(WP_Post $post): void {
        $data = get_post_meta($post->ID,'_tng_ci_event_data',true);
        $data = is_array($data) ? $data : [];
        echo '<div class="tng-ci-event-preview">';
        foreach ([
            'external_id'=>'External ID','url'=>'Source URL','start'=>'Start','end'=>'End',
            'doors'=>'Doors','venue'=>'Venue','status'=>'Status','price'=>'Price from'
        ] as $key=>$label) {
            if (!empty($data[$key])) printf('<p><strong>%s</strong><span>%s</span></p>',esc_html($label),esc_html((string)$data[$key]));
        }
        if (!empty($data['image'])) printf('<p><img src="%s" alt="" style="max-width:360px;height:auto"></p>',esc_url($data['image']));
        echo '</div>';
        echo '<p>';
        printf('<a class="button button-primary" href="%s">Import event</a> ',esc_url(wp_nonce_url(admin_url('admin-post.php?action=tng_concert_import_item&item_id='.$post->ID),'tng_concert_import_'.$post->ID)));
        printf('<a class="button" href="%s">Ignore</a>',esc_url(wp_nonce_url(admin_url('admin-post.php?action=tng_concert_ignore_item&item_id='.$post->ID),'tng_concert_ignore_'.$post->ID)));
        echo '</p>';
    }

    public function save_source(int $post_id, WP_Post $post): void {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;
        if (!isset($_POST['tng_concert_source_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['tng_concert_source_nonce'])),'tng_concert_source_save')) return;
        if (!current_user_can('manage_options')) return;

        update_post_meta($post_id,'_tng_ci_provider','tixr');
        update_post_meta($post_id,'_tng_ci_source_url',isset($_POST['_tng_ci_source_url']) ? esc_url_raw(wp_unslash($_POST['_tng_ci_source_url'])) : '');
        update_post_meta($post_id,'_tng_ci_venue_id',isset($_POST['_tng_ci_venue_id']) ? absint($_POST['_tng_ci_venue_id']) : 0);
        update_post_meta($post_id,'_tng_ci_enabled',isset($_POST['_tng_ci_enabled']) ? '1' : '0');
        update_post_meta($post_id,'_tng_ci_auto_import',isset($_POST['_tng_ci_auto_import']) ? '1' : '0');
        update_post_meta(
            $post_id,
            '_tng_ci_manual_event_urls',
            isset($_POST['_tng_ci_manual_event_urls']) ? sanitize_textarea_field(wp_unslash($_POST['_tng_ci_manual_event_urls'])) : ''
        );
    }

    public function save_venue(int $post_id, WP_Post $post): void {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;
        if (!isset($_POST['tng_venue_defaults_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['tng_venue_defaults_nonce'])),'tng_venue_defaults_save')) return;
        if (!current_user_can('edit_post',$post_id)) return;

        update_post_meta($post_id,'_tng_venue_primary_destination',isset($_POST['_tng_venue_primary_destination']) ? absint($_POST['_tng_venue_primary_destination']) : 0);
        $related = isset($_POST['_tng_venue_related_destinations']) ? array_values(array_unique(array_filter(array_map('absint',(array)wp_unslash($_POST['_tng_venue_related_destinations']))))) : [];
        update_post_meta($post_id,'_tng_venue_related_destinations',$related);
        update_post_meta($post_id,'_tng_venue_activity_term',isset($_POST['_tng_venue_activity_term']) ? sanitize_title(wp_unslash($_POST['_tng_venue_activity_term'])) : 'concerts');
        foreach (['_tng_venue_address','_tng_venue_lat','_tng_venue_lng','_tng_venue_trip_length'] as $key) {
            update_post_meta($post_id,$key,isset($_POST[$key]) ? sanitize_text_field(wp_unslash($_POST[$key])) : '');
        }
        update_post_meta($post_id,'_tng_venue_trip_note',isset($_POST['_tng_venue_trip_note']) ? sanitize_textarea_field(wp_unslash($_POST['_tng_venue_trip_note'])) : '');
        update_post_meta($post_id,'_tng_venue_trip_enabled',isset($_POST['_tng_venue_trip_enabled']) ? '1' : '0');
    }

    public function ensure_defaults(): void {
        if (get_option('tng_ci_defaults_created')) return;

        $venue = get_page_by_title('The Caverns', OBJECT, self::VENUE_TYPE);
        if (!$venue) {
            $venue_id = wp_insert_post([
                'post_type'=>self::VENUE_TYPE,'post_status'=>'publish','post_title'=>'The Caverns',
                'post_content'=>'Underground concert venue and outdoor amphitheater in Pelham, Tennessee.',
            ]);
        } else {
            $venue_id = $venue->ID;
        }

        if ($venue_id && !is_wp_error($venue_id)) {
            update_post_meta($venue_id,'_tng_venue_activity_term','concerts');
            update_post_meta($venue_id,'_tng_venue_address','555 Charlie Roberts Rd, Pelham, TN 37366');
            update_post_meta($venue_id,'_tng_venue_trip_enabled','1');
            update_post_meta($venue_id,'_tng_venue_trip_length','overnight');
            update_post_meta($venue_id,'_tng_venue_trip_note','Plan extra time for parking and entry. Cave temperatures can be cool year-round, so bring a light layer.');
        }

        $source = get_page_by_title('The Caverns — Tixr', OBJECT, self::SOURCE_TYPE);
        if (!$source) {
            $source_id = wp_insert_post([
                'post_type'=>self::SOURCE_TYPE,'post_status'=>'publish','post_title'=>'The Caverns — Tixr',
            ]);
        } else {
            $source_id = $source->ID;
        }

        if ($source_id && !is_wp_error($source_id)) {
            update_post_meta($source_id,'_tng_ci_provider','tixr');
            update_post_meta($source_id,'_tng_ci_source_url','https://www.tixr.com/groups/thecaverns');
            update_post_meta($source_id,'_tng_ci_venue_id',(int)$venue_id);
            update_post_meta($source_id,'_tng_ci_enabled','1');
            update_post_meta($source_id,'_tng_ci_auto_import','0');
        }

        update_option('tng_ci_defaults_created',1,false);
    }

    public function cron_schedules(array $schedules): array {
        $schedules['tng_six_hours'] = ['interval'=>6*HOUR_IN_SECONDS,'display'=>'Every six hours'];
        return $schedules;
    }

    public function schedule_cron(): void {
        if (!wp_next_scheduled(self::CRON)) wp_schedule_event(time()+300,'tng_six_hours',self::CRON);
    }

    public function scheduled_sync(): void {
        $sources = get_posts([
            'post_type'=>self::SOURCE_TYPE,'post_status'=>'publish','posts_per_page'=>-1,
            'meta_key'=>'_tng_ci_enabled','meta_value'=>'1',
        ]);
        foreach ($sources as $source) $this->sync_source($source->ID);
    }

    public function handle_sync(): void {
        $source_id = isset($_GET['source_id']) ? absint($_GET['source_id']) : 0;
        if (!$source_id || !current_user_can('manage_options')) wp_die('Not allowed.');
        check_admin_referer('tng_concert_sync_'.$source_id);
        $result = $this->sync_source($source_id);
        $message = is_wp_error($result) ? $result->get_error_message() : sprintf('%d events discovered; %d new or updated.',(int)$result['discovered'],(int)$result['queued']);
        wp_safe_redirect(add_query_arg(['page'=>'tng-concert-intelligence','tng_notice'=>rawurlencode($message)],admin_url('admin.php')));
        exit;
    }

    public function handle_import(): void {
        $item_id = isset($_GET['item_id']) ? absint($_GET['item_id']) : 0;
        if (!$item_id || !current_user_can('edit_posts')) wp_die('Not allowed.');
        check_admin_referer('tng_concert_import_'.$item_id);
        $this->import_item($item_id);
        wp_safe_redirect(admin_url('edit.php?post_type='.self::QUEUE_TYPE));
        exit;
    }

    public function handle_ignore(): void {
        $item_id = isset($_GET['item_id']) ? absint($_GET['item_id']) : 0;
        if (!$item_id || !current_user_can('edit_posts')) wp_die('Not allowed.');
        check_admin_referer('tng_concert_ignore_'.$item_id);
        update_post_meta($item_id,'_tng_ci_queue_status','ignored');
        wp_update_post(['ID'=>$item_id,'post_status'=>'draft']);
        wp_safe_redirect(admin_url('edit.php?post_type='.self::QUEUE_TYPE));
        exit;
    }

    public function handle_import_all(): void {
        if (!current_user_can('edit_posts')) wp_die('Not allowed.');
        check_admin_referer('tng_concert_import_all');
        $items = get_posts([
            'post_type'=>self::QUEUE_TYPE,'post_status'=>'pending','posts_per_page'=>-1,
            'meta_key'=>'_tng_ci_queue_status','meta_value'=>'new',
            'fields'=>'ids',
        ]);
        foreach ($items as $item_id) $this->import_item((int)$item_id);
        wp_safe_redirect(admin_url('admin.php?page=tng-concert-intelligence'));
        exit;
    }

    public function sync_source(int $source_id) {
        $source_url = (string)get_post_meta($source_id,'_tng_ci_source_url',true);
        if (!$source_url) return new \WP_Error('missing_url','Source URL is missing.');

        $endpoint = untrailingslashit((string)get_option('tng_ci_api_endpoint',''));
        $api_key = (string)get_option('tng_ci_api_key','');
        if (!$endpoint || !$api_key) {
            return new \WP_Error('api_not_configured','Concert Intelligence API is not configured. Open TN Game OS → API Settings.');
        }

        $request_url = $endpoint . '/v1/providers/tixr/sync';
        $response = wp_remote_post($request_url,[
            'timeout'=>120,
            'redirection'=>3,
            'headers'=>[
                'Content-Type'=>'application/json',
                'Accept'=>'application/json',
                'X-API-Key'=>$api_key,
            ],
            'body'=>wp_json_encode([
                'source_url'=>$source_url,
                'force'=>true,
            ]),
        ]);

        $diagnostics = [
            'started_at'=>current_time('mysql'),
            'transport'=>'concert-intelligence-api',
            'endpoint'=>$endpoint,
        ];

        if (is_wp_error($response)) {
            $diagnostics['error']=$response->get_error_message();
            update_post_meta($source_id,'_tng_ci_diagnostics',$diagnostics);
            return $response;
        }

        $code=wp_remote_retrieve_response_code($response);
        $body=wp_remote_retrieve_body($response);
        $decoded=json_decode($body,true);
        $diagnostics['http_code']=$code;
        $diagnostics['response_bytes']=strlen($body);

        if ($code < 200 || $code >= 300 || !is_array($decoded) || empty($decoded['ok'])) {
            $message=is_array($decoded) ? (string)($decoded['error'] ?? 'API request failed.') : 'API returned invalid JSON.';
            if (is_array($decoded) && !empty($decoded['detail'])) $message.=' '.$decoded['detail'];
            $diagnostics['error']=$message;
            update_post_meta($source_id,'_tng_ci_diagnostics',$diagnostics);
            return new \WP_Error('api_error',$message);
        }

        $data=$decoded['data'] ?? [];
        $events=is_array($data['events'] ?? null) ? $data['events'] : [];
        $queued=0;
        $failed=0;

        foreach ($events as $event) {
            if (!is_array($event) || empty($event['title']) || empty($event['url'])) {
                $failed++; continue;
            }
            $event['source_id']=$source_id;
            $event['venue_id']=absint(get_post_meta($source_id,'_tng_ci_venue_id',true));
            $event['hash']=md5(wp_json_encode([
                $event['title'] ?? '',$event['start'] ?? '',$event['end'] ?? '',
                $event['venue'] ?? '',$event['status'] ?? '',$event['price'] ?? '',
                $event['image'] ?? ''
            ]));
            $item_id=$this->queue_event($event);
            if ($item_id) {
                $queued++;
                if (get_post_meta($source_id,'_tng_ci_auto_import',true)==='1') $this->import_item($item_id);
            }
        }

        $diagnostics['cached']=!empty($decoded['cached']);
        $diagnostics['discovered']=(int)($data['discovered'] ?? count($events));
        $diagnostics['events_received']=count($events);
        $diagnostics['provider_failures']=count((array)($data['failures'] ?? []));
        $diagnostics['queue_failures']=$failed;
        $diagnostics['queued']=$queued;
        $diagnostics['finished_at']=current_time('mysql');

        update_post_meta($source_id,'_tng_ci_last_sync',current_time('mysql'));
        update_post_meta($source_id,'_tng_ci_last_discovered',(int)($data['discovered'] ?? count($events)));
        update_post_meta($source_id,'_tng_ci_last_queued',$queued);
        update_post_meta($source_id,'_tng_ci_diagnostics',$diagnostics);

        return [
            'discovered'=>(int)($data['discovered'] ?? count($events)),
            'queued'=>$queued,
            'failed'=>$failed,
        ];
    }

    private function robust_get(string $url, string $referer='') {
        $profiles = [
            [
                'user-agent'=>'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Safari/605.1.15',
                'headers'=>[
                    'Accept'=>'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language'=>'en-US,en;q=0.9',
                    'Cache-Control'=>'no-cache',
                    'Pragma'=>'no-cache',
                    'Sec-Fetch-Dest'=>'document',
                    'Sec-Fetch-Mode'=>'navigate',
                    'Sec-Fetch-Site'=>'none',
                    'Upgrade-Insecure-Requests'=>'1',
                ],
            ],
            [
                'user-agent'=>'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
                'headers'=>[
                    'Accept'=>'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                    'Accept-Language'=>'en-US,en;q=0.8',
                    'DNT'=>'1',
                    'Upgrade-Insecure-Requests'=>'1',
                ],
            ],
            [
                'user-agent'=>'Googlebot/2.1 (+http://www.google.com/bot.html)',
                'headers'=>[
                    'Accept'=>'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language'=>'en-US,en;q=0.9',
                ],
            ],
        ];

        $last_error = null;
        foreach ($profiles as $index=>$profile) {
            if ($referer) $profile['headers']['Referer']=$referer;
            $response = wp_remote_get($url,[
                'timeout'=>30,
                'redirection'=>8,
                'user-agent'=>$profile['user-agent'],
                'headers'=>$profile['headers'],
                'sslverify'=>true,
                'decompress'=>true,
                'cookies'=>[],
            ]);

            if (is_wp_error($response)) {
                $last_error=$response;
                continue;
            }

            $code=wp_remote_retrieve_response_code($response);
            if ($code >= 200 && $code < 400 && wp_remote_retrieve_body($response) !== '') {
                return $response;
            }

            $last_error = new \WP_Error(
                'http_'.$code,
                'HTTP '.$code.' from '.wp_parse_url($url,PHP_URL_HOST).' using request profile '.($index+1).'.'
            );
        }

        return $last_error ?: new \WP_Error('request_failed','Request failed.');
    }

    private function response_diagnostic($response): array {
        if (is_wp_error($response)) {
            return ['ok'=>false,'error'=>$response->get_error_message()];
        }
        return [
            'ok'=>true,
            'http_code'=>wp_remote_retrieve_response_code($response),
            'content_type'=>wp_remote_retrieve_header($response,'content-type'),
            'bytes'=>strlen((string)wp_remote_retrieve_body($response)),
            'final_url'=>wp_remote_retrieve_header($response,'location') ?: '',
        ];
    }

    private function discover_from_tixr_sitemaps(string $source_url, array &$diagnostics): array {
        $group_slug = '';
        if (preg_match('~/groups/([^/?#]+)~i',$source_url,$m)) $group_slug = sanitize_title($m[1]);
        if (!$group_slug) return [];

        $roots = [
            'https://www.tixr.com/static/sitemap.xml',
            'https://www.tixr.com/sitemap.xml',
        ];
        $event_links = [];
        $seen_sitemaps = [];
        $queue = $roots;

        while ($queue && count($seen_sitemaps) < 35 && count($event_links) < 250) {
            $sitemap_url = array_shift($queue);
            if (isset($seen_sitemaps[$sitemap_url])) continue;
            $seen_sitemaps[$sitemap_url]=true;

            $response = $this->robust_get($sitemap_url,'https://www.tixr.com/');
            $diagnostics['strategies']['sitemaps'][$sitemap_url] = $this->response_diagnostic($response);
            if (is_wp_error($response)) continue;

            $xml = wp_remote_retrieve_body($response);
            if (!$xml) continue;

            if (preg_match_all('~<loc>\s*(.*?)\s*</loc>~is',$xml,$matches)) {
                foreach ($matches[1] as $loc) {
                    $loc = html_entity_decode(trim(strip_tags($loc)),ENT_QUOTES,'UTF-8');
                    if (!$loc) continue;

                    if (preg_match('~\.xml(?:\.gz)?(?:\?|$)~i',$loc)) {
                        if (count($seen_sitemaps)+count($queue) < 40) $queue[]=$loc;
                        continue;
                    }

                    if (preg_match(
                        '~^https?://(?:www\.)?tixr\.com/groups/'.preg_quote($group_slug,'~').'/events/[^?#]+-\d+/?$~i',
                        $loc
                    )) {
                        $event_links[] = rtrim($loc,'/');
                    }
                }
            }
        }

        $diagnostics['strategies']['sitemap_summary'] = [
            'group_slug'=>$group_slug,
            'sitemaps_checked'=>count($seen_sitemaps),
            'matching_events'=>count(array_unique($event_links)),
        ];
        return array_values(array_unique($event_links));
    }

    private function discover_tixr_links(string $html, string $base_url): array {
        $links = [];

        if (preg_match_all('~https?://(?:www\.)?tixr\.com/groups/[^"\']+/events/[^"\'?#\s<]+~i',$html,$matches)) {
            $links = array_merge($links,$matches[0]);
        }

        if (preg_match_all('~href=["\']([^"\']*/events/[^"\']+)["\']~i',$html,$matches)) {
            foreach ($matches[1] as $href) {
                if (strpos($href,'http') === 0) $links[] = $href;
                elseif (strpos($href,'/') === 0) $links[] = 'https://www.tixr.com'.$href;
            }
        }

        if (preg_match_all('~\\\\?"url\\\\?"\s*:\s*\\\\?"(https?:\\\\?/\\\\?/(?:www\.)?tixr\.com/groups/[^"]+/events/[^"\\\\]+)~i',$html,$matches)) {
            foreach ($matches[1] as $url) $links[] = str_replace(['\\/','\\\\/'],'/',$url);
        }

        $clean = [];
        foreach ($links as $link) {
            $link = html_entity_decode(stripslashes($link),ENT_QUOTES,'UTF-8');
            $link = preg_replace('~[,"\']+$~','',$link);
            $link = preg_replace('~([?#]).*$~','',$link);
            if (preg_match('~/events/[^/]+-\d+$~',$link)) $clean[] = $link;
        }

        return array_values(array_unique($clean));
    }

    private function fetch_tixr_event(string $url, array &$diagnostics=[]) {
        $response = $this->robust_get($url, 'https://www.tixr.com/');
        $diagnostics['event_fetches'][$url] = $this->response_diagnostic($response);
        if (is_wp_error($response)) return $response;
        $html = wp_remote_retrieve_body($response);
        if (!$html) return new \WP_Error('empty_event','Empty event page.');

        $event = [
            'url'=>$url,'external_id'=>$this->external_id_from_url($url),'provider'=>'tixr',
            'title'=>'','description'=>'','image'=>'','start'=>'','end'=>'','doors'=>'',
            'venue'=>'','address'=>'','status'=>'scheduled','price'=>'','age'=>'',
            'artists'=>[],
        ];

        foreach ($this->json_ld_objects($html) as $object) {
            $type = $object['@type'] ?? '';
            if (is_array($type)) $type = implode(' ',$type);
            if (stripos((string)$type,'Event') === false) continue;

            $event['title'] = sanitize_text_field((string)($object['name'] ?? $event['title']));
            $event['description'] = wp_kses_post((string)($object['description'] ?? $event['description']));
            $event['start'] = sanitize_text_field((string)($object['startDate'] ?? $event['start']));
            $event['end'] = sanitize_text_field((string)($object['endDate'] ?? $event['end']));
            $event['image'] = $this->first_image($object['image'] ?? $event['image']);
            $location = $object['location'] ?? [];
            if (is_array($location)) {
                $event['venue'] = sanitize_text_field((string)($location['name'] ?? $event['venue']));
                $address = $location['address'] ?? '';
                if (is_array($address)) {
                    $event['address'] = sanitize_text_field(implode(', ',array_filter([
                        $address['streetAddress'] ?? '',
                        $address['addressLocality'] ?? '',
                        $address['addressRegion'] ?? '',
                        $address['postalCode'] ?? '',
                    ])));
                } else {
                    $event['address'] = sanitize_text_field((string)$address);
                }
            }
            $offers = $object['offers'] ?? [];
            if (isset($offers['lowPrice'])) $event['price'] = sanitize_text_field((string)$offers['lowPrice']);
            elseif (isset($offers['price'])) $event['price'] = sanitize_text_field((string)$offers['price']);
            if (!empty($object['eventStatus']) && stripos((string)$object['eventStatus'],'Cancelled') !== false) $event['status']='cancelled';
            break;
        }

        $meta = $this->meta_tags($html);
        if (!$event['title']) $event['title'] = sanitize_text_field($meta['og:title'] ?? $meta['twitter:title'] ?? '');
        if (!$event['description']) $event['description'] = wp_kses_post($meta['og:description'] ?? $meta['description'] ?? '');
        if (!$event['image']) $event['image'] = esc_url_raw($meta['og:image'] ?? $meta['twitter:image'] ?? '');

        if (!$event['start'] && preg_match('~"startDate"\s*:\s*"([^"]+)"~',$html,$m)) $event['start']=sanitize_text_field(stripslashes($m[1]));
        if (preg_match('~Doors?\s*(?:open)?\s*(?:at)?\s*([0-9]{1,2}:[0-9]{2}\s*[AP]M)~i',wp_strip_all_tags($html),$m)) $event['doors']=sanitize_text_field($m[1]);
        if (preg_match('~\b(All Ages|[0-9]{1,2}\+)\b~i',wp_strip_all_tags($html),$m)) $event['age']=sanitize_text_field($m[1]);

        if ($event['title']) {
            $artist = preg_split('~\s+(?:at|in|with|w/|feat\.?)\s+~i',$event['title'])[0] ?? '';
            $artist = trim(preg_replace('~\s+\([^)]+\)$~','',$artist));
            if ($artist && mb_strlen($artist) < 120) $event['artists']=[$artist];
        }

        $event['hash'] = md5(wp_json_encode([
            $event['title'],$event['start'],$event['end'],$event['venue'],$event['status'],$event['price'],$event['image']
        ]));

        return $event;
    }

    private function json_ld_objects(string $html): array {
        $objects = [];
        if (!preg_match_all('~<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>~is',$html,$matches)) return [];
        foreach ($matches[1] as $json) {
            $decoded = json_decode(html_entity_decode(trim($json),ENT_QUOTES,'UTF-8'),true);
            if (!is_array($decoded)) continue;
            if (isset($decoded['@graph']) && is_array($decoded['@graph'])) $objects=array_merge($objects,$decoded['@graph']);
            elseif (array_is_list($decoded)) $objects=array_merge($objects,$decoded);
            else $objects[]=$decoded;
        }
        return $objects;
    }

    private function meta_tags(string $html): array {
        $meta = [];
        if (!class_exists(DOMDocument::class)) return $meta;
        libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        if (!$doc->loadHTML('<?xml encoding="utf-8" ?>'.$html)) return $meta;
        $xpath = new DOMXPath($doc);
        foreach ($xpath->query('//meta[@content]') as $node) {
            $key = $node->getAttribute('property') ?: $node->getAttribute('name');
            if ($key) $meta[strtolower($key)]=$node->getAttribute('content');
        }
        return $meta;
    }

    private function first_image($image): string {
        if (is_string($image)) return esc_url_raw($image);
        if (is_array($image)) {
            if (isset($image['url'])) return esc_url_raw((string)$image['url']);
            $first = reset($image);
            return is_string($first) ? esc_url_raw($first) : '';
        }
        return '';
    }

    private function external_id_from_url(string $url): string {
        if (preg_match('~-(\d+)(?:[/?#]|$)~',$url,$m)) return $m[1];
        return md5($url);
    }

    private function queue_event(array $event): int {
        $existing = get_posts([
            'post_type'=>self::QUEUE_TYPE,'post_status'=>['pending','draft','publish'],
            'posts_per_page'=>1,'fields'=>'ids',
            'meta_query'=>[
                'relation'=>'OR',
                ['key'=>'_tng_ci_external_key','value'=>$event['provider'].':'.$event['external_id']],
                ['key'=>'_tng_ci_source_url','value'=>$event['url']],
            ],
        ]);

        $item_id = $existing ? (int)$existing[0] : 0;
        $old_hash = $item_id ? (string)get_post_meta($item_id,'_tng_ci_event_hash',true) : '';
        if ($item_id && $old_hash === $event['hash'] && get_post_meta($item_id,'_tng_ci_queue_status',true) !== 'new') return 0;

        $payload = [
            'ID'=>$item_id,
            'post_type'=>self::QUEUE_TYPE,
            'post_status'=>'pending',
            'post_title'=>$event['title'],
            'post_content'=>$event['description'],
        ];
        $item_id = $item_id ? wp_update_post($payload) : wp_insert_post($payload);
        if (!$item_id || is_wp_error($item_id)) return 0;

        update_post_meta($item_id,'_tng_ci_event_data',$event);
        update_post_meta($item_id,'_tng_ci_external_key',$event['provider'].':'.$event['external_id']);
        update_post_meta($item_id,'_tng_ci_source_url',$event['url']);
        update_post_meta($item_id,'_tng_ci_event_hash',$event['hash']);
        update_post_meta($item_id,'_tng_ci_queue_status','new');
        update_post_meta($item_id,'_tng_ci_source_id',absint($event['source_id'] ?? 0));
        update_post_meta($item_id,'_tng_ci_venue_id',absint($event['venue_id'] ?? 0));
        return (int)$item_id;
    }

    public function import_item(int $item_id): int {
        $event = get_post_meta($item_id,'_tng_ci_event_data',true);
        if (!is_array($event) || empty($event['title'])) return 0;

        $external_key = (string)get_post_meta($item_id,'_tng_ci_external_key',true);
        $existing = get_posts([
            'post_type'=>'st_activity','post_status'=>['publish','draft','pending','private'],
            'posts_per_page'=>1,'fields'=>'ids',
            'meta_key'=>'_tng_source_external_key','meta_value'=>$external_key,
        ]);

        if (!$existing && !empty($event['url'])) {
            $existing = get_posts([
                'post_type'=>'st_activity','post_status'=>['publish','draft','pending','private'],
                'posts_per_page'=>1,'fields'=>'ids',
                'meta_key'=>'_tng_source_url','meta_value'=>$event['url'],
            ]);
        }

        $activity_id = $existing ? (int)$existing[0] : 0;
        if (!$activity_id && !empty($event['start'])) {
            $date = substr((string)$event['start'],0,10);
            $candidates = get_posts([
                'post_type'=>'st_activity','post_status'=>['publish','draft','pending','private'],
                'posts_per_page'=>20,'fields'=>'ids',
                'meta_key'=>'_tng_trip_date','meta_value'=>$date,
            ]);
            foreach ($candidates as $candidate) {
                if (sanitize_title(get_the_title($candidate)) === sanitize_title((string)$event['title'])) {
                    $activity_id=(int)$candidate; break;
                }
            }
        }

        $postarr = [
            'ID'=>$activity_id,
            'post_type'=>'st_activity',
            'post_status'=>'publish',
            'post_title'=>sanitize_text_field((string)$event['title']),
        ];
        if (!$activity_id || !get_post_field('post_content',$activity_id)) $postarr['post_content']=wp_kses_post((string)($event['description'] ?? ''));
        $activity_id = $activity_id ? wp_update_post($postarr) : wp_insert_post($postarr);
        if (!$activity_id || is_wp_error($activity_id)) return 0;

        $venue_id = absint($event['venue_id'] ?? get_post_meta($item_id,'_tng_ci_venue_id',true));
        $venue_name = $venue_id ? get_the_title($venue_id) : sanitize_text_field((string)($event['venue'] ?? ''));
        $start_ts = !empty($event['start']) ? strtotime((string)$event['start']) : false;
        $end_ts = !empty($event['end']) ? strtotime((string)$event['end']) : false;

        update_post_meta($activity_id,'_tng_source_provider','tixr');
        update_post_meta($activity_id,'_tng_source_external_key',$external_key);
        update_post_meta($activity_id,'_tng_source_event_id',sanitize_text_field((string)($event['external_id'] ?? '')));
        update_post_meta($activity_id,'_tng_source_url',esc_url_raw((string)($event['url'] ?? '')));
        update_post_meta($activity_id,'_tng_source_hash',sanitize_text_field((string)($event['hash'] ?? '')));
        update_post_meta($activity_id,'_tng_source_last_sync',current_time('mysql'));
        update_post_meta($activity_id,'_tng_event_status',sanitize_key((string)($event['status'] ?? 'scheduled')));
        update_post_meta($activity_id,'_tng_event_price_from',sanitize_text_field((string)($event['price'] ?? '')));
        update_post_meta($activity_id,'_tng_event_age_policy',sanitize_text_field((string)($event['age'] ?? '')));
        update_post_meta($activity_id,'_tng_venue_id',$venue_id);

        update_post_meta($activity_id,'_tng_trip_enabled',$venue_id && get_post_meta($venue_id,'_tng_venue_trip_enabled',true)==='0' ? '0' : '1');
        update_post_meta($activity_id,'_tng_trip_venue',$venue_name ?: 'The Caverns');
        update_post_meta($activity_id,'_tng_trip_date',$start_ts ? wp_date('Y-m-d',$start_ts) : '');
        update_post_meta($activity_id,'_tng_trip_time',$start_ts ? wp_date('H:i',$start_ts) : '');
        update_post_meta($activity_id,'_tng_trip_doors',$this->normalize_time((string)($event['doors'] ?? '')));
        update_post_meta($activity_id,'_tng_trip_ticket_url',esc_url_raw((string)($event['url'] ?? '')));
        update_post_meta($activity_id,'_tng_trip_length',$venue_id ? ((string)get_post_meta($venue_id,'_tng_venue_trip_length',true) ?: 'overnight') : 'overnight');
        update_post_meta($activity_id,'_tng_trip_arrival','Arrive 2–3 hours before doors');
        update_post_meta($activity_id,'_tng_trip_note',$venue_id ? (string)get_post_meta($venue_id,'_tng_venue_trip_note',true) : '');
        update_post_meta($activity_id,'_tng_trip_sections',['lodging','food','trails','waterfalls','camping','shops','history']);

        if ($start_ts) {
            update_post_meta($activity_id,'_tng_event_start',gmdate('c',$start_ts));
            update_post_meta($activity_id,'start_date',gmdate('Y-m-d',$start_ts));
            update_post_meta($activity_id,'start_time',wp_date('H:i',$start_ts));
        }
        if ($end_ts) update_post_meta($activity_id,'_tng_event_end',gmdate('c',$end_ts));

        if ($venue_id) {
            $primary = absint(get_post_meta($venue_id,'_tng_venue_primary_destination',true));
            $related = get_post_meta($venue_id,'_tng_venue_related_destinations',true);
            $relationships = $this->container->get('destination_relationships');
            if ($relationships && is_callable([$relationships,'assign'])) {
                $relationships->assign($activity_id,$primary,is_array($related)?$related:[]);
            }
            $this->assign_activity_term($activity_id,(string)get_post_meta($venue_id,'_tng_venue_activity_term',true) ?: 'concerts');
        }

        foreach ((array)($event['artists'] ?? []) as $artist_name) {
            $artist_id = $this->upsert_artist((string)$artist_name);
            if ($artist_id) add_post_meta($activity_id,'_tng_artist_ids',$artist_id,false);
        }

        if (!empty($event['image']) && !has_post_thumbnail($activity_id)) {
            $this->sideload_image((string)$event['image'],$activity_id,(string)$event['title']);
        }

        update_post_meta($item_id,'_tng_ci_queue_status','imported');
        update_post_meta($item_id,'_tng_ci_activity_id',$activity_id);
        wp_update_post(['ID'=>$item_id,'post_status'=>'publish']);

        return (int)$activity_id;
    }

    private function normalize_time(string $value): string {
        if (!$value) return '';
        $timestamp = strtotime($value);
        return $timestamp ? wp_date('H:i',$timestamp) : '';
    }

    private function assign_activity_term(int $activity_id, string $slug): void {
        $taxonomy = '';
        $services = $this->container->get('services');
        if ($services && is_callable([$services,'taxonomy'])) $taxonomy=(string)$services->taxonomy();
        if (!$taxonomy) foreach(['st_activity_type','activity_type','st_activity_types'] as $candidate) if (taxonomy_exists($candidate)) {$taxonomy=$candidate;break;}
        if (!$taxonomy) return;

        $term = get_term_by('slug',$slug,$taxonomy);
        if (!$term || is_wp_error($term)) {
            $created = wp_insert_term(ucwords(str_replace('-',' ',$slug)),$taxonomy,['slug'=>$slug]);
            if (is_wp_error($created)) return;
            $term_id=(int)$created['term_id'];
        } else $term_id=(int)$term->term_id;

        wp_set_object_terms($activity_id,[$term_id],$taxonomy,true);
    }

    private function upsert_artist(string $name): int {
        $name = sanitize_text_field(trim($name));
        if (!$name) return 0;
        $existing = get_page_by_title($name,OBJECT,self::ARTIST_TYPE);
        if ($existing) return (int)$existing->ID;
        $id = wp_insert_post(['post_type'=>self::ARTIST_TYPE,'post_status'=>'publish','post_title'=>$name]);
        return is_wp_error($id) ? 0 : (int)$id;
    }

    private function sideload_image(string $url, int $post_id, string $description=''): int {
        if (!$url) return 0;
        require_once ABSPATH.'wp-admin/includes/file.php';
        require_once ABSPATH.'wp-admin/includes/media.php';
        require_once ABSPATH.'wp-admin/includes/image.php';
        $attachment_id = media_sideload_image($url,$post_id,$description,'id');
        if (is_wp_error($attachment_id)) {
            update_post_meta($post_id,'_tng_source_image_url',esc_url_raw($url));
            return 0;
        }
        set_post_thumbnail($post_id,(int)$attachment_id);
        update_post_meta($post_id,'_tng_source_image_url',esc_url_raw($url));
        return (int)$attachment_id;
    }

    public function save_api_settings(): void {
        if (!current_user_can('manage_options')) wp_die('Not allowed.');
        check_admin_referer('tng_ci_save_api_settings');
        $endpoint=isset($_POST['tng_ci_api_endpoint']) ? esc_url_raw(wp_unslash($_POST['tng_ci_api_endpoint'])) : '';
        $endpoint=untrailingslashit($endpoint);
        update_option('tng_ci_api_endpoint',$endpoint,false);
        if (isset($_POST['tng_ci_api_key']) && trim((string)$_POST['tng_ci_api_key'])!=='') {
            update_option('tng_ci_api_key',sanitize_text_field(wp_unslash($_POST['tng_ci_api_key'])),false);
        }
        wp_safe_redirect(add_query_arg(['page'=>'tng-concert-api-settings','updated'=>'1'],admin_url('admin.php')));
        exit;
    }

    public function test_api(): void {
        if (!current_user_can('manage_options')) wp_die('Not allowed.');
        check_admin_referer('tng_ci_test_api');
        $endpoint=untrailingslashit((string)get_option('tng_ci_api_endpoint',''));
        $response=$endpoint ? wp_remote_get($endpoint.'/health',['timeout'=>30]) : new \WP_Error('missing','Endpoint missing.');
        $message=is_wp_error($response)
            ? $response->get_error_message()
            : 'HTTP '.wp_remote_retrieve_response_code($response).': '.wp_remote_retrieve_body($response);
        wp_safe_redirect(add_query_arg(['page'=>'tng-concert-api-settings','test'=>rawurlencode($message)],admin_url('admin.php')));
        exit;
    }

    private function api_health(): array {
        $endpoint=untrailingslashit((string)get_option('tng_ci_api_endpoint',''));
        if (!$endpoint) return ['ok'=>false,'error'=>'API endpoint is not configured.'];
        $cached=get_transient('tng_ci_api_health'); if (is_array($cached)) return $cached;
        $response=wp_remote_get($endpoint.'/health',['timeout'=>30,'headers'=>['Accept'=>'application/json']]);
        if (is_wp_error($response)) $health=['ok'=>false,'error'=>$response->get_error_message()];
        else { $health=json_decode(wp_remote_retrieve_body($response),true); if (!is_array($health)) $health=['ok'=>false,'error'=>'Invalid health response.']; $health['_http_code']=wp_remote_retrieve_response_code($response); }
        set_transient('tng_ci_api_health',$health,60); return $health;
    }

    public function api_settings_page(): void {
        if (!current_user_can('manage_options')) return;
        $endpoint=(string)get_option('tng_ci_api_endpoint','');
        $has_key=(string)get_option('tng_ci_api_key','')!=='';
        ?>
        <div class="wrap tng-ci-dashboard">
            <header class="tng-ci-header"><div><span>CONCERT INTELLIGENCE</span><h1>API Settings</h1><p>Connect TN Game OS to the private Playwright provider service.</p></div></header>
            <?php if(isset($_GET['updated'])): ?><div class="notice notice-success"><p>API settings saved.</p></div><?php endif; ?>
            <?php if(isset($_GET['test'])): ?><div class="notice notice-info"><p><?php echo esc_html(rawurldecode(sanitize_text_field(wp_unslash($_GET['test'])))); ?></p></div><?php endif; ?>
            <section class="tng-ci-panel">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="tng_ci_save_api_settings">
                    <?php wp_nonce_field('tng_ci_save_api_settings'); ?>
                    <div class="tng-ci-fields">
                        <label class="wide"><span>API base URL</span><input type="url" name="tng_ci_api_endpoint" value="<?php echo esc_attr($endpoint); ?>" placeholder="https://concert-api.example.com"></label>
                        <label class="wide"><span>API key</span><input type="password" name="tng_ci_api_key" value="" placeholder="<?php echo $has_key ? 'Key saved — enter only to replace it' : 'Enter API key'; ?>" autocomplete="new-password"></label>
                    </div>
                    <p><button class="button button-primary" type="submit">Save API settings</button></p>
                </form>
                <?php if($endpoint): ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="tng_ci_test_api">
                    <?php wp_nonce_field('tng_ci_test_api'); ?>
                    <button class="button" type="submit">Test API health</button>
                </form>
                <?php endif; ?>
            </section>
        </div>
        <?php
    }

    public function dashboard(): void {
        if (!current_user_can('edit_posts')) return;
        $sources = get_posts(['post_type'=>self::SOURCE_TYPE,'post_status'=>['publish','draft'],'posts_per_page'=>-1]);
        $new_count = (new WP_Query(['post_type'=>self::QUEUE_TYPE,'post_status'=>'pending','posts_per_page'=>1,'meta_key'=>'_tng_ci_queue_status','meta_value'=>'new']))->found_posts;
        $imported_count = (new WP_Query(['post_type'=>self::QUEUE_TYPE,'post_status'=>'publish','posts_per_page'=>1,'meta_key'=>'_tng_ci_queue_status','meta_value'=>'imported']))->found_posts;
        $venue_count = wp_count_posts(self::VENUE_TYPE)->publish ?? 0;
        ?>
        <div class="wrap tng-ci-dashboard">
            <header class="tng-ci-header">
                <div><span>TN GAME OS</span><h1>Concert Intelligence</h1><p>Discover concerts, review changes, publish Traveler Activities, and build complete destination trip pages.</p></div>
                <div><a class="button" href="<?php echo esc_url(admin_url('post-new.php?post_type='.self::SOURCE_TYPE)); ?>">Add source</a> <a class="button button-primary" href="<?php echo esc_url(admin_url('edit.php?post_type='.self::QUEUE_TYPE)); ?>">Open import queue</a></div>
            </header>

            <?php if(isset($_GET['tng_notice'])): ?><div class="notice notice-info is-dismissible"><p><?php echo esc_html(rawurldecode(sanitize_text_field(wp_unslash($_GET['tng_notice'])))); ?></p></div><?php endif; ?>
            <?php if(!(string)get_option('tng_ci_api_endpoint','') || !(string)get_option('tng_ci_api_key','')): ?><div class="notice notice-warning"><p>Concert Intelligence API is not configured. <a href="<?php echo esc_url(admin_url('admin.php?page=tng-concert-api-settings')); ?>">Configure the API connection</a>.</p></div><?php endif; ?>
            <?php $api_health=$this->api_health(); $browser_health=is_array($api_health['browser'] ?? null)?$api_health['browser']:[]; $provider_health=is_array($api_health['providers']['tixr'] ?? null)?$api_health['providers']['tixr']:[]; ?>
            <section class="tng-ci-health">
              <article class="<?php echo !empty($api_health['ok'])?'ok':'bad'; ?>"><span>API</span><strong><?php echo !empty($api_health['ok'])?'Online':'Needs attention'; ?></strong><small><?php echo esc_html((string)($api_health['version'] ?? $api_health['error'] ?? 'No response')); ?></small></article>
              <article class="<?php echo !empty($browser_health['ok'])?'ok':'bad'; ?>"><span>Browser</span><strong><?php echo !empty($browser_health['ok'])?'Chromium ready':'Unavailable'; ?></strong><small><?php echo esc_html((string)($browser_health['playwright_version'] ?? $browser_health['error'] ?? 'No response')); ?></small></article>
              <article class="<?php echo !empty($provider_health['ok'])?'ok':'bad'; ?>"><span>Tixr provider</span><strong><?php echo !empty($provider_health['ok'])?'Ready':'Unavailable'; ?></strong><small><?php echo esc_html((string)($provider_health['adapter_version'] ?? $provider_health['error'] ?? 'No response')); ?></small></article>
            </section>

            <div class="tng-ci-stats">
                <article><strong><?php echo (int)$new_count; ?></strong><span>Awaiting review</span></article>
                <article><strong><?php echo (int)$imported_count; ?></strong><span>Imported events</span></article>
                <article><strong><?php echo (int)$venue_count; ?></strong><span>Venue profiles</span></article>
                <article><strong><?php echo count($sources); ?></strong><span>Concert sources</span></article>
            </div>

            <section class="tng-ci-panel">
                <div class="tng-ci-panel-head"><div><span>CONNECTED SOURCES</span><h2>Venue feeds</h2></div><?php if($new_count): ?><a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tng_concert_import_all'),'tng_concert_import_all')); ?>">Import all reviewed events</a><?php endif; ?></div>
                <?php if(!$sources): ?><div class="tng-ci-empty"><span>🎟</span><h3>No sources connected</h3></div><?php else: ?>
                <div class="tng-ci-source-grid">
                    <?php foreach($sources as $source):
                        $url=(string)get_post_meta($source->ID,'_tng_ci_source_url',true);
                        $venue_id=absint(get_post_meta($source->ID,'_tng_ci_venue_id',true));
                    ?>
                    <article>
                        <div><small>TIXR</small><h3><?php echo esc_html($source->post_title); ?></h3><p><?php echo esc_html($venue_id ? get_the_title($venue_id) : 'Venue not assigned'); ?></p></div>
                        <dl><div><dt>Last sync</dt><dd><?php echo esc_html((string)get_post_meta($source->ID,'_tng_ci_last_sync',true) ?: 'Never'); ?></dd></div><div><dt>Events found</dt><dd><?php echo (int)get_post_meta($source->ID,'_tng_ci_last_discovered',true); ?></dd></div></dl>
                        <footer><a href="<?php echo esc_url(get_edit_post_link($source->ID)); ?>">Configure</a><a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tng_concert_sync_source&source_id='.$source->ID),'tng_concert_sync_'.$source->ID)); ?>">Sync now</a></footer>
                    </article>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </section>

            <section class="tng-ci-panel tng-ci-steps">
                <span>HOW IT WORKS</span><h2>One source creates the complete visitor journey</h2>
                <ol><li><b>1</b><strong>Discover</strong><p>Read the Tixr group page and find event URLs.</p></li><li><b>2</b><strong>Normalize</strong><p>Extract title, dates, venue, image, description, ticket URL, status and price.</p></li><li><b>3</b><strong>Review</strong><p>Queue new or changed events and block duplicates.</p></li><li><b>4</b><strong>Publish</strong><p>Create or update a Traveler Activity with venue defaults and multiple destinations.</p></li><li><b>5</b><strong>Build the trip</strong><p>Populate the Concert Trip Page and local recommendations automatically.</p></li></ol>
            </section>
        </div>
        <?php
    }

    public function queue_columns(array $columns): array {
        return [
            'cb'=>$columns['cb'] ?? '<input type="checkbox">',
            'title'=>'Event',
            'tng_ci_date'=>'Event date',
            'tng_ci_venue'=>'Venue',
            'tng_ci_status'=>'Queue status',
            'tng_ci_actions'=>'Actions',
            'date'=>'Discovered',
        ];
    }

    public function queue_column(string $column, int $post_id): void {
        $data = get_post_meta($post_id,'_tng_ci_event_data',true);
        $data = is_array($data) ? $data : [];
        if ($column==='tng_ci_date') echo esc_html(substr((string)($data['start'] ?? ''),0,16) ?: '—');
        elseif ($column==='tng_ci_venue') {
            $venue_id=absint(get_post_meta($post_id,'_tng_ci_venue_id',true));
            echo esc_html($venue_id ? get_the_title($venue_id) : ((string)($data['venue'] ?? '—')));
        }
        elseif ($column==='tng_ci_status') {
            $status=(string)get_post_meta($post_id,'_tng_ci_queue_status',true) ?: 'new';
            printf('<span class="tng-ci-pill %s">%s</span>',esc_attr($status),esc_html(ucfirst($status)));
        }
        elseif ($column==='tng_ci_actions') {
            printf('<a href="%s">Import</a> · ',esc_url(wp_nonce_url(admin_url('admin-post.php?action=tng_concert_import_item&item_id='.$post_id),'tng_concert_import_'.$post_id)));
            printf('<a href="%s">Ignore</a>',esc_url(wp_nonce_url(admin_url('admin-post.php?action=tng_concert_ignore_item&item_id='.$post_id),'tng_concert_ignore_'.$post_id)));
        }
    }
}
