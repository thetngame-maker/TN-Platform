<?php
namespace TNG_OS\Modules\Sources;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class Town_Monitor implements Module_Interface {
    private const OPTION = 'tng_town_monitor_settings_v1';
    private const LOG_OPTION = 'tng_town_monitor_log_v1';
    private const HISTORY_OPTION = 'tng_town_scanner_history_v1';
    private const TOKEN_OPTION = 'tng_maps_apify_token';
    private const ACTOR_OPTION = 'tng_maps_apify_actor';
    private const CRON_HOOK = 'tng_town_monitor_cron';
    private const NONCE = 'tng_town_monitor_action';
    private const HISTORY_LIMIT = 25;

    public function id(): string { return 'town_monitor'; }

    public function register(Container $container): void {
        add_action('admin_menu', [$this, 'admin_menu'], 28);
        add_action('admin_post_tng_town_monitor_save', [$this, 'save_action']);
        add_action('admin_post_tng_town_monitor_run', [$this, 'run_now_action']);
        add_action(self::CRON_HOOK, [$this, 'cron_run']);
        add_filter('cron_schedules', [$this, 'cron_schedules']);
        $container->set('town_monitor', $this);
    }

    public function boot(Container $container): void { $this->sync_schedule(); }

    public function cron_schedules(array $schedules): array {
        if (!isset($schedules['tng_weekly'])) {
            $schedules['tng_weekly'] = ['interval'=>7 * DAY_IN_SECONDS, 'display'=>'Once Weekly'];
        }
        return $schedules;
    }

    public function admin_menu(): void {
        add_submenu_page('tng-content-studio','Town Monitoring','Town Monitoring','edit_posts','tng-town-monitor',[$this,'render_page']);
    }

    private function defaults(): array {
        return [
            'enabled'=>0,
            'cadence'=>'weekly',
            'towns'=>"Monteagle, TN\nTracy City, TN\nSewanee, TN\nPelham, TN\nCoalmont, TN",
            'types'=>['restaurants','coffee','shops','lodging','campgrounds'],
            'max_items'=>50,
        ];
    }

    private function settings(): array {
        $saved=get_option(self::OPTION,[]);
        return wp_parse_args(is_array($saved)?$saved:[],$this->defaults());
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

    private function towns(array $settings): array {
        $raw=preg_split('/\r\n|\r|\n/',(string)($settings['towns']??''));
        return array_values(array_unique(array_filter(array_map('sanitize_text_field',(array)$raw))));
    }

    private function actor(): string {
        $actor=trim((string)get_option(self::ACTOR_OPTION,'pro100chok~google-maps-scraper'));
        return str_replace('/','~',$actor?:'pro100chok~google-maps-scraper');
    }

    private function schedule_name(array $settings): string {
        return (($settings['cadence']??'weekly')==='daily')?'daily':'tng_weekly';
    }

    private function sync_schedule(): void {
        $settings=$this->settings();
        $next=wp_next_scheduled(self::CRON_HOOK);
        if (empty($settings['enabled'])) {
            if ($next) wp_clear_scheduled_hook(self::CRON_HOOK);
            return;
        }
        $desired=$this->schedule_name($settings);
        if ($next) {
            $event=wp_get_scheduled_event(self::CRON_HOOK);
            if ($event && $event->schedule===$desired) return;
            wp_clear_scheduled_hook(self::CRON_HOOK);
        }
        wp_schedule_event(time()+300,$desired,self::CRON_HOOK);
    }

    private function redirect(string $message): void {
        wp_safe_redirect(add_query_arg(['page'=>'tng-town-monitor','tng_notice'=>rawurlencode($message)],admin_url('admin.php')));
        exit;
    }

    public function save_action(): void {
        if (!current_user_can('edit_posts')) wp_die('You do not have permission to manage Town Monitoring.');
        check_admin_referer(self::NONCE);
        $defs=$this->definitions();
        $types=array_values(array_intersect(array_keys($defs),(array)wp_unslash($_POST['types']??[])));
        if (!$types) $types=array_keys($defs);
        $settings=[
            'enabled'=>!empty($_POST['enabled'])?1:0,
            'cadence'=>sanitize_key((string)wp_unslash($_POST['cadence']??'weekly'))==='daily'?'daily':'weekly',
            'towns'=>sanitize_textarea_field((string)wp_unslash($_POST['towns']??'')),
            'types'=>$types,
            'max_items'=>max(5,min(100,absint($_POST['max_items']??50))),
        ];
        update_option(self::OPTION,$settings,false);
        wp_clear_scheduled_hook(self::CRON_HOOK);
        $this->sync_schedule();
        $this->redirect('Town Monitoring settings saved.');
    }

    public function run_now_action(): void {
        if (!current_user_can('edit_posts')) wp_die('You do not have permission to run Town Monitoring.');
        check_admin_referer(self::NONCE);
        $summary=$this->run_all(true);
        $this->redirect($summary);
    }

    public function cron_run(): void { $this->run_all(false); }

    private function run_all(bool $manual=false): string {
        $settings=$this->settings();
        $towns=$this->towns($settings);
        if (!$towns) return 'No towns are configured for monitoring.';
        $token=trim((string)get_option(self::TOKEN_OPTION,''));
        if (!$token) return 'Apify token is not configured.';
        $ok=0; $failed=0; $changes=0;
        foreach ($towns as $town) {
            $result=$this->scan_town($town,(array)$settings['types'],(int)$settings['max_items'],$token);
            if (is_wp_error($result)) { $failed++; $this->log($town,'error',$result->get_error_message()); continue; }
            $ok++;
            $changes+=array_sum((array)($result['change_counts']??[]));
            $this->log($town,$manual?'manual':'scheduled',sprintf('%d places; %d actionable changes',(int)($result['total']??0),array_sum((array)($result['change_counts']??[]))));
        }
        return sprintf('Town monitoring finished: %d town%s scanned, %d failed, %d change signals recorded.',$ok,$ok===1?'':'s',$failed,$changes);
    }

    private function scan_town(string $town,array $selected,int $max,string $token) {
        $defs=$this->definitions();
        $selected=array_values(array_intersect(array_keys($defs),$selected));
        if (!$selected) $selected=array_keys($defs);
        $queries=[]; foreach ($selected as $key) $queries[]=$defs[$key]['query'];
        $input=['searchStringsArray'=>$queries,'locationQuery'=>$town,'maxItems'=>max(5,min(100,$max)),'deepSearch'=>false,'countryCode'=>'us','language'=>'en','scrapeContactsFromWebsite'=>true,'skipPlacesWithoutEmail'=>false,'skipDuplicateEmails'=>false,'includeReviews'=>false,'includeImages'=>true,'maxImagesPerPlace'=>10];
        $endpoint='https://api.apify.com/v2/acts/'.rawurlencode($this->actor()).'/run-sync-get-dataset-items';
        $response=wp_remote_post($endpoint,['timeout'=>180,'headers'=>['Content-Type'=>'application/json','Accept'=>'application/json','Authorization'=>'Bearer '.$token],'body'=>wp_json_encode($input)]);
        if (is_wp_error($response)) return $response;
        $status=wp_remote_retrieve_response_code($response); $body=json_decode(wp_remote_retrieve_body($response),true);
        if ($status<200||$status>=300||!is_array($body)) return new \WP_Error('tng_monitor_http','Apify returned HTTP '.$status.'.');
        $results=[]; $seen=[];
        foreach ($body as $raw) {
            if (!is_array($raw)) continue;
            $item=$this->normalize($raw); if (!$item['name']) continue;
            $dedupe=$item['place_id']?:($item['maps_url']?:strtolower($item['name'].'|'.$item['address']));
            if (isset($seen[$dedupe])) continue; $seen[$dedupe]=true;
            $activity_id=$this->existing_activity_id($item); $candidate_id=$activity_id?0:$this->existing_candidate_id($item);
            $item['activity_id']=$activity_id; $item['candidate_id']=$candidate_id;
            $item['status']=$activity_id?'existing':($candidate_id?'discovery':'new');
            $item['service']=$this->suggested_service($item['category'],$item['name']);
            $results[]=$item;
        }
        [$results,$change_counts]=$this->apply_history($town,$selected,$results);
        return ['total'=>count($seen),'change_counts'=>$change_counts];
    }

    private function pick(array $data,array $keys,$default='') {
        foreach ($keys as $key) if (array_key_exists($key,$data)&&$data[$key]!==''&&$data[$key]!==null&&!is_array($data[$key])) return $data[$key];
        return $default;
    }

    private function normalize(array $item): array {
        $location=isset($item['location'])&&is_array($item['location'])?$item['location']:[];
        $coords=isset($item['coordinates'])&&is_array($item['coordinates'])?$item['coordinates']:[];
        $lat=$this->pick($item,['latitude','lat'],$this->pick($location,['lat','latitude'],$this->pick($coords,['lat','latitude'],'')));
        $lng=$this->pick($item,['longitude','lng','lon'],$this->pick($location,['lng','longitude','lon'],$this->pick($coords,['lng','longitude','lon'],'')));
        $photos=[];
        foreach (['imageUrls','images','photos'] as $key) if (!empty($item[$key])&&is_array($item[$key])) foreach ($item[$key] as $photo) {
            if (is_string($photo)) $photos[]=esc_url_raw($photo);
            elseif (is_array($photo)) { $url=$this->pick($photo,['url','imageUrl','photoUrl','thumbnail']); if ($url) $photos[]=esc_url_raw((string)$url); }
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
            'email'=>sanitize_email((string)$this->pick($item,['email','businessEmail'])),'socials'=>[],
            'photos'=>array_slice(array_values(array_unique(array_filter($photos))),0,10),
        ];
    }

    private function suggested_service(string $category,string $name=''): string {
        $text=strtolower(trim($category.' '.$name));
        if (preg_match('/campground|camping|rv park/',$text)) return 'campgrounds';
        if (preg_match('/hotel|motel|lodging|inn|resort|bed and breakfast|cabin rental/',$text)) return 'lodging';
        if (preg_match('/shop|store|boutique|gift|market/',$text)) return 'shops';
        return 'food';
    }

    private function existing_activity_id(array $item): int {
        $meta=[];
        if (!empty($item['place_id'])) foreach (['_tng_food_google_place_id','_tng_google_place_id'] as $key) $meta[]=['key'=>$key,'value'=>$item['place_id']];
        if (!empty($item['maps_url'])) foreach (['_tng_food_google_maps_url','_tng_source_maps_url'] as $key) $meta[]=['key'=>$key,'value'=>$item['maps_url']];
        if ($meta) { $ids=get_posts(['post_type'=>'st_activity','post_status'=>'any','numberposts'=>1,'fields'=>'ids','meta_query'=>array_merge(['relation'=>'OR'],$meta)]); if ($ids) return (int)$ids[0]; }
        if (!empty($item['name'])) { $posts=get_posts(['post_type'=>'st_activity','post_status'=>'any','numberposts'=>1,'title'=>$item['name']]); if ($posts) return (int)$posts[0]->ID; }
        return 0;
    }

    private function existing_candidate_id(array $item): int {
        $meta=['relation'=>'OR'];
        if (!empty($item['place_id'])) $meta[]=['key'=>'_tng_local_place_id','value'=>$item['place_id']];
        if (!empty($item['maps_url'])) $meta[]=['key'=>'_tng_local_maps_url','value'=>$item['maps_url']];
        if (count($meta)===1) return 0;
        $ids=get_posts(['post_type'=>'tng_local_candidate','post_status'=>'any','numberposts'=>1,'fields'=>'ids','meta_query'=>$meta]);
        return $ids?(int)$ids[0]:0;
    }

    private function changed_fields(array $old,array $new): array {
        $labels=['address'=>'Address','phone'=>'Phone','website'=>'Website','category'=>'Category']; $changed=[];
        foreach ($labels as $field=>$label) { $before=trim((string)($old[$field]??'')); $after=trim((string)($new[$field]??'')); if ($before!==$after&&($before!==''||$after!=='')) $changed[]=$label; }
        return $changed;
    }

    private function place_key(array $item): string {
        if (!empty($item['place_id'])) return 'place:'.$item['place_id'];
        if (!empty($item['maps_url'])) return 'maps:'.md5(strtolower($item['maps_url']));
        return 'fallback:'.md5(strtolower(($item['name']??'').'|'.($item['address']??'')));
    }

    private function apply_history(string $town,array $types,array $results): array {
        $history=get_option(self::HISTORY_OPTION,[]); if (!is_array($history)) $history=[];
        $town_key=sanitize_title(strtolower(trim($town))); $town_history=is_array($history[$town_key]??null)?$history[$town_key]:[];
        $previous=is_array($town_history['snapshot']??null)?$town_history['snapshot']:[]; $previous_types=is_array($town_history['types']??null)?$town_history['types']:[];
        $a=$previous_types; $b=$types; sort($a); sort($b); $can_mark_missing=$previous&&$a===$b;
        $snapshot=[]; $seen=[];
        foreach ($results as &$item) {
            $key=$this->place_key($item); $seen[$key]=true; $old=$previous[$key]??null;
            $item['change_status']=$old?'unchanged':'new'; $item['changed_fields']=[]; $item['miss_count']=0;
            if (is_array($old)) { $changed=$this->changed_fields($old,$item); if ($changed) { $item['change_status']='changed'; $item['changed_fields']=$changed; } elseif (!empty($old['miss_count'])) $item['change_status']='returned'; }
            $snapshot[$key]=$item;
        }
        unset($item);
        if ($can_mark_missing) foreach ($previous as $key=>$old) {
            if (isset($seen[$key])||!is_array($old)) continue;
            $miss_count=max(0,absint($old['miss_count']??0))+1; $missing=$old;
            unset($missing['_review_status'],$missing['_reviewed_at'],$missing['_reviewed_by']);
            $missing['status']='missing'; $missing['change_status']=$miss_count>=2?'possibly_closed':'missing'; $missing['changed_fields']=[]; $missing['miss_count']=$miss_count;
            $results[]=$missing; $snapshot[$key]=$missing;
        }
        $counts=['new'=>0,'changed'=>0,'returned'=>0,'missing'=>0,'possibly_closed'=>0]; foreach ($results as $item) if (isset($counts[$item['change_status']??''])) $counts[$item['change_status']]++;
        $scan=['scanned_at'=>current_time('mysql'),'types'=>$types,'total'=>count($seen),'counts'=>$counts,'source'=>'monitor'];
        $scans=is_array($town_history['scans']??null)?$town_history['scans']:[]; array_unshift($scans,$scan); $scans=array_slice($scans,0,self::HISTORY_LIMIT);
        $history[$town_key]=['town'=>$town,'types'=>$types,'snapshot'=>$snapshot,'scans'=>$scans,'updated_at'=>current_time('mysql')];
        update_option(self::HISTORY_OPTION,$history,false);
        return [$results,$counts];
    }

    private function log(string $town,string $status,string $message): void {
        $log=get_option(self::LOG_OPTION,[]); if (!is_array($log)) $log=[];
        array_unshift($log,['time'=>current_time('mysql'),'town'=>sanitize_text_field($town),'status'=>sanitize_key($status),'message'=>sanitize_text_field($message)]);
        update_option(self::LOG_OPTION,array_slice($log,0,50),false);
    }

    public function render_page(): void {
        if (!current_user_can('edit_posts')) return;
        $settings=$this->settings(); $defs=$this->definitions(); $notice=sanitize_text_field(wp_unslash($_GET['tng_notice']??''));
        $next=wp_next_scheduled(self::CRON_HOOK); $log=get_option(self::LOG_OPTION,[]); if (!is_array($log)) $log=[];
        ?>
        <div class="wrap"><h1>🛰️ Town Monitoring</h1>
        <p>Automatically rescan selected towns and feed changes into the same Town Scanner history and Changes Inbox.</p>
        <?php if($notice): ?><div class="notice notice-info is-dismissible"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:20px;max-width:1000px">
            <input type="hidden" name="action" value="tng_town_monitor_save"><?php wp_nonce_field(self::NONCE); ?>
            <table class="form-table">
                <tr><th>Automatic monitoring</th><td><label><input type="checkbox" name="enabled" value="1" <?php checked(!empty($settings['enabled'])); ?>> Enable scheduled town scans</label></td></tr>
                <tr><th>Cadence</th><td><select name="cadence"><option value="weekly" <?php selected($settings['cadence'],'weekly'); ?>>Weekly</option><option value="daily" <?php selected($settings['cadence'],'daily'); ?>>Daily</option></select></td></tr>
                <tr><th>Towns</th><td><textarea name="towns" rows="7" class="large-text code"><?php echo esc_textarea((string)$settings['towns']); ?></textarea><p class="description">One town per line.</p></td></tr>
                <tr><th>Scan for</th><td><?php foreach($defs as $key=>$def): ?><label style="margin-right:18px"><input type="checkbox" name="types[]" value="<?php echo esc_attr($key); ?>" <?php checked(in_array($key,(array)$settings['types'],true)); ?>> <?php echo esc_html($def['label']); ?></label><?php endforeach; ?></td></tr>
                <tr><th>Maximum results</th><td><input type="number" min="5" max="100" name="max_items" value="<?php echo absint($settings['max_items']); ?>"></td></tr>
            </table>
            <?php submit_button('Save Monitoring Settings'); ?>
        </form>
        <p style="margin-top:14px"><strong>Next scheduled run:</strong> <?php echo $next?esc_html(wp_date('Y-m-d g:i A',$next)):'Not scheduled'; ?></p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:14px 0"><input type="hidden" name="action" value="tng_town_monitor_run"><?php wp_nonce_field(self::NONCE); ?><button class="button button-primary">Run all monitored towns now</button> <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=tng-town-changes')); ?>">Open Changes Inbox</a></form>
        <h2>Recent monitoring runs</h2>
        <?php if(!$log): ?><p>No monitoring runs yet.</p><?php else: ?><table class="widefat striped" style="max-width:1000px"><thead><tr><th>Time</th><th>Town</th><th>Run</th><th>Result</th></tr></thead><tbody><?php foreach(array_slice($log,0,25) as $row): ?><tr><td><?php echo esc_html((string)($row['time']??'')); ?></td><td><?php echo esc_html((string)($row['town']??'')); ?></td><td><?php echo esc_html((string)($row['status']??'')); ?></td><td><?php echo esc_html((string)($row['message']??'')); ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
        </div><?php
    }
}
