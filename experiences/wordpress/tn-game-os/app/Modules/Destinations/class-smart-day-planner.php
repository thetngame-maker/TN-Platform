<?php
namespace TNG_OS\Modules\Destinations;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;
use WP_Post;

if (!defined('ABSPATH')) exit;

final class Smart_Day_Planner implements Module_Interface {
    private const PAGE = 'tng-smart-day-planner';
    private const NONCE = 'tng_smart_day_planner_settings';

    public function id(): string { return 'smart_day_planner'; }

    public function register(Container $container): void {
        $container->set('smart_day_planner', $this);
        add_action('admin_menu', [$this, 'menu'], 30);
        add_shortcode('tng_smart_day_planner', [$this, 'shortcode']);
        add_filter('the_content', [$this, 'append_to_content'], 44);
        add_action('add_meta_boxes', [$this, 'meta_boxes']);
        add_action('save_post', [$this, 'save'], 145, 2);
    }

    public function boot(Container $container): void {}

    public function menu(): void {
        add_submenu_page('tn-game-os', 'Smart Day Planner', 'Day Planner', 'manage_options', self::PAGE, [$this, 'page']);
    }

    public function meta_boxes(): void {
        foreach ($this->post_types() as $type) {
            add_meta_box('tng-smart-day-planner-settings', 'Smart Day Planner', [$this, 'meta_box'], $type, 'side', 'default');
        }
    }

    public function meta_box(WP_Post $post): void {
        $enabled = get_post_meta($post->ID, '_tng_day_planner_enabled', true);
        $enabled = $enabled === '' ? '1' : $enabled;
        $title = (string)get_post_meta($post->ID, '_tng_day_planner_title', true);
        $hours = (int)get_post_meta($post->ID, '_tng_day_planner_hours', true) ?: 5;
        wp_nonce_field(self::NONCE, 'tng_day_planner_nonce');
        ?>
        <p><label><input type="checkbox" name="tng_day_planner_enabled" value="1" <?php checked($enabled, '1'); ?>> Show automatically on this listing</label></p>
        <p><label for="tng_day_planner_title"><strong>Section title</strong></label><input class="widefat" id="tng_day_planner_title" name="tng_day_planner_title" value="<?php echo esc_attr($title); ?>" placeholder="Plan a day around this stop"></p>
        <p><label for="tng_day_planner_hours"><strong>Default trip length</strong></label><select class="widefat" id="tng_day_planner_hours" name="tng_day_planner_hours"><?php foreach ([3=>'3 hours',5=>'5 hours',8=>'Full day'] as $value=>$label): ?><option value="<?php echo (int)$value; ?>" <?php selected($hours,$value); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></p>
        <p class="description">Use <code>[tng_smart_day_planner]</code> in WPBakery or another page builder.</p>
        <?php
    }

    public function save(int $post_id, WP_Post $post): void {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;
        if (!in_array($post->post_type, $this->post_types(), true)) return;
        if (!isset($_POST['tng_day_planner_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['tng_day_planner_nonce'])), self::NONCE)) return;
        if (!current_user_can('edit_post', $post_id)) return;
        update_post_meta($post_id, '_tng_day_planner_enabled', isset($_POST['tng_day_planner_enabled']) ? '1' : '0');
        update_post_meta($post_id, '_tng_day_planner_title', sanitize_text_field(wp_unslash($_POST['tng_day_planner_title'] ?? '')));
        $hours = absint($_POST['tng_day_planner_hours'] ?? 5);
        update_post_meta($post_id, '_tng_day_planner_hours', in_array($hours,[3,5,8],true) ? $hours : 5);
    }

    public function append_to_content(string $content): string {
        if (is_admin() || !is_singular($this->post_types()) || !in_the_loop() || !is_main_query()) return $content;
        $post_id = get_queried_object_id();
        if (!$post_id || get_post_meta($post_id, '_tng_graph_excluded', true)) return $content;
        if (has_shortcode($content, 'tng_smart_day_planner')) return $content;
        if (get_post_meta($post_id, '_tng_day_planner_enabled', true) === '0') return $content;
        $hours = (int)get_post_meta($post_id, '_tng_day_planner_hours', true) ?: 5;
        $title = (string)get_post_meta($post_id, '_tng_day_planner_title', true);
        return $content . $this->render($post_id, $hours, $title ?: 'Plan a day around this stop');
    }

    public function shortcode(array $atts = []): string {
        $atts = shortcode_atts(['id'=>0,'hours'=>5,'title'=>'Plan a day around this stop'], $atts, 'tng_smart_day_planner');
        $post_id = absint($atts['id']) ?: get_the_ID();
        $hours = absint($atts['hours']);
        if (!in_array($hours,[3,5,8],true)) $hours = 5;
        return $post_id ? $this->render($post_id, $hours, sanitize_text_field($atts['title'])) : '';
    }

    public function page(): void {
        if (!current_user_can('manage_options')) wp_die('Unauthorized.');
        $ids = $this->node_ids();
        $selected = isset($_GET['source_id']) ? absint($_GET['source_id']) : (int)($ids[0] ?? 0);
        if ($selected && !in_array($selected,$ids,true)) $selected = (int)($ids[0] ?? 0);
        ?>
        <div class="wrap"><h1>Smart Day Planner</h1><p>Preview visitor-ready mini-itineraries assembled from Destination AI Profiles and Knowledge Graph recommendations.</p>
        <form method="get" style="background:#fff;border:1px solid #dcdcde;border-radius:14px;padding:18px;margin:18px 0"><input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE); ?>"><label for="source_id"><strong>Build around</strong></label> <select id="source_id" name="source_id" style="min-width:360px"><?php foreach($ids as $id): ?><option value="<?php echo (int)$id; ?>" <?php selected($selected,$id); ?>><?php echo esc_html(get_the_title($id).' ('.get_post_type($id).')'); ?></option><?php endforeach; ?></select> <button class="button button-primary">Preview plan</button></form>
        <?php echo $selected ? $this->render($selected,5,'Smart day preview') : '<div class="notice notice-warning"><p>No recommendable destination listings found.</p></div>'; ?></div>
        <?php
    }

    private function render(int $source_id, int $default_hours, string $title): string {
        if (!class_exists(Smart_Recommendation_Engine::class)) return '';
        $plan = $this->build_plan($source_id);
        if (count($plan) < 2) return '';
        $uid = 'tng-day-plan-'.$source_id.'-'.wp_rand(1000,9999);
        $payload = [];
        foreach ($plan as $stop) $payload[] = ['id'=>$stop['id'],'title'=>$stop['title'],'url'=>get_permalink($stop['id']),'minutes'=>$stop['minutes']];
        ob_start();
        ?>
        <section class="tng-day-planner" id="<?php echo esc_attr($uid); ?>" data-default-hours="<?php echo (int)$default_hours; ?>">
        <style>
        .tng-day-planner{max-width:1180px;margin:38px auto;padding:0 18px;box-sizing:border-box}.tng-dp-shell{background:linear-gradient(145deg,#f8f6ff,#fff);border:1px solid #ded7f2;border-radius:24px;overflow:hidden;box-shadow:0 15px 38px rgba(23,33,63,.08)}.tng-dp-head{background:linear-gradient(135deg,#18213f,#70429a);color:#fff;padding:26px 28px;display:flex;justify-content:space-between;gap:25px;align-items:end;flex-wrap:wrap}.tng-dp-kicker{color:#ffd34e;font-size:12px;font-weight:800;letter-spacing:.18em;text-transform:uppercase}.tng-dp-head h2{color:#fff;font-size:clamp(28px,4vw,42px);line-height:1.05;margin:7px 0}.tng-dp-head p{margin:0;color:rgba(255,255,255,.78);max-width:680px}.tng-dp-lengths{display:flex;gap:8px;flex-wrap:wrap}.tng-dp-length{border:1px solid rgba(255,255,255,.38);background:rgba(255,255,255,.1);color:#fff;border-radius:999px;padding:9px 13px;font-weight:800;cursor:pointer}.tng-dp-length.is-active{background:#fff;color:#34234f}.tng-dp-body{padding:22px 28px}.tng-dp-summary{display:flex;gap:18px;flex-wrap:wrap;color:#667085;font-size:13px;margin-bottom:18px}.tng-dp-summary strong{color:#17213f}.tng-dp-list{position:relative}.tng-dp-stop{display:grid;grid-template-columns:72px 42px 1fr auto;gap:14px;align-items:center;padding:16px 0;border-top:1px solid #e6e3ee}.tng-dp-stop:first-child{border-top:0}.tng-dp-time{font-size:14px;font-weight:800;color:#17213f}.tng-dp-number{width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:#7c4ce0;color:#fff;font-weight:800;box-shadow:0 0 0 6px #f0eaff}.tng-dp-stop.is-anchor .tng-dp-number{background:#17b978;box-shadow:0 0 0 6px #e2f8ef}.tng-dp-stop h3{font-size:20px;line-height:1.2;margin:0 0 5px;color:#17213f}.tng-dp-stop h3 a{color:inherit;text-decoration:none}.tng-dp-meta{color:#667085;font-size:13px}.tng-dp-badge{display:inline-block;background:#eee8ff;color:#673ab7;border-radius:999px;padding:5px 8px;font-size:11px;font-weight:800;margin-right:6px}.tng-dp-open{display:inline-flex;background:#17213f;color:#fff!important;text-decoration:none!important;border-radius:10px;padding:9px 12px;font-size:12px;font-weight:800}.tng-dp-actions{display:flex;justify-content:flex-end;gap:10px;padding-top:18px;border-top:1px solid #e6e3ee;margin-top:8px}.tng-dp-action{border:0;border-radius:11px;padding:11px 16px;font-weight:800;cursor:pointer}.tng-dp-save{background:#7c4ce0;color:#fff}.tng-dp-share{background:#17213f;color:#fff}.tng-dp-status{margin-right:auto;align-self:center;color:#198754;font-size:13px;font-weight:700}.tng-dp-stop[hidden]{display:none!important}@media(max-width:700px){.tng-day-planner{padding:0 14px}.tng-dp-head,.tng-dp-body{padding:21px 18px}.tng-dp-stop{grid-template-columns:58px 36px 1fr}.tng-dp-open{grid-column:3;justify-self:start}.tng-dp-actions{flex-wrap:wrap}.tng-dp-status{width:100%}}
        </style>
        <div class="tng-dp-shell">
            <header class="tng-dp-head"><div><div class="tng-dp-kicker">Destination AI</div><h2><?php echo esc_html($title); ?></h2><p>Start with <?php echo esc_html(get_the_title($source_id)); ?>, then add nearby places selected for distance, compatibility, and the time you have available.</p></div><div class="tng-dp-lengths" aria-label="Trip length"><button type="button" class="tng-dp-length" data-hours="3">3 hours</button><button type="button" class="tng-dp-length" data-hours="5">5 hours</button><button type="button" class="tng-dp-length" data-hours="8">Full day</button></div></header>
            <div class="tng-dp-body"><div class="tng-dp-summary"><span><strong class="tng-dp-visible-count">0</strong> stops</span><span><strong class="tng-dp-total-time">0 hr</strong> estimated</span><span>Built from live destination relationships</span></div><div class="tng-dp-list">
            <?php $elapsed=0; foreach($plan as $index=>$stop): $elapsed += (int)$stop['minutes']; $min_hours=(int)ceil($elapsed/60); ?>
                <article class="tng-dp-stop <?php echo $stop['anchor']?'is-anchor':''; ?>" data-min-hours="<?php echo (int)max(1,$min_hours); ?>" data-minutes="<?php echo (int)$stop['minutes']; ?>">
                    <div class="tng-dp-time"><?php echo esc_html($this->clock_label($plan,$index)); ?></div><div class="tng-dp-number"><?php echo (int)($index+1); ?></div><div><h3><a href="<?php echo esc_url(get_permalink($stop['id'])); ?>"><?php echo esc_html($stop['title']); ?></a></h3><div class="tng-dp-meta"><span class="tng-dp-badge"><?php echo esc_html($stop['label']); ?></span><?php echo esc_html($stop['detail']); ?> · <?php echo esc_html($this->duration_label($stop['minutes'])); ?></div></div><a class="tng-dp-open" href="<?php echo esc_url(get_permalink($stop['id'])); ?>">View stop</a>
                </article>
            <?php endforeach; ?></div><div class="tng-dp-actions"><span class="tng-dp-status" aria-live="polite"></span><button type="button" class="tng-dp-action tng-dp-save">Save plan</button><button type="button" class="tng-dp-action tng-dp-share">Share plan</button></div></div>
        </div>
        <script>
        (function(){const root=document.getElementById(<?php echo wp_json_encode($uid); ?>);if(!root)return;const buttons=[...root.querySelectorAll('.tng-dp-length')],stops=[...root.querySelectorAll('.tng-dp-stop')],count=root.querySelector('.tng-dp-visible-count'),total=root.querySelector('.tng-dp-total-time'),status=root.querySelector('.tng-dp-status'),payload=<?php echo wp_json_encode($payload); ?>;function setHours(hours){buttons.forEach(b=>b.classList.toggle('is-active',Number(b.dataset.hours)===hours));let mins=0,visible=0;stops.forEach(s=>{const show=Number(s.dataset.minHours)<=hours;s.hidden=!show;if(show){mins+=Number(s.dataset.minutes);visible++;}});count.textContent=visible;total.textContent=mins>=60?(Math.round(mins/6)/10)+' hr':mins+' min';root.dataset.hours=String(hours);}buttons.forEach(b=>b.addEventListener('click',()=>setHours(Number(b.dataset.hours))));root.querySelector('.tng-dp-save').addEventListener('click',()=>{const hours=Number(root.dataset.hours||5);try{localStorage.setItem('tng_saved_day_plan',JSON.stringify({source:<?php echo (int)$source_id; ?>,hours,stops:payload,savedAt:Date.now()}));status.textContent='Plan saved on this device.';window.dispatchEvent(new CustomEvent('tng:day-plan-saved',{detail:{sourceId:<?php echo (int)$source_id; ?>,hours}}));}catch(e){status.textContent='This browser could not save the plan.';}});root.querySelector('.tng-dp-share').addEventListener('click',async()=>{const visible=stops.filter(s=>!s.hidden).map(s=>s.querySelector('h3').innerText);const text='My TN Game day plan: '+visible.join(' → ');try{if(navigator.share)await navigator.share({title:<?php echo wp_json_encode($title); ?>,text,url:location.href});else{await navigator.clipboard.writeText(text+' '+location.href);status.textContent='Plan copied to your clipboard.';}}catch(e){}});setHours(<?php echo (int)$default_hours; ?>);})();
        </script>
        </section>
        <?php
        return (string)ob_get_clean();
    }

    private function build_plan(int $source_id): array {
        $profile = class_exists(Destination_AI_Profiles::class) ? Destination_AI_Profiles::profile($source_id) : [];
        $traits = strtolower((string)($profile['traits'] ?? ''));
        $is_event = (bool)preg_match('/concert|music|event|festival/', $traits.' '.strtolower(get_the_title($source_id)));
        $source = $this->stop($source_id, 'Main experience', 'The place this plan is built around', true);
        $food = $this->first($source_id,'food_after','Food & drink','A convenient meal or refreshment stop');
        $similar = $this->first($source_id,'similar','Nearby experience','A strong match from the destination network');
        $photo = $this->first($source_id,'photography','Scenic stop','A nearby place with photography potential');
        $family = $this->first($source_id,'family','Flexible stop','A broadly suitable nearby experience');
        $lodging = $this->first($source_id,'lodging','Stay nearby','A nearby place to extend the trip');
        $ordered = $is_event ? [$food,$source,$similar,$lodging] : [$source,$food,$similar,$photo,$family,$lodging];
        $seen=[];$plan=[];
        foreach($ordered as $stop){if(!$stop||isset($seen[$stop['id']]))continue;$seen[$stop['id']]=true;$plan[]=$stop;}
        return $plan;
    }

    private function first(int $source_id,string $scenario,string $label,string $detail): ?array {
        foreach(Smart_Recommendation_Engine::recommend($source_id,$scenario,5) as $row){if((int)$row['id']!==$source_id)return $this->stop((int)$row['id'],$label,$detail.' · '.$row['distance_label'],false);}return null;
    }

    private function stop(int $id,string $label,string $detail,bool $anchor): array {
        $profile=class_exists(Destination_AI_Profiles::class)?Destination_AI_Profiles::profile($id):[];
        $minutes=absint($profile['visit_minutes']??0);
        if(!$minutes){$type=get_post_type($id);$minutes=in_array($type,['st_hotel','st_rental'],true)?45:($type==='st_activity'?90:60);}
        return ['id'=>$id,'title'=>get_the_title($id)?:'#'.$id,'label'=>$label,'detail'=>$detail,'minutes'=>min(240,max(30,$minutes)),'anchor'=>$anchor];
    }

    private function clock_label(array $plan,int $index): string {
        $minutes=9*60;for($i=0;$i<$index;$i++)$minutes+=(int)$plan[$i]['minutes']+20;$hours=(int)floor($minutes/60);$mins=$minutes%60;$suffix=$hours>=12?'PM':'AM';$display=$hours%12?:12;return sprintf('%d:%02d %s',$display,$mins,$suffix);
    }
    private function duration_label(int $minutes): string { return $minutes>=60?(round($minutes/60,1).' hr'):($minutes.' min'); }
    private function node_ids(): array { return get_posts(['post_type'=>$this->post_types(),'post_status'=>'publish','posts_per_page'=>-1,'fields'=>'ids','orderby'=>'title','order'=>'ASC']); }
    private function post_types(): array { return array_values(array_filter(['tng_destination','st_activity','st_hotel','st_tours','st_rental','top_sight'],'post_type_exists')); }
}
