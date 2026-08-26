<?php
namespace TNG_OS\Modules\Frontend;
use TNG_OS\Core\Container; use TNG_OS\Core\Module_Interface; use WP_Query;
if(!defined('ABSPATH')) exit;
final class Destinations_Widget implements Module_Interface {
 private Container $container;
 private array $services=['trails'=>['hiking-trails','trails'],'food'=>['food-and-drink','restaurants'],'concerts'=>['concerts'],'shops'=>['shops'],'history'=>['historic-sites','history'],'campgrounds'=>['campgrounds'],'waterfalls'=>['waterfalls'],'scenic'=>['scenic-views','scenic']];
 public function id():string{return 'destinations_widget';}
 public function register(Container $c):void{$this->container=$c;add_shortcode('tng_destinations',[$this,'shortcode']);add_shortcode('tn_game_destinations',[$this,'shortcode']);add_action('wp_enqueue_scripts',[$this,'assets'],61);add_action('vc_before_init',[$this,'vc']);}
 public function boot(Container $c):void{}
 public function assets():void{if(!is_admin())wp_enqueue_style('tng-os-destinations',TNG_OS_URL.'assets/frontend/destinations.css',[],TNG_OS_VERSION);}
 private function type_tax():string{$r=$this->container->get('services');if($r&&is_callable([$r,'taxonomy']))return(string)$r->taxonomy();return taxonomy_exists('st_activity_type')?'st_activity_type':'';}
 private function location_tax():string{foreach(get_object_taxonomies('st_activity','objects') as $t){$s=strtolower($t->name.' '.$t->label);if(strpos($s,'location')!==false||strpos($s,'destination')!==false||strpos($s,'city')!==false)return$t->name;}return'';}
 private function term_ids(array $slugs):array{$tax=$this->type_tax();$ids=[];if(!$tax)return[];foreach($slugs as $slug){$t=get_term_by('slug',$slug,$tax);if($t&&!is_wp_error($t))$ids[]=(int)$t->term_id;}return$ids;}
 private function activity_count($dest,string $service):int{$tax=$this->type_tax();$q=[['taxonomy'=>$dest->taxonomy,'field'=>'term_id','terms'=>[(int)$dest->term_id]]];if($service==='activities'){$all=[];foreach($this->services as $slugs)$all=array_merge($all,$this->term_ids($slugs));if($tax&&$all)$q[]=['taxonomy'=>$tax,'field'=>'term_id','terms'=>array_unique($all),'operator'=>'NOT IN'];}else{$ids=$this->term_ids($this->services[$service]??[]);if($tax&&$ids)$q[]=['taxonomy'=>$tax,'field'=>'term_id','terms'=>$ids];else return 0;}$w=new WP_Query(['post_type'=>'st_activity','post_status'=>'publish','posts_per_page'=>1,'fields'=>'ids','tax_query'=>array_merge(['relation'=>'AND'],$q)]);return(int)$w->found_posts;}
 private function traveler_count($dest,array $types):int{foreach($types as $type){if(!post_type_exists($type))continue;foreach(get_object_taxonomies($type,'objects') as $tax){$s=strtolower($tax->name.' '.$tax->label);if(strpos($s,'location')===false&&strpos($s,'destination')===false&&strpos($s,'city')===false)continue;$t=get_term_by('slug',$dest->slug,$tax->name)?:get_term_by('name',$dest->name,$tax->name);if(!$t||is_wp_error($t))continue;$q=new WP_Query(['post_type'=>$type,'post_status'=>'publish','posts_per_page'=>1,'fields'=>'ids','tax_query'=>[['taxonomy'=>$tax->name,'field'=>'term_id','terms'=>[(int)$t->term_id]]]]);return(int)$q->found_posts;}}return 0;}
 private function image($term):string{foreach(['thumbnail_id','featured_image','image_id','st_location_image'] as $k){$v=get_term_meta($term->term_id,$k,true);if(is_numeric($v)&&($u=wp_get_attachment_image_url((int)$v,'large')))return$u;if(is_string($v)&&filter_var($v,FILTER_VALIDATE_URL))return$v;}foreach(get_posts(['post_type'=>['st_activity','st_hotel','st_tours'],'post_status'=>'publish','posts_per_page'=>12,'tax_query'=>[['taxonomy'=>$term->taxonomy,'field'=>'term_id','terms'=>[(int)$term->term_id]]]]) as $p){if($u=get_the_post_thumbnail_url($p->ID,'large'))return$u;}return TNG_OS_URL.'assets/frontend/destination-placeholder.svg';}
 private function counts($d):array{return['trails'=>$this->activity_count($d,'trails'),'food'=>$this->activity_count($d,'food'),'activities'=>$this->activity_count($d,'activities'),'hotels'=>$this->traveler_count($d,['st_hotel','hotel']),'tours'=>$this->traveler_count($d,['st_tours','st_tour','tour']),'rentals'=>$this->traveler_count($d,['st_rental','rental']),'cars'=>$this->traveler_count($d,['st_cars','st_car','car']),'concerts'=>$this->activity_count($d,'concerts'),'shops'=>$this->activity_count($d,'shops'),'history'=>$this->activity_count($d,'history'),'campgrounds'=>$this->activity_count($d,'campgrounds'),'waterfalls'=>$this->activity_count($d,'waterfalls'),'scenic'=>$this->activity_count($d,'scenic')];}
 public function shortcode(array $a=[]):string{$a=shortcode_atts(['heading'=>'Top destinations','eyebrow'=>'EXPLORE THE REGION','limit'=>6,'columns'=>3,'categories'=>'trails,food,activities,hotels,tours,concerts,shops,history,campgrounds'],$a,'tng_destinations');$tax=$this->location_tax();if(!$tax)return current_user_can('manage_options')?'<div class="tng-destination-notice">Could not identify Traveler location taxonomy.</div>':'';$terms=get_terms(['taxonomy'=>$tax,'hide_empty'=>true,'number'=>min(24,max(1,(int)$a['limit'])),'orderby'=>'count','order'=>'DESC']);if(!$terms||is_wp_error($terms))return'';$labels=['trails'=>['🥾','Trails'],'food'=>['🍽','Food & Drink'],'activities'=>['🧭','Activities'],'hotels'=>['🏨','Hotels'],'tours'=>['🚌','Tours'],'rentals'=>['🏡','Rentals'],'cars'=>['🚗','Cars'],'concerts'=>['🎵','Concerts'],'shops'=>['🛍','Shops'],'history'=>['🏛','Historic Sites'],'campgrounds'=>['⛺','Campgrounds'],'waterfalls'=>['💧','Waterfalls'],'scenic'=>['🌄','Scenic Views']];$cats=array_filter(array_map('sanitize_key',explode(',',$a['categories'])));ob_start();?>
<section class="tng-destinations" style="--tng-destination-columns:<?php echo esc_attr(min(4,max(1,(int)$a['columns'])));?>"><div class="tng-destinations-inner"><header class="tng-destinations-heading"><span><?php echo esc_html($a['eyebrow']);?></span><h2><?php echo esc_html($a['heading']);?></h2></header><div class="tng-destinations-grid">
<?php foreach($terms as $d):
$c=$this->counts($d);
$total=array_sum($c);
$link=get_term_link($d);
if(is_wp_error($link))$link=home_url('/');
$image=$this->image($d);
$is_placeholder=strpos($image,'destination-placeholder.svg')!==false;
$visible_counts=[];
foreach($cats as $k){
    if(empty($c[$k])||empty($labels[$k]))continue;
    $visible_counts[$k]=$c[$k];
    if(count($visible_counts)>=4)break;
}
?>
<article class="tng-destination-card <?php echo $is_placeholder?'has-placeholder':'';?>">
    <a class="tng-destination-media" href="<?php echo esc_url($link);?>">
        <img src="<?php echo esc_url($image);?>" alt="<?php echo esc_attr($d->name);?>" loading="lazy">
        <span class="tng-destination-overlay"></span>
        <div class="tng-destination-summary">
            <span class="tng-destination-kicker">DESTINATION</span>
            <h3><?php echo esc_html($d->name);?></h3>
            <p><?php echo esc_html(number_format_i18n($total));?> places &amp; experiences</p>
        </div>
    </a>
    <div class="tng-destination-footer">
        <?php if($visible_counts):?>
            <div class="tng-destination-counts">
                <?php foreach($visible_counts as $k=>$count):?>
                    <span><i><?php echo esc_html($labels[$k][0]);?></i><b><?php echo esc_html($labels[$k][1]);?></b><strong><?php echo esc_html(number_format_i18n($count));?></strong></span>
                <?php endforeach;?>
            </div>
        <?php else:?>
            <p class="tng-destination-empty">New places and experiences are coming soon.</p>
        <?php endif;?>
        <a class="tng-destination-link" href="<?php echo esc_url($link);?>">
            <span>Explore <?php echo esc_html($d->name);?></span><b>→</b>
        </a>
    </div>
</article>
<?php endforeach;?>
</div></div></section><?php return(string)ob_get_clean();}
 public function vc():void{if(function_exists('vc_map'))vc_map(['name'=>'TN Game Destinations','base'=>'tng_destinations','category'=>'TN Game OS','params'=>[['type'=>'textfield','heading'=>'Heading','param_name'=>'heading','value'=>'Top destinations'],['type'=>'textfield','heading'=>'Limit','param_name'=>'limit','value'=>'6'],['type'=>'dropdown','heading'=>'Columns','param_name'=>'columns','value'=>['2'=>'2','3'=>'3','4'=>'4'],'std'=>'3']]]);}
}
