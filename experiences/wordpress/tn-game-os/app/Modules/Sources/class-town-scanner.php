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
    private const HISTORY_OPTION = 'tng_town_scanner_history_v1';
    private const HISTORY_LIMIT = 25;

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
        add_submenu_page('tng-content-studio','Town Scanner','Town Scanner','edit_posts','tng-town-scanner',[$this,'render_page']);
    }

    private function guard(): void {
        if (!current_user_can('edit_posts')) wp_die('You do not have permission to use Town Scanner.');
        check_admin_referer(self::NONCE);
    }

    private function transient_key(): string { return 'tng_town_scan_results_' . get_current_user_id(); }

    private function actor(): string {
        $actor=trim((string)get_option(self::ACTOR_OPTION,'pro100chok~google-maps-scraper'));
        return str_replace('/','~',$actor ?: 'pro100chok~google-maps-scraper');
    }

    private function definitions(): array {
        return [
            'restaurants'=>['label'=>'Restaurants','query'=>'restaurants','service'=>'food'],
            'coffee'=>['label'=>'Coffee & Cafés','query'=>'coffee shops','service'=>'food'],
            'shops'=>['label'=>'Shops','query'=>'shops','service'=>'shops'],
            'lodging'=>['label'=>'Lodging','query'=>'hotels lodging','service'=>'lodging'],
            'campgrounds'=>['label'=>'Campgrounds & RV','query'=>'campgrounds rv parks','service'=>'campgrounds'],
        ];
    }

    private function redirect(string $message): void {
        wp_safe_redirect(add_query_arg(['page'=>'tng-town-scanner','tng_notice'=>rawurlencode($message)],admin_url('admin.php')));
        exit;
    }

    private function pick(array $data,array $keys,$default='') {
        foreach ($keys as $key) if (array_key_exists($key,$data) && $data[$key]!=='' && $data[$key]!==null && !is_array($data[$key])) return $data[$key];
        return $default;
    }

    private function normalize(array $item): array {
        $location=isset($item['location'])&&is_array($item['location'])?$item['location']:[];
        $coords=isset($item['coordinates'])&&is_array($item['coordinates'])?$item['coordinates']:[];
        $lat=$this->pick($item,['latitude','lat'],$this->pick($location,['lat','latitude'],$this->pick($coords,['lat','latitude'],'')));
        $lng=$this->pick($item,['longitude','lng','lon'],$this->pick($location,['lng','longitude','lon'],$this->pick($coords,['lng','longitude','lon'],'')));
        $photos=[];
        foreach (['imageUrls','images','photos'] as $key) {
            if (empty($item[$key])||!is_array($item[$key])) continue;
            foreach ($item[$key] as $photo) {
                if (is_string($photo)) { $photos[]=esc_url_raw($photo); continue; }
                if (!is_array($photo)) continue;
                $url=$this->pick($photo,['url','imageUrl','photoUrl','thumbnail']);
                if ($url) $photos[]=esc_url_raw((string)$url);
            }
        }
        $socials=[];
        foreach ([
            'facebook'=>['facebook','facebookUrl','facebook_url'],'instagram'=>['instagram','instagramUrl','instagram_url'],
            'x'=>['twitter','twitterUrl','xUrl'],'linkedin'=>['linkedin','linkedinUrl'],'youtube'=>['youtube','youtubeUrl'],'tiktok'=>['tiktok','tiktokUrl'],
        ] as $platform=>$keys) {
            $url=$this->pick($item,$keys); if ($url) $socials[$platform]=esc_url_raw((string)$url);
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
            'latitude'=>sanitize_text_field((string)$lat),'longitude'=>sanitize_text_field((string)$lng),
            'email'=>sanitize_email((string)$this->pick($item,['email','businessEmail'])),'socials'=>$socials,
            'photos'=>array_slice(array_values(array_unique(array_filter($photos))),0,10),
        ];
    }

    private function suggested_service(string $category,string $name=''): string {
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

    private function existing_candidate_id(array $item): int {
        $meta=['relation'=>'OR'];
        if (!empty($item['place_id'])) $meta[]=['key'=>'_tng_local_place_id','value'=>$item['place_id']];
        if (!empty($item['maps_url'])) $meta[]=['key'=>'_tng_local_maps_url','value'=>$item['maps_url']];
        if (count($meta)===1) return 0;
        $ids=get_posts(['post_type'=>self::CANDIDATE_CPT,'post_status'=>'any','numberposts'=>1,'fields'=>'ids','meta_query'=>$meta]);
        return $ids?(int)$ids[0]:0;
    }

    private function town_key(string $town): string { return sanitize_title(strtolower(trim($town))); }

    private function place_key(array $item): string {
        if (!empty($item['place_id'])) return 'place:'.$item['place_id'];
        if (!empty($item['maps_url'])) return 'maps:'.md5(strtolower($item['maps_url']));
        return 'fallback:'.md5(strtolower(($item['name']??'').'|'.($item['address']??'')));
    }

    private function history_all(): array {
        $history=get_option(self::HISTORY_OPTION,[]);
        return is_array($history)?$history:[];
    }

    private function comparable_types(array $a,array $b): bool {
        sort($a); sort($b); return $a===$b;
    }

    private function changed_fields(array $old,array $new): array {
        $labels=['address'=>'Address','phone'=>'Phone','website'=>'Website','category'=>'Category'];
        $changed=[];
        foreach ($labels as $field=>$label) {
            $before=trim((string)($old[$field]??'')); $after=trim((string)($new[$field]??''));
            if ($before!==$after && ($before!=='' || $after!=='')) $changed[]=$label;
        }
        return $changed;
    }

    private function apply_history(string $town,array $types,array $results): array {
        $history=$this->history_all(); $town_key=$this->town_key($town);
        $town_history=is_array($history[$town_key]??null)?$history[$town_key]:[];
        $previous=is_array($town_history['snapshot']??null)?$town_history['snapshot']:[];
        $previous_types=is_array($town_history['types']??null)?$town_history['types']:[];
        $can_mark_missing=$previous && $this->comparable_types($previous_types,$types);
        $snapshot=[]; $seen=[];
        foreach ($results as &$item) {
            $key=$this->place_key($item); $seen[$key]=true; $old=$previous[$key]??null;
            $item['change_status']=$old?'unchanged':'new'; $item['changed_fields']=[]; $item['miss_count']=0;
            if (is_array($old)) {
                $changed=$this->changed_fields($old,$item);
                if ($changed) { $item['change_status']='changed'; $item['changed_fields']=$changed; }
                elseif (!empty($old['miss_count'])) $item['change_status']='returned';
            }
            $snapshot[$key]=$item;
        }
        unset($item);
        if ($can_mark_missing) {
            foreach ($previous as $key=>$old) {
                if (isset($seen[$key])||!is_array($old)) continue;
                $miss_count=max(0,absint($old['miss_count']??0))+1;
                $missing=$old;
                unset($missing['_review_status'],$missing['_reviewed_at'],$missing['_reviewed_by']);
                $missing['status']='missing'; $missing['activity_id']=absint($old['activity_id']??0); $missing['candidate_id']=absint($old['candidate_id']??0);
                $missing['change_status']=$miss_count>=2?'possibly_closed':'missing'; $missing['changed_fields']=[]; $missing['miss_count']=$miss_count;
                $results[]=$missing; $snapshot[$key]=$missing;
            }
        }
        $counts=['new'=>0,'changed'=>0,'returned'=>0,'missing'=>0,'possibly_closed'=>0];
        foreach ($results as $item) if (isset($counts[$item['change_status']??''])) $counts[$item['change_status']]++;
        $scan=['scanned_at'=>current_time('mysql'),'types'=>$types,'total'=>count($seen),'counts'=>$counts];
        $scans=is_array($town_history['scans']??null)?$town_history['scans']:[];
        array_unshift($scans,$scan); $scans=array_slice($scans,0,self::HISTORY_LIMIT);
        $history[$town_key]=['town'=>$town,'types'=>$types,'snapshot'=>$snapshot,'scans'=>$scans,'updated_at'=>current_time('mysql')];
        update_option(self::HISTORY_OPTION,$history,false);
        return [$results,$counts,$scans];
    }

    public function scan_action(): void {
        $this->guard(); $token=trim((string)get_option(self::TOKEN_OPTION,''));
        if (!$token) $this->redirect('Apify token is not configured.');
        $town=sanitize_text_field(wp_unslash($_POST['town']??'')); if (!$town) $this->redirect('Enter a town or city to scan.');
        $defs=$this->definitions();
        $selected=array_values(array_intersect(array_keys($defs),(array)wp_unslash($_POST['scan_types']??[])));
        if (!$selected) $selected=array_keys($defs);
        $max=max(5,min(100,absint($_POST['max_items']??50)));
        $queries=[]; foreach ($selected as $key) $queries[]=$defs[$key]['query'];
        $input=['searchStringsArray'=>$queries,'locationQuery'=>$town,'maxItems'=>$max,'deepSearch'=>false,'countryCode'=>'us','language'=>'en','scrapeContactsFromWebsite'=>true,'skipPlacesWithoutEmail'=>false,'skipDuplicateEmails'=>false,'includeReviews'=>false,'includeImages'=>true,'maxImagesPerPlace'=>10];
        $endpoint='https://api.apify.com/v2/acts/'.rawurlencode($this->actor()).'/run-sync-get-dataset-items';
        $response=wp_remote_post($endpoint,['timeout'=>180,'headers'=>['Content-Type'=>'application/json','Accept'=>'application/json','Authorization'=>'Bearer '.$token],'body'=>wp_json_encode($input)]);
        if (is_wp_error($response)) $this->redirect('Town scan failed: '.$response->get_error_message());
        $status=wp_remote_retrieve_response_code($response); $body=json_decode(wp_remote_retrieve_body($response),true);
        if ($status<200||$status>=300||!is_array($body)) {
            $message=is_array($body)?($body['error']['message']??$body['message']??''):'';
            $this->redirect('Apify returned HTTP '.$status.($message?': '.$message:''));
        }
        $results=[]; $seen=[];
        foreach ($body as $raw) {
            if (!is_array($raw)) continue; $item=$this->normalize($raw); if (!$item['name']) continue;
            $dedupe=$item['place_id']?:($item['maps_url']?:strtolower($item['name'].'|'.$item['address']));
            if (isset($seen[$dedupe])) continue; $seen[$dedupe]=true;
            $activity_id=$this->existing_activity_id($item); $candidate_id=$activity_id?0:$this->existing_candidate_id($item);
            $item['activity_id']=$activity_id; $item['candidate_id']=$candidate_id;
            $item['status']=$activity_id?'existing':($candidate_id?'discovery':'new');
            $item['service']=$this->suggested_service($item['category'],$item['name']); $results[]=$item;
        }
        [$results,$change_counts,$scans]=$this->apply_history($town,$selected,$results);
        set_transient($this->transient_key(),['town'=>$town,'types'=>$selected,'results'=>$results,'scanned_at'=>current_time('mysql'),'change_counts'=>$change_counts,'scans'=>$scans],HOUR_IN_SECONDS);
        $counts=['new'=>0,'existing'=>0,'discovery'=>0]; foreach ($results as $item) if (isset($counts[$item['status']])) $counts[$item['status']]++;
        $this->redirect(sprintf('Town scan finished: %d new, %d changed, %d existing, %d in Discovery, %d missing, %d possibly closed.',$counts['new'],$change_counts['changed'],$counts['existing'],$counts['discovery'],$change_counts['missing'],$change_counts['possibly_closed']));
    }

    public function bulk_add_action(): void {
        $this->guard(); $cache=get_transient($this->transient_key()); $results=is_array($cache)?($cache['results']??[]):[];
        if (!$results) $this->redirect('Town scan results expired. Run the scan again.');
        $selected=array_values(array_unique(array_filter(array_map('absint',(array)wp_unslash($_POST['selected']??[])))));
        if (!$selected) $this->redirect('Select at least one new place.');
        $added=0; $skipped=0;
        foreach ($selected as $index) {
            if (!isset($results[$index])||!is_array($results[$index])) { $skipped++; continue; }
            $item=$results[$index]; if (($item['status']??'')!=='new') { $skipped++; continue; }
            if ($this->existing_candidate_id($item)) { $skipped++; continue; }
            $id=wp_insert_post(['post_type'=>self::CANDIDATE_CPT,'post_status'=>'publish','post_title'=>$item['name'],'post_content'=>''],true);
            if (is_wp_error($id)||!$id) { $skipped++; continue; }
            $meta=['_tng_local_source'=>'google_maps_apify','_tng_local_place_id'=>$item['place_id'],'_tng_local_maps_url'=>$item['maps_url'],'_tng_local_address'=>$item['address'],'_tng_local_phone'=>$item['phone'],'_tng_local_website'=>$item['website'],'_tng_local_category'=>$item['category'],'_tng_local_rating'=>$item['rating'],'_tng_local_rating_count'=>$item['rating_count'],'_tng_local_latitude'=>$item['latitude'],'_tng_local_longitude'=>$item['longitude'],'_tng_local_email'=>$item['email'],'_tng_local_socials'=>$item['socials'],'_tng_local_photos'=>$item['photos'],'_tng_local_status'=>'review','_tng_local_service'=>$item['service'],'_tng_local_scan_town'=>(string)($cache['town']??''),'_tng_local_discovered_at'=>current_time('mysql')];
            foreach ($meta as $key=>$value) update_post_meta((int)$id,$key,$value);
            $results[$index]['candidate_id']=(int)$id; $results[$index]['status']='discovery'; $added++;
        }
        $cache['results']=$results; set_transient($this->transient_key(),$cache,HOUR_IN_SECONDS);
        $this->redirect($added.' place'.($added===1?'':'s').' added to Local Discovery'.($skipped?' ('.$skipped.' skipped)':'').'.');
    }

    public function render_page(): void {
        if (!current_user_can('edit_posts')) return;
        $cache=get_transient($this->transient_key()); $results=is_array($cache)?($cache['results']??[]):[];
        $notice=sanitize_text_field(wp_unslash($_GET['tng_notice']??''));
        $defs=$this->definitions(); $selected=is_array($cache['types']??null)?$cache['types']:array_keys($defs); $town=(string)($cache['town']??'Monteagle, TN');
        $change_counts=is_array($cache['change_counts']??null)?$cache['change_counts']:['new'=>0,'changed'=>0,'returned'=>0,'missing'=>0,'possibly_closed'=>0];
        $status_counts=['new'=>0,'existing'=>0,'discovery'=>0]; foreach ($results as $item) if (isset($status_counts[$item['status']??''])) $status_counts[$item['status']]++;
        ?>
        <div class="wrap"><h1>🏘️ Town Scanner</h1><p>Scan several local-business categories at once, compare them with TN Game, and send only new places into the Local Discovery review queue.</p>
        <?php if($notice): ?><div class="notice notice-info is-dismissible"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>
        <div style="background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:20px;max-width:1100px;margin:20px 0"><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="tng_town_scan"><?php wp_nonce_field(self::NONCE); ?>
        <table class="form-table"><tr><th>Town or city</th><td><input class="regular-text" name="town" value="<?php echo esc_attr($town); ?>" required></td></tr><tr><th>Scan for</th><td><?php foreach($defs as $key=>$def): ?><label style="display:inline-block;margin:0 22px 8px 0"><input type="checkbox" name="scan_types[]" value="<?php echo esc_attr($key); ?>" <?php checked(in_array($key,$selected,true)); ?>> <?php echo esc_html($def['label']); ?></label><?php endforeach; ?></td></tr><tr><th>Maximum total results</th><td><input type="number" name="max_items" value="50" min="5" max="100"><p class="description">The result cap applies to the combined town scan.</p></td></tr></table><?php submit_button('Scan Town'); ?></form></div>
        <?php if($results): ?><div style="display:flex;gap:10px;flex-wrap:wrap;margin:18px 0"><span style="background:#fff;border-left:4px solid #46b450;padding:12px 14px"><strong><?php echo absint($change_counts['new']??0); ?></strong> new</span><span style="background:#fff;border-left:4px solid #dba617;padding:12px 14px"><strong><?php echo absint($change_counts['changed']??0); ?></strong> changed</span><span style="background:#fff;border-left:4px solid #3858e9;padding:12px 14px"><strong><?php echo absint($status_counts['existing']); ?></strong> in TN Game</span><span style="background:#fff;border-left:4px solid #dba617;padding:12px 14px"><strong><?php echo absint($change_counts['missing']??0); ?></strong> missing once</span><span style="background:#fff;border-left:4px solid #b32d2e;padding:12px 14px"><strong><?php echo absint($change_counts['possibly_closed']??0); ?></strong> possibly closed</span></div>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="tng_town_scan_add"><?php wp_nonce_field(self::NONCE); ?><p><button type="button" class="button" onclick="document.querySelectorAll('.tng-town-new').forEach(function(c){c.checked=true;});">Select all new</button> <?php submit_button('Add Selected to Discovery','primary','submit',false); ?></p><div style="overflow-x:auto"><table class="widefat striped"><thead><tr><th></th><th>Place</th><th>Category</th><th>Section</th><th>Rating</th><th>Change</th><th>TN Game status</th></tr></thead><tbody>
        <?php foreach($results as $index=>$item): $is_new=($item['status']==='new' && !in_array($item['change_status']??'', ['missing','possibly_closed'],true)); ?><tr><td><?php if($is_new): ?><input class="tng-town-new" type="checkbox" name="selected[]" value="<?php echo absint($index); ?>"><?php endif; ?></td><td><strong><?php echo esc_html($item['name']); ?></strong><br><small><?php echo esc_html($item['address']); ?></small></td><td><?php echo esc_html($item['category']?:'—'); ?></td><td><?php echo esc_html(ucwords(str_replace('_',' & ',$item['service']))); ?></td><td><?php echo $item['rating']!==''?'⭐ '.esc_html($item['rating']):'—'; ?></td><td><?php $cs=$item['change_status']??'unchanged'; if($cs==='new') echo '<strong style="color:#008a20">New</strong>'; elseif($cs==='changed') echo '<strong style="color:#b26200">Changed</strong><br><small>'.esc_html(implode(', ',(array)$item['changed_fields'])).'</small>'; elseif($cs==='returned') echo '<strong style="color:#2271b1">Returned</strong>'; elseif($cs==='missing') echo '<strong style="color:#996800">Not found this scan</strong>'; elseif($cs==='possibly_closed') echo '<strong style="color:#b32d2e">Possibly closed</strong>'; else echo 'Existing'; ?></td><td><?php if($item['status']==='existing'): ?>Already in TN Game<?php if($item['activity_id']): ?><br><a href="<?php echo esc_url(get_edit_post_link($item['activity_id'])); ?>">Open listing ↗</a><?php endif; ?><?php elseif($item['status']==='discovery'): ?>In Local Discovery<?php else: ?><strong style="color:#008a20">Not added yet</strong><?php endif; ?></td></tr><?php endforeach; ?>
        </tbody></table></div></form>
        <?php $scans=is_array($cache['scans']??null)?$cache['scans']:[]; if($scans): ?><h2 style="margin-top:28px">Recent scan history</h2><table class="widefat striped" style="max-width:900px"><thead><tr><th>Scanned</th><th>New</th><th>Changed</th><th>Returned</th><th>Missing</th><th>Possibly closed</th></tr></thead><tbody><?php foreach(array_slice($scans,0,10) as $scan): $c=(array)($scan['counts']??[]); ?><tr><td><?php echo esc_html($scan['scanned_at']??''); ?></td><td><?php echo absint($c['new']??0); ?></td><td><?php echo absint($c['changed']??0); ?></td><td><?php echo absint($c['returned']??0); ?></td><td><?php echo absint($c['missing']??0); ?></td><td><?php echo absint($c['possibly_closed']??0); ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
        <?php endif; ?></div><?php
    }
}
