<?php
if (!defined('ABSPATH')) exit;
class TN_Game_Core {
    const VERSION = '2.1.0';
    const OPTION_TOKEN = 'tng_mapbox_access_token';
    const OPTION_STYLE = 'tng_mapbox_style_url';
    const OPTION_AUTO_DEV = 'tng_auto_developer_mode';
    const GALLERY_REPAIR_OPTION = 'tng_gallery_repair_settings';
    const GALLERY_BACKUP_META = '_tng_gallery_repair_backups';
    const GALLERY_LAST_SCAN = 'tng_gallery_repair_last_scan';
    const HEALTH_SCAN_OPTION = 'tng_core_health_scan';
    const TOP_SIGHT_AUDIT_OPTION = 'tng_core_top_sight_audit';
    const TRAIL_AUDIT_OPTION = 'tng_core_trail_audit';
    const GAMIPRESS_AUDIT_OPTION = 'tng_core_gamipress_audit';
    const NONCE_ACTION = 'tng_core_game_nonce';
    const FOOD_GOOGLE_KEY_OPTION = 'tng_food_google_places_api_key';
    const FOOD_META_PREFIX = '_tng_food_';

    public function __construct(){
        add_action('admin_menu',[$this,'add_settings_page']);
        add_action('admin_init',[$this,'register_settings']);
        add_action('admin_enqueue_scripts',[$this,'admin_editor_assets']);
        add_action('wp_enqueue_scripts',[$this,'register_assets']);
        add_filter('body_class',[$this,'hiking_trail_body_class']);
        add_action('wp_enqueue_scripts',[$this,'enqueue_mobile_trail_actions'],30);
        add_action('wp_footer',[$this,'progress_sidebar_script'],999);
        add_action('wp_enqueue_scripts',[$this,'enqueue_global_player_hud'],40);
        add_action('wp_footer',[$this,'render_global_player_hud'],1000);
        add_action('wp_ajax_tng_player_hud_state',[$this,'ajax_player_hud_state']);
        add_action('wp_ajax_tng_odometer_update',[$this,'ajax_odometer_update']);
        add_action('wp_ajax_tng_simulator_odometer_update',[$this,'ajax_simulator_odometer_update']);
        add_action('wp_enqueue_scripts',[$this,'enqueue_odometer'],45);
        add_action('wp_ajax_tng_core_claim_checkpoint',[$this,'ajax_claim_checkpoint']);
        add_action('wp_ajax_tng_core_unclaim_checkpoint',[$this,'ajax_unclaim_checkpoint']);
        add_action('admin_menu',[$this,'gallery_repair_menu']);
        add_action('admin_post_tng_gallery_scan',[$this,'handle_gallery_scan']);
        add_action('admin_post_tng_gallery_repair',[$this,'handle_gallery_repair']);
        add_action('admin_post_tng_gallery_restore',[$this,'handle_gallery_restore']);
        add_action('admin_post_tng_run_database_health',[$this,'handle_database_health_scan']);
        add_action('admin_post_tng_run_top_sight_audit',[$this,'handle_top_sight_audit']);
        add_action('admin_post_tng_run_trail_audit',[$this,'handle_trail_audit']);
        add_action('admin_post_tng_run_gamipress_audit',[$this,'handle_gamipress_audit']);
        add_action('admin_enqueue_scripts',[$this,'management_admin_assets']);
        add_action('wp_ajax_tng_core_editor_get_sights',[$this,'ajax_editor_get_sights']);
        add_action('wp_ajax_tng_core_editor_get_trails',[$this,'ajax_editor_get_trails']);
        add_action('wp_ajax_tng_core_editor_save_sight',[$this,'ajax_editor_save_sight']);
        add_action('wp_ajax_tng_core_award_action',[$this,'ajax_award_action']);
        add_filter('gamipress_activity_triggers',[$this,'register_gamipress_events']);

        add_action('add_meta_boxes',[$this,'food_add_meta_boxes']);
        add_action('save_post_st_activity',[$this,'food_save_meta'],20,2);
        add_action('wp_ajax_tng_food_google_import',[$this,'ajax_food_google_import']);
        add_action('wp_ajax_tng_food_checkin',[$this,'ajax_food_checkin']);
        add_action('wp_ajax_nopriv_tng_food_checkin',[$this,'ajax_food_checkin']);
        add_filter('the_content',[$this,'food_inject_experience'],8);
        add_filter('body_class',[$this,'food_body_class']);
        add_action('wp_enqueue_scripts',[$this,'enqueue_food_assets'],35);

        /*
         * Traveler 3.2.9 can request the removed TinyMCE `hr` plugin from
         * /wp-includes/js/tinymce/plugins/hr/plugin.min.js. Modern WordPress
         * no longer ships that plugin, so remove the stale registration.
         */
        add_filter('tiny_mce_plugins',[$this,'repair_tinymce_plugins'],999);
        add_filter('mce_external_plugins',[$this,'repair_tinymce_external_plugins'],999);
        add_filter('tiny_mce_before_init',[$this,'repair_tinymce_initialization'],999);

        add_shortcode('tng_food_experience',[$this,'food_experience_shortcode']);
        add_shortcode('food_experience',[$this,'food_experience_shortcode']);

        add_shortcode('trail_route_map',[$this,'trail_route_map_shortcode']);
        add_shortcode('trail_experience',[$this,'trail_experience_shortcode']);
        add_shortcode('trail_overview',[$this,'trail_experience_shortcode']);
        add_shortcode('trail_game_button',[$this,'trail_game_button_shortcode']);
        add_shortcode('trail_stats',[$this,'trail_stats_shortcode']);
        add_shortcode('trail_top_sights',[$this,'trail_top_sights_shortcode']);
        add_shortcode('tng_player_progress',[$this,'player_progress_shortcode']);
        add_shortcode('tng_xp_balance',[$this,'xp_balance_shortcode']);

    }

    public function add_settings_page(){
        /*
         * TN Game OS owns the parent dashboard. The compatibility runtime
         * registers its specialist pages beneath the stable OS parent slug.
         */
        add_submenu_page(
            'tn-game-os',
            'Food & Drink',
            'Food & Drink',
            'manage_options',
            'tn-game-food',
            [$this,'food_admin_page']
        );

        add_submenu_page(
            'tn-game-os',
            'Core Settings',
            'Core Settings',
            'manage_options',
            'tn-game-core-settings',
            [$this,'settings_page_html']
        );

        add_submenu_page(
            'tn-game-os',
            'Developer Mode',
            'Developer Mode',
            'manage_options',
            'tn-game-developer-mode',
            [$this,'developer_mode_page']
        );

        add_submenu_page(
            'tn-game-os',
            'Route Simulator',
            'Route Simulator',
            'manage_options',
            'tn-game-route-simulator',
            [$this,'route_simulator_page']
        );

        add_submenu_page(
            'tn-game-os',
            'Database Health',
            'Database Health',
            'manage_options',
            'tn-game-database-health',
            [$this,'database_health_page']
        );

        add_submenu_page(
            'tn-game-os',
            'Photo Library',
            'Photo Library',
            'manage_options',
            'tn-game-photo-library',
            [$this,'photo_library_page']
        );

        add_submenu_page(
            'tn-game-os',
            'Top Sight Audit',
            'Top Sight Audit',
            'manage_options',
            'tn-game-top-sight-audit',
            [$this,'top_sight_audit_page']
        );

        add_submenu_page(
            'tn-game-os',
            'Trail Audit',
            'Trail Audit',
            'manage_options',
            'tn-game-trail-audit',
            [$this,'trail_audit_page']
        );

        add_submenu_page(
            'tn-game-os',
            'GamiPress Audit',
            'GamiPress Audit',
            'manage_options',
            'tn-game-gamipress-audit',
            [$this,'gamipress_audit_page']
        );

        add_submenu_page(
            'tn-game-os',
            'Developer Map',
            'Developer Map',
            'manage_options',
            'tn-game-core-developer-map',
            [$this,'developer_map_page']
        );
    }

    public function register_settings(){
        register_setting('tng_core_settings',self::OPTION_TOKEN,['type'=>'string','sanitize_callback'=>'sanitize_text_field','default'=>'']);
        register_setting('tng_core_settings',self::OPTION_STYLE,['type'=>'string','sanitize_callback'=>'esc_url_raw','default'=>'mapbox://styles/mapbox/outdoors-v12']);
        register_setting('tng_core_settings',self::OPTION_AUTO_DEV,[
            'type'=>'boolean',
            'sanitize_callback'=>function($value){
                return empty($value)?0:1;
            },
            'default'=>0
        ]);
        register_setting('tng_core_settings','tng_progression_settings',[
            'type'=>'array',
            'sanitize_callback'=>[$this,'sanitize_progression_settings'],
            'default'=>[]
        ]);

    }

    public function settings_page_html(){
        if(!current_user_can('manage_options')) return; ?>
        <div class="wrap"><h1>TN Game Core</h1><form method="post" action="options.php"><?php settings_fields('tng_core_settings'); ?>
        <table class="form-table"><tr><th>Mapbox Access Token</th><td><input type="text" name="<?php echo esc_attr(self::OPTION_TOKEN); ?>" value="<?php echo esc_attr(get_option(self::OPTION_TOKEN)); ?>" class="regular-text" /></td></tr>
        <tr><th>Mapbox Style URL</th><td><input type="text" name="<?php echo esc_attr(self::OPTION_STYLE); ?>" value="<?php echo esc_attr(get_option(self::OPTION_STYLE,'mapbox://styles/mapbox/outdoors-v12')); ?>" class="regular-text" /></td></tr>
        <tr>
            <th>Administrator Developer Mode</th>
            <td>
                <label>
                    <input
                        type="checkbox"
                        name="<?php echo esc_attr(self::OPTION_AUTO_DEV); ?>"
                        value="1"
                        <?php checked(1,(int)get_option(self::OPTION_AUTO_DEV,0)); ?>
                    />
                    Automatically enable developer mode when an administrator opens a full trail map
                </label>
                <p class="description">
                    This only affects logged-in administrators. Regular players will always receive the normal map.
                    Add <code>&amp;tng_dev=0</code> to a full-map URL when you temporarily need to test the normal player view.
                </p>
            </td>
        </tr>
        <?php $ps=$this->progression_settings(); ?>
        <tr><th colspan="2"><h2 style="margin:16px 0 0">Progression</h2></th></tr>
        <tr><th>GamiPress Points Type</th><td><input type="text" name="tng_progression_settings[points_type]" value="<?php echo esc_attr($ps['points_type']); ?>" class="regular-text" /><p class="description">Usually <code>explorer-xp</code>.</p></td></tr>
        <tr><th>Checkpoint Radius</th><td><input type="number" min="5" max="500" name="tng_progression_settings[radius]" value="<?php echo esc_attr($ps['radius']); ?>" /> feet</td></tr>
        <tr><th>Default Checkpoint XP</th><td><input type="number" min="0" name="tng_progression_settings[checkpoint]" value="<?php echo esc_attr($ps['checkpoint']); ?>" /></td></tr>
        <tr><th>Trail Completion XP</th><td><input type="number" min="0" name="tng_progression_settings[trail]" value="<?php echo esc_attr($ps['trail']); ?>" /></td></tr>
        <tr><th>Photo XP</th><td><input type="number" min="0" name="tng_progression_settings[photo]" value="<?php echo esc_attr($ps['photo']); ?>" /></td></tr>
        <tr><th>Historic Site XP</th><td><input type="number" min="0" name="tng_progression_settings[history]" value="<?php echo esc_attr($ps['history']); ?>" /></td></tr>
        <tr><th>Restaurant XP</th><td><input type="number" min="0" name="tng_progression_settings[restaurant]" value="<?php echo esc_attr($ps['restaurant']); ?>" /></td></tr>
        <tr><th>Shop XP</th><td><input type="number" min="0" name="tng_progression_settings[shop]" value="<?php echo esc_attr($ps['shop']); ?>" /></td></tr>
        <tr><th>Concert XP</th><td><input type="number" min="0" name="tng_progression_settings[concert]" value="<?php echo esc_attr($ps['concert']); ?>" /></td></tr>
        </table><?php submit_button(); ?></form>
        <h2>Shortcodes</h2><p><code>[trail_stats]</code> <code>[trail_top_sights]</code> <code>[trail_route_map]</code> <code>[trail_game_button]</code></p>
        <p><code>[trail_route_map fullscreen="yes" game="yes"]</code> opens the GPS checkpoint game.</p>
        <h2>GamiPress</h2><p>Checkpoint claims use the points type configured by TN Game Progression (default: <code>explorer-xp</code>).</p>
        <h2>Developer Tools</h2>
        <p><a class="button button-secondary" href="<?php echo esc_url(admin_url('admin.php?page=tn-game-core-developer-map')); ?>">Open Developer Map Editor</a></p>
        </div><?php
    }


    private function normalize_file_url($value){
        if(is_array($value)){
            if(!empty($value['url'])) return esc_url_raw($value['url']);
            if(!empty($value['ID'])) return esc_url_raw(wp_get_attachment_url(absint($value['ID'])));
            if(!empty($value['id'])) return esc_url_raw(wp_get_attachment_url(absint($value['id'])));
        }
        if(is_numeric($value)) return esc_url_raw(wp_get_attachment_url(absint($value)));
        return is_string($value)?esc_url_raw(trim($value)):'';
    }

    private function update_field_value($name,$value,$post_id){
        if(function_exists('update_field')) return update_field($name,$value,$post_id);
        return update_post_meta($post_id,$name,$value);
    }

    public function admin_editor_assets($hook){
        if($hook!=='settings_page_tn-game-core-developer-map') return;

        wp_enqueue_style(
            'leaflet',
            'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css',
            [],
            '1.9.4'
        );
        wp_enqueue_script(
            'leaflet',
            'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',
            [],
            '1.9.4',
            true
        );
        wp_enqueue_style(
            'tng-core-editor',
            TNG_OS_URL.'assets/tng-core-editor.css',
            ['leaflet'],
            self::VERSION
        );
        wp_enqueue_script(
            'tng-core-editor',
            TNG_OS_URL.'assets/tng-core-editor.js',
            ['leaflet'],
            self::VERSION,
            true
        );
        wp_localize_script('tng-core-editor','TNGCoreEditor',[
            'ajaxUrl'=>admin_url('admin-ajax.php'),
            'nonce'=>wp_create_nonce(self::NONCE_ACTION),
        ]);
    }

    public function developer_map_page(){
        if(!current_user_can('manage_options')) return; ?>
        <div class="wrap tng-core-editor-wrap">
            <h1>TN Game Developer Map</h1>
            <p>Drag Top Sight markers to update their locations. Changes save directly to <code>sight_latitude</code> and <code>sight_longitude</code>.</p>

            <div class="tng-core-editor-toolbar">
                <label>
                    Trail overlay
                    <select id="tng-core-editor-trail">
                        <option value="">No trail selected</option>
                    </select>
                </label>

                <label>
                    Search Top Sights
                    <input id="tng-core-editor-search" type="search" placeholder="Waterfall, overlook…">
                </label>

                <button type="button" class="button" id="tng-core-editor-fit">Fit all sights</button>
                <span id="tng-core-editor-status" aria-live="polite"></span>
            </div>

            <div class="tng-core-editor-layout">
                <div id="tng-core-editor-map"></div>
                <aside class="tng-core-editor-sidebar">
                    <h2>Top Sights</h2>
                    <div id="tng-core-editor-list"></div>
                </aside>
            </div>
        </div>
        <?php
    }

    public function ajax_editor_get_sights(){
        check_ajax_referer(self::NONCE_ACTION,'nonce');
        if(!current_user_can('manage_options')){
            wp_send_json_error(['message'=>'You are not allowed to use this editor.'],403);
        }

        $posts=get_posts([
            'post_type'=>'top-sights',
            'post_status'=>['publish','draft','private'],
            'numberposts'=>-1,
            'orderby'=>'title',
            'order'=>'ASC'
        ]);

        $items=[];
        foreach($posts as $post){
            $lat=(float)$this->field('sight_latitude',$post->ID);
            $lng=(float)$this->field('sight_longitude',$post->ID);
            if(!$lat || !$lng) continue;

            $items[]=[
                'id'=>$post->ID,
                'title'=>get_the_title($post),
                'type'=>(string)$this->field('sight_type',$post->ID),
                'lat'=>$lat,
                'lng'=>$lng,
                'points'=>absint($this->field('sight_points',$post->ID)),
                'editUrl'=>get_edit_post_link($post->ID,'raw')
            ];
        }

        wp_send_json_success($items);
    }

    public function ajax_editor_get_trails(){
        check_ajax_referer(self::NONCE_ACTION,'nonce');
        if(!current_user_can('manage_options')){
            wp_send_json_error(['message'=>'You are not allowed to use this editor.'],403);
        }

        $posts=get_posts([
            'post_type'=>'st_activity',
            'post_status'=>['publish','draft','private'],
            'numberposts'=>-1,
            'orderby'=>'title',
            'order'=>'ASC'
        ]);

        $items=[];
        foreach($posts as $post){
            $gpx=$this->normalize_file_url($this->field('trail_gpx_url',$post->ID));
            if(!$gpx) continue;
            $items[]=[
                'id'=>$post->ID,
                'title'=>get_the_title($post),
                'gpxUrl'=>$gpx
            ];
        }

        wp_send_json_success($items);
    }

    public function ajax_editor_save_sight(){
        check_ajax_referer(self::NONCE_ACTION,'nonce');
        if(!current_user_can('manage_options')){
            wp_send_json_error(['message'=>'You are not allowed to edit Top Sights.'],403);
        }

        $post_id=absint($_POST['post_id']??0);
        $lat=isset($_POST['lat'])?(float)$_POST['lat']:0;
        $lng=isset($_POST['lng'])?(float)$_POST['lng']:0;

        if(!$post_id || get_post_type($post_id)!=='top-sights'){
            wp_send_json_error(['message'=>'Invalid Top Sight.'],400);
        }
        if($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180){
            wp_send_json_error(['message'=>'Invalid coordinates.'],400);
        }

        $this->update_field_value('sight_latitude',$lat,$post_id);
        $this->update_field_value('sight_longitude',$lng,$post_id);
        clean_post_cache($post_id);

        wp_send_json_success([
            'message'=>'Location saved.',
            'post_id'=>$post_id,
            'lat'=>$lat,
            'lng'=>$lng
        ]);
    }

    public function register_assets(){
        wp_register_style('mapbox-gl-css','https://api.mapbox.com/mapbox-gl-js/v3.5.1/mapbox-gl.css',[],'3.5.1');
        wp_register_script('mapbox-gl-js','https://api.mapbox.com/mapbox-gl-js/v3.5.1/mapbox-gl.js',[],'3.5.1',true);
        wp_register_script('togeojson','https://unpkg.com/@mapbox/togeojson@0.16.0/togeojson.js',[],'0.16.0',true);
        wp_register_style('tng-core-css',TNG_OS_URL.'assets/tng-core.css',[],self::VERSION);
        wp_register_script('tng-core-js',TNG_OS_URL.'assets/tng-core.js',['mapbox-gl-js','togeojson'],self::VERSION,true);
        wp_register_script(
            'tng-trail-experience',
            TNG_OS_URL.'assets/tng-trail-experience.js',
            ['tng-core-js'],
            self::VERSION,
            true
        );
        wp_register_script(
            'tng-mobile-trail-actions',
            TNG_OS_URL.'assets/tng-mobile-trail-actions.js',
            [],
            self::VERSION,
            true
        );
        wp_register_script(
            'tng-player-hud',
            TNG_OS_URL.'assets/tng-player-hud.js',
            [],
            self::VERSION,
            true
        );
        wp_register_script(
            'tng-odometer',
            TNG_OS_URL.'assets/tng-odometer.js',
            [],
            self::VERSION,
            true
        );
        wp_register_script(
            'tng-food',
            TNG_OS_URL.'assets/tng-food.js',
            ['mapbox-gl-js'],
            self::VERSION,
            true
        );
    }

    public function hiking_trail_body_class($classes){
        if(is_singular('st_activity') && $this->is_hiking_trail_activity(get_queried_object_id())){
            $classes[]='tng-hiking-trail-page';

            if(isset($_GET['trail_game_map']) && (string)$_GET['trail_game_map']==='1'){
                $classes[]='tng-fullscreen-trail-map-page';
            }
        }
        return $classes;
    }

    public function enqueue_mobile_trail_actions(){
        if(!is_singular('st_activity')) return;
        $post_id=get_queried_object_id();
        if(!$this->is_hiking_trail_activity($post_id)) return;

        wp_enqueue_style('tng-core-css');
        wp_enqueue_script('tng-mobile-trail-actions');

        $map_url=add_query_arg('trail_game_map',1,get_permalink($post_id));
        wp_localize_script('tng-mobile-trail-actions','TNGMobileTrailActions',[
            'mapUrl'=>esc_url_raw($map_url),
            'title'=>get_the_title($post_id),
            'shareText'=>sprintf(__('Explore %s on The TN Game','tn-game-core'),get_the_title($post_id)),
            'fullscreenMap'=>isset($_GET['trail_game_map']) && (string)$_GET['trail_game_map']==='1',
        ]);
    }



    public function enqueue_odometer(){
        if(
            is_admin() ||
            !is_user_logged_in() ||
            !is_singular('st_activity') ||
            !$this->is_hiking_trail_activity(get_queried_object_id()) ||
            !isset($_GET['trail_game_map']) ||
            (string)$_GET['trail_game_map']!=='1'
        ){
            return;
        }

        wp_enqueue_script('tng-odometer');

        wp_localize_script('tng-odometer','TNGOdometer',[
            'ajaxUrl'=>admin_url('admin-ajax.php'),
            'nonce'=>wp_create_nonce('tng_odometer'),
            'postId'=>get_queried_object_id(),
            'accuracyLimitMeters'=>30,
            'minimumSegmentFeet'=>12,
            'maximumSpeedMph'=>15,
            'rewardMiles'=>0.5,
            'rewardXp'=>10,
        ]);
    }

    private function odometer_total_feet($user_id){
        return max(0,(float)get_user_meta($user_id,'_tng_odometer_total_feet',true));
    }

    private function odometer_total_miles($user_id){
        return $this->odometer_total_feet($user_id)/5280;
    }

    private function odometer_distance_feet($lat1,$lng1,$lat2,$lng2){
        $earth_radius_feet=20902260;
        $lat1=deg2rad((float)$lat1);
        $lng1=deg2rad((float)$lng1);
        $lat2=deg2rad((float)$lat2);
        $lng2=deg2rad((float)$lng2);

        $dlat=$lat2-$lat1;
        $dlng=$lng2-$lng1;

        $a=sin($dlat/2)*sin($dlat/2)
            +cos($lat1)*cos($lat2)*sin($dlng/2)*sin($dlng/2);

        return $earth_radius_feet*2*atan2(sqrt($a),sqrt(max(0,1-$a)));
    }


    private function apply_odometer_segment($user_id,$segment_feet,$source='gps'){
        $segment_feet=max(0,(float)$segment_feet);
        $before_feet=$this->odometer_total_feet($user_id);
        $after_feet=$before_feet+$segment_feet;
        update_user_meta($user_id,'_tng_odometer_total_feet',$after_feet);

        $reward_interval_feet=2640;
        $before_milestones=(int)floor($before_feet/$reward_interval_feet);
        $after_milestones=(int)floor($after_feet/$reward_interval_feet);
        $new_milestones=max(0,$after_milestones-$before_milestones);
        $xp_awarded=$new_milestones*10;

        if($xp_awarded>0 && function_exists('gamipress_award_points_to_user')){
            $settings=$this->progression_settings();
            gamipress_award_points_to_user(
                $user_id,
                $xp_awarded,
                sanitize_key($settings['points_type'])
            );
            update_user_meta(
                $user_id,
                '_tng_odometer_rewarded_half_miles',
                $after_milestones
            );
            do_action(
                'tng_odometer_xp_awarded',
                $user_id,
                $xp_awarded,
                $after_milestones,
                sanitize_key($source)
            );
        }

        return [
            'segmentFeet'=>round($segment_feet,1),
            'miles'=>round($after_feet/5280,2),
            'xpAwarded'=>$xp_awarded,
            'nextRewardMiles'=>round((($after_milestones+1)*$reward_interval_feet)/5280,1),
        ];
    }

    public function ajax_simulator_odometer_update(){
        if(!is_user_logged_in() || !current_user_can('manage_options')){
            wp_send_json_error(['message'=>'Administrator access is required.'],403);
        }

        check_ajax_referer(self::NONCE_ACTION,'nonce');

        $post_id=absint($_POST['post_id']??0);
        $segment_feet=filter_var($_POST['segment_feet']??null,FILTER_VALIDATE_FLOAT);

        if(
            !$post_id ||
            get_post_type($post_id)!=='st_activity' ||
            !$this->is_hiking_trail_activity($post_id)
        ){
            wp_send_json_error(['message'=>'Invalid hiking trail.'],400);
        }

        if($segment_feet===false || $segment_feet<=0 || $segment_feet>2640){
            wp_send_json_error(['message'=>'Invalid simulator distance segment.'],400);
        }

        $result=$this->apply_odometer_segment(
            get_current_user_id(),
            $segment_feet,
            'simulator'
        );
        $result['accepted']=true;
        $result['simulated']=true;

        wp_send_json_success($result);
    }

    public function ajax_odometer_update(){
        if(!is_user_logged_in()){
            wp_send_json_error(['message'=>'Please sign in to track mileage.'],401);
        }

        check_ajax_referer('tng_odometer','nonce');

        $post_id=absint($_POST['post_id']??0);
        if(
            !$post_id ||
            get_post_type($post_id)!=='st_activity' ||
            !$this->is_hiking_trail_activity($post_id)
        ){
            wp_send_json_error(['message'=>'Invalid hiking trail.'],400);
        }

        $latitude=filter_var($_POST['latitude']??null,FILTER_VALIDATE_FLOAT);
        $longitude=filter_var($_POST['longitude']??null,FILTER_VALIDATE_FLOAT);
        $accuracy=filter_var($_POST['accuracy']??null,FILTER_VALIDATE_FLOAT);
        $client_time=absint($_POST['client_time']??0);

        if(
            $latitude===false || $longitude===false ||
            $latitude < -90 || $latitude > 90 ||
            $longitude < -180 || $longitude > 180
        ){
            wp_send_json_error(['message'=>'Invalid GPS location.'],400);
        }

        if($accuracy===false || $accuracy<=0 || $accuracy>30){
            wp_send_json_success([
                'accepted'=>false,
                'reason'=>'Waiting for a more accurate GPS signal.',
                'miles'=>round($this->odometer_total_miles(get_current_user_id()),2),
                'xpAwarded'=>0,
            ]);
        }

        $user_id=get_current_user_id();
        $now=time();
        $last=get_user_meta($user_id,'_tng_odometer_last_point',true);
        $current=[
            'lat'=>(float)$latitude,
            'lng'=>(float)$longitude,
            'accuracy'=>(float)$accuracy,
            'server_time'=>$now,
            'client_time'=>$client_time,
            'post_id'=>$post_id,
        ];

        /*
         * First reliable point starts the session. It does not add distance.
         * A stale point is also treated as a new session to prevent a jump
         * between visits from being counted.
         */
        if(
            !is_array($last) ||
            empty($last['server_time']) ||
            ($now-absint($last['server_time']))>900
        ){
            update_user_meta($user_id,'_tng_odometer_last_point',$current);
            wp_send_json_success([
                'accepted'=>false,
                'reason'=>'Odometer started.',
                'miles'=>round($this->odometer_total_miles($user_id),2),
                'xpAwarded'=>0,
            ]);
        }

        if(
            $client_time &&
            !empty($last['client_time']) &&
            $client_time<=absint($last['client_time'])
        ){
            wp_send_json_success([
                'accepted'=>false,
                'reason'=>'Duplicate GPS update ignored.',
                'miles'=>round($this->odometer_total_miles($user_id),2),
                'xpAwarded'=>0,
            ]);
        }

        $elapsed=max(1,$now-absint($last['server_time']));
        $segment_feet=$this->odometer_distance_feet(
            $last['lat'],$last['lng'],$current['lat'],$current['lng']
        );

        /*
         * Ignore GPS drift and unrealistic movement. Fifteen mph allows brisk
         * running while rejecting vehicle-speed jumps for this hiking reward.
         */
        if($segment_feet<12){
            update_user_meta($user_id,'_tng_odometer_last_point',$current);
            wp_send_json_success([
                'accepted'=>false,
                'reason'=>'Movement was below the GPS drift threshold.',
                'miles'=>round($this->odometer_total_miles($user_id),2),
                'xpAwarded'=>0,
            ]);
        }

        $speed_mph=($segment_feet/5280)/($elapsed/3600);
        if($speed_mph>15 || $segment_feet>1320){
            update_user_meta($user_id,'_tng_odometer_last_point',$current);
            wp_send_json_success([
                'accepted'=>false,
                'reason'=>'Unrealistic GPS jump ignored.',
                'miles'=>round($this->odometer_total_miles($user_id),2),
                'xpAwarded'=>0,
            ]);
        }

        update_user_meta($user_id,'_tng_odometer_last_point',$current);

        $result=$this->apply_odometer_segment($user_id,$segment_feet,'gps');
        $result['accepted']=true;

        wp_send_json_success($result);
    }

    public function enqueue_global_player_hud(){
        if(is_admin() || !is_user_logged_in()) return;

        wp_enqueue_style('tng-core-css');
        wp_enqueue_script('tng-player-hud');

        wp_localize_script('tng-player-hud','TNGPlayerHUD',[
            'ajaxUrl'=>admin_url('admin-ajax.php'),
            'nonce'=>wp_create_nonce('tng_player_hud'),
            'pollInterval'=>6000,
            'storageKey'=>'tng_player_hud_user_'.get_current_user_id(),
        ]);
    }

    private function player_hud_state($user_id){
        $user=get_userdata($user_id);
        $data=$this->progression_data($user_id);
        $level=$this->explorer_level_data($data['xp']);
        $achievement=$this->latest_player_achievement($user_id);

        return [
            'userId'=>absint($user_id),
            'displayName'=>$user?$user->display_name:'',
            'avatarUrl'=>get_avatar_url($user_id,['size'=>160]),
            'profileUrl'=>admin_url('profile.php'),
            'xp'=>absint($data['xp']),
            'level'=>absint($level['level']),
            'progress'=>absint($level['progress']),
            'remaining'=>absint($level['remaining']),
            'nextLevel'=>absint($level['level']+1),
            'stats'=>[
                'checkpoints'=>absint($data['checkpoints']),
                'trails'=>absint($data['trails']),
                'photos'=>absint($data['photos']),
                'history'=>absint($data['history']),
                'restaurants'=>absint($data['restaurants']),
                'shops'=>absint($data['shops']),
                'concerts'=>absint($data['concerts']),
                'miles'=>(float)$data['miles'],
            ],
            'achievement'=>$achievement,
        ];
    }

    public function ajax_player_hud_state(){
        if(!is_user_logged_in()){
            wp_send_json_error(['message'=>'Authentication required.'],401);
        }

        check_ajax_referer('tng_player_hud','nonce');
        wp_send_json_success($this->player_hud_state(get_current_user_id()));
    }

    public function render_global_player_hud(){
        if(is_admin() || !is_user_logged_in()) return;

        $state=$this->player_hud_state(get_current_user_id());
        ?>
        <div
            class="tng-player-hud"
            data-initial-state="<?php echo esc_attr(wp_json_encode($state)); ?>"
            aria-live="polite"
        >
            <button
                type="button"
                class="tng-player-hud-profile"
                aria-label="Open Explorer profile"
                aria-expanded="false"
            >
                <span class="tng-player-hud-avatar-wrap">
                    <img
                        class="tng-player-hud-avatar"
                        src="<?php echo esc_url($state['avatarUrl']); ?>"
                        alt=""
                    >
                    <span class="tng-player-hud-level"><?php echo esc_html($state['level']); ?></span>
                </span>
            </button>

            <div class="tng-player-hud-xp">
                <div class="tng-player-hud-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr($state['progress']); ?>">
                    <span style="width:<?php echo esc_attr($state['progress']); ?>%"></span>
                </div>
                <span class="tng-player-hud-xp-text">
                    <?php echo esc_html(number_format_i18n($state['xp'])); ?> XP
                </span>
            </div>

            <div class="tng-player-hud-gain" hidden></div>

            <div class="tng-player-hud-backdrop" hidden></div>

            <section class="tng-player-hud-panel" hidden aria-modal="true" role="dialog" aria-label="Explorer statistics">
                <button type="button" class="tng-player-hud-close" aria-label="Close">×</button>

                <div class="tng-player-hud-panel-hero">
                    <img src="<?php echo esc_url($state['avatarUrl']); ?>" alt="">
                    <div>
                        <span class="tng-player-hud-panel-kicker">EXPLORER PROFILE</span>
                        <h2><?php echo esc_html($state['displayName']); ?></h2>
                        <strong>Level <?php echo esc_html($state['level']); ?></strong>
                        <span><?php echo esc_html(number_format_i18n($state['xp'])); ?> Explorer XP</span>
                    </div>
                </div>

                <div class="tng-player-hud-panel-progress">
                    <div class="tng-player-hud-panel-bar">
                        <span style="width:<?php echo esc_attr($state['progress']); ?>%"></span>
                    </div>
                    <span><?php echo esc_html(number_format_i18n($state['remaining'])); ?> XP until Level <?php echo esc_html($state['nextLevel']); ?></span>
                </div>

                <div class="tng-player-hud-stats">
                    <div><span>📍</span><strong data-stat="checkpoints"><?php echo esc_html($state['stats']['checkpoints']); ?></strong><small>Checkpoints</small></div>
                    <div><span>🥾</span><strong data-stat="trails"><?php echo esc_html($state['stats']['trails']); ?></strong><small>Trails</small></div>
                    <div><span>📸</span><strong data-stat="photos"><?php echo esc_html($state['stats']['photos']); ?></strong><small>Photos</small></div>
                    <div><span>🏛️</span><strong data-stat="history"><?php echo esc_html($state['stats']['history']); ?></strong><small>Historic Sites</small></div>
                    <div><span>🍽️</span><strong data-stat="restaurants"><?php echo esc_html($state['stats']['restaurants']); ?></strong><small>Restaurants</small></div>
                    <div><span>🛍️</span><strong data-stat="shops"><?php echo esc_html($state['stats']['shops']); ?></strong><small>Shops</small></div>
                    <div><span>🎵</span><strong data-stat="concerts"><?php echo esc_html($state['stats']['concerts']); ?></strong><small>Concerts</small></div>
                    <div><span>👣</span><strong data-stat="miles"><?php echo esc_html(number_format_i18n($state['stats']['miles'],2)); ?></strong><small>Miles</small></div>
                </div>

                <div class="tng-player-hud-achievement">
                    <span>🏆</span>
                    <div>
                        <small>LATEST ACHIEVEMENT</small>
                        <strong><?php echo esc_html($state['achievement']['title']); ?></strong>
                        <span><?php echo esc_html($state['achievement']['description']); ?></span>
                    </div>
                </div>

                <a class="tng-player-hud-account-link" href="<?php echo esc_url($state['profileUrl']); ?>">Account settings</a>
            </section>

            <section class="tng-level-up-overlay" hidden aria-modal="true" role="dialog" aria-label="Level up">
                <div class="tng-level-up-rays"></div>
                <span class="tng-level-up-kicker">LEVEL UP!</span>
                <div class="tng-level-up-orb">
                    <span class="tng-level-up-number"><?php echo esc_html($state['level']); ?></span>
                </div>
                <h2>Explorer Level <span><?php echo esc_html($state['level']); ?></span></h2>
                <p>Your adventure is growing.</p>
                <div class="tng-level-up-actions">
                    <button type="button" class="tng-level-up-stats">View stats</button>
                    <button type="button" class="tng-level-up-continue">Continue</button>
                </div>
            </section>
        </div>
        <?php
    }

    private function progression_settings(){
        return wp_parse_args(get_option('tng_progression_settings',[]),[
            'points_type'=>'explorer-xp',
            'radius'=>30,
            'checkpoint'=>25,
            'photo'=>25,
            'trail'=>500,
            'history'=>75,
            'restaurant'=>50,
            'shop'=>50,
            'concert'=>150,
        ]);
    }

    public function sanitize_progression_settings($input){
        $out=[];
        $out['points_type']=sanitize_key($input['points_type']??'explorer-xp');
        $out['radius']=max(5,min(500,absint($input['radius']??30)));
        foreach(['checkpoint','photo','trail','history','restaurant','shop','concert'] as $key){
            $out[$key]=max(0,absint($input[$key]??0));
        }
        return $out;
    }

    public function register_gamipress_events($triggers){
        $triggers['TN Game']=[
            'tng_checkpoint_claimed'=>__('Claim a TN Game checkpoint','tn-game-core'),
            'tng_checkpoint_photo_uploaded'=>__('Upload a checkpoint photo','tn-game-core'),
            'tng_trail_completed'=>__('Complete a TN Game trail','tn-game-core'),
            'tng_history_visited'=>__('Visit a historic site','tn-game-core'),
            'tng_restaurant_visited'=>__('Visit a restaurant','tn-game-core'),
            'tng_shop_visited'=>__('Visit a local shop','tn-game-core'),
            'tng_concert_attended'=>__('Attend a concert','tn-game-core'),
        ];
        return $triggers;
    }

    private function award_progression($user_id,$points,$unique_key,$event,$object_id,$category){
        if(!function_exists('gamipress_award_points_to_user')){
            return new WP_Error('gamipress_missing','GamiPress is not active.');
        }

        $meta_key='_tng_reward_'.sanitize_key($unique_key);
        if(get_user_meta($user_id,$meta_key,true)){
            return new WP_Error('already_awarded','This reward has already been earned.');
        }

        $settings=$this->progression_settings();
        $points=max(0,absint($points));
        if($points>0){
            gamipress_award_points_to_user($user_id,$points,sanitize_key($settings['points_type']));
        }

        update_user_meta($user_id,$meta_key,current_time('mysql'));

        $count_key='_tng_total_'.sanitize_key($category);
        update_user_meta(
            $user_id,
            $count_key,
            absint(get_user_meta($user_id,$count_key,true))+1
        );

        do_action($event,$user_id,absint($object_id));

        $balance=function_exists('gamipress_get_user_points')
            ? absint(gamipress_get_user_points($user_id,sanitize_key($settings['points_type'])))
            : 0;

        return [
            'points_awarded'=>$points,
            'new_balance'=>$balance,
        ];
    }

    private function maybe_complete_trail($user_id,$activity_id){
        $activity_id=absint($activity_id);
        if(!$activity_id || get_post_type($activity_id)!=='st_activity'){
            return ['completed'=>false];
        }

        $sights=$this->top_sights($activity_id);
        if(empty($sights)){
            return ['completed'=>false];
        }

        foreach($sights as $sight){
            if(!get_user_meta($user_id,'_tng_reward_checkpoint_'.$sight['id'],true)){
                return ['completed'=>false];
            }
        }

        $settings=$this->progression_settings();
        $result=$this->award_progression(
            $user_id,
            absint($settings['trail']),
            'trail_complete_'.$activity_id,
            'tng_trail_completed',
            $activity_id,
            'trails'
        );

        if(is_wp_error($result)){
            if($result->get_error_code()==='already_awarded'){
                return ['completed'=>true,'already_completed'=>true,'points_awarded'=>0];
            }
            return ['completed'=>false,'error'=>$result->get_error_message()];
        }

        return [
            'completed'=>true,
            'already_completed'=>false,
            'points_awarded'=>$result['points_awarded'],
            'new_balance'=>$result['new_balance'],
        ];
    }

    private function field($name,$post_id=null){
        $post_id=$post_id?:get_the_ID();
        if(function_exists('get_field')){
            $value=get_field($name,$post_id);
            if($value!==null && $value!=='') return $value;
        }
        return get_post_meta($post_id,$name,true);
    }

    private function top_sights($post_id){
        $items=$this->field('related_top_sights',$post_id);
        if(empty($items)||!is_array($items)) return [];
        $settings=$this->progression_settings();
        $default_points=max(1,absint($settings['checkpoint']));
        $s=[];
        foreach($items as $item){
            $id=is_object($item)?$item->ID:intval($item);
            if(!$id) continue;
            $lat=$this->field('sight_latitude',$id);
            $lng=$this->field('sight_longitude',$id);
            if($lat===''||$lng===''||$lat===null||$lng===null) continue;
            $pts=$this->field('sight_points',$id);
            $s[]=[
                'id'=>$id,
                'title'=>get_the_title($id),
                'lat'=>(float)$lat,
                'lng'=>(float)$lng,
                'type'=>$this->field('sight_type',$id)?:'Top Sight',
                'description'=>wp_strip_all_tags($this->field('sight_short_description',$id)?:''),
                'points'=>$pts!=='' && $pts!==null ? intval($pts) : $default_points,
                'url'=>get_permalink($id),
                'image'=>get_the_post_thumbnail_url($id,'medium')?:''
            ];
        }
        return $s;
    }

    private function all_top_sights_for_dev(){
        if(!current_user_can('manage_options')) return [];
        $posts=get_posts([
            'post_type'=>'top-sights',
            'post_status'=>['publish','draft','private'],
            'numberposts'=>-1,
            'orderby'=>'title',
            'order'=>'ASC'
        ]);
        $settings=$this->progression_settings();
        $default_points=max(1,absint($settings['checkpoint']));
        $items=[];
        foreach($posts as $post){
            $lat=$this->field('sight_latitude',$post->ID);
            $lng=$this->field('sight_longitude',$post->ID);
            if($lat===''||$lng===''||$lat===null||$lng===null) continue;
            $pts=$this->field('sight_points',$post->ID);
            $items[]=[
                'id'=>$post->ID,
                'title'=>get_the_title($post->ID),
                'lat'=>(float)$lat,
                'lng'=>(float)$lng,
                'type'=>$this->field('sight_type',$post->ID)?:'Top Sight',
                'description'=>wp_strip_all_tags($this->field('sight_short_description',$post->ID)?:''),
                'points'=>$pts!==''&&$pts!==null?intval($pts):$default_points,
                'url'=>get_permalink($post->ID),
                'editUrl'=>get_edit_post_link($post->ID,'raw')?:'',
                'image'=>get_the_post_thumbnail_url($post->ID,'medium')?:''
            ];
        }
        return $items;
    }

    private function distance_feet($lat1,$lng1,$lat2,$lng2){
        $earth=6371000;
        $lat1r=deg2rad($lat1);
        $lat2r=deg2rad($lat2);
        $dlat=deg2rad($lat2-$lat1);
        $dlng=deg2rad($lng2-$lng1);
        $a=sin($dlat/2)**2+cos($lat1r)*cos($lat2r)*sin($dlng/2)**2;
        $meters=$earth*2*atan2(sqrt($a),sqrt(1-$a));
        return $meters*3.28084;
    }

    private function user_claimed_ids($user_id,$sights){
        if(!$user_id) return [];
        $claimed=[];
        foreach($sights as $sight){
            if(get_user_meta($user_id,'_tng_reward_checkpoint_'.$sight['id'],true)){
                $claimed[]=(string)$sight['id'];
            }
        }
        return $claimed;
    }

    private function user_xp($user_id){
        if(!$user_id || !function_exists('gamipress_get_user_points')) return 0;
        $settings=$this->progression_settings();
        return absint(gamipress_get_user_points($user_id,$settings['points_type']));
    }


    private function deduct_points_from_user($user_id,$points,$points_type){
        $points=max(0,absint($points));
        if(!$points) return true;

        if(function_exists('gamipress_deduct_points_to_user')){
            gamipress_deduct_points_to_user($user_id,$points,$points_type);
            return true;
        }

        /*
         * Compatibility fallback for older GamiPress versions.
         * GamiPress accepts a negative adjustment through the award function.
         */
        if(function_exists('gamipress_award_points_to_user')){
            gamipress_award_points_to_user($user_id,-$points,$points_type);
            return true;
        }

        return false;
    }

    private function decrement_progression_total($user_id,$category){
        $key='_tng_total_'.sanitize_key($category);
        $current=absint(get_user_meta($user_id,$key,true));
        update_user_meta($user_id,$key,max(0,$current-1));
    }

    /**
     * Administrator-only developer reset.
     *
     * Removes a checkpoint claim and reverses its XP. If that checkpoint was
     * part of a completed trail, the trail-completion reward is also reversed
     * so the complete trail can be tested again.
     */
    public function ajax_unclaim_checkpoint(){
        check_ajax_referer(self::NONCE_ACTION,'nonce');

        if(!is_user_logged_in() || !current_user_can('manage_options')){
            wp_send_json_error(['message'=>'Administrator access is required.'],403);
        }

        $checkpoint_id=absint($_POST['checkpoint_id']??0);
        $activity_id=absint($_POST['activity_id']??0);

        if(!$checkpoint_id || get_post_type($checkpoint_id)!=='top-sights'){
            wp_send_json_error(['message'=>'Invalid checkpoint.'],400);
        }

        $user_id=get_current_user_id();
        $checkpoint_key='_tng_reward_checkpoint_'.$checkpoint_id;

        if(!get_user_meta($user_id,$checkpoint_key,true)){
            wp_send_json_error(['message'=>'This checkpoint is not currently claimed.'],409);
        }

        $settings=$this->progression_settings();
        $points_type=sanitize_key($settings['points_type']);

        $checkpoint_points=absint($this->field('sight_points',$checkpoint_id));
        if(!$checkpoint_points){
            $checkpoint_points=max(1,absint($settings['checkpoint']));
        }

        if(!$this->deduct_points_from_user($user_id,$checkpoint_points,$points_type)){
            wp_send_json_error(['message'=>'GamiPress is not active.'],500);
        }

        delete_user_meta($user_id,$checkpoint_key);
        $this->decrement_progression_total($user_id,'checkpoints');

        $trail_reset=false;
        $trail_points=0;

        if($activity_id && get_post_type($activity_id)==='st_activity'){
            $trail_key='_tng_reward_trail_complete_'.$activity_id;

            if(get_user_meta($user_id,$trail_key,true)){
                $trail_points=absint($settings['trail']);
                $this->deduct_points_from_user($user_id,$trail_points,$points_type);
                delete_user_meta($user_id,$trail_key);
                $this->decrement_progression_total($user_id,'trails');
                $trail_reset=true;
            }
        }

        $balance=function_exists('gamipress_get_user_points')
            ? absint(gamipress_get_user_points($user_id,$points_type))
            : 0;

        do_action('tng_core_checkpoint_unclaimed',$user_id,$checkpoint_id,$activity_id);

        wp_send_json_success([
            'message'=>'Checkpoint reset for testing.',
            'checkpoint_id'=>$checkpoint_id,
            'checkpoint_points_removed'=>$checkpoint_points,
            'trail_reset'=>$trail_reset,
            'trail_points_removed'=>$trail_points,
            'new_balance'=>$balance,
        ]);
    }

    public function ajax_claim_checkpoint(){
        check_ajax_referer(self::NONCE_ACTION,'nonce');

        if(!is_user_logged_in()){
            wp_send_json_error(['message'=>'Please sign in to claim this checkpoint.'],401);
        }
        if(!function_exists('gamipress_award_points_to_user')){
            wp_send_json_error(['message'=>'GamiPress is not active.'],500);
        }

        $checkpoint_id=absint($_POST['checkpoint_id']??0);
        $player_lat=isset($_POST['latitude'])?(float)$_POST['latitude']:0;
        $player_lng=isset($_POST['longitude'])?(float)$_POST['longitude']:0;

        if(!$checkpoint_id || !$player_lat || !$player_lng){
            wp_send_json_error(['message'=>'Missing checkpoint or location information.'],400);
        }
        if(get_post_type($checkpoint_id)!=='top-sights'){
            wp_send_json_error(['message'=>'Invalid checkpoint.'],400);
        }

        $checkpoint_lat=(float)$this->field('sight_latitude',$checkpoint_id);
        $checkpoint_lng=(float)$this->field('sight_longitude',$checkpoint_id);
        if(!$checkpoint_lat || !$checkpoint_lng){
            wp_send_json_error(['message'=>'Checkpoint coordinates are missing.'],400);
        }

        $settings=$this->progression_settings();
        $radius=max(5,absint($settings['radius']));
        $distance=$this->distance_feet($player_lat,$player_lng,$checkpoint_lat,$checkpoint_lng);
        if($distance>$radius){
            wp_send_json_error([
                'message'=>'Move closer to claim this checkpoint.',
                'distance_feet'=>round($distance),
                'required_feet'=>$radius
            ],403);
        }

        $user_id=get_current_user_id();
        $points=absint($this->field('sight_points',$checkpoint_id));
        if(!$points) $points=max(1,absint($settings['checkpoint']));

        $checkpoint_result=$this->award_progression(
            $user_id,
            $points,
            'checkpoint_'.$checkpoint_id,
            'tng_checkpoint_claimed',
            $checkpoint_id,
            'checkpoints'
        );

        if(is_wp_error($checkpoint_result)){
            wp_send_json_error(['message'=>$checkpoint_result->get_error_message()],409);
        }

        do_action('tng_core_checkpoint_claimed',$user_id,$checkpoint_id,$points);

        $activity_id=absint($_POST['activity_id']??0);
        $trail_result=$this->maybe_complete_trail($user_id,$activity_id);

        $balance=$checkpoint_result['new_balance'];
        if(!empty($trail_result['new_balance'])){
            $balance=$trail_result['new_balance'];
        }

        wp_send_json_success([
            'message'=>'Checkpoint claimed!',
            'checkpoint_id'=>$checkpoint_id,
            'points_awarded'=>$points,
            'new_balance'=>$balance,
            'distance_feet'=>round($distance),
            'trail_completed'=>!empty($trail_result['completed']),
            'trail_already_completed'=>!empty($trail_result['already_completed']),
            'trail_points_awarded'=>absint($trail_result['points_awarded']??0)
        ]);
    }


    public function ajax_award_action(){
        check_ajax_referer(self::NONCE_ACTION,'nonce');
        if(!is_user_logged_in()){
            wp_send_json_error(['message'=>'Please sign in to earn XP.'],401);
        }

        $action=sanitize_key($_POST['game_action']??'');
        $object_id=absint($_POST['object_id']??0);
        $map=[
            'checkpoint_photo'=>['photo','tng_checkpoint_photo_uploaded','photos'],
            'trail_complete'=>['trail','tng_trail_completed','trails'],
            'history_visit'=>['history','tng_history_visited','history'],
            'restaurant_visit'=>['restaurant','tng_restaurant_visited','restaurants'],
            'shop_visit'=>['shop','tng_shop_visited','shops'],
            'concert_attend'=>['concert','tng_concert_attended','concerts'],
        ];

        if(!$object_id || !isset($map[$action])){
            wp_send_json_error(['message'=>'Invalid game action.'],400);
        }

        [$points_key,$event,$category]=$map[$action];
        $settings=$this->progression_settings();
        $result=$this->award_progression(
            get_current_user_id(),
            absint($settings[$points_key]),
            $action.'_'.$object_id,
            $event,
            $object_id,
            $category
        );

        if(is_wp_error($result)){
            wp_send_json_error(['message'=>$result->get_error_message()],409);
        }

        wp_send_json_success($result);
    }

    private function progression_data($user_id){
        $settings=$this->progression_settings();
        return [
            'xp'=>function_exists('gamipress_get_user_points')
                ? absint(gamipress_get_user_points($user_id,sanitize_key($settings['points_type'])))
                : 0,
            'checkpoints'=>absint(get_user_meta($user_id,'_tng_total_checkpoints',true)),
            'photos'=>absint(get_user_meta($user_id,'_tng_total_photos',true)),
            'trails'=>absint(get_user_meta($user_id,'_tng_total_trails',true)),
            'history'=>absint(get_user_meta($user_id,'_tng_total_history',true)),
            'restaurants'=>absint(get_user_meta($user_id,'_tng_total_restaurants',true)),
            'shops'=>absint(get_user_meta($user_id,'_tng_total_shops',true)),
            'concerts'=>absint(get_user_meta($user_id,'_tng_total_concerts',true)),
            'miles'=>round($this->odometer_total_miles($user_id),2),
        ];
    }

    public function xp_balance_shortcode(){
        if(!is_user_logged_in()) return '<span class="tng-login-message">Sign in to view Explorer XP.</span>';
        $data=$this->progression_data(get_current_user_id());
        return '<span class="tng-xp-balance">'.number_format_i18n($data['xp']).' Explorer XP</span>';
    }

    private function explorer_level_data($xp){
        $xp=max(0,absint($xp));

        // Progressive milestones: early levels arrive quickly, later levels take longer.
        $thresholds=[0,100,300,600,1000,1500,2200,3000,4000,5500,7500,10000];
        $level=1;
        $current=0;
        $next=100;

        foreach($thresholds as $index=>$threshold){
            if($xp >= $threshold){
                $level=$index+1;
                $current=$threshold;
                $next=$thresholds[$index+1]??($threshold+max(2500,(int)round($threshold*.35)));
            } else {
                break;
            }
        }

        $span=max(1,$next-$current);
        $progress=max(0,min(100,(int)round((($xp-$current)/$span)*100)));

        return [
            'level'=>$level,
            'current_threshold'=>$current,
            'next_threshold'=>$next,
            'remaining'=>max(0,$next-$xp),
            'progress'=>$progress,
        ];
    }

    private function latest_player_achievement($user_id){
        $all=get_user_meta($user_id);
        $latest_time=0;
        $latest_title='';

        foreach($all as $key=>$values){
            if(strpos($key,'_tng_reward_trail_complete_')!==0) continue;

            $post_id=absint(substr($key,strlen('_tng_reward_trail_complete_')));
            if(!$post_id) continue;

            $raw=is_array($values)?reset($values):$values;
            $timestamp=is_numeric($raw)?absint($raw):strtotime((string)$raw);
            if(!$timestamp) $timestamp=1;

            if($timestamp >= $latest_time){
                $latest_time=$timestamp;
                $latest_title=get_the_title($post_id);
            }
        }

        if($latest_title){
            return [
                'title'=>$latest_title.' Completed',
                'description'=>'Trail achievement unlocked',
            ];
        }

        return [
            'title'=>'Your next achievement awaits',
            'description'=>'Complete every checkpoint on a trail to unlock it.',
        ];
    }

    public function player_progress_shortcode(){
        wp_enqueue_style('tng-core-css');

        if(!is_user_logged_in()){
            return '<section class="tng-progress-card tng-progress-login"><div class="tng-progress-section"><span class="tng-progress-kicker">Explorer Progress</span><h2>Start your adventure</h2><p>Sign in to track Explorer XP, checkpoints, trails, photos, and discoveries.</p></div></section>';
        }

        $user=wp_get_current_user();
        $progress_avatar=get_avatar_url($user->ID,['size'=>160]);
        $data=$this->progression_data($user->ID);
        $level=$this->explorer_level_data($data['xp']);
        $next_level_target=absint($data['xp'])+absint($level['remaining']);
        $achievement=$this->latest_player_achievement($user->ID);
        $items=[
            ['📍','checkpoints','Checkpoints'],
            ['🥾','trails','Trails'],
            ['📸','photos','Photos'],
            ['🏛️','history','Historic Sites'],
            ['🍽️','restaurants','Restaurants'],
            ['🛍️','shops','Shops'],
            ['🎵','concerts','Concerts'],
            ['👣','miles','Miles'],
        ];

        ob_start(); ?>
        <section class="tng-progress-card" aria-label="Explorer progress">
            <div class="tng-progress-section tng-progress-overview">
                <div class="tng-progress-profile-header">
                    <img
                        class="tng-progress-avatar"
                        src="<?php echo esc_url($progress_avatar); ?>"
                        alt=""
                    >

                    <div class="tng-progress-profile-copy">
                        <span class="tng-progress-kicker">
                            <?php echo esc_html($user->display_name); ?>
                        </span>

                        <div class="tng-progress-level">
                            <small>LEVEL</small>
                            <span><?php echo esc_html($level['level']); ?></span>
                        </div>
                    </div>
                </div>

                <div class="tng-progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr($level['progress']); ?>">
                    <span style="width:<?php echo esc_attr($level['progress']); ?>%"></span>
                </div>

                <div class="tng-progress-until">
                    <strong>
                        <?php echo esc_html(number_format_i18n($data['xp'])); ?>
                        <span>/</span>
                        <?php echo esc_html(number_format_i18n($next_level_target)); ?> XP
                    </strong>
                    <small><?php echo esc_html($level['progress']); ?>%</small>
                </div>
            </div>

            <div class="tng-progress-section tng-progress-stats" aria-label="Explorer statistics">
                <?php foreach($items as $item): ?>
                    <div class="tng-progress-row">
                        <span class="tng-progress-row-icon" aria-hidden="true"><?php echo esc_html($item[0]); ?></span>
                        <span class="tng-progress-row-label"><?php echo esc_html($item[2]); ?></span>
                        <strong<?php echo $item[1]==='miles'?' data-tng-odometer-miles':''; ?>>
                            <?php
                            echo esc_html(
                                $item[1]==='miles'
                                    ? number_format_i18n((float)$data[$item[1]],2)
                                    : number_format_i18n($data[$item[1]])
                            );
                            ?>
                        </strong>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if(
                is_singular('st_activity') &&
                $this->is_hiking_trail_activity(get_queried_object_id())
            ): ?>
                <div class="tng-progress-section tng-progress-map-action">
                    <a
                        class="tng-progress-open-map"
                        href="<?php echo esc_url(
                            add_query_arg(
                                'trail_game_map',
                                '1',
                                get_permalink(get_queried_object_id())
                            )
                        ); ?>"
                    >
                        <span aria-hidden="true">⌖</span>
                        <span>Open Map</span>
                    </a>
                </div>
            <?php endif; ?>

            <div class="tng-progress-section tng-progress-achievement">
                <span class="tng-progress-achievement-icon" aria-hidden="true">🏆</span>
                <div>
                    <span class="tng-progress-achievement-label">Latest Achievement</span>
                    <strong><?php echo esc_html($achievement['title']); ?></strong>
                    <small><?php echo esc_html($achievement['description']); ?></small>
                </div>
            </div>
        </section>
        <?php return ob_get_clean();
    }


    /**
     * Move the Explorer Progress card into Traveler's activity sidebar.
     *
     * The shortcode can remain where it is currently placed. On activity
     * pages this script relocates that existing card above the booking box,
     * preventing duplicate cards and surviving delayed theme rendering.
     */
    public function progress_sidebar_script(){
        if(!is_singular('st_activity')) return;
        ?>
        <script id="tng-progress-sidebar-script">
        (function(){
            'use strict';

            function findSidebarTarget(){
                var selectors=[
                    '.st-form-book-wrapper',
                    '.st-form-booking-wrapper',
                    '.st-booking-form',
                    '.booking-form',
                    '.single-st-content .col-lg-4 .widget-box',
                    '.single-st-content .col-md-4 .widget-box',
                    '.single-st-content .col-xl-4 .widget-box',
                    '.st-sidebar .widget-box'
                ];

                for(var i=0;i<selectors.length;i++){
                    var target=document.querySelector(selectors[i]);
                    if(target && !target.classList.contains('tng-progress-card')){
                        return target;
                    }
                }

                return null;
            }

            function findSidebarColumn(target){
                if(target && target.parentElement) return target.parentElement;

                return document.querySelector(
                    '.single-st-content .col-lg-4,'+
                    '.single-st-content .col-md-4,'+
                    '.single-st-content .col-xl-4,'+
                    '.st-sidebar'
                );
            }

            function moveProgressCard(){
                var card=document.querySelector('.tng-progress-card');
                if(!card) return false;

                var target=findSidebarTarget();
                var column=findSidebarColumn(target);

                if(target && target.parentNode){
                    if(card.nextElementSibling!==target || card.parentNode!==target.parentNode){
                        target.parentNode.insertBefore(card,target);
                    }
                }else if(column){
                    if(card.parentNode!==column || column.firstElementChild!==card){
                        column.insertBefore(card,column.firstChild);
                    }
                }else{
                    return false;
                }

                card.classList.add('tng-progress-sidebar');
                card.setAttribute('data-tng-sidebar','true');
                return true;
            }

            function initialize(){
                if(moveProgressCard()) return;

                var attempts=0;
                var timer=window.setInterval(function(){
                    attempts++;
                    if(moveProgressCard() || attempts>=20){
                        window.clearInterval(timer);
                    }
                },250);

                var observer=new MutationObserver(function(){
                    if(moveProgressCard()){
                        observer.disconnect();
                    }
                });

                observer.observe(document.body,{
                    childList:true,
                    subtree:true
                });

                window.setTimeout(function(){
                    observer.disconnect();
                },7000);
            }

            if(document.readyState==='loading'){
                document.addEventListener('DOMContentLoaded',initialize);
            }else{
                initialize();
            }
        })();
        </script>
        <?php
    }

    public function trail_stats_shortcode($atts=[]){
        wp_enqueue_style('tng-core-css');
        $id=get_the_ID();
        $stats=[['📏','Length',$this->field('trail_length',$id)],['⛰️','Elevation Gain',$this->field('trail_elevation',$id)],['⏱️','Estimated Time',$this->field('trail_time',$id)],['🔁','Route Type',$this->field('trail_type',$id)]];
        $has=false; foreach($stats as $st){if(!empty($st[2]))$has=true;} if(!$has)return'';
        ob_start(); ?><section class="tng-trail-stats"><?php foreach($stats as $st):if(empty($st[2]))continue;?><div class="tng-trail-stat"><div class="tng-trail-stat-icon"><?php echo esc_html($st[0]);?></div><div><div class="tng-trail-stat-value"><?php echo esc_html($st[2]);?></div><div class="tng-trail-stat-label"><?php echo esc_html($st[1]);?></div></div></div><?php endforeach;?></section><?php return ob_get_clean();
    }

    public function trail_top_sights_shortcode($atts=[]){
        wp_enqueue_style('tng-core-css');
        $items=$this->top_sights(get_the_ID()); if(empty($items))return'';
        ob_start();?><section class="tng-top-sights-section"><div class="tng-section-heading"><h2>Trail Checkpoints</h2></div><div class="tng-top-sights-list"><?php foreach($items as $s):?><a class="tng-top-sight-item" href="<?php echo esc_url($s['url']);?>"><div class="tng-top-sight-thumb"><?php if($s['image']):?><img src="<?php echo esc_url($s['image']);?>" alt=""><?php else:?><span>📍</span><?php endif;?></div><div class="tng-top-sight-content"><strong><?php echo esc_html($s['title']);?></strong><span><?php echo esc_html($s['type']);?></span></div><div class="tng-top-sight-points">+<?php echo esc_html($s['points']);?></div><div class="tng-top-sight-arrow">›</div></a><?php endforeach;?></div></section><?php return ob_get_clean();
    }

    public function trail_game_button_shortcode($atts=[]){
        wp_enqueue_style('tng-core-css');
        $atts=shortcode_atts(['label'=>'Open Map'],$atts);
        return '<a class="tng-open-game-map-btn" href="'.esc_url(add_query_arg('trail_game_map','1',get_permalink(get_the_ID()))).'">'.esc_html($atts['label']).'</a>';
    }


    /**
     * Determine whether the current full-map request should use developer mode.
     *
     * Explicit URL values take priority:
     * - tng_dev=1 enables it for administrators.
     * - tng_dev=0 disables it temporarily.
     *
     * Otherwise the saved automatic developer-mode setting is used.
     */


    public function management_admin_assets($hook){
        if(strpos($hook,'tn-game')===false) return;
        wp_enqueue_style(
            'tng-core-management',
            TNG_OS_URL.'assets/tng-core.css',
            [],
            self::VERSION
        );
    }

    private function admin_page_url($slug,$args=[]){
        return add_query_arg($args,admin_url('admin.php?page='.$slug));
    }

    private function get_management_counts(){
        $activity_counts=wp_count_posts('st_activity');
        $sight_counts=wp_count_posts('top-sights');
        $user_count=count_users();

        return [
            'activities'=>absint($activity_counts->publish??0),
            'top_sights'=>absint($sight_counts->publish??0),
            'users'=>absint($user_count['total_users']??0),
            'media'=>absint(wp_count_attachments('image')->inherit??0),
        ];
    }

    private function management_status($state,$label,$detail=''){
        $allowed=['good','warning','bad','neutral'];
        if(!in_array($state,$allowed,true)) $state='neutral';
        return [
            'state'=>$state,
            'label'=>$label,
            'detail'=>$detail,
        ];
    }

    private function latest_scan_age($option_name){
        $scan=get_option($option_name,[]);
        if(empty($scan['generated_at'])) return 'Not scanned';
        return human_time_diff(strtotime($scan['generated_at']),current_time('timestamp')).' ago';
    }

    public function system_report_page(){
        if(!current_user_can('manage_options')) return;

        $counts=$this->get_management_counts();
        $db=get_option(self::HEALTH_SCAN_OPTION,[]);
        $sights=get_option(self::TOP_SIGHT_AUDIT_OPTION,[]);
        $trails=get_option(self::TRAIL_AUDIT_OPTION,[]);
        $gamipress=get_option(self::GAMIPRESS_AUDIT_OPTION,[]);
        $gallery=get_option(self::GALLERY_LAST_SCAN,[]);

        $gallery_bad=absint($gallery['summary']['malformed_activities']??0);
        $sight_warn=absint($sights['summary']['issues']??0);
        $trail_warn=absint($trails['summary']['issues']??0);
        $gp_warn=absint($gamipress['summary']['issues']??0);
        $db_warn=absint($db['summary']['issues']??0);

        $cards=[
            [
                'title'=>'Activities',
                'value'=>$counts['activities'],
                'status'=>$this->management_status($counts['activities']?'good':'warning',$counts['activities']?'Available':'No Activities'),
                'url'=>$this->admin_page_url('tn-game-trail-audit'),
            ],
            [
                'title'=>'Top Sights',
                'value'=>$counts['top_sights'],
                'status'=>$this->management_status($sight_warn?'warning':'good',$sight_warn?$sight_warn.' audit issues':'Healthy'),
                'url'=>$this->admin_page_url('tn-game-top-sight-audit'),
            ],
            [
                'title'=>'Trail Audit',
                'value'=>$trail_warn,
                'status'=>$this->management_status($trail_warn?'warning':'good',$trail_warn?'Issues found':'Healthy'),
                'url'=>$this->admin_page_url('tn-game-trail-audit'),
            ],
            [
                'title'=>'Photo Galleries',
                'value'=>$gallery_bad,
                'status'=>$this->management_status($gallery_bad?'warning':'good',$gallery_bad?'Need repair':'Healthy'),
                'url'=>$this->admin_page_url('tn-game-gallery-repair'),
            ],
            [
                'title'=>'Database',
                'value'=>$db_warn,
                'status'=>$this->management_status($db_warn?'warning':'good',$db_warn?'Warnings':'Healthy'),
                'url'=>$this->admin_page_url('tn-game-database-health'),
            ],
            [
                'title'=>'GamiPress',
                'value'=>$gp_warn,
                'status'=>$this->management_status($gp_warn?'warning':'good',$gp_warn?'Warnings':'Healthy'),
                'url'=>$this->admin_page_url('tn-game-gamipress-audit'),
            ],
        ];

        $auto_dev=(bool)get_option(self::OPTION_AUTO_DEV,0);
        ?>
        <div class="wrap tng-management-wrap">
            <div class="tng-management-hero">
                <div>
                    <span class="tng-management-kicker">TN GAME CORE</span>
                    <h1>System Report</h1>
                    <p>Content health, gameplay data, diagnostics, repairs, and developer tools.</p>
                </div>
                <div class="tng-management-version">
                    <strong>v<?php echo esc_html(self::VERSION); ?></strong>
                    <span><?php echo $auto_dev?'Developer mode enabled':'Player mode default'; ?></span>
                </div>
            </div>

            <div class="tng-system-grid">
                <?php foreach($cards as $card): ?>
                    <a class="tng-system-card" href="<?php echo esc_url($card['url']); ?>">
                        <span class="tng-system-card-label"><?php echo esc_html($card['title']); ?></span>
                        <strong><?php echo esc_html(number_format_i18n($card['value'])); ?></strong>
                        <span class="tng-health-badge is-<?php echo esc_attr($card['status']['state']); ?>">
                            <?php echo esc_html($card['status']['label']); ?>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>

            <div class="tng-management-columns">
                <section class="tng-management-panel">
                    <h2>Platform overview</h2>
                    <div class="tng-detail-list">
                        <div><span>Published Activities</span><strong><?php echo esc_html($counts['activities']); ?></strong></div>
                        <div><span>Published Top Sights</span><strong><?php echo esc_html($counts['top_sights']); ?></strong></div>
                        <div><span>Registered users</span><strong><?php echo esc_html($counts['users']); ?></strong></div>
                        <div><span>Image attachments</span><strong><?php echo esc_html($counts['media']); ?></strong></div>
                        <div><span>PHP</span><strong><?php echo esc_html(PHP_VERSION); ?></strong></div>
                        <div><span>WordPress</span><strong><?php echo esc_html(get_bloginfo('version')); ?></strong></div>
                    </div>
                </section>

                <section class="tng-management-panel">
                    <h2>Latest scans</h2>
                    <div class="tng-detail-list">
                        <div><span>Database Health</span><strong><?php echo esc_html($this->latest_scan_age(self::HEALTH_SCAN_OPTION)); ?></strong></div>
                        <div><span>Top Sight Audit</span><strong><?php echo esc_html($this->latest_scan_age(self::TOP_SIGHT_AUDIT_OPTION)); ?></strong></div>
                        <div><span>Trail Audit</span><strong><?php echo esc_html($this->latest_scan_age(self::TRAIL_AUDIT_OPTION)); ?></strong></div>
                        <div><span>GamiPress Audit</span><strong><?php echo esc_html($this->latest_scan_age(self::GAMIPRESS_AUDIT_OPTION)); ?></strong></div>
                        <div><span>Gallery Repair</span><strong><?php echo esc_html($this->latest_scan_age(self::GALLERY_LAST_SCAN)); ?></strong></div>
                    </div>
                </section>
            </div>

            <section class="tng-management-panel">
                <h2>Quick actions</h2>
                <div class="tng-quick-actions">
                    <a class="button button-primary" href="<?php echo esc_url($this->admin_page_url('tn-game-database-health')); ?>">Run Database Health</a>
                    <a class="button" href="<?php echo esc_url($this->admin_page_url('tn-game-top-sight-audit')); ?>">Audit Top Sights</a>
                    <a class="button" href="<?php echo esc_url($this->admin_page_url('tn-game-trail-audit')); ?>">Audit Trails</a>
                    <a class="button" href="<?php echo esc_url($this->admin_page_url('tn-game-gallery-repair')); ?>">Repair Galleries</a>
                    <a class="button" href="<?php echo esc_url($this->admin_page_url('tn-game-core-settings')); ?>">Core Settings</a>
                </div>
            </section>
        </div>
        <?php
    }

    public function developer_mode_page(){
        if(!current_user_can('manage_options')) return;
        $enabled=(bool)get_option(self::OPTION_AUTO_DEV,0);
        ?>
        <div class="wrap tng-management-wrap">
            <h1>Developer Mode</h1>
            <div class="tng-management-panel">
                <p>Developer mode exposes testing controls only to administrators.</p>
                <p><strong>Current status:</strong>
                    <span class="tng-health-badge is-<?php echo $enabled?'good':'neutral'; ?>">
                        <?php echo $enabled?'Enabled for administrators':'Manual URL mode'; ?>
                    </span>
                </p>
                <p>
                    <a class="button button-primary" href="<?php echo esc_url($this->admin_page_url('tn-game-core-settings')); ?>">Change Developer Mode Setting</a>
                    <a class="button" href="<?php echo esc_url($this->admin_page_url('tn-game-core-developer-map')); ?>">Open Developer Map</a>
                </p>
                <h2>URL controls</h2>
                <p><code>&amp;tng_dev=1</code> forces developer mode for an administrator.</p>
                <p><code>&amp;tng_dev=0</code> temporarily forces the normal player view.</p>
            </div>
        </div>
        <?php
    }

    public function route_simulator_page(){
        if(!current_user_can('manage_options')) return;
        $activities=get_posts([
            'post_type'=>'st_activity',
            'post_status'=>'publish',
            'numberposts'=>100,
            'orderby'=>'title',
            'order'=>'ASC',
        ]);
        ?>
        <div class="wrap tng-management-wrap">
            <h1>Route Simulator</h1>
            <div class="tng-management-panel">
                <p>Select a trail to open its full-screen game map in developer mode.</p>
                <div class="tng-route-list">
                    <?php foreach($activities as $activity): ?>
                        <?php
                        $gpx=$this->field('trail_gpx_url',$activity->ID);
                        if(empty($gpx)) continue;
                        $url=add_query_arg(['trail_game_map'=>1,'tng_dev'=>1],get_permalink($activity));
                        ?>
                        <a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener">
                            <strong><?php echo esc_html(get_the_title($activity)); ?></strong>
                            <span>Open simulator ↗</span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php
    }

    private function database_health_scan(){
        global $wpdb;

        $orphan_postmeta=(int)$wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
             LEFT JOIN {$wpdb->posts} p ON p.ID=pm.post_id
             WHERE p.ID IS NULL"
        );

        $orphan_usermeta=(int)$wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->usermeta} um
             LEFT JOIN {$wpdb->users} u ON u.ID=um.user_id
             WHERE u.ID IS NULL"
        );

        $duplicate_gallery_rows=(int)$wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM (
                    SELECT post_id,COUNT(*) row_count
                    FROM {$wpdb->postmeta}
                    WHERE meta_key=%s
                    GROUP BY post_id
                    HAVING COUNT(*)>1
                ) x",
                'gallery'
            )
        );

        $autoload_bytes=(int)$wpdb->get_var(
            "SELECT COALESCE(SUM(LENGTH(option_value)),0)
             FROM {$wpdb->options}
             WHERE autoload IN ('yes','on','auto-on','auto')"
        );

        $expired_transients=(int)$wpdb->get_var(
            "SELECT COUNT(*)
             FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_timeout_%'
               AND CAST(option_value AS UNSIGNED) < UNIX_TIMESTAMP()"
        );

        $issues=0;
        if($orphan_postmeta) $issues++;
        if($orphan_usermeta) $issues++;
        if($duplicate_gallery_rows) $issues++;
        if($autoload_bytes>3*1024*1024) $issues++;
        if($expired_transients>1000) $issues++;

        return [
            'generated_at'=>current_time('mysql'),
            'summary'=>['issues'=>$issues],
            'checks'=>[
                ['label'=>'Orphaned post meta','value'=>$orphan_postmeta,'state'=>$orphan_postmeta?'warning':'good'],
                ['label'=>'Orphaned user meta','value'=>$orphan_usermeta,'state'=>$orphan_usermeta?'warning':'good'],
                ['label'=>'Activities with duplicate gallery rows','value'=>$duplicate_gallery_rows,'state'=>$duplicate_gallery_rows?'warning':'good'],
                ['label'=>'Autoloaded options size','value'=>size_format($autoload_bytes,2),'state'=>$autoload_bytes>3*1024*1024?'warning':'good'],
                ['label'=>'Expired transient timeouts','value'=>$expired_transients,'state'=>$expired_transients>1000?'warning':'good'],
            ],
        ];
    }

    public function handle_database_health_scan(){
        if(!current_user_can('manage_options')) wp_die('Administrator access is required.');
        check_admin_referer('tng_run_database_health');
        update_option(self::HEALTH_SCAN_OPTION,$this->database_health_scan(),false);
        wp_safe_redirect($this->admin_page_url('tn-game-database-health',['tng_notice'=>'scanned']));
        exit;
    }

    public function database_health_page(){
        if(!current_user_can('manage_options')) return;
        $scan=get_option(self::HEALTH_SCAN_OPTION,[]);
        ?>
        <div class="wrap tng-management-wrap">
            <h1>Database Health</h1>
            <div class="tng-management-panel">
                <p>This scan is read-only. It does not delete or alter database records.</p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('tng_run_database_health'); ?>
                    <input type="hidden" name="action" value="tng_run_database_health">
                    <?php submit_button('Run Database Health Scan','primary','submit',false); ?>
                </form>
            </div>
            <?php $this->render_audit_results($scan['checks']??[],'Database checks'); ?>
        </div>
        <?php
    }

    private function top_sight_audit(){
        $posts=get_posts([
            'post_type'=>'top-sights',
            'post_status'=>['publish','draft','private'],
            'numberposts'=>-1,
            'orderby'=>'title',
            'order'=>'ASC',
        ]);

        $rows=[];
        $issues=0;
        foreach($posts as $post){
            $lat=(float)$this->field('sight_latitude',$post->ID);
            $lng=(float)$this->field('sight_longitude',$post->ID);
            $points=absint($this->field('sight_points',$post->ID));
            $type=(string)$this->field('sight_type',$post->ID);
            $image_id=get_post_thumbnail_id($post->ID);

            $problems=[];
            if(!$lat || !$lng) $problems[]='Missing coordinates';
            if(!$points) $problems[]='Missing XP';
            if(!$type) $problems[]='Missing sight type';
            if(!$image_id) $problems[]='Missing featured image';

            $connected=get_posts([
                'post_type'=>'st_activity',
                'post_status'=>['publish','draft','private'],
                'numberposts'=>1,
                'fields'=>'ids',
                'meta_query'=>[
                    [
                        'key'=>'related_top_sights',
                        'value'=>'"'.$post->ID.'"',
                        'compare'=>'LIKE',
                    ],
                ],
            ]);
            if(empty($connected)) $problems[]='Not connected to an Activity';

            if($problems) $issues+=count($problems);

            $rows[]=[
                'label'=>get_the_title($post),
                'value'=>$problems?implode(', ',$problems):'Healthy',
                'state'=>$problems?'warning':'good',
                'url'=>get_edit_post_link($post->ID,'raw'),
            ];
        }

        return [
            'generated_at'=>current_time('mysql'),
            'summary'=>[
                'posts'=>count($posts),
                'issues'=>$issues,
            ],
            'checks'=>$rows,
        ];
    }

    public function handle_top_sight_audit(){
        if(!current_user_can('manage_options')) wp_die('Administrator access is required.');
        check_admin_referer('tng_run_top_sight_audit');
        update_option(self::TOP_SIGHT_AUDIT_OPTION,$this->top_sight_audit(),false);
        wp_safe_redirect($this->admin_page_url('tn-game-top-sight-audit',['tng_notice'=>'scanned']));
        exit;
    }

    public function top_sight_audit_page(){
        if(!current_user_can('manage_options')) return;
        $scan=get_option(self::TOP_SIGHT_AUDIT_OPTION,[]);
        ?>
        <div class="wrap tng-management-wrap">
            <h1>Top Sight Audit</h1>
            <div class="tng-management-panel">
                <p>Checks coordinates, XP, sight type, featured image, and Activity relationships.</p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('tng_run_top_sight_audit'); ?>
                    <input type="hidden" name="action" value="tng_run_top_sight_audit">
                    <?php submit_button('Run Top Sight Audit','primary','submit',false); ?>
                </form>
            </div>
            <?php $this->render_audit_results($scan['checks']??[],'Top Sight results'); ?>
        </div>
        <?php
    }

    private function trail_audit(){
        $posts=get_posts([
            'post_type'=>'st_activity',
            'post_status'=>['publish','draft','private'],
            'numberposts'=>-1,
            'orderby'=>'title',
            'order'=>'ASC',
        ]);

        $rows=[];
        $issues=0;
        foreach($posts as $post){
            $problems=[];
            $gpx=$this->field('trail_gpx_url',$post->ID);
            $related=$this->field('related_top_sights',$post->ID);
            $difficulty=$this->field('trail_difficulty',$post->ID);
            if(!$difficulty) $difficulty=$this->field('difficulty',$post->ID);
            $length=$this->field('trail_length',$post->ID);
            if(!$length) $length=$this->field('length',$post->ID);
            $route_type=$this->field('trail_route_type',$post->ID);

            if(empty($gpx)) $problems[]='Missing GPX';
            if(empty($related)) $problems[]='No Top Sights';
            if(empty($difficulty)) $problems[]='Missing difficulty';
            if(empty($length)) $problems[]='Missing length';
            if(empty($route_type)) $problems[]='Missing route type';
            if(!get_post_thumbnail_id($post->ID)) $problems[]='Missing featured image';

            if($problems) $issues+=count($problems);

            $rows[]=[
                'label'=>get_the_title($post),
                'value'=>$problems?implode(', ',$problems):'Playable',
                'state'=>$problems?'warning':'good',
                'url'=>get_edit_post_link($post->ID,'raw'),
            ];
        }

        return [
            'generated_at'=>current_time('mysql'),
            'summary'=>[
                'posts'=>count($posts),
                'issues'=>$issues,
            ],
            'checks'=>$rows,
        ];
    }

    public function handle_trail_audit(){
        if(!current_user_can('manage_options')) wp_die('Administrator access is required.');
        check_admin_referer('tng_run_trail_audit');
        update_option(self::TRAIL_AUDIT_OPTION,$this->trail_audit(),false);
        wp_safe_redirect($this->admin_page_url('tn-game-trail-audit',['tng_notice'=>'scanned']));
        exit;
    }

    public function trail_audit_page(){
        if(!current_user_can('manage_options')) return;
        $scan=get_option(self::TRAIL_AUDIT_OPTION,[]);
        ?>
        <div class="wrap tng-management-wrap">
            <h1>Trail Audit</h1>
            <div class="tng-management-panel">
                <p>Checks GPX files, Top Sight relationships, trail statistics, and featured images.</p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('tng_run_trail_audit'); ?>
                    <input type="hidden" name="action" value="tng_run_trail_audit">
                    <?php submit_button('Run Trail Audit','primary','submit',false); ?>
                </form>
            </div>
            <?php $this->render_audit_results($scan['checks']??[],'Trail results'); ?>
        </div>
        <?php
    }

    private function gamipress_audit(){
        global $wpdb;

        $settings=$this->progression_settings();
        $points_type=sanitize_key($settings['points_type']);
        $plugin_active=function_exists('gamipress_get_user_points');
        $points_exists=false;

        if(post_type_exists('points-type')){
            $points=get_posts([
                'post_type'=>'points-type',
                'post_status'=>'publish',
                'name'=>$points_type,
                'numberposts'=>1,
                'fields'=>'ids',
            ]);
            $points_exists=!empty($points);
        }elseif($plugin_active){
            $points_exists=true;
        }

        $duplicate_reward_rows=(int)$wpdb->get_var(
            "SELECT COUNT(*) FROM (
                SELECT user_id,meta_key,COUNT(*) c
                FROM {$wpdb->usermeta}
                WHERE meta_key LIKE '_tng_reward_%'
                GROUP BY user_id,meta_key
                HAVING COUNT(*)>1
            ) x"
        );

        $orphan_claim_users=(int)$wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->usermeta} um
             LEFT JOIN {$wpdb->users} u ON u.ID=um.user_id
             WHERE um.meta_key LIKE '_tng_reward_%'
               AND u.ID IS NULL"
        );

        $checks=[
            ['label'=>'GamiPress active','value'=>$plugin_active?'Yes':'No','state'=>$plugin_active?'good':'bad'],
            ['label'=>'Configured points type','value'=>$points_type,'state'=>$points_exists?'good':'warning'],
            ['label'=>'Duplicate TN Game reward rows','value'=>$duplicate_reward_rows,'state'=>$duplicate_reward_rows?'warning':'good'],
            ['label'=>'Orphaned reward users','value'=>$orphan_claim_users,'state'=>$orphan_claim_users?'warning':'good'],
        ];

        $issues=0;
        foreach($checks as $check){
            if(in_array($check['state'],['warning','bad'],true)) $issues++;
        }

        return [
            'generated_at'=>current_time('mysql'),
            'summary'=>['issues'=>$issues],
            'checks'=>$checks,
        ];
    }

    public function handle_gamipress_audit(){
        if(!current_user_can('manage_options')) wp_die('Administrator access is required.');
        check_admin_referer('tng_run_gamipress_audit');
        update_option(self::GAMIPRESS_AUDIT_OPTION,$this->gamipress_audit(),false);
        wp_safe_redirect($this->admin_page_url('tn-game-gamipress-audit',['tng_notice'=>'scanned']));
        exit;
    }

    public function gamipress_audit_page(){
        if(!current_user_can('manage_options')) return;
        $scan=get_option(self::GAMIPRESS_AUDIT_OPTION,[]);
        ?>
        <div class="wrap tng-management-wrap">
            <h1>GamiPress Audit</h1>
            <div class="tng-management-panel">
                <p>Checks the active GamiPress integration, points type, and TN Game reward metadata.</p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('tng_run_gamipress_audit'); ?>
                    <input type="hidden" name="action" value="tng_run_gamipress_audit">
                    <?php submit_button('Run GamiPress Audit','primary','submit',false); ?>
                </form>
            </div>
            <?php $this->render_audit_results($scan['checks']??[],'GamiPress results'); ?>
        </div>
        <?php
    }

    public function photo_library_page(){
        if(!current_user_can('manage_options')) return;

        $attachments=get_posts([
            'post_type'=>'attachment',
            'post_status'=>'inherit',
            'post_mime_type'=>'image',
            'numberposts'=>40,
            'orderby'=>'date',
            'order'=>'DESC',
            'meta_query'=>[
                'relation'=>'OR',
                ['key'=>'_tng_top_sight_id','compare'=>'EXISTS'],
                ['key'=>'_tng_submission_id','compare'=>'EXISTS'],
            ],
        ]);
        ?>
        <div class="wrap tng-management-wrap">
            <h1>Photo Library</h1>
            <div class="tng-management-panel">
                <p>This page shows images associated with TN Game submissions or Top Sights. The full moderation workflow can be merged here when the photo plugin is added.</p>
                <p><a class="button button-primary" href="<?php echo esc_url(admin_url('upload.php')); ?>">Open WordPress Media Library</a></p>
            </div>

            <div class="tng-photo-library-grid">
                <?php if(empty($attachments)): ?>
                    <div class="tng-empty-state">No TN Game photo submissions are currently indexed.</div>
                <?php else: ?>
                    <?php foreach($attachments as $attachment): ?>
                        <a href="<?php echo esc_url(get_edit_post_link($attachment->ID)); ?>">
                            <?php echo wp_get_attachment_image($attachment->ID,'medium'); ?>
                            <span><?php echo esc_html(get_the_title($attachment)); ?></span>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private function render_audit_results($checks,$title='Audit results'){
        if(empty($checks)) return;
        ?>
        <section class="tng-management-panel">
            <h2><?php echo esc_html($title); ?></h2>
            <div class="tng-audit-list">
                <?php foreach($checks as $check): ?>
                    <div class="tng-audit-row">
                        <div>
                            <?php if(!empty($check['url'])): ?>
                                <a href="<?php echo esc_url($check['url']); ?>"><strong><?php echo esc_html($check['label']); ?></strong></a>
                            <?php else: ?>
                                <strong><?php echo esc_html($check['label']); ?></strong>
                            <?php endif; ?>
                        </div>
                        <span class="tng-audit-value"><?php echo esc_html((string)$check['value']); ?></span>
                        <span class="tng-health-badge is-<?php echo esc_attr($check['state']??'neutral'); ?>">
                            <?php echo esc_html(ucfirst($check['state']??'neutral')); ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php
    }

    public function gallery_repair_menu(){
        add_submenu_page(
            'tn-game-os',
            'Gallery Repair',
            'Gallery Repair',
            'manage_options',
            'tn-game-gallery-repair',
            [$this,'gallery_repair_page']
        );
    }

    private function gallery_repair_settings(){
        return wp_parse_args(get_option(self::GALLERY_REPAIR_OPTION,[]),[
            'meta_key'=>'gallery',
            'output_format'=>'csv',
            'candidate_keys'=>['gallery','activity_gallery','st_gallery','gallery_images'],
        ]);
    }

    private function sanitize_gallery_meta_key($key){
        $key=sanitize_key($key);
        return $key?:'gallery';
    }

    private function gallery_candidate_keys($preferred=''){
        $settings=$this->gallery_repair_settings();
        $keys=$settings['candidate_keys'];
        if(!is_array($keys)) $keys=[];
        if($preferred) array_unshift($keys,$preferred);
        $keys=array_merge($keys,['gallery','activity_gallery','st_gallery','gallery_images']);
        return array_values(array_unique(array_filter(array_map('sanitize_key',$keys))));
    }

    private function gallery_raw_meta_rows($post_id,$keys){
        global $wpdb;

        if(empty($keys)) return [];

        $placeholders=implode(',',array_fill(0,count($keys),'%s'));
        $sql="SELECT meta_id,meta_key,meta_value
              FROM {$wpdb->postmeta}
              WHERE post_id=%d
                AND meta_key IN ($placeholders)
              ORDER BY meta_id ASC";

        $params=array_merge([absint($post_id)],$keys);
        return $wpdb->get_results($wpdb->prepare($sql,$params),ARRAY_A);
    }

    private function recursively_collect_attachment_ids($value,&$ids,&$notes,$depth=0){
        if($depth>8){
            $notes[]='Nested gallery data exceeded the safe parsing depth.';
            return;
        }

        if($value instanceof WP_Post){
            $ids[]=absint($value->ID);
            return;
        }

        if(is_object($value)){
            if(isset($value->ID)) $ids[]=absint($value->ID);
            elseif(isset($value->id)) $ids[]=absint($value->id);
            else $this->recursively_collect_attachment_ids((array)$value,$ids,$notes,$depth+1);
            return;
        }

        if(is_array($value)){
            if(isset($value['ID'])) $ids[]=absint($value['ID']);
            elseif(isset($value['id'])) $ids[]=absint($value['id']);
            elseif(isset($value['attachment_id'])) $ids[]=absint($value['attachment_id']);
            else{
                foreach($value as $item){
                    $this->recursively_collect_attachment_ids($item,$ids,$notes,$depth+1);
                }
            }
            return;
        }

        if(is_int($value) || is_float($value)){
            $ids[]=absint($value);
            return;
        }

        if(!is_string($value)) return;

        $trimmed=trim($value);
        if($trimmed==='') return;

        $maybe=maybe_unserialize($trimmed);
        if($maybe!==$trimmed){
            $notes[]='Converted serialized gallery data.';
            $this->recursively_collect_attachment_ids($maybe,$ids,$notes,$depth+1);
            return;
        }

        $decoded=json_decode($trimmed,true);
        if(json_last_error()===JSON_ERROR_NONE && is_array($decoded)){
            $notes[]='Converted JSON gallery data.';
            $this->recursively_collect_attachment_ids($decoded,$ids,$notes,$depth+1);
            return;
        }

        if(preg_match('/^\s*\[.*\]\s*$/s',$trimmed)){
            $notes[]='Gallery resembled JSON but could not be decoded.';
        }

        if(preg_match_all('/\d+/',$trimmed,$matches)){
            foreach($matches[0] as $match) $ids[]=absint($match);

            if(strpos($trimmed,',')!==false) $notes[]='Converted comma-separated gallery data.';
            elseif(strpos($trimmed,'|')!==false) $notes[]='Converted pipe-separated gallery data.';
            elseif(strpos($trimmed,' ')!==false) $notes[]='Converted space-separated gallery data.';
            return;
        }

        $notes[]='Unrecognized gallery value was ignored.';
    }

    private function inspect_gallery_ids($raw_value){
        $ids=[];
        $notes=[];
        $this->recursively_collect_attachment_ids($raw_value,$ids,$notes);

        $original_ids=array_values(array_filter(array_map('absint',$ids)));
        $valid=[];
        $invalid=[];
        $non_images=[];

        foreach($original_ids as $id){
            if(get_post_type($id)!=='attachment'){
                $invalid[]=$id;
                continue;
            }

            $mime=(string)get_post_mime_type($id);
            if(strpos($mime,'image/')!==0){
                $non_images[]=$id;
                continue;
            }

            $valid[]=$id;
        }

        $deduped=array_values(array_unique($valid));
        $duplicates=max(0,count($valid)-count($deduped));

        return [
            'original_ids'=>$original_ids,
            'valid_ids'=>$deduped,
            'invalid_ids'=>array_values(array_unique($invalid)),
            'non_image_ids'=>array_values(array_unique($non_images)),
            'duplicates_removed'=>$duplicates,
            'notes'=>array_values(array_unique($notes)),
        ];
    }

    private function canonical_gallery_value($ids,$format='csv'){
        $ids=array_values(array_unique(array_filter(array_map('absint',$ids))));

        if($format==='array') return $ids;
        if($format==='json') return wp_json_encode($ids);

        // Traveler commonly expects a comma-separated attachment ID list.
        return implode(',',$ids);
    }

    private function activity_gallery_report($activity_id,$preferred_key='gallery'){
        $keys=$this->gallery_candidate_keys($preferred_key);
        $rows=$this->gallery_raw_meta_rows($activity_id,$keys);

        $all_ids=[];
        $invalid=[];
        $non_images=[];
        $duplicates=0;
        $notes=[];
        $source_keys=[];
        $raw_rows=[];

        foreach($rows as $row){
            $source_keys[]=$row['meta_key'];
            $raw_rows[]=[
                'meta_id'=>absint($row['meta_id']),
                'meta_key'=>$row['meta_key'],
                'meta_value'=>$row['meta_value'],
            ];

            $inspection=$this->inspect_gallery_ids($row['meta_value']);
            $all_ids=array_merge($all_ids,$inspection['valid_ids']);
            $invalid=array_merge($invalid,$inspection['invalid_ids']);
            $non_images=array_merge($non_images,$inspection['non_image_ids']);
            $duplicates+=$inspection['duplicates_removed'];
            $notes=array_merge($notes,$inspection['notes']);
        }

        $before_count=count($all_ids);
        $valid_ids=array_values(array_unique(array_filter(array_map('absint',$all_ids))));
        $duplicates+=max(0,$before_count-count($valid_ids));

        $malformed=false;
        if(count($rows)>1) $malformed=true;
        if(array_values(array_unique($source_keys))!==[$preferred_key] && !empty($rows)) $malformed=true;
        if(!empty($invalid) || !empty($non_images) || $duplicates>0 || !empty($notes)) $malformed=true;

        $expected=$this->canonical_gallery_value($valid_ids,'csv');
        if(count($rows)===1 && $rows[0]['meta_key']===$preferred_key){
            $actual=$rows[0]['meta_value'];
            if(is_string($actual) && trim($actual)!==$expected) $malformed=true;
            if(!is_string($actual)) $malformed=true;
        }

        return [
            'activity_id'=>absint($activity_id),
            'title'=>get_the_title($activity_id),
            'edit_url'=>get_edit_post_link($activity_id,'raw'),
            'preferred_key'=>$preferred_key,
            'source_keys'=>array_values(array_unique($source_keys)),
            'raw_rows'=>$raw_rows,
            'valid_ids'=>$valid_ids,
            'invalid_ids'=>array_values(array_unique($invalid)),
            'non_image_ids'=>array_values(array_unique($non_images)),
            'duplicates_removed'=>$duplicates,
            'notes'=>array_values(array_unique($notes)),
            'malformed'=>$malformed,
            'has_gallery'=>!empty($rows),
        ];
    }

    private function scan_all_activity_galleries($preferred_key='gallery'){
        $activity_ids=get_posts([
            'post_type'=>'st_activity',
            'post_status'=>['publish','draft','private','pending'],
            'numberposts'=>-1,
            'fields'=>'ids',
            'orderby'=>'ID',
            'order'=>'ASC',
        ]);

        $reports=[];
        $summary=[
            'activities_scanned'=>count($activity_ids),
            'activities_with_gallery'=>0,
            'malformed_activities'=>0,
            'invalid_ids'=>0,
            'non_image_ids'=>0,
            'duplicates'=>0,
        ];

        foreach($activity_ids as $activity_id){
            $report=$this->activity_gallery_report($activity_id,$preferred_key);
            if($report['has_gallery']) $summary['activities_with_gallery']++;
            if($report['malformed']) $summary['malformed_activities']++;
            $summary['invalid_ids']+=count($report['invalid_ids']);
            $summary['non_image_ids']+=count($report['non_image_ids']);
            $summary['duplicates']+=$report['duplicates_removed'];
            $reports[]=$report;
        }

        return [
            'generated_at'=>current_time('mysql'),
            'preferred_key'=>$preferred_key,
            'summary'=>$summary,
            'reports'=>$reports,
        ];
    }

    private function backup_activity_gallery($activity_id,$report){
        $backups=get_post_meta($activity_id,self::GALLERY_BACKUP_META,true);
        if(!is_array($backups)) $backups=[];

        $backup=[
            'backup_id'=>wp_generate_uuid4(),
            'created_at'=>current_time('mysql'),
            'created_by'=>get_current_user_id(),
            'preferred_key'=>$report['preferred_key'],
            'raw_rows'=>$report['raw_rows'],
        ];

        $backups[]=$backup;

        // Keep the most recent 10 backups per Activity.
        if(count($backups)>10) $backups=array_slice($backups,-10);

        update_post_meta($activity_id,self::GALLERY_BACKUP_META,$backups);
        return $backup['backup_id'];
    }

    private function delete_gallery_meta_rows($activity_id,$keys){
        global $wpdb;
        if(empty($keys)) return;

        $placeholders=implode(',',array_fill(0,count($keys),'%s'));
        $sql="DELETE FROM {$wpdb->postmeta}
              WHERE post_id=%d
                AND meta_key IN ($placeholders)";
        $params=array_merge([absint($activity_id)],$keys);
        $wpdb->query($wpdb->prepare($sql,$params));
        clean_post_cache($activity_id);
    }

    private function repair_activity_gallery($activity_id,$preferred_key='gallery',$format='csv'){
        $report=$this->activity_gallery_report($activity_id,$preferred_key);
        $backup_id=$this->backup_activity_gallery($activity_id,$report);

        $keys=$this->gallery_candidate_keys($preferred_key);
        $this->delete_gallery_meta_rows($activity_id,$keys);

        $canonical=$this->canonical_gallery_value($report['valid_ids'],$format);
        update_post_meta($activity_id,$preferred_key,$canonical);
        clean_post_cache($activity_id);

        return [
            'activity_id'=>$activity_id,
            'backup_id'=>$backup_id,
            'valid_ids'=>$report['valid_ids'],
            'repaired'=>true,
        ];
    }

    private function restore_latest_activity_gallery_backup($activity_id){
        global $wpdb;

        $backups=get_post_meta($activity_id,self::GALLERY_BACKUP_META,true);
        if(!is_array($backups) || empty($backups)){
            return new WP_Error('no_backup','No gallery backup exists for this Activity.');
        }

        $backup=array_pop($backups);
        $preferred_key=$this->sanitize_gallery_meta_key($backup['preferred_key']??'gallery');
        $keys=$this->gallery_candidate_keys($preferred_key);

        $this->delete_gallery_meta_rows($activity_id,$keys);

        if(!empty($backup['raw_rows']) && is_array($backup['raw_rows'])){
            foreach($backup['raw_rows'] as $row){
                if(empty($row['meta_key'])) continue;
                $wpdb->insert(
                    $wpdb->postmeta,
                    [
                        'post_id'=>absint($activity_id),
                        'meta_key'=>sanitize_key($row['meta_key']),
                        'meta_value'=>$row['meta_value'],
                    ],
                    ['%d','%s','%s']
                );
            }
        }

        update_post_meta($activity_id,self::GALLERY_BACKUP_META,$backups);
        clean_post_cache($activity_id);

        return true;
    }

    public function handle_gallery_scan(){
        if(!current_user_can('manage_options')) wp_die('Administrator access is required.');
        check_admin_referer('tng_gallery_scan');

        $meta_key=$this->sanitize_gallery_meta_key($_POST['meta_key']??'gallery');
        $format=sanitize_key($_POST['output_format']??'csv');
        if(!in_array($format,['csv','array','json'],true)) $format='csv';

        update_option(self::GALLERY_REPAIR_OPTION,[
            'meta_key'=>$meta_key,
            'output_format'=>$format,
            'candidate_keys'=>$this->gallery_candidate_keys($meta_key),
        ]);

        $scan=$this->scan_all_activity_galleries($meta_key);
        update_option(self::GALLERY_LAST_SCAN,$scan,false);

        wp_safe_redirect(admin_url('admin.php?page=tn-game-gallery-repair&tng_notice=scanned'));
        exit;
    }

    public function handle_gallery_repair(){
        if(!current_user_can('manage_options')) wp_die('Administrator access is required.');
        check_admin_referer('tng_gallery_repair');

        $settings=$this->gallery_repair_settings();
        $meta_key=$this->sanitize_gallery_meta_key($_POST['meta_key']??$settings['meta_key']);
        $format=sanitize_key($_POST['output_format']??$settings['output_format']);
        if(!in_array($format,['csv','array','json'],true)) $format='csv';

        $scan=$this->scan_all_activity_galleries($meta_key);
        $repaired=0;

        foreach($scan['reports'] as $report){
            if(!$report['malformed']) continue;
            $this->repair_activity_gallery($report['activity_id'],$meta_key,$format);
            $repaired++;
        }

        $fresh=$this->scan_all_activity_galleries($meta_key);
        $fresh['repair_summary']=[
            'repaired_activities'=>$repaired,
            'repaired_at'=>current_time('mysql'),
        ];
        update_option(self::GALLERY_LAST_SCAN,$fresh,false);

        wp_safe_redirect(admin_url('admin.php?page=tn-game-gallery-repair&tng_notice=repaired&count='.$repaired));
        exit;
    }

    public function handle_gallery_restore(){
        if(!current_user_can('manage_options')) wp_die('Administrator access is required.');
        check_admin_referer('tng_gallery_restore');

        $activity_id=absint($_POST['activity_id']??0);
        if(!$activity_id || get_post_type($activity_id)!=='st_activity'){
            wp_die('Invalid Activity.');
        }

        $result=$this->restore_latest_activity_gallery_backup($activity_id);
        $notice=is_wp_error($result)?'restore_failed':'restored';

        wp_safe_redirect(admin_url('admin.php?page=tn-game-gallery-repair&tng_notice='.$notice));
        exit;
    }

    public function gallery_repair_page(){
        if(!current_user_can('manage_options')) return;

        $settings=$this->gallery_repair_settings();
        $scan=get_option(self::GALLERY_LAST_SCAN,[]);
        $notice=sanitize_key($_GET['tng_notice']??'');
        $summary=$scan['summary']??[];
        $reports=$scan['reports']??[];
        ?>
        <div class="wrap tng-gallery-repair-wrap">
            <h1>TN Game Gallery Repair</h1>
            <p>
                Scan Traveler Activity galleries, detect malformed values, convert older formats,
                remove invalid or non-image attachments, remove duplicates, and back up every
                original meta row before repair.
            </p>

            <?php if($notice==='scanned'): ?>
                <div class="notice notice-info is-dismissible"><p>Gallery scan completed. No data was changed.</p></div>
            <?php elseif($notice==='repaired'): ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html(absint($_GET['count']??0)); ?> Activity galleries repaired and backed up.</p></div>
            <?php elseif($notice==='restored'): ?>
                <div class="notice notice-success is-dismissible"><p>The latest gallery backup was restored.</p></div>
            <?php elseif($notice==='restore_failed'): ?>
                <div class="notice notice-error is-dismissible"><p>No gallery backup could be restored.</p></div>
            <?php endif; ?>

            <div class="tng-gallery-tool-card">
                <h2>Gallery settings</h2>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('tng_gallery_scan'); ?>
                    <input type="hidden" name="action" value="tng_gallery_scan">

                    <table class="form-table">
                        <tr>
                            <th><label for="tng-gallery-meta-key">Traveler gallery meta key</label></th>
                            <td>
                                <input id="tng-gallery-meta-key" type="text" class="regular-text" name="meta_key" value="<?php echo esc_attr($settings['meta_key']); ?>">
                                <p class="description">Use <code>gallery</code> unless your Traveler installation stores Activity galleries under another key.</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="tng-gallery-output-format">Repaired storage format</label></th>
                            <td>
                                <select id="tng-gallery-output-format" name="output_format">
                                    <option value="csv" <?php selected($settings['output_format'],'csv'); ?>>Comma-separated attachment IDs — recommended for Traveler</option>
                                    <option value="array" <?php selected($settings['output_format'],'array'); ?>>WordPress serialized array</option>
                                    <option value="json" <?php selected($settings['output_format'],'json'); ?>>JSON array</option>
                                </select>
                            </td>
                        </tr>
                    </table>

                    <?php submit_button('Scan Activity Galleries','secondary'); ?>
                </form>
            </div>

            <?php if(!empty($summary)): ?>
                <div class="tng-gallery-summary-grid">
                    <div><strong><?php echo esc_html(absint($summary['activities_scanned']??0)); ?></strong><span>Activities scanned</span></div>
                    <div><strong><?php echo esc_html(absint($summary['activities_with_gallery']??0)); ?></strong><span>With gallery data</span></div>
                    <div><strong><?php echo esc_html(absint($summary['malformed_activities']??0)); ?></strong><span>Need repair</span></div>
                    <div><strong><?php echo esc_html(absint($summary['invalid_ids']??0)); ?></strong><span>Invalid IDs</span></div>
                    <div><strong><?php echo esc_html(absint($summary['non_image_ids']??0)); ?></strong><span>Non-images</span></div>
                    <div><strong><?php echo esc_html(absint($summary['duplicates']??0)); ?></strong><span>Duplicates</span></div>
                </div>

                <?php if(absint($summary['malformed_activities']??0)>0): ?>
                    <div class="tng-gallery-tool-card tng-gallery-repair-action">
                        <h2>Repair malformed galleries</h2>
                        <p>
                            Each affected Activity is backed up before any gallery meta is changed.
                            The repair consolidates recognized gallery values into the selected key and format.
                        </p>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Back up and repair every malformed Activity gallery?');">
                            <?php wp_nonce_field('tng_gallery_repair'); ?>
                            <input type="hidden" name="action" value="tng_gallery_repair">
                            <input type="hidden" name="meta_key" value="<?php echo esc_attr($settings['meta_key']); ?>">
                            <input type="hidden" name="output_format" value="<?php echo esc_attr($settings['output_format']); ?>">
                            <?php submit_button('Back Up and Repair All','primary', 'submit', false); ?>
                        </form>
                    </div>
                <?php endif; ?>

                <div class="tng-gallery-tool-card">
                    <h2>Scan results</h2>
                    <table class="widefat striped tng-gallery-results">
                        <thead>
                            <tr>
                                <th>Activity</th>
                                <th>Status</th>
                                <th>Source keys</th>
                                <th>Valid images</th>
                                <th>Removed</th>
                                <th>Notes</th>
                                <th>Backup</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach($reports as $report): ?>
                            <?php
                            $backups=get_post_meta($report['activity_id'],self::GALLERY_BACKUP_META,true);
                            $has_backup=is_array($backups)&&!empty($backups);
                            $removed=count($report['invalid_ids'])+count($report['non_image_ids'])+$report['duplicates_removed'];
                            ?>
                            <tr>
                                <td>
                                    <strong><a href="<?php echo esc_url($report['edit_url']); ?>"><?php echo esc_html($report['title']); ?></a></strong>
                                    <br><code>#<?php echo esc_html($report['activity_id']); ?></code>
                                </td>
                                <td>
                                    <?php if($report['malformed']): ?>
                                        <span class="tng-gallery-badge is-bad">Needs repair</span>
                                    <?php else: ?>
                                        <span class="tng-gallery-badge is-good">Healthy</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html(implode(', ',$report['source_keys'])?:'—'); ?></td>
                                <td><?php echo esc_html(count($report['valid_ids'])); ?></td>
                                <td><?php echo esc_html($removed); ?></td>
                                <td><?php echo esc_html(implode(' ',$report['notes'])?:'—'); ?></td>
                                <td>
                                    <?php if($has_backup): ?>
                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Restore the latest gallery backup for this Activity?');">
                                            <?php wp_nonce_field('tng_gallery_restore'); ?>
                                            <input type="hidden" name="action" value="tng_gallery_restore">
                                            <input type="hidden" name="activity_id" value="<?php echo esc_attr($report['activity_id']); ?>">
                                            <button type="submit" class="button button-small">Restore latest</button>
                                        </form>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    private function developer_mode_enabled(){
        if(!current_user_can('manage_options')){
            return false;
        }

        if(isset($_GET['tng_dev'])){
            $requested=sanitize_text_field(wp_unslash($_GET['tng_dev']));

            if($requested==='0'){
                return false;
            }

            if($requested==='1'){
                return true;
            }
        }

        return (bool)get_option(self::OPTION_AUTO_DEV,0);
    }


    private function trail_experience_scalar($value){
        if(is_object($value)){
            if(isset($value->post_title)) return (string)$value->post_title;
            if(isset($value->name)) return (string)$value->name;
            if(isset($value->label)) return (string)$value->label;
            if(isset($value->ID)) return (string)get_the_title($value->ID);
        }

        if(is_array($value)){
            if(isset($value['label'])) return (string)$value['label'];
            if(isset($value['value']) && !is_array($value['value'])) return (string)$value['value'];
            if(isset($value['post_title'])) return (string)$value['post_title'];
            if(isset($value['name'])) return (string)$value['name'];

            $first=reset($value);
            return $this->trail_experience_scalar($first);
        }

        return trim(wp_strip_all_tags((string)$value));
    }

    private function trail_experience_first_field($post_id,$names){
        foreach((array)$names as $name){
            $value=$this->field($name,$post_id);
            if($value!=='' && $value!==null && $value!==false){
                return $value;
            }
        }
        return '';
    }

    private function trail_experience_format_distance($value){
        $value=$this->trail_experience_scalar($value);
        if($value==='') return '';
        if(preg_match('/mi|mile/i',$value)) return $value;
        if(is_numeric($value)){
            return rtrim(rtrim(number_format((float)$value,1),'0'),'.').' mi';
        }
        return $value;
    }

    private function trail_experience_format_gain($value){
        $value=$this->trail_experience_scalar($value);
        if($value==='') return '';
        if(preg_match('/ft|feet/i',$value)) return $value;
        if(is_numeric($value)) return number_format((float)$value).' ft';
        return $value;
    }

    private function trail_experience_difficulty_class($difficulty){
        $difficulty=strtolower($difficulty);
        if(strpos($difficulty,'easy')!==false) return 'easy';
        if(strpos($difficulty,'moderate')!==false) return 'moderate';
        if(strpos($difficulty,'hard')!==false) return 'hard';
        if(strpos($difficulty,'expert')!==false) return 'expert';
        return 'default';
    }

    private function trail_experience_difficulty_icon($difficulty){
        $difficulty=strtolower($difficulty);
        if(strpos($difficulty,'easy')!==false) return '🟢';
        if(strpos($difficulty,'moderate')!==false) return '🟨';
        if(strpos($difficulty,'hard')!==false) return '🟠';
        if(strpos($difficulty,'expert')!==false) return '🔴';
        return '🥾';
    }

    private function trail_experience_park($value){
        if(empty($value)) return ['',''];
        if(is_array($value)) $value=reset($value);

        if(is_object($value) && isset($value->ID)){
            return [get_the_title($value->ID),get_permalink($value->ID)];
        }

        if(is_numeric($value)){
            return [get_the_title(absint($value)),get_permalink(absint($value))];
        }

        return [$this->trail_experience_scalar($value),''];
    }




    /**
     * Remove TinyMCE plugins that are requested by older Traveler builds but
     * no longer exist in current WordPress.
     */
    public function repair_tinymce_plugins($plugins){
        if(!is_admin() || !is_array($plugins)){
            return $plugins;
        }

        return array_values(array_filter(
            $plugins,
            static function($plugin){
                return !in_array(
                    sanitize_key((string)$plugin),
                    ['hr'],
                    true
                );
            }
        ));
    }

    /**
     * Remove external TinyMCE registrations for the obsolete HR plugin.
     */
    public function repair_tinymce_external_plugins($plugins){
        if(!is_admin() || !is_array($plugins)){
            return $plugins;
        }

        foreach(array_keys($plugins) as $key){
            $normalized=sanitize_key((string)$key);
            $url=(string)$plugins[$key];

            if(
                $normalized==='hr' ||
                strpos($url,'/tinymce/plugins/hr/')!==false
            ){
                unset($plugins[$key]);
            }
        }

        return $plugins;
    }

    /**
     * Clean a comma-separated plugin list when another theme component adds
     * `hr` directly to TinyMCE initialization.
     */
    public function repair_tinymce_initialization($settings){
        if(!is_admin() || !is_array($settings)){
            return $settings;
        }

        if(!empty($settings['plugins']) && is_string($settings['plugins'])){
            $plugins=preg_split(
                '/[\s,]+/',
                $settings['plugins'],
                -1,
                PREG_SPLIT_NO_EMPTY
            );

            $plugins=array_values(array_filter(
                $plugins,
                static function($plugin){
                    return sanitize_key((string)$plugin)!=='hr';
                }
            ));

            $settings['plugins']=implode(' ',$plugins);
        }

        return $settings;
    }

    private function is_food_activity($post_id){
        if(get_post_type($post_id)!=='st_activity') return false;

        $food_slugs=[
            'food',
            'food-drink',
            'food-and-drink',
            'restaurants',
            'restaurant',
            'dining',
        ];

        foreach(get_object_taxonomies('st_activity','names') as $taxonomy){
            foreach($food_slugs as $slug){
                if(has_term($slug,$taxonomy,$post_id)){
                    return true;
                }
            }
        }

        return (bool)get_post_meta($post_id,self::FOOD_META_PREFIX.'enabled',true);
    }

    private function food_meta($post_id,$key,$default=''){
        $value=get_post_meta($post_id,self::FOOD_META_PREFIX.$key,true);
        return $value===''?$default:$value;
    }

    private function food_service_options(){
        return [
            'dine_in'=>'Dine-in',
            'takeout'=>'Takeout',
            'delivery'=>'Delivery',
            'outdoor_seating'=>'Outdoor seating',
            'reservations'=>'Reservations',
            'kid_friendly'=>'Kid-friendly',
            'wheelchair_accessible'=>'Wheelchair accessible',
            'live_music'=>'Live music',
            'breakfast'=>'Breakfast',
            'lunch'=>'Lunch',
            'dinner'=>'Dinner',
            'coffee'=>'Coffee',
            'dessert'=>'Dessert',
            'beer'=>'Beer',
            'wine'=>'Wine',
            'cocktails'=>'Cocktails',
        ];
    }

    public function food_add_meta_boxes(){
        add_meta_box(
            'tng-food-details',
            'TN Game Food & Drink',
            [$this,'food_meta_box_html'],
            'st_activity',
            'normal',
            'high'
        );
    }

    public function food_meta_box_html($post){
        wp_nonce_field('tng_food_save','tng_food_nonce');

        $fields=[
            'enabled'=>$this->food_meta($post->ID,'enabled'),
            'google_place_id'=>$this->food_meta($post->ID,'google_place_id'),
            'cuisine'=>$this->food_meta($post->ID,'cuisine'),
            'price_range'=>$this->food_meta($post->ID,'price_range'),
            'address'=>$this->food_meta($post->ID,'address'),
            'latitude'=>$this->food_meta($post->ID,'latitude'),
            'longitude'=>$this->food_meta($post->ID,'longitude'),
            'phone'=>$this->food_meta($post->ID,'phone'),
            'website'=>$this->food_meta($post->ID,'website'),
            'google_maps_url'=>$this->food_meta($post->ID,'google_maps_url'),
            'menu_url'=>$this->food_meta($post->ID,'menu_url'),
            'order_url'=>$this->food_meta($post->ID,'order_url'),
            'reservation_url'=>$this->food_meta($post->ID,'reservation_url'),
            'featured_dish'=>$this->food_meta($post->ID,'featured_dish'),
            'local_tip'=>$this->food_meta($post->ID,'local_tip'),
            'rating'=>$this->food_meta($post->ID,'rating'),
            'rating_count'=>$this->food_meta($post->ID,'rating_count'),
            'business_status'=>$this->food_meta($post->ID,'business_status'),
            'hours_text'=>$this->food_meta($post->ID,'hours_text'),
            'checkin_radius'=>$this->food_meta($post->ID,'checkin_radius',150),
            'checkin_xp'=>$this->food_meta($post->ID,'checkin_xp',25),
        ];

        $services=(array)get_post_meta($post->ID,self::FOOD_META_PREFIX.'services',true);
        ?>
        <style>
            .tng-food-admin-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
            .tng-food-admin-grid label{display:block;font-weight:700}
            .tng-food-admin-grid input,.tng-food-admin-grid textarea{width:100%;margin-top:5px}
            .tng-food-admin-full{grid-column:1/-1}
            .tng-food-services{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin-top:8px}
            .tng-food-google-row{display:flex;gap:8px;align-items:flex-end}
            .tng-food-google-row label{flex:1}
            #tng-food-import-status{margin-top:8px;font-weight:600}
            @media(max-width:800px){.tng-food-admin-grid{grid-template-columns:1fr}.tng-food-services{grid-template-columns:1fr 1fr}}
        </style>
        <div class="tng-food-admin-grid">
            <label class="tng-food-admin-full">
                <input type="checkbox" name="tng_food[enabled]" value="1" <?php checked($fields['enabled'],'1'); ?>>
                Enable TN Game Food & Drink experience for this Activity
            </label>

            <div class="tng-food-admin-full tng-food-google-row">
                <label>
                    Google Place ID
                    <input type="text" id="tng-food-place-id" name="tng_food[google_place_id]" value="<?php echo esc_attr($fields['google_place_id']); ?>">
                </label>
                <button type="button" class="button button-primary" id="tng-food-google-import">Import Google details</button>
            </div>
            <div class="tng-food-admin-full" id="tng-food-import-status"></div>

            <label>Cuisine types<input type="text" name="tng_food[cuisine]" value="<?php echo esc_attr($fields['cuisine']); ?>" placeholder="Southern, BBQ, Coffee"></label>
            <label>Price range<input type="text" name="tng_food[price_range]" value="<?php echo esc_attr($fields['price_range']); ?>" placeholder="$, $$, $$$"></label>
            <label class="tng-food-admin-full">Address<input type="text" name="tng_food[address]" value="<?php echo esc_attr($fields['address']); ?>"></label>
            <label>Latitude<input type="text" name="tng_food[latitude]" value="<?php echo esc_attr($fields['latitude']); ?>"></label>
            <label>Longitude<input type="text" name="tng_food[longitude]" value="<?php echo esc_attr($fields['longitude']); ?>"></label>
            <label>Phone<input type="text" name="tng_food[phone]" value="<?php echo esc_attr($fields['phone']); ?>"></label>
            <label>Website<input type="url" name="tng_food[website]" value="<?php echo esc_attr($fields['website']); ?>"></label>
            <label>Google Maps URL<input type="url" name="tng_food[google_maps_url]" value="<?php echo esc_attr($fields['google_maps_url']); ?>"></label>
            <label>Menu URL<input type="url" name="tng_food[menu_url]" value="<?php echo esc_attr($fields['menu_url']); ?>"></label>
            <label>Online ordering URL<input type="url" name="tng_food[order_url]" value="<?php echo esc_attr($fields['order_url']); ?>"></label>
            <label>Reservation URL<input type="url" name="tng_food[reservation_url]" value="<?php echo esc_attr($fields['reservation_url']); ?>"></label>
            <label>Featured dish<input type="text" name="tng_food[featured_dish]" value="<?php echo esc_attr($fields['featured_dish']); ?>"></label>
            <label>Rating<input type="text" name="tng_food[rating]" value="<?php echo esc_attr($fields['rating']); ?>"></label>
            <label>Google rating count<input type="number" name="tng_food[rating_count]" value="<?php echo esc_attr($fields['rating_count']); ?>"></label>
            <label>Business status<input type="text" name="tng_food[business_status]" value="<?php echo esc_attr($fields['business_status']); ?>"></label>
            <label>Check-in radius (feet)<input type="number" min="30" max="1000" name="tng_food[checkin_radius]" value="<?php echo esc_attr($fields['checkin_radius']); ?>"></label>
            <label>First check-in XP<input type="number" min="0" max="1000" name="tng_food[checkin_xp]" value="<?php echo esc_attr($fields['checkin_xp']); ?>"></label>
            <label class="tng-food-admin-full">Hours<textarea rows="5" name="tng_food[hours_text]"><?php echo esc_textarea($fields['hours_text']); ?></textarea></label>
            <label class="tng-food-admin-full">Local recommendation / what to order<textarea rows="4" name="tng_food[local_tip]"><?php echo esc_textarea($fields['local_tip']); ?></textarea></label>

            <div class="tng-food-admin-full">
                <strong>Services and attributes</strong>
                <div class="tng-food-services">
                    <?php foreach($this->food_service_options() as $key=>$label): ?>
                        <label>
                            <input type="checkbox" name="tng_food_services[]" value="<?php echo esc_attr($key); ?>" <?php checked(in_array($key,$services,true)); ?>>
                            <?php echo esc_html($label); ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <script>
        document.addEventListener('DOMContentLoaded',function(){
            const button=document.getElementById('tng-food-google-import');
            if(!button)return;
            button.addEventListener('click',async function(){
                const placeId=document.getElementById('tng-food-place-id').value.trim();
                const status=document.getElementById('tng-food-import-status');
                if(!placeId){status.textContent='Enter a Google Place ID first.';return;}
                button.disabled=true;
                status.textContent='Importing Google Places data…';
                const body=new URLSearchParams();
                body.set('action','tng_food_google_import');
                body.set('nonce','<?php echo esc_js(wp_create_nonce('tng_food_google_import')); ?>');
                body.set('post_id','<?php echo absint($post->ID); ?>');
                body.set('place_id',placeId);
                try{
                    const response=await fetch(ajaxurl,{
                        method:'POST',
                        headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
                        body:body.toString()
                    });
                    const payload=await response.json();
                    if(payload.success){
                        status.textContent='Imported. Save or update the Activity, then refresh this editor.';
                    }else{
                        status.textContent=(payload.data&&payload.data.message)||'Import failed.';
                    }
                }catch(error){
                    status.textContent='Import failed.';
                }finally{
                    button.disabled=false;
                }
            });
        });
        </script>
        <?php
    }

    public function food_save_meta($post_id,$post){
        if(
            !isset($_POST['tng_food_nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['tng_food_nonce'])),'tng_food_save') ||
            !current_user_can('edit_post',$post_id) ||
            (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
        ){
            return;
        }

        $input=isset($_POST['tng_food'])?(array)wp_unslash($_POST['tng_food']):[];
        $text_fields=[
            'enabled','google_place_id','cuisine','price_range','address',
            'latitude','longitude','phone','featured_dish','rating',
            'rating_count','business_status','checkin_radius','checkin_xp',
        ];
        $url_fields=['website','google_maps_url','menu_url','order_url','reservation_url'];
        $textarea_fields=['hours_text','local_tip'];

        foreach($text_fields as $field){
            $value=isset($input[$field])?sanitize_text_field($input[$field]):'';
            update_post_meta($post_id,self::FOOD_META_PREFIX.$field,$value);
        }
        foreach($url_fields as $field){
            $value=isset($input[$field])?esc_url_raw($input[$field]):'';
            update_post_meta($post_id,self::FOOD_META_PREFIX.$field,$value);
        }
        foreach($textarea_fields as $field){
            $value=isset($input[$field])?sanitize_textarea_field($input[$field]):'';
            update_post_meta($post_id,self::FOOD_META_PREFIX.$field,$value);
        }

        $allowed=array_keys($this->food_service_options());
        $services=isset($_POST['tng_food_services'])
            ? array_values(array_intersect($allowed,array_map('sanitize_key',(array)wp_unslash($_POST['tng_food_services']))))
            : [];
        update_post_meta($post_id,self::FOOD_META_PREFIX.'services',$services);
    }

    public function food_admin_page(){
        if(!current_user_can('manage_options')) return;

        if(isset($_POST['tng_food_settings_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['tng_food_settings_nonce'])),'tng_food_settings')){
            update_option(
                self::FOOD_GOOGLE_KEY_OPTION,
                sanitize_text_field(wp_unslash($_POST['tng_food_google_key']??''))
            );
            echo '<div class="notice notice-success"><p>Food & Drink settings saved.</p></div>';
        }

        $key=(string)get_option(self::FOOD_GOOGLE_KEY_OPTION,'');
        $food_query=new WP_Query([
            'post_type'=>'st_activity',
            'post_status'=>['publish','draft','pending'],
            'posts_per_page'=>100,
            'meta_key'=>self::FOOD_META_PREFIX.'enabled',
            'meta_value'=>'1',
            'orderby'=>'title',
            'order'=>'ASC',
        ]);
        ?>
        <div class="wrap">
            <h1>Food & Drink</h1>
            <p>Food listings remain Traveler Activities. Assign the Activity to a Food, Food & Drink, Restaurant, Restaurants, or Dining term, or enable Food & Drink in the Activity editor.</p>

            <form method="post" style="max-width:760px;background:#fff;border:1px solid #dcdcde;padding:22px;margin:20px 0">
                <?php wp_nonce_field('tng_food_settings','tng_food_settings_nonce'); ?>
                <h2>Google Places</h2>
                <p>Enter a restricted Google Places API key. The importer uses Place Details (New) and requests only the fields needed by TN Game.</p>
                <input type="password" name="tng_food_google_key" value="<?php echo esc_attr($key); ?>" class="regular-text" autocomplete="off">
                <?php submit_button('Save Google Places Settings'); ?>
            </form>

            <h2>Food listings</h2>
            <table class="widefat striped">
                <thead><tr><th>Restaurant</th><th>Place ID</th><th>Cuisine</th><th>Coordinates</th><th>Status</th></tr></thead>
                <tbody>
                <?php if($food_query->have_posts()): while($food_query->have_posts()): $food_query->the_post(); $id=get_the_ID(); ?>
                    <tr>
                        <td><a href="<?php echo esc_url(get_edit_post_link($id)); ?>"><?php echo esc_html(get_the_title()); ?></a></td>
                        <td><?php echo esc_html($this->food_meta($id,'google_place_id','—')); ?></td>
                        <td><?php echo esc_html($this->food_meta($id,'cuisine','—')); ?></td>
                        <td><?php echo esc_html($this->food_meta($id,'latitude','—').' / '.$this->food_meta($id,'longitude','—')); ?></td>
                        <td><?php echo esc_html(get_post_status($id)); ?></td>
                    </tr>
                <?php endwhile; wp_reset_postdata(); else: ?>
                    <tr><td colspan="5">No Food & Drink Activities have been enabled yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function ajax_food_google_import(){
        if(!current_user_can('edit_posts')){
            wp_send_json_error(['message'=>'Permission denied.'],403);
        }
        check_ajax_referer('tng_food_google_import','nonce');

        $post_id=absint($_POST['post_id']??0);
        $place_id=sanitize_text_field(wp_unslash($_POST['place_id']??''));
        $api_key=(string)get_option(self::FOOD_GOOGLE_KEY_OPTION,'');

        if(!$post_id || get_post_type($post_id)!=='st_activity' || !$place_id){
            wp_send_json_error(['message'=>'Invalid Activity or Place ID.'],400);
        }
        if($api_key===''){
            wp_send_json_error(['message'=>'Add a Google Places API key under TN Game Core → Food & Drink first.'],400);
        }

        $fields=[
            'id','displayName','formattedAddress','location',
            'nationalPhoneNumber','websiteUri','googleMapsUri',
            'rating','userRatingCount','priceLevel','businessStatus',
            'regularOpeningHours','primaryTypeDisplayName',
            'types','dineIn','takeout','delivery','outdoorSeating',
            'reservable','servesBreakfast','servesLunch','servesDinner',
            'servesCoffee','servesDessert','servesBeer','servesWine',
            'servesCocktails','liveMusic','goodForChildren',
            'accessibilityOptions',
        ];

        $response=wp_remote_get(
            'https://places.googleapis.com/v1/places/'.rawurlencode($place_id),
            [
                'timeout'=>20,
                'headers'=>[
                    'X-Goog-Api-Key'=>$api_key,
                    'X-Goog-FieldMask'=>implode(',',$fields),
                ],
            ]
        );

        if(is_wp_error($response)){
            wp_send_json_error(['message'=>$response->get_error_message()],500);
        }

        $status=wp_remote_retrieve_response_code($response);
        $data=json_decode(wp_remote_retrieve_body($response),true);
        if($status<200 || $status>=300 || !is_array($data)){
            $message=$data['error']['message']??'Google Places import failed.';
            wp_send_json_error(['message'=>$message],$status?:500);
        }

        $updates=[
            'enabled'=>'1',
            'google_place_id'=>$place_id,
            'address'=>$data['formattedAddress']??'',
            'latitude'=>$data['location']['latitude']??'',
            'longitude'=>$data['location']['longitude']??'',
            'phone'=>$data['nationalPhoneNumber']??'',
            'website'=>$data['websiteUri']??'',
            'google_maps_url'=>$data['googleMapsUri']??'',
            'rating'=>$data['rating']??'',
            'rating_count'=>$data['userRatingCount']??'',
            'price_range'=>$data['priceLevel']??'',
            'business_status'=>$data['businessStatus']??'',
            'cuisine'=>$data['primaryTypeDisplayName']['text']??'',
            'hours_text'=>isset($data['regularOpeningHours']['weekdayDescriptions'])
                ? implode("\n",$data['regularOpeningHours']['weekdayDescriptions'])
                : '',
        ];

        foreach($updates as $key=>$value){
            update_post_meta($post_id,self::FOOD_META_PREFIX.$key,is_scalar($value)?(string)$value:'');
        }

        $service_map=[
            'dineIn'=>'dine_in',
            'takeout'=>'takeout',
            'delivery'=>'delivery',
            'outdoorSeating'=>'outdoor_seating',
            'reservable'=>'reservations',
            'goodForChildren'=>'kid_friendly',
            'liveMusic'=>'live_music',
            'servesBreakfast'=>'breakfast',
            'servesLunch'=>'lunch',
            'servesDinner'=>'dinner',
            'servesCoffee'=>'coffee',
            'servesDessert'=>'dessert',
            'servesBeer'=>'beer',
            'servesWine'=>'wine',
            'servesCocktails'=>'cocktails',
        ];
        $services=[];
        foreach($service_map as $google_key=>$local_key){
            if(!empty($data[$google_key])) $services[]=$local_key;
        }
        if(!empty($data['accessibilityOptions']['wheelchairAccessibleEntrance'])){
            $services[]='wheelchair_accessible';
        }
        update_post_meta($post_id,self::FOOD_META_PREFIX.'services',array_values(array_unique($services)));

        wp_send_json_success(['message'=>'Google Places data imported.','fields'=>$updates]);
    }

    public function food_body_class($classes){
        if(is_singular('st_activity') && $this->is_food_activity(get_queried_object_id())){
            $classes[]='tng-food-activity-page';
        }
        return $classes;
    }

    public function enqueue_food_assets(){
        if(!is_singular('st_activity')) return;
        $post_id=get_queried_object_id();
        if(!$this->is_food_activity($post_id)) return;

        wp_enqueue_style('mapbox-gl-css');
        wp_enqueue_style('tng-core-css');
        wp_enqueue_script('mapbox-gl-js');
        wp_enqueue_script('tng-food');

        wp_localize_script('tng-food','TNGFood',[
            'ajaxUrl'=>admin_url('admin-ajax.php'),
            'nonce'=>wp_create_nonce('tng_food_checkin'),
            'postId'=>$post_id,
            'latitude'=>(float)$this->food_meta($post_id,'latitude',0),
            'longitude'=>(float)$this->food_meta($post_id,'longitude',0),
            'title'=>get_the_title($post_id),
            'mapboxToken'=>(string)get_option(self::OPTION_TOKEN,''),
            'mapboxStyle'=>(string)get_option(self::OPTION_STYLE,'mapbox://styles/mapbox/outdoors-v12'),
            'radiusFeet'=>absint($this->food_meta($post_id,'checkin_radius',150)),
            'loginUrl'=>wp_login_url(get_permalink($post_id)),
            'isLoggedIn'=>is_user_logged_in(),
        ]);
    }

    public function food_inject_experience($content){
        if(
            is_admin() ||
            !is_singular('st_activity') ||
            !in_the_loop() ||
            !is_main_query()
        ){
            return $content;
        }

        $post_id=get_queried_object_id();
        if(!$this->is_food_activity($post_id)) return $content;
        if(has_shortcode($content,'tng_food_experience') || has_shortcode($content,'food_experience')){
            return $content;
        }

        return $this->food_experience_shortcode(['post_id'=>$post_id]).$content;
    }

    public function food_experience_shortcode($atts=[]){
        $atts=shortcode_atts(['post_id'=>0],$atts,'tng_food_experience');
        $post_id=absint($atts['post_id'])?:get_the_ID();
        if(!$post_id || get_post_type($post_id)!=='st_activity') return '';

        $cuisine=$this->food_meta($post_id,'cuisine');
        $price=$this->food_meta($post_id,'price_range');
        $address=$this->food_meta($post_id,'address');
        $phone=$this->food_meta($post_id,'phone');
        $website=$this->food_meta($post_id,'website');
        $maps_url=$this->food_meta($post_id,'google_maps_url');
        $menu_url=$this->food_meta($post_id,'menu_url');
        $order_url=$this->food_meta($post_id,'order_url');
        $reservation_url=$this->food_meta($post_id,'reservation_url');
        $featured_dish=$this->food_meta($post_id,'featured_dish');
        $local_tip=$this->food_meta($post_id,'local_tip');
        $rating=$this->food_meta($post_id,'rating');
        $rating_count=$this->food_meta($post_id,'rating_count');
        $hours=$this->food_meta($post_id,'hours_text');
        $services=(array)get_post_meta($post_id,self::FOOD_META_PREFIX.'services',true);
        $service_labels=$this->food_service_options();
        $checkin_xp=absint($this->food_meta($post_id,'checkin_xp',25));
        $claimed=is_user_logged_in() && get_user_meta(get_current_user_id(),'_tng_food_checked_in_'.$post_id,true);

        $description=trim(wp_strip_all_tags(strip_shortcodes(get_post_field('post_content',$post_id))));
        if(strlen($description)>700) $description=wp_trim_words($description,90,'…');

        $directions_url=$maps_url;
        if(!$directions_url && $address){
            $directions_url='https://www.google.com/maps/dir/?api=1&destination='.rawurlencode($address);
        }

        ob_start();
        ?>
        <section class="tng-food-experience" data-food-post="<?php echo absint($post_id); ?>">
            <div class="tng-food-card tng-food-overview">
                <span class="tng-food-eyebrow">FOOD &amp; DRINK</span>
                <div class="tng-food-title-row">
                    <div>
                        <h2><?php echo esc_html(get_the_title($post_id)); ?></h2>
                        <div class="tng-food-badges">
                            <?php if($cuisine): ?><span>🍴 <?php echo esc_html($cuisine); ?></span><?php endif; ?>
                            <?php if($price): ?><span>💵 <?php echo esc_html($price); ?></span><?php endif; ?>
                            <?php if($rating): ?><span>⭐ <?php echo esc_html($rating); ?><?php echo $rating_count?' ('.esc_html(number_format_i18n($rating_count)).')':''; ?></span><?php endif; ?>
                        </div>
                    </div>
                </div>

                <?php if($description): ?><p class="tng-food-description"><?php echo esc_html($description); ?></p><?php endif; ?>

                <?php if($services): ?>
                    <div class="tng-food-services">
                        <?php foreach($services as $service): if(isset($service_labels[$service])): ?>
                            <span><?php echo esc_html($service_labels[$service]); ?></span>
                        <?php endif; endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="tng-food-actions">
                    <?php if($directions_url): ?><a href="<?php echo esc_url($directions_url); ?>" target="_blank" rel="noopener">Directions</a><?php endif; ?>
                    <?php if($menu_url): ?><a href="<?php echo esc_url($menu_url); ?>" target="_blank" rel="noopener">Menu</a><?php endif; ?>
                    <?php if($order_url): ?><a href="<?php echo esc_url($order_url); ?>" target="_blank" rel="noopener">Order online</a><?php endif; ?>
                    <?php if($reservation_url): ?><a href="<?php echo esc_url($reservation_url); ?>" target="_blank" rel="noopener">Reserve</a><?php endif; ?>
                    <?php if($phone): ?><a href="tel:<?php echo esc_attr(preg_replace('/[^0-9+]/','',$phone)); ?>">Call</a><?php endif; ?>
                </div>
            </div>

            <div class="tng-food-card tng-food-map-card">
                <div class="tng-food-section-heading">
                    <div><span class="tng-food-eyebrow">LOCATION</span><h3>Restaurant Map</h3></div>
                    <?php if($address): ?><span><?php echo esc_html($address); ?></span><?php endif; ?>
                </div>
                <div class="tng-food-map" data-tng-food-map></div>
                <div class="tng-food-checkin-panel">
                    <div>
                        <strong><?php echo $claimed?'Restaurant completed':'Check in at this restaurant'; ?></strong>
                        <span><?php echo $claimed?'Already counted in Explorer Progress':'Be within '.esc_html($this->food_meta($post_id,'checkin_radius',150)).' ft to earn +'.esc_html($checkin_xp).' Explorer XP'; ?></span>
                    </div>
                    <button type="button" data-tng-food-checkin <?php disabled($claimed); ?>>
                        <?php echo $claimed?'✓ Checked In':'Check In'; ?>
                    </button>
                </div>
                <div class="tng-food-status" data-tng-food-status aria-live="polite"></div>
            </div>

            <div class="tng-food-grid">
                <div class="tng-food-card">
                    <span class="tng-food-eyebrow">DETAILS</span>
                    <h3>Restaurant Information</h3>
                    <dl class="tng-food-details">
                        <?php if($hours): ?><div><dt>Hours</dt><dd><?php echo nl2br(esc_html($hours)); ?></dd></div><?php endif; ?>
                        <?php if($address): ?><div><dt>Address</dt><dd><?php echo esc_html($address); ?></dd></div><?php endif; ?>
                        <?php if($phone): ?><div><dt>Phone</dt><dd><?php echo esc_html($phone); ?></dd></div><?php endif; ?>
                        <?php if($website): ?><div><dt>Website</dt><dd><a href="<?php echo esc_url($website); ?>" target="_blank" rel="noopener">Visit website</a></dd></div><?php endif; ?>
                    </dl>
                </div>

                <div class="tng-food-card">
                    <span class="tng-food-eyebrow">LOCAL PICKS</span>
                    <h3>What to Try</h3>
                    <?php if($featured_dish): ?><p><strong>Featured dish:</strong> <?php echo esc_html($featured_dish); ?></p><?php endif; ?>
                    <?php if($local_tip): ?><p><?php echo nl2br(esc_html($local_tip)); ?></p><?php endif; ?>
                    <?php if(!$featured_dish && !$local_tip): ?><p>Add a featured dish and local recommendation in the Activity editor.</p><?php endif; ?>
                </div>
            </div>
        </section>
        <?php
        return ob_get_clean();
    }

    public function ajax_food_checkin(){
        if(!is_user_logged_in()){
            wp_send_json_error(['message'=>'Sign in to check in and earn XP.'],401);
        }
        check_ajax_referer('tng_food_checkin','nonce');

        $post_id=absint($_POST['post_id']??0);
        $lat=filter_var($_POST['latitude']??null,FILTER_VALIDATE_FLOAT);
        $lng=filter_var($_POST['longitude']??null,FILTER_VALIDATE_FLOAT);
        $accuracy=filter_var($_POST['accuracy']??null,FILTER_VALIDATE_FLOAT);

        if(!$post_id || !$this->is_food_activity($post_id)){
            wp_send_json_error(['message'=>'Invalid restaurant.'],400);
        }
        if($lat===false || $lng===false){
            wp_send_json_error(['message'=>'A valid GPS location is required.'],400);
        }

        $target_lat=(float)$this->food_meta($post_id,'latitude',0);
        $target_lng=(float)$this->food_meta($post_id,'longitude',0);
        if(!$target_lat || !$target_lng){
            wp_send_json_error(['message'=>'This restaurant does not have valid coordinates yet.'],400);
        }
        if($accuracy!==false && $accuracy>75){
            wp_send_json_error(['message'=>'Waiting for a more accurate GPS signal.'],400);
        }

        $distance=$this->odometer_distance_feet($lat,$lng,$target_lat,$target_lng);
        $radius=max(30,absint($this->food_meta($post_id,'checkin_radius',150)));
        if($distance>$radius){
            wp_send_json_error([
                'message'=>'Move closer to check in. You are '.number_format_i18n(round($distance)).' ft away.',
                'distance'=>round($distance),
            ],400);
        }

        $user_id=get_current_user_id();
        $claim_key='_tng_food_checked_in_'.$post_id;
        if(get_user_meta($user_id,$claim_key,true)){
            wp_send_json_success([
                'message'=>'This restaurant was already completed.',
                'alreadyClaimed'=>true,
                'restaurants'=>absint(get_user_meta($user_id,'_tng_total_restaurants',true)),
            ]);
        }

        update_user_meta($user_id,$claim_key,current_time('mysql'));
        $restaurants=absint(get_user_meta($user_id,'_tng_total_restaurants',true))+1;
        update_user_meta($user_id,'_tng_total_restaurants',$restaurants);

        $xp=max(0,absint($this->food_meta($post_id,'checkin_xp',25)));
        if($xp && function_exists('gamipress_award_points_to_user')){
            $settings=$this->progression_settings();
            gamipress_award_points_to_user($user_id,$xp,sanitize_key($settings['points_type']));
        }

        wp_send_json_success([
            'message'=>'Restaurant completed! +'.$xp.' Explorer XP',
            'xpAwarded'=>$xp,
            'restaurants'=>$restaurants,
            'distance'=>round($distance),
        ]);
    }

    private function is_hiking_trail_activity($post_id){
        if(get_post_type($post_id)!=='st_activity') return false;

        foreach(get_object_taxonomies('st_activity','names') as $taxonomy){
            if(has_term('hiking-trails',$taxonomy,$post_id)){
                return true;
            }
        }

        return false;
    }

    private function trail_experience_description($post_id){
        $content=(string)get_post_field('post_content',$post_id);
        if(trim($content)==='') return '';

        /*
         * These shortcodes are page-layout components, not part of the trail
         * description. Remove them before the description is moved into the
         * unified Trail Experience card.
         */
        $layout_shortcodes=[
            'trail_experience',
            'trail_overview',
            'trail_route_map',
            'trail_header',
            'trail_elevation_profile',
            'trail_top_sights',
            'trail_weather',
            'trail_game_button',
            'tng_player_progress',
            'tng_trail_weather',
            'tng_trail_top_sights',
            'tng_game_button',
        ];

        foreach($layout_shortcodes as $shortcode){
            $pattern=get_shortcode_regex([$shortcode]);
            $content=preg_replace('/'.$pattern.'/s','',$content);
        }

        /*
         * Remove any remaining standalone TN Game layout shortcode lines,
         * including aliases added by older plugin versions. This intentionally
         * targets only tng_*, trail_* and tn_game_* shortcode names.
         */
        $content=preg_replace(
            '/^\s*\[(?:tng_|trail_|tn_game_)[a-z0-9_-]+(?:\s+[^\]]*)?\]\s*$/mi',
            '',
            $content
        );

        /*
         * Do not execute shortcodes inside the description. Unknown shortcode
         * text is stripped instead of being shown to visitors.
         */
        $content=strip_shortcodes($content);
        $content=trim($content);

        if($content==='') return '';

        return trim(wpautop(wp_kses_post($content)));
    }

    public function trail_experience_shortcode($atts=[]){
        $atts=shortcode_atts([
            'post_id'=>0,
            'map_height'=>'430px',
            'zoom'=>'13',
            'show_sights'=>'yes',
            'title'=>'',
        ],$atts,'trail_experience');

        $post_id=absint($atts['post_id'])?:get_the_ID();
        if(!$post_id) return '';

        $display_title=trim((string)$atts['title']);
        if($display_title===''){
            $display_title=get_the_title($post_id);
        }

        $gpx=$this->trail_experience_first_field($post_id,[
            'trail_gpx_url','gpx_url','trail_gpx'
        ]);
        if(is_array($gpx)) $gpx=$gpx['url']??'';

        if(empty($gpx)){
            return '<div class="tng-map-error">No GPX URL found for this trail.</div>';
        }

        $difficulty=$this->trail_experience_scalar(
            $this->trail_experience_first_field($post_id,['trail_difficulty','difficulty'])
        );

        [$park_label,$park_url]=$this->trail_experience_park(
            $this->trail_experience_first_field($post_id,['state_park','trail_state_park','park_location'])
        );

        $xp=absint(
            $this->trail_experience_first_field($post_id,['trail_xp','xp_available'])
        );

        $distance=$this->trail_experience_format_distance(
            $this->trail_experience_first_field($post_id,['trail_length','length','trail_distance','distance'])
        );

        $gain=$this->trail_experience_format_gain(
            $this->trail_experience_first_field($post_id,['trail_elevation_gain','elevation_gain','trail_gain'])
        );

        $estimated_time=$this->trail_experience_scalar(
            $this->trail_experience_first_field($post_id,['trail_estimated_time','estimated_time','trail_duration','duration'])
        );

        $route_type=$this->trail_experience_scalar(
            $this->trail_experience_first_field($post_id,['trail_route_type','route_type'])
        );

        $is_hiking_trail=$this->is_hiking_trail_activity($post_id);
        $description=$is_hiking_trail
            ? $this->trail_experience_description($post_id)
            : '';

        wp_enqueue_style('tng-core-css');
        wp_enqueue_script('tng-trail-experience');

        $id='tng-trail-experience-'.wp_rand(1000,999999);

        $map_shortcode=sprintf(
            '[trail_route_map height="%s" zoom="%s" gpx="%s" title="" show_sights="%s"]',
            esc_attr($atts['map_height']),
            esc_attr($atts['zoom']),
            esc_url($gpx),
            esc_attr($atts['show_sights'])
        );

        $data=[
            'rootId'=>$id,
            'gpxUrl'=>esc_url_raw($gpx),
            'distance'=>$distance,
            'gain'=>$gain,
            'estimatedTime'=>$estimated_time,
            'routeType'=>$route_type,
            'hideTravelerDescription'=>$is_hiking_trail,
        ];

        ob_start(); ?>
        <section
            id="<?php echo esc_attr($id); ?>"
            class="tng-trail-experience"
            data-trail-experience="<?php echo esc_attr(wp_json_encode($data)); ?>"
        >
            <header class="tng-trail-experience-header">
                <div class="tng-trail-experience-heading">
                    <span class="tng-trail-experience-eyebrow">TRAIL EXPERIENCE</span>
                    <h2><?php echo esc_html($display_title); ?></h2>
                </div>

                <?php if($difficulty || $park_label || $xp): ?>
                    <div class="tng-trail-experience-pills">
                        <?php if($difficulty): ?>
                            <span class="tng-trail-experience-pill tng-trail-difficulty-<?php echo esc_attr($this->trail_experience_difficulty_class($difficulty)); ?>">
                                <span><?php echo esc_html($this->trail_experience_difficulty_icon($difficulty)); ?></span>
                                <?php echo esc_html($difficulty); ?>
                            </span>
                        <?php endif; ?>

                        <?php if($park_label): ?>
                            <?php if($park_url): ?>
                                <a class="tng-trail-experience-pill" href="<?php echo esc_url($park_url); ?>">
                                    <span>📍</span><?php echo esc_html($park_label); ?>
                                </a>
                            <?php else: ?>
                                <span class="tng-trail-experience-pill">
                                    <span>📍</span><?php echo esc_html($park_label); ?>
                                </span>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php if($xp): ?>
                            <span class="tng-trail-experience-pill tng-trail-xp-pill">
                                <span>🎯</span><?php echo esc_html(number_format_i18n($xp)); ?> XP Available
                            </span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if($description): ?>
                    <div class="tng-trail-experience-description">
                        <?php echo $description; ?>
                    </div>
                <?php endif; ?>

                <div class="tng-trail-experience-stats">
                    <div>
                        <span class="tng-trail-experience-stat-icon">📏</span>
                        <strong><?php echo esc_html($distance?:'—'); ?></strong>
                        <small>Distance</small>
                    </div>
                    <div data-tng-trail-stat="gain">
                        <span class="tng-trail-experience-stat-icon">⛰️</span>
                        <strong><?php echo esc_html($gain?:'—'); ?></strong>
                        <small>Elevation Gain</small>
                    </div>
                    <div>
                        <span class="tng-trail-experience-stat-icon">⏱️</span>
                        <strong><?php echo esc_html($estimated_time?:'—'); ?></strong>
                        <small>Estimated Time</small>
                    </div>
                    <div>
                        <span class="tng-trail-experience-stat-icon">🔁</span>
                        <strong><?php echo esc_html($route_type?:'—'); ?></strong>
                        <small>Route Type</small>
                    </div>
                </div>
            </header>

            <div class="tng-trail-experience-map">
                <?php echo do_shortcode($map_shortcode); ?>
            </div>

            <div class="tng-trail-experience-profile">
                <div class="tng-trail-profile-heading">
                    <div>
                        <span class="tng-trail-experience-eyebrow">INTERACTIVE ELEVATION</span>
                        <h3>Elevation Profile</h3>
                    </div>
                </div>

                <canvas class="tng-trail-profile-canvas" aria-label="Interactive trail elevation profile"></canvas>

                <div class="tng-trail-profile-readout">
                    <strong>Touch or hover on the graph</strong>
                    <span>The marker will move along the trail map</span>
                </div>
            </div>
        </section>
        <?php
        return ob_get_clean();
    }

    public function trail_route_map_shortcode($atts=[]){
        $settings=$this->progression_settings();
        $atts=shortcode_atts([
            'height'=>'500px','zoom'=>'13','gpx'=>'','title'=>'Trail Route','show_sights'=>'yes',
            'fullscreen'=>isset($_GET['trail_game_map'])?'yes':'no',
            'game'=>isset($_GET['trail_game_map'])?'yes':'no',
            'radius_feet'=>(string)$settings['radius']
        ],$atts);

        $post_id=get_the_ID();
        $token=get_option(self::OPTION_TOKEN,'');
        $style=get_option(self::OPTION_STYLE,'mapbox://styles/mapbox/outdoors-v12');
        $gpx=$atts['gpx']?:$this->field('trail_gpx_url',$post_id);
        if(empty($token))return'<div class="tng-map-error">Mapbox token missing. Go to Settings → TN Game Core.</div>';
        if(empty($gpx))return'<div class="tng-map-error">No GPX URL found.</div>';

        wp_enqueue_style('mapbox-gl-css');wp_enqueue_style('tng-core-css');wp_enqueue_script('mapbox-gl-js');wp_enqueue_script('togeojson');wp_enqueue_script('tng-core-js');

        $map_id='tng-trail-map-'.wp_rand(1000,999999);
        $sights=($atts['show_sights']==='yes')?$this->top_sights($post_id):[];
        $full=$atts['fullscreen']==='yes';
        $game=$atts['game']==='yes';
        $height=$full?'100vh':$atts['height'];
        $user_id=get_current_user_id();

        /*
         * Reconcile existing users who had already claimed every checkpoint
         * before trail completion was unified into TN Game Core.
         */
        if($game && $user_id && !empty($sights)){
            $this->maybe_complete_trail($user_id,$post_id);
        }

        $data=[
            'mapId'=>$map_id,
            'token'=>$token,
            'style'=>$style,
            'gpxUrl'=>esc_url_raw($gpx),
            'zoom'=>(float)$atts['zoom'],
            'title'=>sanitize_text_field($atts['title']),
            'sights'=>$sights,
            'game'=>$game,
            'radiusFeet'=>(float)$atts['radius_feet'],
            'postId'=>$post_id,
            'ajaxUrl'=>admin_url('admin-ajax.php'),
            'nonce'=>wp_create_nonce(self::NONCE_ACTION),
            'loggedIn'=>is_user_logged_in(),
            'score'=>$this->user_xp($user_id),
            'claimedIds'=>$this->user_claimed_ids($user_id,$sights),
            'loginUrl'=>wp_login_url(add_query_arg('trail_game_map','1',get_permalink($post_id))),
            'devMode'=>$this->developer_mode_enabled(),
            'devAllSights'=>$this->developer_mode_enabled()?$this->all_top_sights_for_dev():[],
            'editorSaveAction'=>'tng_core_editor_save_sight',
            'simulatorOdometerAction'=>'tng_simulator_odometer_update',
            'canTestOdometer'=>current_user_can('manage_options')
        ];

        ob_start();?><div class="tng-trail-map-wrap <?php echo $full?'tng-fullscreen-map-wrap':'';?>"><div class="tng-trail-map-header"><strong><?php echo esc_html($atts['title']);?></strong><?php if($game):?><span class="tng-game-score">Explorer XP: <b id="<?php echo esc_attr($map_id);?>-score"><?php echo esc_html($data['score']);?></b></span><?php elseif(!empty($sights)):?><span class="tng-sight-count"><?php echo count($sights);?> top sights</span><?php endif;?><?php if($full):?><a class="tng-exit-map" href="<?php echo esc_url(remove_query_arg('trail_game_map'));?>">Exit</a><?php endif;?></div><?php if($game):?><div class="tng-game-message" id="<?php echo esc_attr($map_id);?>-message">Tap the location button, then select a checkpoint to claim it.</div><?php endif;?><div id="<?php echo esc_attr($map_id);?>" class="tng-trail-map" style="height:<?php echo esc_attr($height);?>"></div></div><script>window.TNGTrailMaps=window.TNGTrailMaps||[];window.TNGTrailMaps.push(<?php echo wp_json_encode($data);?>);</script><?php return ob_get_clean();
    }
}

