<?php
namespace TNG_OS\Modules\Sources;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;
use TNG_OS\Modules\Sources\Providers\Google_Places_Provider;

if (!defined('ABSPATH')) exit;

final class Local_Discovery implements Module_Interface {
    private const CPT = 'tng_local_candidate';
    private const NONCE = 'tng_maps_discovery_action';
    private const TOKEN_OPTION = 'tng_maps_apify_token';
    private const ACTOR_OPTION = 'tng_maps_apify_actor';

    private Container $container;

    public function id(): string { return 'local_discovery'; }

    public function register(Container $container): void {
        $this->container = $container;
        add_action('init', [$this, 'register_candidate_type']);
        add_action('admin_menu', [$this, 'admin_menu'], 25);
        add_action('admin_post_tng_maps_discovery_search', [$this, 'search_action']);
        add_action('admin_post_tng_maps_discovery_save', [$this, 'save_candidate_action']);
        add_action('admin_post_tng_maps_discovery_delete', [$this, 'delete_candidate_action']);
        add_action('admin_post_tng_maps_discovery_create_listing', [$this, 'create_listing_action']);
        $container->set('local_discovery', $this);
    }

    public function boot(Container $container): void {}

    public function register_candidate_type(): void {
        register_post_type(self::CPT, [
            'labels' => ['name'=>'Local Discovery Candidates','singular_name'=>'Local Discovery Candidate'],
            'public' => false,
            'show_ui' => false,
            'supports' => ['title','editor'],
            'capability_type' => 'post',
            'map_meta_cap' => true,
        ]);
    }

    public function admin_menu(): void {
        add_submenu_page('tng-content-studio', 'Local Discovery', 'Local Discovery', 'edit_posts', 'tng-local-discovery', [$this, 'render_page']);
    }

    private function transient_key(): string { return 'tng_maps_results_' . get_current_user_id(); }

    private function actor(): string {
        $actor = trim((string)get_option(self::ACTOR_OPTION, 'pro100chok~google-maps-scraper'));
        return str_replace('/', '~', $actor ?: 'pro100chok~google-maps-scraper');
    }

    private function guard(): void {
        if (!current_user_can('edit_posts')) wp_die('You do not have permission to use Local Discovery.');
        check_admin_referer(self::NONCE);
    }

    private function redirect(string $message): void {
        wp_safe_redirect(add_query_arg(['page'=>'tng-local-discovery','tng_notice'=>rawurlencode($message)], admin_url('admin.php')));
        exit;
    }

    private function pick(array $data, array $keys, $default='') {
        foreach ($keys as $key) {
            if (array_key_exists($key,$data) && $data[$key] !== '' && $data[$key] !== null && !is_array($data[$key])) return $data[$key];
        }
        return $default;
    }

    private function normalize(array $item): array {
        $location = isset($item['location']) && is_array($item['location']) ? $item['location'] : [];
        $coords = isset($item['coordinates']) && is_array($item['coordinates']) ? $item['coordinates'] : [];
        $lat = $this->pick($item,['latitude','lat'],$this->pick($location,['lat','latitude'],$this->pick($coords,['lat','latitude'],'')));
        $lng = $this->pick($item,['longitude','lng','lon'],$this->pick($location,['lng','longitude','lon'],$this->pick($coords,['lng','longitude','lon'],'')));

        $photos=[];
        foreach (['imageUrls','images','photos'] as $key) {
            if (empty($item[$key]) || !is_array($item[$key])) continue;
            foreach ($item[$key] as $photo) {
                if (is_string($photo)) { $photos[] = esc_url_raw($photo); continue; }
                if (is_array($photo)) {
                    $url = $this->pick($photo,['url','imageUrl','photoUrl','thumbnail']);
                    if ($url) $photos[] = esc_url_raw((string)$url);
                }
            }
        }

        $socials=[];
        $social_map=[
            'facebook'=>['facebook','facebookUrl','facebook_url'],
            'instagram'=>['instagram','instagramUrl','instagram_url'],
            'x'=>['twitter','twitterUrl','xUrl'],
            'linkedin'=>['linkedin','linkedinUrl'],
            'youtube'=>['youtube','youtubeUrl'],
            'tiktok'=>['tiktok','tiktokUrl'],
        ];
        foreach ($social_map as $platform=>$keys) {
            $url=$this->pick($item,$keys);
            if ($url) $socials[$platform]=esc_url_raw((string)$url);
        }
        if (!empty($item['socialLinks']) && is_array($item['socialLinks'])) {
            foreach ($item['socialLinks'] as $platform=>$url) if (is_string($url) && $url) $socials[sanitize_key((string)$platform)] = esc_url_raw($url);
        }

        return [
            'name'=>sanitize_text_field(html_entity_decode((string)$this->pick($item,['title','name','businessName'],'Google Maps Place'),ENT_QUOTES|ENT_HTML5,'UTF-8')),
            'place_id'=>sanitize_text_field((string)$this->pick($item,['placeId','place_id','googlePlaceId'])),
            'maps_url'=>esc_url_raw((string)$this->pick($item,['url','placeUrl','googleMapsUrl','googleMapsUri','googleMapsLink','mapsUrl','link'])),
            'address'=>sanitize_text_field((string)$this->pick($item,['address','fullAddress','formattedAddress'])),
            'phone'=>sanitize_text_field((string)$this->pick($item,['phone','phoneNumber','phoneUnformatted'])),
            'website'=>esc_url_raw((string)$this->pick($item,['website','websiteUrl'])),
            'category'=>sanitize_text_field((string)$this->pick($item,['categoryName','category','primaryCategory'])),
            'rating'=>sanitize_text_field((string)$this->pick($item,['rating','totalScore','stars'])),
            'rating_count'=>sanitize_text_field((string)$this->pick($item,['reviewsCount','reviewCount','userRatingCount','totalReviews'])),
            'latitude'=>sanitize_text_field((string)$lat),
            'longitude'=>sanitize_text_field((string)$lng),
            'email'=>sanitize_email((string)$this->pick($item,['email','businessEmail'])),
            'socials'=>$socials,
            'photos'=>array_slice(array_values(array_unique(array_filter($photos))),0,10),
        ];
    }

    private function service_definitions(): array {
        return [
            'food'=>['label'=>'Food & Drink','term'=>'food-and-drink'],
            'shops'=>['label'=>'Shops','term'=>'shops'],
            'campgrounds'=>['label'=>'Campgrounds','term'=>'campgrounds'],
            'lodging'=>['label'=>'Lodging','term'=>'lodging'],
            'history'=>['label'=>'Historic Sites','term'=>'historic-sites'],
            'scenic'=>['label'=>'Scenic Views','term'=>'scenic-views'],
            'events'=>['label'=>'Events','term'=>'events'],
        ];
    }

    private function suggested_service(string $category,string $name=''): string {
        $text=strtolower(trim($category.' '.$name));
        if (preg_match('/restaurant|coffee|cafe|café|bakery|brewery|pizza|sandwich|fast food|food/',$text)) return 'food';
        if (preg_match('/campground|camping|rv park/',$text)) return 'campgrounds';
        if (preg_match('/hotel|motel|lodging|inn|resort|bed and breakfast|cabin rental/',$text)) return 'lodging';
        if (preg_match('/historic|museum|historical landmark/',$text)) return 'history';
        if (preg_match('/scenic|overlook|viewpoint/',$text)) return 'scenic';
        if (preg_match('/shop|store|boutique|gift|market/',$text)) return 'shops';
        return 'food';
    }

    private function activity_type_taxonomy(): string {
        foreach (['st_activity_type','activity_type','st_activity_types'] as $taxonomy) if (taxonomy_exists($taxonomy)) return $taxonomy;
        foreach (get_object_taxonomies('st_activity','objects') as $taxonomy) if (!empty($taxonomy->hierarchical)) return $taxonomy->name;
        return '';
    }

    private function assign_service_term(int $post_id,string $service): void {
        $defs=$this->service_definitions(); if (empty($defs[$service])) return;
        $taxonomy=$this->activity_type_taxonomy(); if (!$taxonomy) return;
        $term=get_term_by('slug',$defs[$service]['term'],$taxonomy);
        if (!$term) {
            $created=wp_insert_term($defs[$service]['label'],$taxonomy,['slug'=>$defs[$service]['term']]);
            if (!is_wp_error($created)) $term=get_term((int)$created['term_id'],$taxonomy);
        }
        if ($term && !is_wp_error($term)) wp_set_object_terms($post_id,[(int)$term->term_id],$taxonomy,false);
    }

    private function community_from_address(string $address): string {
        $parts=array_values(array_filter(array_map('trim',explode(',',trim($address)))));
        return count($parts)>=3 ? sanitize_text_field($parts[count($parts)-3]) : '';
    }

    private function assign_destination(int $post_id,string $community): int {
        if (!$community || !post_type_exists('tng_destination')) return 0;
        $destinations=get_posts(['post_type'=>'tng_destination','post_status'=>['publish','draft','private'],'posts_per_page'=>-1]);
        foreach ($destinations as $destination) {
            if (strcasecmp(trim($destination->post_title),trim($community))!==0) continue;
            $relationships=$this->container->get('destination_relationships');
            if ($relationships && is_callable([$relationships,'assign'])) {
                $relationships->assign($post_id,(int)$destination->ID,[]);
            } else {
                update_post_meta($post_id,'_tng_destination_id',(int)$destination->ID);
            }
            return (int)$destination->ID;
        }
        return 0;
    }

    private function existing_activity_id(array $item): int {
        $meta=[];
        if (!empty($item['place_id'])) foreach (['_tng_food_google_place_id','_tng_google_place_id'] as $key) $meta[]=['key'=>$key,'value'=>$item['place_id']];
        if (!empty($item['maps_url'])) foreach (['_tng_food_google_maps_url','_tng_source_maps_url'] as $key) $meta[]=['key'=>$key,'value'=>$item['maps_url']];
        if ($meta) {
            $ids=get_posts(['post_type'=>'st_activity','post_status'=>'any','numberposts'=>1,'fields'=>'ids','meta_query'=>array_merge(['relation'=>'OR'],$meta)]);
            if ($ids) return (int)$ids[0];
        }
        if (!empty($item['name'])) {
            $posts=get_posts(['post_type'=>'st_activity','post_status'=>'any','numberposts'=>1,'title'=>$item['name']]);
            if ($posts) return (int)$posts[0]->ID;
        }
        return 0;
    }

    private function candidate_exists(array $item): bool {
        $meta=['relation'=>'OR'];
        if (!empty($item['place_id'])) $meta[]=['key'=>'_tng_local_place_id','value'=>$item['place_id']];
        if (!empty($item['maps_url'])) $meta[]=['key'=>'_tng_local_maps_url','value'=>$item['maps_url']];
        if (count($meta)===1) return false;
        return (bool)get_posts(['post_type'=>self::CPT,'post_status'=>'any','numberposts'=>1,'fields'=>'ids','meta_query'=>$meta]);
    }

    private function apply_google_data(int $post_id,string $place_id,string $service): bool {
        $settings=$this->container->get('settings');
        $key=$settings && is_callable([$settings,'get']) ? (string)$settings->get('google_places_key','') : '';
        if (!$key || !$place_id) return false;
        $provider=new Google_Places_Provider($key);
        $remote=$provider->fetch($place_id,['post_id'=>$post_id]);
        if (is_wp_error($remote)) { update_post_meta($post_id,'_tng_google_auto_sync_error',$remote->get_error_message()); return false; }
        $data=$provider->normalize($remote);
        $sources=(array)get_post_meta($post_id,Content_Sources::META,true);
        $sources['google_places']=['external_id'=>$place_id,'status'=>'connected','last_synced'=>current_time('mysql'),'error'=>'','data'=>$data,'raw_hash'=>hash('sha256',wp_json_encode($remote))];
        update_post_meta($post_id,Content_Sources::META,$sources);
        foreach (['address','latitude','longitude','phone','website','maps_url','rating','rating_count','price_level','business_status','hours','primary_type','primary_type_label','summary'] as $key_name) {
            $value=$data[$key_name]??''; update_post_meta($post_id,'_tng_source_'.$key_name,is_scalar($value)?(string)$value:'');
        }
        update_post_meta($post_id,'_tng_source_types',(array)($data['types']??[]));
        update_post_meta($post_id,'_tng_source_attributes',(array)($data['attributes']??[]));
        update_post_meta($post_id,'_tng_source_photos',(array)($data['photos']??[]));
        if ($service==='food') {
            $map=['enabled'=>'1','google_place_id'=>$place_id,'address'=>$data['address']??'','latitude'=>$data['latitude']??'','longitude'=>$data['longitude']??'','phone'=>$data['phone']??'','website'=>$data['website']??'','google_maps_url'=>$data['maps_url']??'','rating'=>$data['rating']??'','rating_count'=>$data['rating_count']??'','price_range'=>$data['price_level']??'','business_status'=>$data['business_status']??'','cuisine'=>$data['primary_type_label']??'','hours_text'=>$data['hours']??''];
            foreach ($map as $field=>$value) update_post_meta($post_id,'_tng_food_'.$field,(string)$value);
            update_post_meta($post_id,'_tng_food_services',(array)($data['attributes']??[]));
        }
        delete_post_meta($post_id,'_tng_google_auto_sync_error');
        return true;
    }

    public function search_action(): void {
        $this->guard();
        $token=trim((string)get_option(self::TOKEN_OPTION,''));
        if (!$token) $this->redirect('Apify token is not configured yet.');
        $query=sanitize_text_field(wp_unslash($_POST['search_query']??''));
        $location=sanitize_text_field(wp_unslash($_POST['location']??''));
        $category=sanitize_text_field(wp_unslash($_POST['category']??''));
        $max=max(1,min(100,absint($_POST['max_items']??25)));
        if (!$query) $this->redirect('Enter something to search for.');
        $input=['searchStringsArray'=>[$query],'locationQuery'=>$location,'maxItems'=>$max,'deepSearch'=>false,'countryCode'=>'us','language'=>'en','scrapeContactsFromWebsite'=>true,'skipPlacesWithoutEmail'=>false,'skipDuplicateEmails'=>false,'includeReviews'=>!empty($_POST['include_reviews']),'includeImages'=>!empty($_POST['include_images'])];
        if ($category) $input['categoryFilter']=$category;
        if (!empty($_POST['include_reviews'])) $input['maxReviewsPerPlace']=10;
        if (!empty($_POST['include_images'])) $input['maxImagesPerPlace']=8;
        $endpoint='https://api.apify.com/v2/acts/'.rawurlencode($this->actor()).'/run-sync-get-dataset-items';
        $response=wp_remote_post($endpoint,['timeout'=>120,'headers'=>['Content-Type'=>'application/json','Accept'=>'application/json','Authorization'=>'Bearer '.$token],'body'=>wp_json_encode($input)]);
        if (is_wp_error($response)) $this->redirect('Google Maps discovery failed: '.$response->get_error_message());
        $status=wp_remote_retrieve_response_code($response); $body=json_decode(wp_remote_retrieve_body($response),true);
        if ($status<200 || $status>=300) $this->redirect('Apify returned HTTP '.$status.': '.(is_array($body)?($body['error']['message']??$body['message']??''):''));
        if (!is_array($body)) $this->redirect('Apify returned an unreadable response.');
        $results=[];
        foreach ($body as $raw) {
            if (!is_array($raw)) continue;
            $item=$this->normalize($raw); if (!$item['name']) continue;
            $item['existing_activity_id']=$this->existing_activity_id($item);
            $item['duplicate']=$this->candidate_exists($item)||!empty($item['existing_activity_id']);
            $results[]=$item;
        }
        set_transient($this->transient_key(),['query'=>$query,'location'=>$location,'results'=>$results,'searched_at'=>current_time('mysql')],HOUR_IN_SECONDS);
        $this->redirect(count($results).' Google Maps place'.(count($results)===1?'':'s').' found.');
    }

    public function save_candidate_action(): void {
        $this->guard();
        $index=absint($_POST['result_index']??-1); $cache=get_transient($this->transient_key()); $results=is_array($cache)?($cache['results']??[]):[];
        if (!isset($results[$index])) $this->redirect('That discovery result has expired. Search again.');
        $item=$results[$index];
        if ($this->candidate_exists($item)) $this->redirect('That place is already in the Local Discovery queue.');
        $id=wp_insert_post(['post_type'=>self::CPT,'post_status'=>'publish','post_title'=>$item['name'],'post_content'=>''],true);
        if (is_wp_error($id)||!$id) $this->redirect('Could not save that discovery candidate.');
        $meta=['_tng_local_source'=>'google_maps_apify','_tng_local_place_id'=>$item['place_id'],'_tng_local_maps_url'=>$item['maps_url'],'_tng_local_address'=>$item['address'],'_tng_local_phone'=>$item['phone'],'_tng_local_website'=>$item['website'],'_tng_local_category'=>$item['category'],'_tng_local_rating'=>$item['rating'],'_tng_local_rating_count'=>$item['rating_count'],'_tng_local_latitude'=>$item['latitude'],'_tng_local_longitude'=>$item['longitude'],'_tng_local_email'=>$item['email'],'_tng_local_socials'=>$item['socials'],'_tng_local_photos'=>$item['photos'],'_tng_local_status'=>'review','_tng_local_discovered_at'=>current_time('mysql')];
        foreach ($meta as $key=>$value) update_post_meta($id,$key,$value);
        $results[$index]['duplicate']=true; $cache['results']=$results; set_transient($this->transient_key(),$cache,HOUR_IN_SECONDS);
        $this->redirect($item['name'].' added to Local Discovery.');
    }

    public function create_listing_action(): void {
        $this->guard();
        $candidate_id=absint($_POST['candidate_id']??0); $service=sanitize_key(wp_unslash($_POST['service']??'')); $defs=$this->service_definitions();
        if (!$candidate_id || get_post_type($candidate_id)!==self::CPT) $this->redirect('Invalid Local Discovery candidate.');
        if (!isset($defs[$service])) $service='food';
        $item=['name'=>html_entity_decode(get_the_title($candidate_id),ENT_QUOTES|ENT_HTML5,'UTF-8'),'place_id'=>(string)get_post_meta($candidate_id,'_tng_local_place_id',true),'maps_url'=>(string)get_post_meta($candidate_id,'_tng_local_maps_url',true),'address'=>(string)get_post_meta($candidate_id,'_tng_local_address',true),'phone'=>(string)get_post_meta($candidate_id,'_tng_local_phone',true),'website'=>(string)get_post_meta($candidate_id,'_tng_local_website',true),'category'=>(string)get_post_meta($candidate_id,'_tng_local_category',true),'rating'=>(string)get_post_meta($candidate_id,'_tng_local_rating',true),'rating_count'=>(string)get_post_meta($candidate_id,'_tng_local_rating_count',true),'latitude'=>(string)get_post_meta($candidate_id,'_tng_local_latitude',true),'longitude'=>(string)get_post_meta($candidate_id,'_tng_local_longitude',true),'email'=>(string)get_post_meta($candidate_id,'_tng_local_email',true),'socials'=>(array)get_post_meta($candidate_id,'_tng_local_socials',true),'photos'=>(array)get_post_meta($candidate_id,'_tng_local_photos',true)];
        $existing=$this->existing_activity_id($item);
        if ($existing) { update_post_meta($candidate_id,'_tng_local_status','duplicate'); update_post_meta($candidate_id,'_tng_local_activity_id',$existing); $this->redirect($item['name'].' already exists in TN Game as Activity #'.$existing.'.'); }
        $post_id=wp_insert_post(['post_type'=>'st_activity','post_status'=>'draft','post_title'=>$item['name'],'post_content'=>''],true);
        if (is_wp_error($post_id)||!$post_id) $this->redirect('Could not create the TN Game draft listing.');
        $post_id=(int)$post_id; $this->assign_service_term($post_id,$service); $community=$this->community_from_address($item['address']); $destination_id=$this->assign_destination($post_id,$community);
        $meta=['_tng_content_service'=>$service,'_tng_content_community'=>$community,'_tng_source_address'=>$item['address'],'_tng_source_latitude'=>$item['latitude'],'_tng_source_longitude'=>$item['longitude'],'_tng_source_phone'=>$item['phone'],'_tng_source_website'=>$item['website'],'_tng_source_maps_url'=>$item['maps_url'],'_tng_source_rating'=>$item['rating'],'_tng_source_rating_count'=>$item['rating_count'],'_tng_source_primary_type_label'=>$item['category'],'_tng_source_photos'=>$item['photos'],'_tng_source_socials'=>$item['socials'],'_tng_discovery_source'=>'google_maps_apify','_tng_discovery_candidate_id'=>$candidate_id,'_tng_discovery_destination_id'=>$destination_id,'_tng_google_place_id'=>$item['place_id']];
        foreach ($meta as $key=>$value) update_post_meta($post_id,$key,$value);
        if ($item['place_id']) update_post_meta($post_id,'_tng_content_sources',['google_places'=>['external_id'=>$item['place_id'],'status'=>'discovered','discovered_at'=>current_time('mysql')]]);
        if ($service==='food') {
            $food=['enabled'=>'1','google_place_id'=>$item['place_id'],'address'=>$item['address'],'latitude'=>$item['latitude'],'longitude'=>$item['longitude'],'phone'=>$item['phone'],'website'=>$item['website'],'google_maps_url'=>$item['maps_url'],'rating'=>$item['rating'],'rating_count'=>$item['rating_count'],'cuisine'=>$item['category']];
            foreach ($food as $field=>$value) update_post_meta($post_id,'_tng_food_'.$field,(string)$value);
        }
        if ($item['email']) update_post_meta($post_id,'_tng_discovery_email',$item['email']);
        $official=$this->apply_google_data($post_id,$item['place_id'],$service); update_post_meta($post_id,'_tng_discovery_google_official_synced',$official?'1':'0');
        update_post_meta($candidate_id,'_tng_local_status','created'); update_post_meta($candidate_id,'_tng_local_service',$service); update_post_meta($candidate_id,'_tng_local_activity_id',$post_id); update_post_meta($candidate_id,'_tng_local_created_at',current_time('mysql'));
        $this->redirect($item['name'].' created as a draft TN Game listing. Activity #'.$post_id.'.');
    }

    public function delete_candidate_action(): void {
        $this->guard(); $id=absint($_GET['candidate_id']??0);
        if (!$id || get_post_type($id)!==self::CPT || !current_user_can('delete_post',$id)) $this->redirect('Invalid Local Discovery candidate.');
        wp_delete_post($id,true); $this->redirect('Discovery candidate removed.');
    }

    private function action_url(string $action,array $args=[]): string {
        return wp_nonce_url(add_query_arg(array_merge(['action'=>$action],$args),admin_url('admin-post.php')),self::NONCE);
    }

    public function render_page(): void {
        if (!current_user_can('edit_posts')) return;
        $cache=get_transient($this->transient_key()); $results=is_array($cache)?($cache['results']??[]):[]; $notice=sanitize_text_field(wp_unslash($_GET['tng_notice']??''));
        $candidates=get_posts(['post_type'=>self::CPT,'post_status'=>'publish','numberposts'=>50,'orderby'=>'date','order'=>'DESC']);
        ?>
        <div class="wrap"><h1>📍 Local Discovery</h1><p>Search Google Maps for restaurants, coffee shops, attractions, campgrounds, outfitters, stores and other places. Results stay in review until you approve them.</p>
        <?php if($notice): ?><div class="notice notice-info is-dismissible"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>
        <?php if(!get_option(self::TOKEN_OPTION)): ?><div class="notice notice-warning"><p>Apify is not configured yet. Add the token in the existing <code>tng_maps_apify_token</code> option.</p></div><?php endif; ?>
        <div style="background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:20px;max-width:1100px;margin:20px 0"><h2>Google Maps Search</h2>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="tng_maps_discovery_search"><?php wp_nonce_field(self::NONCE); ?>
        <table class="form-table"><tr><th>What are we looking for?</th><td><input class="regular-text" type="text" name="search_query" value="<?php echo esc_attr($cache['query']??''); ?>" placeholder="restaurants, coffee shops, campgrounds..." required></td></tr><tr><th>Location</th><td><input class="regular-text" type="text" name="location" value="<?php echo esc_attr($cache['location']??''); ?>" placeholder="Tracy City, TN"></td></tr><tr><th>Category filter</th><td><input class="regular-text" type="text" name="category" placeholder="Optional — Restaurant, Campground, Coffee shop"></td></tr><tr><th>Maximum results</th><td><input type="number" name="max_items" value="25" min="1" max="100"></td></tr><tr><th>Extra data</th><td><label><input type="checkbox" name="include_images" value="1"> Include image URLs</label><br><label><input type="checkbox" name="include_reviews" value="1"> Include up to 10 reviews per place</label><p class="description">Website contact and social-link discovery is enabled automatically.</p></td></tr></table><?php submit_button('Search Google Maps','primary'); ?></form></div>
        <?php if($results): ?><h2>Search Results (<?php echo count($results); ?>)</h2><div style="overflow-x:auto"><table class="widefat striped"><thead><tr><th>Place</th><th>Category</th><th>Rating</th><th>Contact</th><th>Online</th><th>Discovery</th></tr></thead><tbody>
        <?php foreach($results as $index=>$item): ?><tr><td><strong><?php echo esc_html($item['name']); ?></strong><?php if($item['address']): ?><br><small><?php echo esc_html($item['address']); ?></small><?php endif; ?><?php if($item['maps_url']): ?><br><a href="<?php echo esc_url($item['maps_url']); ?>" target="_blank" rel="noopener">Open Google Maps ↗</a><?php endif; ?></td><td><?php echo esc_html($item['category']?:'—'); ?></td><td><?php echo $item['rating'] ? '⭐ '.esc_html($item['rating']).($item['rating_count']?' <small>('.esc_html($item['rating_count']).')</small>':'') : '—'; ?></td><td><?php echo esc_html($item['phone']?:'—'); ?><?php if($item['email']): ?><br><small><?php echo esc_html($item['email']); ?></small><?php endif; ?></td><td><?php if($item['website']): ?><a href="<?php echo esc_url($item['website']); ?>" target="_blank" rel="noopener">Website ↗</a><?php endif; ?><?php foreach((array)$item['socials'] as $platform=>$url): ?><br><a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener"><?php echo esc_html(ucfirst($platform)); ?> ↗</a><?php endforeach; ?></td><td><?php if($item['duplicate']): ?><strong style="color:#008a20">✓ Known</strong><?php else: ?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="tng_maps_discovery_save"><input type="hidden" name="result_index" value="<?php echo absint($index); ?>"><?php wp_nonce_field(self::NONCE); ?><button class="button button-primary">Add to Discovery</button></form><?php endif; ?></td></tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
        <hr style="margin:35px 0"><h2>Local Discovery Queue (<?php echo count($candidates); ?>)</h2>
        <?php if(!$candidates): ?><p>No local discovery candidates yet.</p><?php else: ?><table class="widefat striped"><thead><tr><th>Place</th><th>Category</th><th>Rating</th><th>Source</th><th>Status</th><th>Action</th></tr></thead><tbody>
        <?php foreach($candidates as $candidate): $maps=get_post_meta($candidate->ID,'_tng_local_maps_url',true); $category=get_post_meta($candidate->ID,'_tng_local_category',true); $rating=get_post_meta($candidate->ID,'_tng_local_rating',true); $status=(string)get_post_meta($candidate->ID,'_tng_local_status',true)?:'review'; $activity_id=absint(get_post_meta($candidate->ID,'_tng_local_activity_id',true)); $service=(string)get_post_meta($candidate->ID,'_tng_local_service',true)?:$this->suggested_service((string)$category,$candidate->post_title); ?>
        <tr><td><strong><?php echo esc_html($candidate->post_title); ?></strong></td><td><?php echo esc_html($category?:'—'); ?></td><td><?php echo esc_html($rating?:'—'); ?></td><td><?php if($maps): ?><a href="<?php echo esc_url($maps); ?>" target="_blank" rel="noopener">Google Maps ↗</a><?php endif; ?></td><td><?php if($status==='created'&&$activity_id): ?><strong style="color:#008a20">✓ Draft Created</strong><br><a href="<?php echo esc_url(get_edit_post_link($activity_id)); ?>">Edit listing ↗</a><?php elseif($status==='duplicate'&&$activity_id): ?><strong style="color:#b26200">Existing Listing</strong><br><a href="<?php echo esc_url(get_edit_post_link($activity_id)); ?>">Open listing ↗</a><?php else: ?><strong>Review</strong><?php endif; ?></td><td><?php if($status==='review'): ?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex;gap:6px;flex-wrap:wrap"><input type="hidden" name="action" value="tng_maps_discovery_create_listing"><input type="hidden" name="candidate_id" value="<?php echo absint($candidate->ID); ?>"><?php wp_nonce_field(self::NONCE); ?><select name="service"><?php foreach($this->service_definitions() as $key=>$def): ?><option value="<?php echo esc_attr($key); ?>" <?php selected($service,$key); ?>><?php echo esc_html($def['label']); ?></option><?php endforeach; ?></select><button class="button button-primary">Create Draft Listing</button></form><?php endif; ?><div style="margin-top:8px"><a href="<?php echo esc_url($this->action_url('tng_maps_discovery_delete',['candidate_id'=>$candidate->ID])); ?>" onclick="return confirm('Remove this candidate?');">Remove</a></div></td></tr>
        <?php endforeach; ?></tbody></table><?php endif; ?></div><?php
    }
}
