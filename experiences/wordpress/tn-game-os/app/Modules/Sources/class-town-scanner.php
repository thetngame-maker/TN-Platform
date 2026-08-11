<?php
namespace TNG_OS\Modules\Sources;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class Town_Scanner implements Module_Interface {
    private const CANDIDATE_CPT = 'tng_local_candidate';
    private const TOKEN_OPTION = 'tng_maps_apify_token';
    private const ACTOR_OPTION = 'tng_maps_apify_actor';
    private const NONCE = 'tng_town_scanner_action';

    private Container $container;

    public function id(): string { return 'town_scanner'; }

    public function register(Container $container): void {
        $this->container = $container;
        add_action('admin_menu', [$this, 'admin_menu'], 26);
        add_action('admin_post_tng_town_scan', [$this, 'scan_action']);
        add_action('admin_post_tng_town_scan_add', [$this, 'bulk_add_action']);
        $container->set('town_scanner', $this);
    }

    public function boot(Container $container): void {}

    public function admin_menu(): void {
        add_submenu_page(
            'tng-content-studio',
            'Town Scanner',
            'Town Scanner',
            'edit_posts',
            'tng-town-scanner',
            [$this, 'render_page']
        );
    }

    private function guard(): void {
        if (!current_user_can('edit_posts')) wp_die('You do not have permission to use Town Scanner.');
        check_admin_referer(self::NONCE);
    }

    private function transient_key(): string {
        return 'tng_town_scan_results_' . get_current_user_id();
    }

    private function actor(): string {
        $actor = trim((string)get_option(self::ACTOR_OPTION, 'pro100chok~google-maps-scraper'));
        return str_replace('/', '~', $actor ?: 'pro100chok~google-maps-scraper');
    }

    private function definitions(): array {
        return [
            'restaurants' => ['label'=>'Restaurants', 'query'=>'restaurants', 'service'=>'food'],
            'coffee' => ['label'=>'Coffee & Cafés', 'query'=>'coffee shops', 'service'=>'food'],
            'shops' => ['label'=>'Shops', 'query'=>'shops', 'service'=>'shops'],
            'lodging' => ['label'=>'Lodging', 'query'=>'hotels lodging', 'service'=>'lodging'],
            'campgrounds' => ['label'=>'Campgrounds & RV', 'query'=>'campgrounds rv parks', 'service'=>'campgrounds'],
        ];
    }

    private function redirect(string $message): void {
        wp_safe_redirect(add_query_arg([
            'page'=>'tng-town-scanner',
            'tng_notice'=>rawurlencode($message),
        ], admin_url('admin.php')));
        exit;
    }

    private function pick(array $data, array $keys, $default='') {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data) && $data[$key] !== '' && $data[$key] !== null && !is_array($data[$key])) {
                return $data[$key];
            }
        }
        return $default;
    }

    private function normalize(array $item): array {
        $location = isset($item['location']) && is_array($item['location']) ? $item['location'] : [];
        $coords = isset($item['coordinates']) && is_array($item['coordinates']) ? $item['coordinates'] : [];
        $lat = $this->pick($item, ['latitude','lat'], $this->pick($location, ['lat','latitude'], $this->pick($coords, ['lat','latitude'], '')));
        $lng = $this->pick($item, ['longitude','lng','lon'], $this->pick($location, ['lng','longitude','lon'], $this->pick($coords, ['lng','longitude','lon'], '')));

        $photos=[];
        foreach (['imageUrls','images','photos'] as $key) {
            if (empty($item[$key]) || !is_array($item[$key])) continue;
            foreach ($item[$key] as $photo) {
                if (is_string($photo)) { $photos[] = esc_url_raw($photo); continue; }
                if (!is_array($photo)) continue;
                $url = $this->pick($photo, ['url','imageUrl','photoUrl','thumbnail']);
                if ($url) $photos[] = esc_url_raw((string)$url);
            }
        }

        $socials=[];
        foreach ([
            'facebook'=>['facebook','facebookUrl','facebook_url'],
            'instagram'=>['instagram','instagramUrl','instagram_url'],
            'x'=>['twitter','twitterUrl','xUrl'],
            'linkedin'=>['linkedin','linkedinUrl'],
            'youtube'=>['youtube','youtubeUrl'],
            'tiktok'=>['tiktok','tiktokUrl'],
        ] as $platform=>$keys) {
            $url=$this->pick($item,$keys);
            if ($url) $socials[$platform]=esc_url_raw((string)$url);
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

    private function suggested_service(string $category, string $name=''): string {
        $text=strtolower(trim($category.' '.$name));
        if (preg_match('/campground|camping|rv park/',$text)) return 'campgrounds';
        if (preg_match('/hotel|motel|lodging|inn|resort|bed and breakfast|cabin rental/',$text)) return 'lodging';
        if (preg_match('/shop|store|boutique|gift|market/',$text)) return 'shops';
        if (preg_match('/historic|museum|historical landmark/',$text)) return 'history';
        if (preg_match('/scenic|overlook|viewpoint/',$text)) return 'scenic';
        return 'food';
    }

    private function existing_activity_id(array $item): int {
        $meta=[];
        if (!empty($item['place_id'])) {
            foreach (['_tng_food_google_place_id','_tng_google_place_id'] as $key) $meta[]=['key'=>$key,'value'=>$item['place_id']];
        }
        if (!empty($item['maps_url'])) {
            foreach (['_tng_food_google_maps_url','_tng_source_maps_url'] as $key) $meta[]=['key'=>$key,'value'=>$item['maps_url']];
        }
        if ($meta) {
            $ids=get_posts([
                'post_type'=>'st_activity','post_status'=>'any','numberposts'=>1,'fields'=>'ids',
                'meta_query'=>array_merge(['relation'=>'OR'],$meta),
            ]);
            if ($ids) return (int)$ids[0];
        }
        if (!empty($item['name'])) {
            $posts=get_posts(['post_type'=>'st_activity','post_status'=>'any','numberposts'=>1,'title'=>$item['name']]);
            if ($posts) return (int)$posts[0]->ID;
        }
        return 0;
    }

    private function existing_candidate_id(array $item): int {
        $meta=['relation'=>'OR'];
        if (!empty($item['place_id'])) $meta[]=['key'=>'_tng_local_place_id','value'=>$item['place_id']];
        if (!empty($item['maps_url'])) $meta[]=['key'=>'_tng_local_maps_url','value'=>$item['maps_url']];
        if (count($meta)===1) return 0;
        $ids=get_posts([
            'post_type'=>self::CANDIDATE_CPT,'post_status'=>'any','numberposts'=>1,'fields'=>'ids','meta_query'=>$meta,
        ]);
        return $ids ? (int)$ids[0] : 0;
    }

    public function scan_action(): void {
        $this->guard();
        $token=trim((string)get_option(self::TOKEN_OPTION,''));
        if (!$token) $this->redirect('Apify token is not configured.');

        $town=sanitize_text_field(wp_unslash($_POST['town']??''));
        if (!$town) $this->redirect('Enter a town or city to scan.');

        $defs=$this->definitions();
        $selected=array_values(array_intersect(array_keys($defs),(array)wp_unslash($_POST['scan_types']??[])));
        if (!$selected) $selected=array_keys($defs);
        $max=max(5,min(100,absint($_POST['max_items']??50)));

        $queries=[];
        foreach ($selected as $key) $queries[]=$defs[$key]['query'];

        $input=[
            'searchStringsArray'=>$queries,
            'locationQuery'=>$town,
            'maxItems'=>$max,
            'deepSearch'=>false,
            'countryCode'=>'us',
            'language'=>'en',
            'scrapeContactsFromWebsite'=>true,
            'skipPlacesWithoutEmail'=>false,
            'skipDuplicateEmails'=>false,
            'includeReviews'=>false,
            'includeImages'=>false,
        ];

        $endpoint='https://api.apify.com/v2/acts/'.rawurlencode($this->actor()).'/run-sync-get-dataset-items';
        $response=wp_remote_post($endpoint,[
            'timeout'=>180,
            'headers'=>[
                'Content-Type'=>'application/json','Accept'=>'application/json','Authorization'=>'Bearer '.$token,
            ],
            'body'=>wp_json_encode($input),
        ]);
        if (is_wp_error($response)) $this->redirect('Town scan failed: '.$response->get_error_message());
        $status=wp_remote_retrieve_response_code($response);
        $body=json_decode(wp_remote_retrieve_body($response),true);
        if ($status<200 || $status>=300 || !is_array($body)) {
            $message=is_array($body) ? ($body['error']['message']??$body['message']??'') : '';
            $this->redirect('Apify returned HTTP '.$status.($message?': '.$message:''));
        }

        $results=[];
        $seen=[];
        foreach ($body as $raw) {
            if (!is_array($raw)) continue;
            $item=$this->normalize($raw);
            if (!$item['name']) continue;
            $dedupe=$item['place_id'] ?: ($item['maps_url'] ?: strtolower($item['name'].'|'.$item['address']));
            if (isset($seen[$dedupe])) continue;
            $seen[$dedupe]=true;

            $activity_id=$this->existing_activity_id($item);
            $candidate_id=$activity_id ? 0 : $this->existing_candidate_id($item);
            $item['activity_id']=$activity_id;
            $item['candidate_id']=$candidate_id;
            $item['status']=$activity_id ? 'existing' : ($candidate_id ? 'discovery' : 'new');
            $item['service']=$this->suggested_service($item['category'],$item['name']);
            $results[]=$item;
        }

        set_transient($this->transient_key(),[
            'town'=>$town,
            'types'=>$selected,
            'results'=>$results,
            'scanned_at'=>current_time('mysql'),
        ],HOUR_IN_SECONDS);

        $counts=['new'=>0,'existing'=>0,'discovery'=>0];
        foreach ($results as $item) $counts[$item['status']]++;
        $this->redirect(sprintf(
            'Town scan finished: %d new, %d already in TN Game, %d already in Discovery.',
            $counts['new'],$counts['existing'],$counts['discovery']
        ));
    }

    public function bulk_add_action(): void {
        $this->guard();
        $cache=get_transient($this->transient_key());
        $results=is_array($cache)?($cache['results']??[]):[];
        if (!$results) $this->redirect('Town scan results expired. Run the scan again.');

        $selected=array_values(array_unique(array_map('absint',(array)wp_unslash($_POST['result_indexes']??[]))));
        if (!$selected) $this->redirect('Select at least one new place.');

        $added=0; $skipped=0;
        foreach ($selected as $index) {
            if (!isset($results[$index]) || ($results[$index]['status']??'')!=='new') { $skipped++; continue; }
            $item=$results[$index];
            if ($this->existing_activity_id($item) || $this->existing_candidate_id($item)) { $skipped++; continue; }

            $id=wp_insert_post([
                'post_type'=>self::CANDIDATE_CPT,
                'post_status'=>'publish',
                'post_title'=>$item['name'],
                'post_content'=>'',
            ],true);
            if (is_wp_error($id) || !$id) { $skipped++; continue; }

            $meta=[
                '_tng_local_source'=>'google_maps_apify',
                '_tng_local_place_id'=>$item['place_id'],
                '_tng_local_maps_url'=>$item['maps_url'],
                '_tng_local_address'=>$item['address'],
                '_tng_local_phone'=>$item['phone'],
                '_tng_local_website'=>$item['website'],
                '_tng_local_category'=>$item['category'],
                '_tng_local_rating'=>$item['rating'],
                '_tng_local_rating_count'=>$item['rating_count'],
                '_tng_local_latitude'=>$item['latitude'],
                '_tng_local_longitude'=>$item['longitude'],
                '_tng_local_email'=>$item['email'],
                '_tng_local_socials'=>$item['socials'],
                '_tng_local_photos'=>$item['photos'],
                '_tng_local_status'=>'review',
                '_tng_local_service'=>$item['service'],
                '_tng_local_scan_town'=>(string)($cache['town']??''),
                '_tng_local_discovered_at'=>current_time('mysql'),
            ];
            foreach ($meta as $key=>$value) update_post_meta($id,$key,$value);
            $results[$index]['status']='discovery';
            $results[$index]['candidate_id']=(int)$id;
            $added++;
        }

        $cache['results']=$results;
        set_transient($this->transient_key(),$cache,HOUR_IN_SECONDS);
        $this->redirect($added.' place'.($added===1?'':'s').' added to Local Discovery'.($skipped?' ('.$skipped.' skipped).':'.'));
    }

    private function status_label(array $item): string {
        if ($item['status']==='existing') return 'Already in TN Game';
        if ($item['status']==='discovery') return 'In Discovery';
        return 'New';
    }

    private function service_label(string $service): string {
        return [
            'food'=>'Food & Drink','shops'=>'Shops','lodging'=>'Lodging','campgrounds'=>'Campgrounds',
            'history'=>'Historic Sites','scenic'=>'Scenic Views','events'=>'Events',
        ][$service]??ucfirst($service);
    }

    public function render_page(): void {
        if (!current_user_can('edit_posts')) return;
        $cache=get_transient($this->transient_key());
        $results=is_array($cache)?($cache['results']??[]):[];
        $town=is_array($cache)?(string)($cache['town']??''):'';
        $notice=sanitize_text_field(wp_unslash($_GET['tng_notice']??''));
        $defs=$this->definitions();
        $counts=['new'=>0,'existing'=>0,'discovery'=>0];
        foreach ($results as $item) if (isset($counts[$item['status']])) $counts[$item['status']]++;
        ?>
        <div class="wrap">
            <h1>🏘 Town Scanner</h1>
            <p>Scan several local-business categories at once, compare them with TN Game, and send only new places into the Local Discovery review queue.</p>
            <?php if ($notice): ?><div class="notice notice-info is-dismissible"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>

            <div style="background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:20px;max-width:1150px;margin:20px 0">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="tng_town_scan">
                    <?php wp_nonce_field(self::NONCE); ?>
                    <table class="form-table">
                        <tr><th>Town or city</th><td><input class="regular-text" name="town" value="<?php echo esc_attr($town); ?>" placeholder="Monteagle, TN" required></td></tr>
                        <tr><th>Scan for</th><td>
                            <?php foreach ($defs as $key=>$def): ?>
                                <label style="display:inline-block;margin:0 18px 8px 0"><input type="checkbox" name="scan_types[]" value="<?php echo esc_attr($key); ?>" checked> <?php echo esc_html($def['label']); ?></label>
                            <?php endforeach; ?>
                        </td></tr>
                        <tr><th>Maximum total results</th><td><input type="number" name="max_items" min="5" max="100" value="50"><p class="description">The result cap applies to the combined town scan.</p></td></tr>
                    </table>
                    <?php submit_button('Scan Town','primary'); ?>
                </form>
            </div>

            <?php if ($results): ?>
                <div style="display:flex;gap:12px;flex-wrap:wrap;margin:20px 0">
                    <div class="notice notice-success inline" style="margin:0"><p><strong><?php echo absint($counts['new']); ?></strong> new</p></div>
                    <div class="notice notice-info inline" style="margin:0"><p><strong><?php echo absint($counts['existing']); ?></strong> already in TN Game</p></div>
                    <div class="notice notice-warning inline" style="margin:0"><p><strong><?php echo absint($counts['discovery']); ?></strong> already in Discovery</p></div>
                </div>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="tng_town_scan_add">
                    <?php wp_nonce_field(self::NONCE); ?>
                    <p><button type="button" class="button" onclick="document.querySelectorAll('.tng-town-new').forEach(function(c){c.checked=true;});">Select all new</button> <?php submit_button('Add Selected to Discovery','primary','submit',false); ?></p>
                    <div style="overflow-x:auto">
                    <table class="widefat striped">
                        <thead><tr><th style="width:36px"></th><th>Place</th><th>Category</th><th>Suggested section</th><th>Rating</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach ($results as $index=>$item): ?>
                            <tr>
                                <td><?php if ($item['status']==='new'): ?><input class="tng-town-new" type="checkbox" name="result_indexes[]" value="<?php echo absint($index); ?>"><?php endif; ?></td>
                                <td><strong><?php echo esc_html($item['name']); ?></strong><?php if ($item['address']): ?><br><small><?php echo esc_html($item['address']); ?></small><?php endif; ?><?php if ($item['maps_url']): ?><br><a target="_blank" rel="noopener" href="<?php echo esc_url($item['maps_url']); ?>">Google Maps ↗</a><?php endif; ?></td>
                                <td><?php echo esc_html($item['category']?:'—'); ?></td>
                                <td><?php echo esc_html($this->service_label($item['service'])); ?></td>
                                <td><?php echo $item['rating']!==''?'⭐ '.esc_html($item['rating']):'—'; ?><?php if ($item['rating_count']): ?> <small>(<?php echo esc_html($item['rating_count']); ?>)</small><?php endif; ?></td>
                                <td>
                                    <?php if ($item['status']==='new'): ?><strong style="color:#008a20">New</strong>
                                    <?php elseif ($item['status']==='existing'): ?><strong>Already in TN Game</strong><?php if ($item['activity_id']): ?><br><a href="<?php echo esc_url(get_edit_post_link((int)$item['activity_id'])); ?>">Open listing ↗</a><?php endif; ?>
                                    <?php else: ?><strong style="color:#b26200">In Discovery</strong><?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                    <p style="margin-top:15px"><?php submit_button('Add Selected to Discovery','primary','submit',false); ?></p>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }
}
