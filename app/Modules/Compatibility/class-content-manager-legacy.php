<?php
if(!defined('ABSPATH')) exit;

/**
 * TN Game Content Manager
 *
 * Creates a service-oriented administration layer while retaining Traveler
 * st_activity records underneath for compatibility with existing templates,
 * galleries, locations, reviews, favorites and search.
 */
class TNG_Content_Manager {
    const VERSION = '2.1.1';
    const OPTION_SERVICES = 'tng_content_manager_services';
    const ASSET_POST_TYPE = 'tng_asset';
    const ASSET_TAXONOMY = 'tng_asset_type';

    private $service_definitions = [];
    private static $instance_initialized = false;
    private static $admin_pages_registered = false;

    public function __construct(){
        $this->service_definitions = $this->service_definitions();

        if (self::$instance_initialized) return;
        self::$instance_initialized = true;

        add_action('init',[$this,'register_asset_library']);
        add_action('admin_menu',[$this,'register_admin_pages'],30);
        add_action('admin_enqueue_scripts',[$this,'enqueue_admin_assets']);
        add_action('admin_init',[$this,'handle_admin_actions']);

        add_action('add_meta_boxes',[$this,'add_asset_meta_box']);
        add_action('save_post_'.self::ASSET_POST_TYPE,[$this,'save_asset_meta'],10,2);

        add_filter('manage_'.self::ASSET_POST_TYPE.'_posts_columns',[$this,'asset_columns']);
        add_action('manage_'.self::ASSET_POST_TYPE.'_posts_custom_column',[$this,'asset_column_content'],10,2);

        add_filter('post_row_actions',[$this,'activity_row_actions'],10,2);
        add_action('admin_post_tng_quick_duplicate',[$this,'quick_duplicate_action']);

        add_action('wp_ajax_tng_content_find_google_place',[$this,'ajax_find_google_place']);
    }

    private function service_definitions(){
        return [
            'trail'=>[
                'label'=>'Trails',
                'singular'=>'Trail',
                'icon'=>'dashicons-location-alt',
                'term'=>'hiking-trails',
                'color'=>'#16a34a',
                'description'=>'GPX routes, state parks, trail details, checkpoints and weather.',
            ],
            'food'=>[
                'label'=>'Food & Drink',
                'singular'=>'Restaurant',
                'icon'=>'dashicons-food',
                'term'=>'food-and-drink',
                'color'=>'#f97316',
                'description'=>'Restaurants, cafés, breweries, check-ins, menus and Google Places.',
            ],
            'concert'=>[
                'label'=>'Concerts',
                'singular'=>'Concert',
                'icon'=>'dashicons-format-audio',
                'term'=>'concerts',
                'color'=>'#8b5cf6',
                'description'=>'Performers, venues, dates, tickets, posters and event imports.',
            ],
            'shop'=>[
                'label'=>'Shops',
                'singular'=>'Shop',
                'icon'=>'dashicons-store',
                'term'=>'shops',
                'color'=>'#ec4899',
                'description'=>'Local shops, boutiques, retail check-ins and featured products.',
            ],
            'history'=>[
                'label'=>'Historic Sites',
                'singular'=>'Historic Site',
                'icon'=>'dashicons-building',
                'term'=>'historic-sites',
                'color'=>'#a16207',
                'description'=>'Historic places, markers, museums and discovery check-ins.',
            ],
            'waterfall'=>[
                'label'=>'Waterfalls',
                'singular'=>'Waterfall',
                'icon'=>'dashicons-image-filter',
                'term'=>'waterfalls',
                'color'=>'#0ea5e9',
                'description'=>'Waterfalls as destinations or reusable Top Sight assets.',
            ],
            'campground'=>[
                'label'=>'Campgrounds',
                'singular'=>'Campground',
                'icon'=>'dashicons-palmtree',
                'term'=>'campgrounds',
                'color'=>'#15803d',
                'description'=>'Campgrounds, campsites, amenities, reservations and nearby trails.',
            ],
            'lodging'=>[
                'label'=>'Lodging',
                'singular'=>'Lodging',
                'icon'=>'dashicons-admin-home',
                'term'=>'lodging',
                'color'=>'#2563eb',
                'description'=>'Hotels, cabins, vacation rentals and affiliate booking links.',
            ],
            'event'=>[
                'label'=>'Events',
                'singular'=>'Event',
                'icon'=>'dashicons-calendar-alt',
                'term'=>'events',
                'color'=>'#dc2626',
                'description'=>'Festivals, markets, community events and scheduled activities.',
            ],
            'scenic'=>[
                'label'=>'Scenic Views',
                'singular'=>'Scenic View',
                'icon'=>'dashicons-format-image',
                'term'=>'scenic-views',
                'color'=>'#0891b2',
                'description'=>'Overlooks, viewpoints and scenic discovery locations.',
            ],
        ];
    }

    public function register_asset_library(){
        register_post_type(self::ASSET_POST_TYPE,[
            'labels'=>[
                'name'=>'TN Game Assets',
                'singular_name'=>'TN Game Asset',
                'add_new_item'=>'Add Reusable Asset',
                'edit_item'=>'Edit Reusable Asset',
                'search_items'=>'Search Assets',
            ],
            'public'=>false,
            'show_ui'=>true,
            'show_in_menu'=>false,
            'supports'=>['title','thumbnail','editor'],
            'capability_type'=>'post',
            'map_meta_cap'=>true,
        ]);

        register_taxonomy(self::ASSET_TAXONOMY,self::ASSET_POST_TYPE,[
            'labels'=>[
                'name'=>'Asset Types',
                'singular_name'=>'Asset Type',
            ],
            'public'=>false,
            'show_ui'=>true,
            'show_admin_column'=>true,
            'hierarchical'=>true,
        ]);

        $types=[
            'gpx-route'=>'GPX Route',
            'photo-collection'=>'Photo Collection',
            'location'=>'Reusable Location',
            'venue'=>'Venue',
            'state-park'=>'State Park',
            'badge'=>'Badge',
            'top-sight'=>'Top Sight',
            'import-source'=>'Import Source',
        ];

        foreach($types as $slug=>$name){
            if(!term_exists($slug,self::ASSET_TAXONOMY)){
                wp_insert_term($name,self::ASSET_TAXONOMY,['slug'=>$slug]);
            }
        }
    }

    public function register_admin_pages(){
        if (self::$admin_pages_registered) return;
        self::$admin_pages_registered = true;
        add_submenu_page(
            'tn-game-os',
            'Content Dashboard',
            'Content Dashboard',
            'edit_posts',
            'tn-game-content-dashboard',
            [$this,'dashboard_page']
        );

        add_submenu_page(
            'tn-game-os',
            'Content Wizard',
            '＋ Content Wizard',
            'edit_posts',
            'tn-game-content-wizard',
            [$this,'wizard_page']
        );

        foreach($this->service_definitions as $key=>$service){
            add_submenu_page(
                'tn-game-os',
                $service['label'],
                $service['label'],
                'edit_posts',
                'tn-game-service-'.$key,
                function() use ($key){ $this->service_page($key); }
            );
        }

        add_submenu_page(
            'tn-game-os',
            'Quick Duplicate',
            'Quick Duplicate',
            'edit_posts',
            'tn-game-quick-duplicate',
            [$this,'quick_duplicate_page']
        );

        add_submenu_page(
            'tn-game-os',
            'Asset Library',
            'Asset Library',
            'upload_files',
            'tn-game-asset-library',
            [$this,'asset_library_page']
        );

        add_submenu_page(
            'tn-game-os',
            'Import Center',
            'Import Center',
            'manage_options',
            'tn-game-import-center',
            [$this,'import_center_page']
        );

        add_submenu_page(
            'tn-game-os',
            'Content Settings',
            'Content Settings',
            'manage_options',
            'tn-game-content-settings',
            [$this,'settings_page']
        );
    }

    public function enqueue_admin_assets($hook){
        $is_content_manager_page =
            strpos((string)$hook,'tn-game')!==false ||
            strpos((string)$hook,'tng-os')!==false ||
            (!empty($_GET['page']) && (
                strpos(sanitize_key(wp_unslash($_GET['page'])),'tn-game')===0 ||
                strpos(sanitize_key(wp_unslash($_GET['page'])),'tng-os')===0
            ));

        if(!$is_content_manager_page && get_post_type()!==self::ASSET_POST_TYPE){
            return;
        }

        wp_enqueue_media();
        wp_enqueue_style(
            'tng-content-manager',
            TNG_OS_URL.'assets/tng-content-manager.css',
            [],
            self::VERSION
        );
        wp_enqueue_script(
            'tng-content-manager',
            TNG_OS_URL.'assets/tng-content-manager.js',
            ['jquery'],
            self::VERSION,
            true
        );
        wp_localize_script('tng-content-manager','TNGContentManager',[
            'ajaxUrl'=>admin_url('admin-ajax.php'),
            'googleNonce'=>wp_create_nonce('tng_content_find_google_place'),
        ]);
    }

    private function enabled_services(){
        $saved=get_option(self::OPTION_SERVICES,[]);
        if(!is_array($saved)) $saved=[];
        $result=[];
        foreach($this->service_definitions as $key=>$definition){
            $result[$key]=array_key_exists($key,$saved)?!empty($saved[$key]):true;
        }
        return $result;
    }

    private function activity_type_taxonomy(){
        $preferred=[
            'st_activity_type',
            'activity_type',
            'st_activity_types',
        ];

        foreach($preferred as $taxonomy){
            if(taxonomy_exists($taxonomy)) return $taxonomy;
        }

        $taxonomies=get_object_taxonomies('st_activity','objects');
        foreach($taxonomies as $taxonomy){
            if(!empty($taxonomy->hierarchical)) return $taxonomy->name;
        }

        return '';
    }

    private function ensure_service_term($service_key){
        if(!isset($this->service_definitions[$service_key])) return null;
        $taxonomy=$this->activity_type_taxonomy();
        if(!$taxonomy) return null;

        $definition=$this->service_definitions[$service_key];
        $term=get_term_by('slug',$definition['term'],$taxonomy);

        if(!$term){
            $created=wp_insert_term(
                $definition['label'],
                $taxonomy,
                ['slug'=>$definition['term']]
            );
            if(!is_wp_error($created)){
                $term=get_term($created['term_id'],$taxonomy);
            }
        }

        return $term && !is_wp_error($term)
            ? ['taxonomy'=>$taxonomy,'term'=>$term]
            : null;
    }

    private function service_query_args($service_key,$limit=50){
        $args=[
            'post_type'=>'st_activity',
            'post_status'=>['publish','draft','pending','private'],
            'posts_per_page'=>$limit,
            'orderby'=>'modified',
            'order'=>'DESC',
        ];

        $term_data=$this->ensure_service_term($service_key);
        if($term_data){
            $args['tax_query']=[[
                'taxonomy'=>$term_data['taxonomy'],
                'field'=>'term_id',
                'terms'=>[$term_data['term']->term_id],
            ]];
        }else{
            $args['meta_query']=[[
                'key'=>'_tng_content_service',
                'value'=>$service_key,
            ]];
        }

        return $args;
    }

    public function dashboard_page(){
        if(!current_user_can('edit_posts')) return;
        $enabled=$this->enabled_services();
        ?>
        <div class="wrap tng-cm-wrap">
            <div class="tng-cm-hero">
                <div>
                    <span class="tng-cm-eyebrow">TN GAME CONTENT MANAGER</span>
                    <h1>Build every experience from one place</h1>
                    <p>Traveler remains the listing engine. TN Game Core now provides the service-specific workflow.</p>
                </div>
                <a class="button button-primary button-hero" href="<?php echo esc_url(admin_url('admin.php?page=tn-game-content-wizard')); ?>">Create New Content</a>
            </div>

            <div class="tng-cm-service-grid">
                <?php foreach($this->service_definitions as $key=>$service):
                    if(empty($enabled[$key])) continue;
                    $query=new WP_Query($this->service_query_args($key,1));
                    $count=(int)$query->found_posts;
                    wp_reset_postdata();
                ?>
                    <a class="tng-cm-service-card" href="<?php echo esc_url(admin_url('admin.php?page=tn-game-service-'.$key)); ?>" style="--service-color:<?php echo esc_attr($service['color']); ?>">
                        <span class="dashicons <?php echo esc_attr($service['icon']); ?>"></span>
                        <strong><?php echo esc_html($service['label']); ?></strong>
                        <small><?php echo esc_html($service['description']); ?></small>
                        <b><?php echo esc_html(number_format_i18n($count)); ?> listings</b>
                    </a>
                <?php endforeach; ?>
            </div>

            <div class="tng-cm-actions-grid">
                <a class="tng-cm-action-card" href="<?php echo esc_url(admin_url('admin.php?page=tn-game-quick-duplicate')); ?>">
                    <span class="dashicons dashicons-admin-page"></span>
                    <div><strong>Quick Duplicate</strong><small>Clone an existing listing and choose exactly what carries over.</small></div>
                </a>
                <a class="tng-cm-action-card" href="<?php echo esc_url(admin_url('admin.php?page=tn-game-asset-library')); ?>">
                    <span class="dashicons dashicons-portfolio"></span>
                    <div><strong>Asset Library</strong><small>Reuse GPX routes, locations, venues, badges and photo collections.</small></div>
                </a>
                <a class="tng-cm-action-card" href="<?php echo esc_url(admin_url('admin.php?page=tn-game-import-center')); ?>">
                    <span class="dashicons dashicons-download"></span>
                    <div><strong>Import Center</strong><small>Google Places, concert feeds and future import connections.</small></div>
                </a>
            </div>
        </div>
        <?php
    }

    public function wizard_page(){
        if(!current_user_can('edit_posts')) return;
        $enabled=$this->enabled_services();
        $selected=sanitize_key($_GET['type']??'');
        ?>
        <div class="wrap tng-cm-wrap">
            <div class="tng-cm-page-heading">
                <div><span class="tng-cm-eyebrow">CONTENT WIZARD</span><h1>What are you adding?</h1></div>
            </div>

            <?php if(!$selected || !isset($this->service_definitions[$selected])): ?>
                <div class="tng-cm-wizard-grid">
                    <?php foreach($this->service_definitions as $key=>$service):
                        if(empty($enabled[$key])) continue;
                    ?>
                        <a href="<?php echo esc_url(add_query_arg(['page'=>'tn-game-content-wizard','type'=>$key],admin_url('admin.php'))); ?>" style="--service-color:<?php echo esc_attr($service['color']); ?>">
                            <span class="dashicons <?php echo esc_attr($service['icon']); ?>"></span>
                            <strong><?php echo esc_html($service['singular']); ?></strong>
                            <small><?php echo esc_html($service['description']); ?></small>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php else:
                $service=$this->service_definitions[$selected];
                $this->wizard_form($selected,$service);
            endif; ?>
        </div>
        <?php
    }

    private function wizard_form($type,$service){
        ?>
        <form method="post" class="tng-cm-form">
            <?php wp_nonce_field('tng_content_wizard','tng_content_wizard_nonce'); ?>
            <input type="hidden" name="tng_cm_action" value="create_content">
            <input type="hidden" name="service_type" value="<?php echo esc_attr($type); ?>">

            <div class="tng-cm-form-header" style="--service-color:<?php echo esc_attr($service['color']); ?>">
                <span class="dashicons <?php echo esc_attr($service['icon']); ?>"></span>
                <div><small>NEW <?php echo esc_html(strtoupper($service['singular'])); ?></small><h2><?php echo esc_html($service['singular']); ?> Setup</h2></div>
            </div>

            <div class="tng-cm-form-grid">
                <label class="tng-cm-field tng-cm-full">
                    <span>Name</span>
                    <input type="text" name="content_title" required placeholder="<?php echo esc_attr($service['singular'].' name'); ?>">
                </label>

                <label class="tng-cm-field tng-cm-full">
                    <span>Description</span>
                    <textarea name="content_description" rows="6" placeholder="Write the main public description."></textarea>
                </label>

                <label class="tng-cm-field">
                    <span>Community / location</span>
                    <input type="text" name="community" placeholder="Tracy City, Pelham, Monteagle…">
                </label>

                <label class="tng-cm-field">
                    <span>Featured image</span>
                    <div class="tng-cm-media-field">
                        <input type="hidden" name="featured_image_id" data-media-id>
                        <button type="button" class="button" data-media-button>Select image</button>
                        <span data-media-name>No image selected</span>
                    </div>
                </label>

                <?php $this->wizard_type_fields($type); ?>

                <div class="tng-cm-field tng-cm-full">
                    <span>Create as</span>
                    <label class="tng-cm-inline-radio"><input type="radio" name="post_status" value="draft" checked> Draft</label>
                    <label class="tng-cm-inline-radio"><input type="radio" name="post_status" value="publish"> Published</label>
                </div>
            </div>

            <div class="tng-cm-form-actions">
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=tn-game-content-wizard')); ?>">Choose another type</a>
                <button class="button button-primary button-hero" type="submit">Create <?php echo esc_html($service['singular']); ?></button>
            </div>
        </form>
        <?php
    }

    private function wizard_type_fields($type){
        if($type==='trail'): ?>
            <label class="tng-cm-field"><span>State park</span><input type="text" name="service[state_park]"></label>
            <label class="tng-cm-field"><span>Difficulty</span><select name="service[difficulty]"><option>Easy</option><option selected>Moderate</option><option>Hard</option></select></label>
            <label class="tng-cm-field"><span>Distance (miles)</span><input type="number" step="0.01" name="service[distance]"></label>
            <label class="tng-cm-field"><span>Estimated time</span><input type="text" name="service[estimated_time]" placeholder="1–1.5 hr"></label>
            <label class="tng-cm-field"><span>Route type</span><select name="service[route_type]"><option>Loop</option><option>Out-and-Back</option><option>Point-to-Point</option></select></label>
            <label class="tng-cm-field"><span>GPX asset</span><?php $this->asset_select('gpx-route','service[gpx_asset]'); ?></label>
        <?php elseif($type==='food'): ?>
            <label class="tng-cm-field"><span>Cuisine</span><input type="text" name="service[cuisine]" placeholder="Southern, BBQ, Coffee"></label>
            <label class="tng-cm-field"><span>Price range</span><select name="service[price_range]"><option value="$">$</option><option value="$$" selected>$$</option><option value="$$$">$$$</option></select></label>
            <label class="tng-cm-field"><span>Google Place ID</span><input type="text" name="service[google_place_id]"></label>
            <label class="tng-cm-field"><span>Check-in XP</span><input type="number" name="service[checkin_xp]" value="25"></label>
        <?php elseif($type==='concert' || $type==='event'): ?>
            <label class="tng-cm-field"><span>Venue</span><?php $this->asset_select('venue','service[venue_asset]'); ?></label>
            <label class="tng-cm-field"><span>Date and time</span><input type="datetime-local" name="service[event_datetime]"></label>
            <label class="tng-cm-field"><span>Ticket URL</span><input type="url" name="service[ticket_url]"></label>
            <label class="tng-cm-field"><span>Performer / organizer</span><input type="text" name="service[performer]"></label>
        <?php elseif($type==='shop'): ?>
            <label class="tng-cm-field"><span>Shop category</span><input type="text" name="service[shop_category]"></label>
            <label class="tng-cm-field"><span>Website</span><input type="url" name="service[website]"></label>
            <label class="tng-cm-field"><span>Check-in XP</span><input type="number" name="service[checkin_xp]" value="25"></label>
        <?php elseif($type==='history' || $type==='waterfall' || $type==='scenic'): ?>
            <label class="tng-cm-field"><span>Latitude</span><input type="text" name="service[latitude]"></label>
            <label class="tng-cm-field"><span>Longitude</span><input type="text" name="service[longitude]"></label>
            <label class="tng-cm-field"><span>Discovery XP</span><input type="number" name="service[checkin_xp]" value="25"></label>
            <label class="tng-cm-field"><span>Create reusable Top Sight asset</span><select name="service[create_asset]"><option value="1">Yes</option><option value="0">No</option></select></label>
        <?php elseif($type==='lodging' || $type==='campground'): ?>
            <label class="tng-cm-field"><span>Booking URL</span><input type="url" name="service[booking_url]"></label>
            <label class="tng-cm-field"><span>Phone</span><input type="text" name="service[phone]"></label>
            <label class="tng-cm-field"><span>Google Place ID</span><input type="text" name="service[google_place_id]"></label>
            <label class="tng-cm-field"><span>Amenities</span><input type="text" name="service[amenities]" placeholder="Wi-Fi, parking, pet-friendly"></label>
        <?php endif;
    }

    private function asset_select($asset_type,$name){
        $query=new WP_Query([
            'post_type'=>self::ASSET_POST_TYPE,
            'post_status'=>'publish',
            'posts_per_page'=>100,
            'orderby'=>'title',
            'order'=>'ASC',
            'tax_query'=>[[
                'taxonomy'=>self::ASSET_TAXONOMY,
                'field'=>'slug',
                'terms'=>[$asset_type],
            ]],
        ]);
        echo '<select name="'.esc_attr($name).'"><option value="">Choose an asset…</option>';
        while($query->have_posts()){
            $query->the_post();
            echo '<option value="'.esc_attr(get_the_ID()).'">'.esc_html(get_the_title()).'</option>';
        }
        wp_reset_postdata();
        echo '</select>';
    }

    public function service_page($service_key){
        if(!current_user_can('edit_posts') || !isset($this->service_definitions[$service_key])) return;
        $service=$this->service_definitions[$service_key];
        $query=new WP_Query($this->service_query_args($service_key,100));
        ?>
        <div class="wrap tng-cm-wrap">
            <div class="tng-cm-page-heading">
                <div>
                    <span class="tng-cm-eyebrow">VIRTUAL TRAVELER SERVICE</span>
                    <h1><?php echo esc_html($service['label']); ?></h1>
                    <p><?php echo esc_html($service['description']); ?></p>
                </div>
                <a class="button button-primary button-hero" href="<?php echo esc_url(add_query_arg(['page'=>'tn-game-content-wizard','type'=>$service_key],admin_url('admin.php'))); ?>">Add <?php echo esc_html($service['singular']); ?></a>
            </div>

            <div class="tng-cm-list-card">
                <table class="widefat striped tng-cm-table">
                    <thead><tr><th>Listing</th><th>Status</th><th>Modified</th><th>Location</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php if($query->have_posts()): while($query->have_posts()): $query->the_post(); $id=get_the_ID(); ?>
                        <tr>
                            <td>
                                <?php if(has_post_thumbnail($id)): echo get_the_post_thumbnail($id,[54,54]); endif; ?>
                                <div><strong><?php echo esc_html(get_the_title()); ?></strong><small>ID <?php echo absint($id); ?></small></div>
                            </td>
                            <td><span class="tng-cm-status tng-cm-status-<?php echo esc_attr(get_post_status($id)); ?>"><?php echo esc_html(ucfirst(get_post_status($id))); ?></span></td>
                            <td><?php echo esc_html(get_the_modified_date('', $id)); ?></td>
                            <td><?php echo esc_html(get_post_meta($id,'_tng_content_community',true)?:'—'); ?></td>
                            <td>
                                <a href="<?php echo esc_url(get_edit_post_link($id)); ?>">Edit</a>
                                · <a href="<?php echo esc_url(get_permalink($id)); ?>" target="_blank">View</a>
                                · <a href="<?php echo esc_url(admin_url('admin.php?page=tn-game-quick-duplicate&source='.$id)); ?>">Duplicate</a>
                            </td>
                        </tr>
                    <?php endwhile; wp_reset_postdata(); else: ?>
                        <tr><td colspan="5">No <?php echo esc_html(strtolower($service['label'])); ?> have been created yet.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    public function quick_duplicate_page(){
        if(!current_user_can('edit_posts')) return;
        $source=absint($_GET['source']??0);
        $recent=get_posts([
            'post_type'=>'st_activity',
            'post_status'=>['publish','draft','pending'],
            'posts_per_page'=>100,
            'orderby'=>'modified',
            'order'=>'DESC',
        ]);
        ?>
        <div class="wrap tng-cm-wrap">
            <div class="tng-cm-page-heading">
                <div><span class="tng-cm-eyebrow">PRODUCTIVITY TOOL</span><h1>Quick Duplicate</h1><p>Clone a Traveler Activity and decide what should be carried into the new draft.</p></div>
            </div>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="tng-cm-form tng-cm-duplicate-form">
                <?php wp_nonce_field('tng_quick_duplicate','tng_quick_duplicate_nonce'); ?>
                <input type="hidden" name="action" value="tng_quick_duplicate">

                <label class="tng-cm-field tng-cm-full">
                    <span>Source listing</span>
                    <select name="source_id" required>
                        <option value="">Choose a listing…</option>
                        <?php foreach($recent as $post): ?>
                            <option value="<?php echo absint($post->ID); ?>" <?php selected($source,$post->ID); ?>><?php echo esc_html($post->post_title); ?> — <?php echo esc_html(ucfirst($post->post_status)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label class="tng-cm-field tng-cm-full">
                    <span>New title</span>
                    <input type="text" name="new_title" placeholder="Leave blank to add “Copy”">
                </label>

                <div class="tng-cm-clone-options">
                    <?php
                    $options=[
                        'taxonomies'=>'Categories, locations and terms',
                        'featured_image'=>'Featured image',
                        'gallery'=>'Gallery fields',
                        'gpx'=>'GPX route and trail data',
                        'top_sights'=>'Top Sight relationships',
                        'food'=>'Food & Drink fields',
                        'progression'=>'XP and game settings',
                        'all_meta'=>'All remaining custom fields',
                    ];
                    foreach($options as $key=>$label): ?>
                        <label><input type="checkbox" name="clone[]" value="<?php echo esc_attr($key); ?>" checked> <?php echo esc_html($label); ?></label>
                    <?php endforeach; ?>
                </div>

                <div class="tng-cm-form-actions">
                    <button class="button button-primary button-hero" type="submit">Create Duplicate Draft</button>
                </div>
            </form>
        </div>
        <?php
    }

    public function quick_duplicate_action(){
        if(!current_user_can('edit_posts')) wp_die('Permission denied.');
        check_admin_referer('tng_quick_duplicate','tng_quick_duplicate_nonce');

        $source_id=absint($_POST['source_id']??0);
        $source=get_post($source_id);
        if(!$source || $source->post_type!=='st_activity') wp_die('Invalid source listing.');

        $clone=array_map('sanitize_key',(array)($_POST['clone']??[]));
        $new_title=sanitize_text_field(wp_unslash($_POST['new_title']??''));
        if($new_title==='') $new_title=$source->post_title.' Copy';

        $new_id=wp_insert_post([
            'post_type'=>'st_activity',
            'post_status'=>'draft',
            'post_title'=>$new_title,
            'post_content'=>$source->post_content,
            'post_excerpt'=>$source->post_excerpt,
            'post_author'=>get_current_user_id(),
        ],true);

        if(is_wp_error($new_id)) wp_die(esc_html($new_id->get_error_message()));

        if(in_array('taxonomies',$clone,true)){
            foreach(get_object_taxonomies('st_activity') as $taxonomy){
                $terms=wp_get_object_terms($source_id,$taxonomy,['fields'=>'ids']);
                if(!is_wp_error($terms)) wp_set_object_terms($new_id,$terms,$taxonomy);
            }
        }

        $meta=get_post_meta($source_id);
        $gallery_patterns=['gallery','image','photo'];
        $gpx_patterns=['gpx','trail','distance','elevation','route_type','estimated_time','difficulty','state_park'];
        $sight_patterns=['sight','checkpoint'];
        $food_patterns=['_tng_food_'];
        $progression_patterns=['xp','gamipress','reward','radius'];

        foreach($meta as $key=>$values){
            if(in_array($key,['_edit_lock','_edit_last'],true)) continue;

            $copy=false;
            if($key==='_thumbnail_id' && in_array('featured_image',$clone,true)) $copy=true;
            if($this->key_matches($key,$gallery_patterns) && in_array('gallery',$clone,true)) $copy=true;
            if($this->key_matches($key,$gpx_patterns) && in_array('gpx',$clone,true)) $copy=true;
            if($this->key_matches($key,$sight_patterns) && in_array('top_sights',$clone,true)) $copy=true;
            if($this->key_matches($key,$food_patterns) && in_array('food',$clone,true)) $copy=true;
            if($this->key_matches($key,$progression_patterns) && in_array('progression',$clone,true)) $copy=true;
            if(in_array('all_meta',$clone,true)) $copy=true;

            if(!$copy) continue;
            foreach($values as $value){
                add_post_meta($new_id,$key,maybe_unserialize($value));
            }
        }

        update_post_meta($new_id,'_tng_duplicated_from',$source_id);
        update_post_meta($new_id,'_tng_duplicated_at',current_time('mysql'));

        wp_safe_redirect(get_edit_post_link($new_id,'url'));
        exit;
    }

    private function key_matches($key,$patterns){
        foreach($patterns as $pattern){
            if(stripos($key,$pattern)!==false) return true;
        }
        return false;
    }

    public function activity_row_actions($actions,$post){
        if($post->post_type!=='st_activity' || !current_user_can('edit_post',$post->ID)) return $actions;
        $url=wp_nonce_url(
            admin_url('admin-post.php?action=tng_quick_duplicate&source_id='.$post->ID.'&clone[]=taxonomies&clone[]=featured_image&clone[]=gallery&clone[]=gpx&clone[]=top_sights&clone[]=food&clone[]=progression&clone[]=all_meta'),
            'tng_quick_duplicate',
            'tng_quick_duplicate_nonce'
        );
        $actions['tng_duplicate']='<a href="'.esc_url(admin_url('admin.php?page=tn-game-quick-duplicate&source='.$post->ID)).'">TN Game Duplicate</a>';
        return $actions;
    }

    public function asset_library_page(){
        if(!current_user_can('upload_files')) return;
        $counts=wp_count_posts(self::ASSET_POST_TYPE);
        ?>
        <div class="wrap tng-cm-wrap">
            <div class="tng-cm-page-heading">
                <div><span class="tng-cm-eyebrow">REUSABLE CONTENT</span><h1>TN Game Asset Library</h1><p>Store routes, locations, venues, badges and shared media once, then reuse them in the Content Wizard.</p></div>
                <a class="button button-primary button-hero" href="<?php echo esc_url(admin_url('post-new.php?post_type='.self::ASSET_POST_TYPE)); ?>">Add Asset</a>
            </div>

            <div class="tng-cm-metrics">
                <div><strong><?php echo esc_html(number_format_i18n((int)($counts->publish??0))); ?></strong><span>Published assets</span></div>
                <div><strong><?php echo esc_html(number_format_i18n((int)($counts->draft??0))); ?></strong><span>Draft assets</span></div>
                <div><strong>8</strong><span>Asset types</span></div>
            </div>

            <p>
                <a class="button" href="<?php echo esc_url(admin_url('edit.php?post_type='.self::ASSET_POST_TYPE)); ?>">Browse all assets</a>
                <a class="button" href="<?php echo esc_url(admin_url('edit-tags.php?taxonomy='.self::ASSET_TAXONOMY.'&post_type='.self::ASSET_POST_TYPE)); ?>">Manage asset types</a>
            </p>
        </div>
        <?php
    }

    public function add_asset_meta_box(){
        add_meta_box(
            'tng-asset-details',
            'Reusable Asset Details',
            [$this,'asset_meta_box'],
            self::ASSET_POST_TYPE,
            'normal',
            'high'
        );
    }

    public function asset_meta_box($post){
        wp_nonce_field('tng_asset_save','tng_asset_nonce');
        $file_id=absint(get_post_meta($post->ID,'_tng_asset_file_id',true));
        $file_url=(string)get_post_meta($post->ID,'_tng_asset_file_url',true);
        $latitude=(string)get_post_meta($post->ID,'_tng_asset_latitude',true);
        $longitude=(string)get_post_meta($post->ID,'_tng_asset_longitude',true);
        $external_url=(string)get_post_meta($post->ID,'_tng_asset_external_url',true);
        ?>
        <div class="tng-cm-form-grid">
            <label class="tng-cm-field tng-cm-full">
                <span>WordPress media file</span>
                <div class="tng-cm-media-field">
                    <input type="hidden" name="tng_asset_file_id" value="<?php echo esc_attr($file_id); ?>" data-media-id>
                    <button type="button" class="button" data-media-button>Select file</button>
                    <span data-media-name><?php echo esc_html($file_url?:'No file selected'); ?></span>
                </div>
            </label>
            <label class="tng-cm-field tng-cm-full"><span>External URL</span><input type="url" name="tng_asset_external_url" value="<?php echo esc_attr($external_url); ?>"></label>
            <label class="tng-cm-field"><span>Latitude</span><input type="text" name="tng_asset_latitude" value="<?php echo esc_attr($latitude); ?>"></label>
            <label class="tng-cm-field"><span>Longitude</span><input type="text" name="tng_asset_longitude" value="<?php echo esc_attr($longitude); ?>"></label>
        </div>
        <?php
    }

    public function save_asset_meta($post_id,$post){
        if(
            !isset($_POST['tng_asset_nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['tng_asset_nonce'])),'tng_asset_save') ||
            !current_user_can('edit_post',$post_id) ||
            (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
        ) return;

        $file_id=absint($_POST['tng_asset_file_id']??0);
        update_post_meta($post_id,'_tng_asset_file_id',$file_id);
        update_post_meta($post_id,'_tng_asset_file_url',$file_id?wp_get_attachment_url($file_id):'');
        update_post_meta($post_id,'_tng_asset_external_url',esc_url_raw(wp_unslash($_POST['tng_asset_external_url']??'')));
        update_post_meta($post_id,'_tng_asset_latitude',sanitize_text_field(wp_unslash($_POST['tng_asset_latitude']??'')));
        update_post_meta($post_id,'_tng_asset_longitude',sanitize_text_field(wp_unslash($_POST['tng_asset_longitude']??'')));
    }

    public function asset_columns($columns){
        $result=[];
        foreach($columns as $key=>$label){
            $result[$key]=$label;
            if($key==='title'){
                $result['tng_asset_file']='File / URL';
                $result['tng_asset_location']='Coordinates';
            }
        }
        return $result;
    }

    public function asset_column_content($column,$post_id){
        if($column==='tng_asset_file'){
            $url=get_post_meta($post_id,'_tng_asset_file_url',true)?:get_post_meta($post_id,'_tng_asset_external_url',true);
            echo $url?'<a href="'.esc_url($url).'" target="_blank">Open asset</a>':'—';
        }
        if($column==='tng_asset_location'){
            $lat=get_post_meta($post_id,'_tng_asset_latitude',true);
            $lng=get_post_meta($post_id,'_tng_asset_longitude',true);
            echo esc_html($lat&&$lng?$lat.', '.$lng:'—');
        }
    }

    public function import_center_page(){
        if(!current_user_can('manage_options')) return;
        ?>
        <div class="wrap tng-cm-wrap">
            <div class="tng-cm-page-heading">
                <div><span class="tng-cm-eyebrow">CONNECTED CONTENT</span><h1>Import Center</h1><p>One home for current and future third-party content sources.</p></div>
            </div>
            <div class="tng-cm-import-grid">
                <div class="tng-cm-import-card">
                    <span class="dashicons dashicons-google"></span><h2>Google Places</h2>
                    <p>Restaurant and place details are available through the Food & Drink importer.</p>
                    <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=tn-game-food')); ?>">Open Google Places settings</a>
                </div>
                <div class="tng-cm-import-card">
                    <span class="dashicons dashicons-tickets-alt"></span><h2>Concert Imports</h2>
                    <p>Reserved for Tixr, venue feeds, ICS, Bandsintown or your existing Caverns endpoint.</p>
                    <span class="tng-cm-coming-soon">Connector ready for next build</span>
                </div>
                <div class="tng-cm-import-card">
                    <span class="dashicons dashicons-admin-site-alt3"></span><h2>Bulk Spreadsheet</h2>
                    <p>Planned CSV import for trails, restaurants, shops and attractions.</p>
                    <span class="tng-cm-coming-soon">Schema foundation added</span>
                </div>
            </div>
        </div>
        <?php
    }

    public function settings_page(){
        if(!current_user_can('manage_options')) return;
        $enabled=$this->enabled_services();
        ?>
        <div class="wrap tng-cm-wrap">
            <div class="tng-cm-page-heading"><div><span class="tng-cm-eyebrow">CONTENT MANAGER</span><h1>Service Settings</h1><p>Enable the virtual services you want TN Game Core to manage.</p></div></div>
            <form method="post" class="tng-cm-form">
                <?php wp_nonce_field('tng_content_settings','tng_content_settings_nonce'); ?>
                <input type="hidden" name="tng_cm_action" value="save_settings">
                <div class="tng-cm-settings-list">
                    <?php foreach($this->service_definitions as $key=>$service): ?>
                        <label style="--service-color:<?php echo esc_attr($service['color']); ?>">
                            <input type="checkbox" name="services[<?php echo esc_attr($key); ?>]" value="1" <?php checked(!empty($enabled[$key])); ?>>
                            <span class="dashicons <?php echo esc_attr($service['icon']); ?>"></span>
                            <div><strong><?php echo esc_html($service['label']); ?></strong><small><?php echo esc_html($service['description']); ?></small></div>
                        </label>
                    <?php endforeach; ?>
                </div>
                <div class="tng-cm-form-actions"><button class="button button-primary button-hero" type="submit">Save Service Settings</button></div>
            </form>
        </div>
        <?php
    }

    public function handle_admin_actions(){
        if(empty($_POST['tng_cm_action'])) return;
        $action=sanitize_key(wp_unslash($_POST['tng_cm_action']));

        if($action==='save_settings'){
            if(!current_user_can('manage_options')) wp_die('Permission denied.');
            check_admin_referer('tng_content_settings','tng_content_settings_nonce');

            $services=[];
            foreach($this->service_definitions as $key=>$definition){
                $services[$key]=!empty($_POST['services'][$key])?1:0;
            }
            update_option(self::OPTION_SERVICES,$services);
            wp_safe_redirect(add_query_arg(['page'=>'tn-game-content-settings','updated'=>'1'],admin_url('admin.php')));
            exit;
        }

        if($action==='create_content'){
            if(!current_user_can('edit_posts')) wp_die('Permission denied.');
            check_admin_referer('tng_content_wizard','tng_content_wizard_nonce');
            $this->create_content_from_wizard();
        }
    }

    private function create_content_from_wizard(){
        $service_type=sanitize_key(wp_unslash($_POST['service_type']??''));
        if(!isset($this->service_definitions[$service_type])) wp_die('Invalid content type.');

        $title=sanitize_text_field(wp_unslash($_POST['content_title']??''));
        if($title==='') wp_die('A title is required.');

        $status=($_POST['post_status']??'draft')==='publish'?'publish':'draft';
        $post_id=wp_insert_post([
            'post_type'=>'st_activity',
            'post_status'=>$status,
            'post_title'=>$title,
            'post_content'=>wp_kses_post(wp_unslash($_POST['content_description']??'')),
            'post_author'=>get_current_user_id(),
        ],true);

        if(is_wp_error($post_id)) wp_die(esc_html($post_id->get_error_message()));

        update_post_meta($post_id,'_tng_content_service',$service_type);
        update_post_meta($post_id,'_tng_content_community',sanitize_text_field(wp_unslash($_POST['community']??'')));
        update_post_meta($post_id,'_tng_content_created_by_wizard','1');
        update_post_meta($post_id,'_tng_content_wizard_version',self::VERSION);

        $featured_id=absint($_POST['featured_image_id']??0);
        if($featured_id) set_post_thumbnail($post_id,$featured_id);

        $term_data=$this->ensure_service_term($service_type);
        if($term_data){
            wp_set_object_terms($post_id,[$term_data['term']->term_id],$term_data['taxonomy'],true);
        }

        $service_data=isset($_POST['service'])?(array)wp_unslash($_POST['service']):[];
        foreach($service_data as $key=>$value){
            $key=sanitize_key($key);
            if(is_array($value)) continue;
            $clean=strpos($key,'url')!==false
                ? esc_url_raw($value)
                : sanitize_text_field($value);
            update_post_meta($post_id,'_tng_wizard_'.$key,$clean);
        }

        $this->map_wizard_fields_to_existing_systems($post_id,$service_type,$service_data);

        if(!empty($service_data['create_asset']) && in_array($service_type,['history','waterfall','scenic'],true)){
            $asset_id=wp_insert_post([
                'post_type'=>self::ASSET_POST_TYPE,
                'post_status'=>'publish',
                'post_title'=>$title,
                'post_content'=>wp_kses_post(wp_unslash($_POST['content_description']??'')),
            ]);
            if($asset_id){
                wp_set_object_terms($asset_id,'top-sight',self::ASSET_TAXONOMY);
                update_post_meta($asset_id,'_tng_asset_latitude',sanitize_text_field($service_data['latitude']??''));
                update_post_meta($asset_id,'_tng_asset_longitude',sanitize_text_field($service_data['longitude']??''));
                if($featured_id) set_post_thumbnail($asset_id,$featured_id);
                update_post_meta($post_id,'_tng_created_asset_id',$asset_id);
            }
        }

        wp_safe_redirect(get_edit_post_link($post_id,'url'));
        exit;
    }

    private function map_wizard_fields_to_existing_systems($post_id,$service_type,$data){
        if($service_type==='food'){
            update_post_meta($post_id,'_tng_food_enabled','1');
            $map=[
                'cuisine'=>'_tng_food_cuisine',
                'price_range'=>'_tng_food_price_range',
                'google_place_id'=>'_tng_food_google_place_id',
                'checkin_xp'=>'_tng_food_checkin_xp',
            ];
            foreach($map as $source=>$target){
                if(isset($data[$source])) update_post_meta($post_id,$target,sanitize_text_field($data[$source]));
            }
        }

        if($service_type==='trail'){
            $map=[
                'state_park'=>['_tng_state_park','trail_state_park'],
                'difficulty'=>['_tng_trail_difficulty','trail_difficulty'],
                'distance'=>['_tng_trail_distance','trail_distance'],
                'estimated_time'=>['_tng_estimated_time','trail_estimated_time'],
                'route_type'=>['_tng_route_type','trail_route_type'],
            ];
            foreach($map as $source=>$targets){
                if(!isset($data[$source])) continue;
                foreach($targets as $target){
                    update_post_meta($post_id,$target,sanitize_text_field($data[$source]));
                }
            }

            $asset_id=absint($data['gpx_asset']??0);
            if($asset_id){
                $gpx=get_post_meta($asset_id,'_tng_asset_file_url',true)?:get_post_meta($asset_id,'_tng_asset_external_url',true);
                if($gpx){
                    foreach(['_tng_gpx_url','trail_gpx_url','gpx_file'] as $key){
                        update_post_meta($post_id,$key,esc_url_raw($gpx));
                    }
                }
                update_post_meta($post_id,'_tng_gpx_asset_id',$asset_id);
            }
        }
    }

    public function ajax_find_google_place(){
        wp_send_json_error([
            'message'=>'Use the Food & Drink Activity editor importer for the first release. Search-by-name will be enabled after the field mapping is validated on your site.'
        ],400);
    }
}
