<?php
/**
 * Plugin Name: TN Game Map UI
 * Description: Native TN Game discovery map screen for the app router.
 * Version: 0.5.0
 * Author: The TN Game
 */
if (!defined('ABSPATH')) exit;

final class TNG_Map_UI {
    public static function boot(): void { add_filter('template_include',[self::class,'template'],100000); add_action('wp_enqueue_scripts',[self::class,'assets'],130); }
    public static function template(string $template): string { if(!class_exists('TNG_OS\\Platform\\App_Router')||TNG_OS\Platform\App_Router::current_route()!=='map')return$template; $map_template=TNG_OS_PATH.'templates/map-shell.php'; return is_readable($map_template)?$map_template:$template; }
    private static function is_map(): bool { return class_exists('TNG_OS\\Platform\\App_Router')&&TNG_OS\Platform\App_Router::current_route()==='map'; }
    private static function valid_coords($lat,$lng): bool { if(!is_numeric($lat)||!is_numeric($lng))return false; $lat=(float)$lat;$lng=(float)$lng; return $lat>=-90&&$lat<=90&&$lng>=-180&&$lng<=180&&!($lat===0.0&&$lng===0.0); }
    private static function coords_from_value($value): array { if(is_array($value)){ $lat=$value['lat']??$value['latitude']??null;$lng=$value['lng']??$value['lon']??$value['longitude']??null;if(self::valid_coords($lat,$lng))return[(float)$lat,(float)$lng]; } if(is_string($value)&&preg_match('/(-?\d{1,2}\.\d+)\s*[,| ]\s*(-?\d{1,3}\.\d+)/',$value,$m)&&self::valid_coords($m[1],$m[2]))return[(float)$m[1],(float)$m[2]]; return[]; }
    private static function coordinates(int $id): array {
        foreach([['_sight_latitude','_sight_longitude'],['sight_latitude','sight_longitude'],['_tng_destination_lat','_tng_destination_lng'],['tng_destination_lat','tng_destination_lng'],['latitude','longitude'],['lat','lng'],['_latitude','_longitude'],['_lat','_lng']] as[$lat_key,$lng_key]){ $lat=get_post_meta($id,$lat_key,true);$lng=get_post_meta($id,$lng_key,true);if(self::valid_coords($lat,$lng))return[(float)$lat,(float)$lng]; }
        foreach(['st_google_map','location','map','google_map','coordinates'] as$key){ $coords=self::coords_from_value(get_post_meta($id,$key,true));if($coords)return$coords;if(function_exists('get_field')){$coords=self::coords_from_value(get_field($key,$id));if($coords)return$coords;} }
        if(get_post_type($id)==='tng_game'){ $raw=get_post_meta($id,'tng_game_checkpoints',true);if(is_array($raw))foreach($raw as$cp){if(!is_array($cp))continue;$lat=$cp['latitude']??null;$lng=$cp['longitude']??null;if(self::valid_coords($lat,$lng))return[(float)$lat,(float)$lng];} }
        return[];
    }
    private static function searchable_text(int $id): string { $text=strtolower(get_the_title($id).' '.get_post_field('post_content',$id)); foreach(get_object_taxonomies(get_post_type($id)) as$tax){$terms=wp_get_post_terms($id,$tax,['fields'=>'names']);if(!is_wp_error($terms))$text.=' '.strtolower(implode(' ',$terms));} return$text; }
    private static function kind(int $id): string {
        $type=get_post_type($id); if(in_array($type,['tng_game','game'],true))return'game'; if(in_array($type,['top_sight','top-sights','top_sights'],true))return'sight'; if(in_array($type,['tng_destination','st_location'],true))return'destination'; if(class_exists('TNG_Games_UI')&&TNG_Games_UI::is_game($id))return'game';
        $text=self::searchable_text($id); foreach(['start_date','event_date','date','st_start_date'] as$key)if(get_post_meta($id,$key,true)!=='')return'event';
        if(preg_match('/concert|festival|show|event|live music|the caverns/',$text))return'event'; if(preg_match('/restaurant|food|cafe|coffee|burger|kitchen|grill|dining|barbecue|bbq/',$text))return'food'; if(preg_match('/trail|hike|hiking|loop|overlook|waterfall|falls|state park/',$text))return'trail'; return'place';
    }
    private static function label(string $kind): string { return['trail'=>'Trail','game'=>'Game','sight'=>'Top Sight','food'=>'Food','event'=>'Event','destination'=>'Destination','place'=>'Place'][$kind]??'Place'; }
    private static function excerpt(int $id): string { $source=has_excerpt($id)?get_post_field('post_excerpt',$id):get_post_field('post_content',$id);$source=preg_replace('/\[[^\]]+\]/',' ',strip_shortcodes((string)$source));$source=preg_replace('/\s+/',' ',trim(wp_strip_all_tags((string)$source)));return wp_trim_words($source,12,'…'); }
    private static function candidate_posts(): array { $types=array_values(array_filter(['tng_game','game','st_activity','activity','top_sight','top-sights','top_sights','tng_destination','st_location'],'post_type_exists'));if(!$types)return[];$query=new WP_Query(['post_type'=>$types,'post_status'=>'publish','posts_per_page'=>120,'ignore_sticky_posts'=>true,'orderby'=>'modified','order'=>'DESC']);return$query->posts; }
    private static function action_url(int $id,string $kind): string { return $kind==='game'?add_query_arg('game',$id,home_url('/game-play/')):get_permalink($id); }
    private static function action_label(string $kind): string { return $kind==='game'?'Play game':'View'; }
    private static function items(): array { $items=[];foreach(self::candidate_posts() as$post){$id=(int)$post->ID;if(get_post_type($id)==='tng_game'&&class_exists('TNG_Games_UI')&&!TNG_Games_UI::is_player_ready($id))continue;$coords=self::coordinates($id);if(!$coords)continue;$kind=self::kind($id);$items[]=['id'=>$id,'title'=>get_the_title($id),'kind'=>$kind,'label'=>self::label($kind),'lat'=>$coords[0],'lng'=>$coords[1],'url'=>get_permalink($id),'actionUrl'=>self::action_url($id,$kind),'actionLabel'=>self::action_label($kind),'image'=>get_the_post_thumbnail_url($id,'medium_large')?:'','subtitle'=>self::excerpt($id)];}return array_slice($items,0,80); }
    public static function assets(): void {
        if(!self::is_map())return;$items=self::items();wp_dequeue_style('tng-map-ui');
        wp_enqueue_style('tng-leaflet','https://unpkg.com/leaflet@1.9.4/dist/leaflet.css',[],'1.9.4');
        wp_enqueue_style('tng-leaflet-markercluster','https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css',['tng-leaflet'],'1.5.3');
        wp_enqueue_style('tng-leaflet-markercluster-default','https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css',['tng-leaflet-markercluster'],'1.5.3');
        wp_enqueue_style('tng-map-ui',TNG_OS_URL.'assets/css/map-ui.css',['tng-ui-kit','tng-leaflet-markercluster-default'],'0.6.0');
        wp_enqueue_script('tng-leaflet','https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',[],'1.9.4',true);
        wp_enqueue_script('tng-leaflet-markercluster','https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js',['tng-leaflet'],'1.5.3',true);
        wp_enqueue_script('tng-map-ui-live',TNG_OS_URL.'assets/js/map-ui.js',['tng-leaflet-markercluster','tng-trip-data'],'0.5.0',true);
        $center=$items?[(float)$items[0]['lat'],(float)$items[0]['lng']]:[35.2600,-85.7500];wp_localize_script('tng-map-ui-live','TNG_DISCOVERY_MAP',['items'=>$items,'center'=>$center,'zoom'=>10]);
    }
    private static function cards(array $items): string {
        if(!$items)return'<div class="tng-map-empty">Map-ready discoveries will appear here as coordinates are added to TN Game content.</div>';ob_start();echo'<div class="tng-map-results" data-tng-map-results>';
        foreach($items as$item){echo'<article class="tng-map-result" data-tng-map-result="'.esc_attr((string)$item['id']).'" data-kind="'.esc_attr($item['kind']).'" data-lat="'.esc_attr((string)$item['lat']).'" data-lng="'.esc_attr((string)$item['lng']).'">';echo'<span class="tng-map-result__media"'.($item['image']?' style="background-image:url('.esc_url($item['image']).')"':'').'></span>';echo'<span class="tng-map-result__copy"><small>'.esc_html($item['label']).'</small><strong>'.esc_html($item['title']).'</strong>';if($item['subtitle'])echo'<em>'.esc_html($item['subtitle']).'</em>';echo'<span class="tng-map-result__meta"><b data-tng-distance></b></span><span class="tng-map-result__actions"><a class="is-primary" data-tng-open-details href="'.esc_url($item['actionUrl']).'">'.esc_html($item['actionLabel']).'</a><button type="button" data-tng-directions data-lat="'.esc_attr((string)$item['lat']).'" data-lng="'.esc_attr((string)$item['lng']).'">Directions</button><button type="button" data-tng-trip-toggle data-post-id="'.esc_attr((string)$item['id']).'">＋ Add to trip</button></span></span></article>';}echo'</div>';return(string)ob_get_clean();
    }
    public static function render(): string { $items=self::items();$cards=self::cards($items);$counts=array_fill_keys(['trail','game','sight','food'],0);foreach($items as$item)if(isset($counts[$item['kind']]))$counts[$item['kind']]++;ob_start(); ?>
        <main class="tng-map-screen tng-app-shell">
            <section class="tng-map-toolbar"><div><span class="tng-eyebrow">Explore nearby</span><h1>Adventure map</h1><p>See trails, games, sights, food, and local places together on one live Tennessee map.</p></div><button class="tng-ui-button" type="button" data-tng-locate><span>⌖</span> Use my location</button></section>
            <section class="tng-map-filterbar" aria-label="Map filters"><button class="is-active" data-tng-map-filter="all" type="button">All <span><?php echo count($items); ?></span></button><button data-tng-map-filter="trail" type="button">🥾 Trails <span><?php echo $counts['trail']; ?></span></button><button data-tng-map-filter="game" type="button">🎮 Games <span><?php echo $counts['game']; ?></span></button><button data-tng-map-filter="sight" type="button">📍 Sights <span><?php echo $counts['sight']; ?></span></button><button data-tng-map-filter="food" type="button">🍽️ Food <span><?php echo $counts['food']; ?></span></button></section>
            <section class="tng-map-nearest" data-tng-nearest hidden aria-live="polite"></section>
            <section class="tng-map-layout"><div class="tng-map-canvas-wrap"><div id="tng-discovery-map" class="tng-map-canvas" aria-label="Interactive Tennessee discovery map"></div><div class="tng-map-live-status"><span class="tng-map-live-dot"></span><strong>Live discovery map</strong><small data-tng-map-count><?php echo count($items); ?> places on map</small></div></div><aside class="tng-map-panel"><div class="tng-map-panel__heading"><div><span class="tng-eyebrow">Around Tennessee</span><h2>Discoveries</h2></div><a href="<?php echo esc_url(home_url('/search/')); ?>">Search all</a></div><p class="tng-map-panel__intro" data-tng-panel-intro>Move the map to discover what is in view. Use your location to sort by distance.</p><?php echo $cards; ?></aside></section>
        </main><?php return(string)ob_get_clean(); }
}
TNG_Map_UI::boot();
