<?php
/**
 * TN Game Trail Game Bootstrap
 * Turns a published trail into a playable TN Game on first Start Trail Game click.
 */
if (!defined('ABSPATH')) exit;

final class TNG_Trail_Game_Bootstrap {
    const ACTION = 'tng_start_trail_game';

    public static function boot(): void { add_action('template_redirect', [__CLASS__, 'handle_start'], 0); }

    public static function linked_game_id(int $trail_id): int {
        if (!$trail_id || !post_type_exists('tng_game')) return 0;
        $ids=get_posts(['post_type'=>'tng_game','post_status'=>['publish','draft','private'],'posts_per_page'=>1,'fields'=>'ids','meta_key'=>'tng_trail_id','meta_value'=>$trail_id,'orderby'=>'modified','order'=>'DESC','no_found_rows'=>true]);
        return $ids?absint($ids[0]):0;
    }

    public static function play_url(int $trail_id): string {
        $game_id=self::linked_game_id($trail_id);
        if($game_id&&get_post_status($game_id)==='publish')return add_query_arg(['game'=>$game_id],home_url('/game-play/'));
        $url=add_query_arg([self::ACTION=>1,'trail'=>$trail_id],home_url('/'));
        return wp_nonce_url($url,self::ACTION.'_'.$trail_id,'_tng_trail_game_nonce');
    }

    private static function valid_trail(int $trail_id): bool {$post=get_post($trail_id);return(bool)($post&&$post->post_status==='publish'&&in_array($post->post_type,['st_activity','activity'],true));}
    private static function ids_from_value($value): array {$ids=[];if(is_object($value)&&isset($value->ID))$value=$value->ID;if(is_numeric($value))return[absint($value)];if(is_string($value)){$maybe=maybe_unserialize($value);if($maybe!==$value)return self::ids_from_value($maybe);if(preg_match_all('/\b\d+\b/',$value,$m))foreach($m[0] as $id)$ids[]=absint($id);}elseif(is_array($value))foreach($value as $item)$ids=array_merge($ids,self::ids_from_value($item));return array_values(array_unique(array_filter($ids)));}
    private static function top_sight_types(): array {$types=[];foreach(get_post_types(['public'=>true],'names') as $type){$key=strtolower(str_replace(['-','_'],'',(string)$type));if($key==='topsight'||(strpos($key,'top')!==false&&strpos($key,'sight')!==false))$types[]=$type;}foreach(['top_sight','top-sight','topsight','top-sights','tng_top_sight'] as $type)if(post_type_exists($type))$types[]=$type;return array_values(array_unique($types));}
    private static function related_sight_ids(int $trail_id): array {$ids=[];foreach(['related_top_sights','top_sights','top_sight_ids','trail_top_sights','tng_top_sights','linked_top_sights','trail_sights'] as $key){$ids=array_merge($ids,self::ids_from_value(get_post_meta($trail_id,$key,true)));if(function_exists('get_field'))$ids=array_merge($ids,self::ids_from_value(get_field($key,$trail_id)));}$types=self::top_sight_types();return array_values(array_filter(array_unique(array_map('absint',$ids)),static function($id)use($types){return$id&&in_array(get_post_type($id),$types,true)&&get_post_status($id)==='publish';}));}
    private static function sight_coordinates(int $id): array {$pairs=[['sight_latitude','sight_longitude'],['_sight_latitude','_sight_longitude'],['latitude','longitude'],['lat','lng'],['top_sight_latitude','top_sight_longitude'],['map_lat','map_lng'],['location_lat','location_lng']];foreach($pairs as[$lat_key,$lng_key]){$lat=get_post_meta($id,$lat_key,true);$lng=get_post_meta($id,$lng_key,true);if(is_numeric($lat)&&is_numeric($lng))return[(float)$lat,(float)$lng];if(function_exists('get_field')){$lat=get_field($lat_key,$id);$lng=get_field($lng_key,$id);if(is_numeric($lat)&&is_numeric($lng))return[(float)$lat,(float)$lng];}}return[0.0,0.0];}
    private static function sight_xp(int $id): int {foreach(['xp','xp_reward','checkpoint_xp','top_sight_xp','xp_available'] as $key){$value=get_post_meta($id,$key,true);if(is_numeric($value)&&absint($value)>0)return absint($value);if(function_exists('get_field')){$value=get_field($key,$id);if(is_numeric($value)&&absint($value)>0)return absint($value);}}return25;}
    private static function route_url(int $trail_id): string {foreach(['trail_gpx_url','trail_gpx','gpx_url','gpx_file','gpx','tng_gpx_url','tng_trail_gpx','route_gpx_url','route_gpx'] as $key){$value=get_post_meta($trail_id,$key,true);if(function_exists('get_field')&&!$value)$value=get_field($key,$trail_id);if(is_numeric($value)){$url=wp_get_attachment_url(absint($value));if($url)return esc_url_raw($url);}if(is_array($value))$value=$value['url']??$value['file']??'';if(is_string($value)&&trim($value)!=='')return esc_url_raw(strpos($value,'/')===0?home_url($value):$value);}return'';}
    private static function route_endpoints(int $trail_id): array {$url=self::route_url($trail_id);if(!$url)return[];$response=wp_remote_get($url,['timeout'=>5,'redirection'=>3]);if(is_wp_error($response)||wp_remote_retrieve_response_code($response)!==200)return[];$body=wp_remote_retrieve_body($response);if(!$body)return[];libxml_use_internal_errors(true);$xml=simplexml_load_string($body);if(!$xml)return[];$points=[];$nodes=$xml->xpath('//*[local-name()="trkpt" or local-name()="rtept"]');if(is_array($nodes))foreach($nodes as $node){$attrs=$node->attributes();if(!isset($attrs['lat'],$attrs['lon']))continue;$lat=(float)$attrs['lat'];$lng=(float)$attrs['lon'];if(abs($lat)<=90&&abs($lng)<=180)$points[]=[$lat,$lng];}if(!$points)return[];return[$points[0],$points[count($points)-1]];}
    private static function build_checkpoints(int $trail_id): array {$checkpoints=[];foreach(self::related_sight_ids($trail_id) as $sight_id){[$lat,$lng]=self::sight_coordinates($sight_id);if(!$lat&&!$lng)continue;$title=get_the_title($sight_id)?:'Top Sight';$checkpoints[]=['title'=>$title,'instructions'=>'Visit '.$title.'.','type'=>'gps','latitude'=>$lat,'longitude'=>$lng,'radius'=>30,'sight_id'=>$sight_id,'top_sight_id'=>$sight_id,'xp'=>self::sight_xp($sight_id)];}if($checkpoints)return$checkpoints;$ends=self::route_endpoints($trail_id);if(count($ends)===2){$checkpoints[]=['title'=>'Trailhead','instructions'=>'Start the trail at the trailhead.','type'=>'gps','latitude'=>$ends[0][0],'longitude'=>$ends[0][1],'radius'=>40,'xp'=>10];$checkpoints[]=['title'=>'Finish the trail','instructions'=>'Reach the end of the trail to complete the adventure.','type'=>'gps','latitude'=>$ends[1][0],'longitude'=>$ends[1][1],'radius'=>40,'xp'=>25];}return$checkpoints;}
    private static function meta_first(int $id,array $keys,string $fallback=''): string {foreach($keys as $key){$value=get_post_meta($id,$key,true);if(is_scalar($value)&&trim((string)$value)!=='')return sanitize_text_field((string)$value);}return$fallback;}
    private static function ensure_game(int $trail_id): int {$existing=self::linked_game_id($trail_id);if($existing){if(get_post_status($existing)!=='publish')wp_update_post(['ID'=>$existing,'post_status'=>'publish']);return$existing;}$trail=get_post($trail_id);if(!$trail)return0;$checkpoints=self::build_checkpoints($trail_id);if(!$checkpoints)return0;$game_id=wp_insert_post(['post_type'=>'tng_game','post_status'=>'publish','post_title'=>get_the_title($trail_id).' Trail Game','post_excerpt'=>'Turn '.get_the_title($trail_id).' into a playable trail adventure.','post_content'=>'Follow the GPX route, visit each checkpoint, discover Top Sights, and earn Explorer XP along the way.','post_author'=>(int)$trail->post_author],true);if(is_wp_error($game_id)||!$game_id)return0;$xp=0;foreach($checkpoints as $cp)$xp+=absint($cp['xp']??0);update_post_meta($game_id,'tng_trail_id',$trail_id);update_post_meta($game_id,'tng_game_checkpoints',$checkpoints);update_post_meta($game_id,'checkpoint_count',count($checkpoints));update_post_meta($game_id,'xp_available',$xp);update_post_meta($game_id,'game_type','Trail Quest');update_post_meta($game_id,'difficulty',self::meta_first($trail_id,['trail_difficulty','difficulty'],'Explore'));update_post_meta($game_id,'estimated_time',self::meta_first($trail_id,['estimated_time','trail_time','duration'],'Trail pace'));update_post_meta($game_id,'players','1+ players');update_post_meta($game_id,'playable','1');update_post_meta($game_id,'tng_auto_generated_from_trail','1');$thumb=get_post_thumbnail_id($trail_id);if($thumb)set_post_thumbnail($game_id,$thumb);return$game_id;}

    public static function handle_start(): void {
        $trail_id=absint($_GET['trail']??0);
        $explicit=!empty($_GET[self::ACTION]);
        $legacy=false;
        if(!$explicit&&$trail_id){
            $path=trim((string)wp_parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH),'/');
            $legacy=($path==='play'||substr($path,-5)==='/play');
        }
        if(!$explicit&&!$legacy)return;
        if(!$trail_id||!self::valid_trail($trail_id))wp_die('This trail is not available.');
        if($explicit){$nonce=sanitize_text_field(wp_unslash($_GET['_tng_trail_game_nonce']??''));if(!$nonce||!wp_verify_nonce($nonce,self::ACTION.'_'.$trail_id))wp_die('Trail game link expired. Please return to the trail and try again.');}
        $game_id=self::ensure_game($trail_id);
        if(!$game_id){wp_safe_redirect(add_query_arg(['trail_game'=>'needs_checkpoints'],get_permalink($trail_id)));exit;}
        wp_safe_redirect(add_query_arg(['game'=>$game_id],home_url('/game-play/')));exit;
    }
}
TNG_Trail_Game_Bootstrap::boot();
