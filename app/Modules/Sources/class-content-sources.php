<?php
namespace TNG_OS\Modules\Sources;
use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;
use TNG_OS\Modules\Sources\Providers\Google_Places_Provider;
if (!defined('ABSPATH')) exit;

final class Content_Sources implements Module_Interface {
    const META='_tng_content_sources';
    private Container $container;
    private Provider_Registry $registry;

    public function id(): string { return 'content_sources'; }
    public function register(Container $container): void {
        $this->container=$container;
        $this->registry=new Provider_Registry();
        $this->registry->register(new Google_Places_Provider((string)$container->get('settings')->get('google_places_key','')));
        do_action('tng_os_register_content_source_providers',$this->registry,$container);
        $container->set('content_sources',$this);
        $container->set('source_providers',$this->registry);
        add_action('add_meta_boxes',[$this,'meta_boxes'],25);
        add_action('save_post_st_activity',[$this,'save_source_ids'],20,2);
        add_action('wp_ajax_tng_os_sync_content_source',[$this,'ajax_sync']);
        add_action('wp_ajax_tng_food_google_import',[$this,'ajax_legacy_google_import'],1);
        add_action('admin_menu',[$this,'menu'],20);
        add_action('admin_enqueue_scripts',[$this,'assets']);
    }
    public function boot(Container $container): void {
        $legacy=$container->get('legacy_core');
        if($legacy) remove_action('wp_ajax_tng_food_google_import',[$legacy,'ajax_food_google_import']);
    }

    public function menu(): void {
        add_submenu_page('tn-game-os','Content Sources','Content Sources','edit_posts','tng-os-content-sources',[$this,'dashboard']);
    }
    public function assets(string $hook): void {
        if(!in_array(get_post_type(),['st_activity'],true) && strpos($hook,'tng-os-content-sources')===false) return;
        wp_enqueue_style('tng-content-sources',TNG_OS_URL.'assets/admin/content-sources.css',[],TNG_OS_VERSION);
        wp_enqueue_script('tng-content-sources',TNG_OS_URL.'assets/admin/content-sources.js',['jquery'],TNG_OS_VERSION,true);
        wp_localize_script('tng-content-sources','TNG_CONTENT_SOURCES',[
            'ajaxUrl'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('tng_os_sync_content_source')
        ]);
    }
    public function meta_boxes(): void {
        add_meta_box('tng-content-sources','TN Game Content Sources',[$this,'meta_box'],'st_activity','normal','high');
    }
    public function meta_box($post): void {
        wp_nonce_field('tng_content_sources_save','tng_content_sources_nonce');
        $sources=$this->sources($post->ID);
        $google=$sources['google_places']??[];
        $place_id=(string)($google['external_id']??get_post_meta($post->ID,'_tng_food_google_place_id',true));
        ?>
        <div class="tng-source-shell">
          <div class="tng-source-intro"><div><strong>Unified source record</strong><p>Connect external facts without replacing your manual editorial content.</p></div><span class="tng-source-state <?php echo !empty($google['last_synced'])?'is-connected':''; ?>"><?php echo !empty($google['last_synced'])?'Connected':'Not connected'; ?></span></div>
          <section class="tng-source-provider">
            <header><div><span class="dashicons dashicons-google"></span><div><strong>Google Places</strong><small>Details, hours, ratings, coordinates and source photo references</small></div></div></header>
            <div class="tng-source-row"><label>Google Place ID<input type="text" name="tng_source_ids[google_places]" id="tng-source-google-id" value="<?php echo esc_attr($place_id); ?>"></label><button type="button" class="button button-primary" data-tng-source-sync="google_places" data-post-id="<?php echo absint($post->ID); ?>">Sync Google Places</button></div>
            <div class="tng-source-result" data-tng-source-result><?php
                if(!empty($google['last_synced'])) echo 'Last synced '.esc_html($google['last_synced']).'. '.esc_html(count((array)($google['data']??[]))).' normalized fields stored.';
                elseif(!empty($google['error'])) echo esc_html($google['error']);
            ?></div>
            <?php if(!empty($google['data'])): $d=$google['data']; ?>
              <dl class="tng-source-preview"><div><dt>Name</dt><dd><?php echo esc_html($d['name']??'—'); ?></dd></div><div><dt>Address</dt><dd><?php echo esc_html($d['address']??'—'); ?></dd></div><div><dt>Rating</dt><dd><?php echo esc_html(($d['rating']??'—').' · '.($d['rating_count']??0).' ratings'); ?></dd></div><div><dt>Photos</dt><dd><?php echo esc_html(count((array)($d['photos']??[])).' source references'); ?></dd></div></dl>
            <?php endif; ?>
          </section>
          <p class="description">Manual fields remain editable. A sync updates fields managed by this source and records the sync time, normalized response and error history.</p>
        </div>
        <?php
    }
    public function save_source_ids($post_id,$post): void {
        if(!isset($_POST['tng_content_sources_nonce'])||!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['tng_content_sources_nonce'])),'tng_content_sources_save')||!current_user_can('edit_post',$post_id)||(defined('DOING_AUTOSAVE')&&DOING_AUTOSAVE)) return;
        $ids=(array)wp_unslash($_POST['tng_source_ids']??[]); $sources=$this->sources($post_id);
        foreach($ids as $provider=>$id){ $provider=sanitize_key($provider); if(!$this->registry->get($provider)) continue; $sources[$provider]['external_id']=sanitize_text_field($id); }
        update_post_meta($post_id,self::META,$sources);
    }
    private function sources(int $post_id): array { $v=get_post_meta($post_id,self::META,true); return is_array($v)?$v:[]; }

    public function ajax_sync(): void {
        if(!current_user_can('edit_posts')) wp_send_json_error(['message'=>'Permission denied.'],403);
        check_ajax_referer('tng_os_sync_content_source','nonce');
        $this->perform_ajax_sync(sanitize_key($_POST['provider']??''),absint($_POST['post_id']??0),sanitize_text_field(wp_unslash($_POST['external_id']??'')));
    }
    public function ajax_legacy_google_import(): void {
        if(!current_user_can('edit_posts')) wp_send_json_error(['message'=>'Permission denied.'],403);
        check_ajax_referer('tng_food_google_import','nonce');
        $this->perform_ajax_sync('google_places',absint($_POST['post_id']??0),sanitize_text_field(wp_unslash($_POST['place_id']??'')));
    }
    private function perform_ajax_sync(string $provider_id,int $post_id,string $external_id): void {
        if(!$post_id||get_post_type($post_id)!=='st_activity'||!current_user_can('edit_post',$post_id)) wp_send_json_error(['message'=>'Invalid Activity.'],400);
        $provider=$this->registry->get($provider_id); if(!$provider) wp_send_json_error(['message'=>'Unknown content source provider.'],400);
        $remote=$provider->fetch($external_id,['post_id'=>$post_id]);
        if(is_wp_error($remote)){ $this->record_error($post_id,$provider_id,$external_id,$remote->get_error_message()); wp_send_json_error(['message'=>$remote->get_error_message()],400); }
        $data=$provider->normalize($remote);
        $this->apply($post_id,$provider_id,$external_id,$data,$remote);
        wp_send_json_success(['message'=>$provider->label().' synchronized. Save or refresh the editor to see all mapped fields.','fields'=>$data,'last_synced'=>current_time('mysql')]);
    }
    private function record_error(int $post_id,string $provider,string $id,string $message): void {
        $sources=$this->sources($post_id); $sources[$provider]=array_merge((array)($sources[$provider]??[]),['external_id'=>$id,'status'=>'error','error'=>$message,'last_attempt'=>current_time('mysql')]); update_post_meta($post_id,self::META,$sources);
    }
    private function apply(int $post_id,string $provider,string $id,array $data,array $raw): void {
        $sources=$this->sources($post_id);
        $sources[$provider]=['external_id'=>$id,'status'=>'connected','last_synced'=>current_time('mysql'),'error'=>'','data'=>$data,'raw_hash'=>hash('sha256',wp_json_encode($raw))];
        update_post_meta($post_id,self::META,$sources);
        foreach(['address','latitude','longitude','phone','website','maps_url','rating','rating_count','price_level','business_status','hours','primary_type','primary_type_label','summary'] as $key){ update_post_meta($post_id,'_tng_source_'.$key,is_scalar($data[$key]??'')?(string)($data[$key]??''):''); }
        update_post_meta($post_id,'_tng_source_types',(array)($data['types']??[]));
        update_post_meta($post_id,'_tng_source_attributes',(array)($data['attributes']??[]));
        update_post_meta($post_id,'_tng_source_photos',(array)($data['photos']??[]));
        if($provider==='google_places') $this->map_google_to_existing($post_id,$id,$data);
        do_action('tng_os_content_source_synced',$post_id,$provider,$data,$raw);
    }
    private function map_google_to_existing(int $post_id,string $id,array $d): void {
        $map=['enabled'=>'1','google_place_id'=>$id,'address'=>$d['address']??'','latitude'=>$d['latitude']??'','longitude'=>$d['longitude']??'','phone'=>$d['phone']??'','website'=>$d['website']??'','google_maps_url'=>$d['maps_url']??'','rating'=>$d['rating']??'','rating_count'=>$d['rating_count']??'','price_range'=>$d['price_level']??'','business_status'=>$d['business_status']??'','cuisine'=>$d['primary_type_label']??'','hours_text'=>$d['hours']??''];
        foreach($map as $key=>$value) update_post_meta($post_id,'_tng_food_'.$key,(string)$value);
        update_post_meta($post_id,'_tng_food_services',(array)($d['attributes']??[]));
    }

    public function dashboard(): void {
        $q=new \WP_Query(['post_type'=>'st_activity','post_status'=>['publish','draft','pending'],'posts_per_page'=>100,'meta_query'=>[['key'=>self::META,'compare'=>'EXISTS']],'orderby'=>'modified','order'=>'DESC']);
        ?>
        <div class="wrap tng-os-wrap"><header class="tng-os-page-heading"><div><span>UNIFIED INTEGRATIONS</span><h1>Content Sources</h1><p>External facts and sync status are stored separately from editorial content.</p></div></header>
        <section class="tng-os-panel"><h2>Registered providers</h2><div class="tng-os-service-grid"><?php foreach($this->registry->all() as $p): ?><article><span class="dashicons dashicons-admin-links"></span><strong><?php echo esc_html($p->label()); ?></strong><small><?php echo esc_html(implode(', ',$p->capabilities())); ?></small></article><?php endforeach; ?><article><span class="dashicons dashicons-plus-alt2"></span><strong>Provider API</strong><small>Future modules can register through tng_os_register_content_source_providers.</small></article></div></section>
        <section class="tng-os-panel"><h2>Connected listings</h2><table class="widefat striped"><thead><tr><th>Listing</th><th>Providers</th><th>Last sync</th><th>Status</th></tr></thead><tbody><?php if($q->have_posts()):while($q->have_posts()):$q->the_post();$sources=$this->sources(get_the_ID());$latest='';foreach($sources as $source){if(($source['last_synced']??'')>$latest)$latest=$source['last_synced'];} ?><tr><td><a href="<?php echo esc_url(get_edit_post_link()); ?>"><?php the_title(); ?></a></td><td><?php echo esc_html(implode(', ',array_keys($sources))); ?></td><td><?php echo esc_html($latest?:'—'); ?></td><td><?php echo esc_html(implode(', ',array_unique(array_map(fn($s)=>$s['status']??'unknown',$sources)))); ?></td></tr><?php endwhile;wp_reset_postdata();else:?><tr><td colspan="4">No listings have connected sources yet.</td></tr><?php endif;?></tbody></table></section></div>
        <?php
    }
}
