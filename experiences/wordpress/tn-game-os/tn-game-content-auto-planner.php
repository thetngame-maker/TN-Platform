<?php
/**
 * TN Game Content Campaign Auto Planner
 * Proposes a reviewable campaign plan from remaining pillar gaps and available campaign dates.
 */
if (!defined('ABSPATH')) exit;

final class TNG_Content_Auto_Planner {
    private const ITEM='tng_social_item';
    private const CAMPAIGN='tng_campaign';
    private const NONCE='tng_content_auto_planner';

    public static function boot():void{
        static $b=false;if($b)return;$b=true;
        add_action('admin_menu',[__CLASS__,'menu'],103);
        add_action('admin_post_tng_apply_auto_plan',[__CLASS__,'apply']);
        add_action('admin_enqueue_scripts',[__CLASS__,'assets']);
    }

    public static function menu():void{
        add_submenu_page('tng-content-studio','Campaign Auto Planner','Auto Planner','edit_posts','tng-content-auto-planner',[__CLASS__,'render']);
    }

    private static function campaigns():array{
        return class_exists('TNG_Content_Campaigns')?TNG_Content_Campaigns::campaigns():[];
    }

    private static function selected_campaign():?WP_Post{
        $id=absint($_GET['campaign_id']??0);
        if($id&&get_post_type($id)===self::CAMPAIGN)return get_post($id);
        foreach(self::campaigns() as $c)if((string)get_post_meta($c->ID,'_tng_campaign_status',true)==='active')return $c;
        $all=self::campaigns();return $all[0]??null;
    }

    private static function pillar_defs():array{
        return [
            'brand_intro'=>['label'=>'Brand introduction','titles'=>['What The TN Game is really about','Why Tennessee needed a game board','Meet The TN Game'],'hooks'=>['Tennessee is full of places worth finding — we are building a new way to explore them.','What if your next Tennessee day felt more like a game?']],
            'feature_reveal'=>['label'=>'Feature reveal','titles'=>['Feature reveal: Trip Mode','How XP works in The TN Game','Turn a Tennessee day into a route'],'hooks'=>['This is one of the features that changes how you explore Tennessee.','Build the day, follow the route, earn progress as you go.']],
            'destination_inspiration'=>['label'=>'Destination inspiration','titles'=>['Save this Tennessee adventure','A Tennessee stop worth adding to your list','Build a day around this place'],'hooks'=>['Save this for your next Tennessee adventure.','This is the kind of place The TN Game is being built around.']],
            'local_business'=>['label'=>'Local business','titles'=>['Add a local stop to the adventure','Where to eat between adventures','A Tennessee local favorite worth knowing'],'hooks'=>['The adventure should not stop when you leave the trail.','Add one local stop to make the whole day better.']],
            'gameplay'=>['label'=>'Gameplay','titles'=>['How a TN Game checkpoint works','Turn this stop into a challenge','What playing The TN Game looks like'],'hooks'=>['Find the stop. Check in. Earn XP. Keep exploring.','This is how an ordinary Tennessee stop becomes part of the game.']],
            'behind_scenes'=>['label'=>'Behind the scenes','titles'=>['Behind the build of The TN Game','What it takes to add a Tennessee adventure','Field testing The TN Game'],'hooks'=>['A lot happens before a place appears inside The TN Game.','Here is what building Tennessee into a game actually looks like.']],
        ];
    }

    private static function preferred_formats(WP_Post $campaign):array{
        $f=get_post_meta($campaign->ID,'_tng_campaign_formats',true);
        $f=is_array($f)?array_values(array_filter($f)):[];
        return $f?:['reel','carousel','story'];
    }

    private static function occupied_dates(WP_Post $campaign):array{
        $items=get_posts(['post_type'=>self::ITEM,'post_status'=>['publish','draft','private'],'posts_per_page'=>300,'meta_query'=>[['key'=>'_tng_campaign','value'=>$campaign->post_title,'compare'=>'=']]]);
        $out=[];foreach($items as $item){$d=(string)get_post_meta($item->ID,'_tng_planned_date',true);if($d)$out[$d]=($out[$d]??0)+1;}return $out;
    }

    private static function date_candidates(WP_Post $campaign,int $needed):array{
        $today=new DateTimeImmutable(current_time('Y-m-d'));
        $start=(string)get_post_meta($campaign->ID,'_tng_campaign_start',true);
        $end=(string)get_post_meta($campaign->ID,'_tng_campaign_end',true);
        try{$s=$start?new DateTimeImmutable($start):$today;}catch(Exception $e){$s=$today;}
        if($s<$today)$s=$today;
        try{$e=$end?new DateTimeImmutable($end):$s->modify('+30 days');}catch(Exception $x){$e=$s->modify('+30 days');}
        $occupied=self::occupied_dates($campaign);$dates=[];$cursor=$s;
        while($cursor<=$e&&count($dates)<max($needed*2,14)){
            $d=$cursor->format('Y-m-d');$dow=(int)$cursor->format('N');
            if($dow<=6&&($occupied[$d]??0)<1)$dates[]=$d;
            $cursor=$cursor->modify('+1 day');
        }
        if(!$dates){for($i=0;$i<$needed;$i++)$dates[]=$today->modify('+'.$i.' day')->format('Y-m-d');}
        return $dates;
    }

    private static function signal_place():string{
        $records=get_posts(['post_type'=>['tng_social_candidate','tng_social_item'],'post_status'=>['publish','draft','private'],'posts_per_page'=>150,'orderby'=>'date','order'=>'DESC']);
        $best='';$bestScore=-1;
        foreach($records as $p){$url=(string)(get_post_meta($p->ID,'_tng_candidate_source_url',true)?:get_post_meta($p->ID,'_tng_source_url',true));if(!str_contains(strtolower($url),'instagram.com'))continue;
            $place=trim((string)(get_post_meta($p->ID,'_tng_candidate_location',true)?:get_post_meta($p->ID,'_tng_location_name',true)));if(!$place)continue;
            $score=(int)(get_post_meta($p->ID,'_tng_candidate_trend_score',true)?:get_post_meta($p->ID,'_tng_source_opportunity_score',true));if($score>$bestScore){$best=$place;$bestScore=$score;}
        }
        return $best;
    }

    private static function build_plan(WP_Post $campaign):array{
        $defs=self::pillar_defs();$formats=self::preferred_formats($campaign);$place=self::signal_place();$focus=trim((string)get_post_meta($campaign->ID,'_tng_campaign_focus',true));if(!$place)$place=$focus?:'Tennessee';
        $coverage=class_exists('TNG_Content_Pillars')?TNG_Content_Pillars::coverage($campaign):['pillars'=>[]];
        $remaining=[];foreach($defs as $key=>$def){$row=$coverage['pillars'][$key]??['target'=>0,'created'=>0];$count=max(0,(int)($row['target']??0)-(int)($row['created']??0));for($i=0;$i<$count;$i++)$remaining[]=$key;}
        $dates=self::date_candidates($campaign,count($remaining));$plan=[];$fi=0;
        foreach($remaining as $i=>$pillar){$def=$defs[$pillar];$format=$formats[$fi%count($formats)];$fi++;$title=$def['titles'][$i%count($def['titles'])];$hook=$def['hooks'][$i%count($def['hooks'])];
            if(in_array($pillar,['destination_inspiration','local_business','gameplay','behind_scenes'],true))$title.=' — '.$place;
            $plan[]=['pillar'=>$pillar,'pillar_label'=>$def['label'],'title'=>$title,'hook'=>$hook,'format'=>$format,'place'=>$place,'date'=>$dates[$i%count($dates)]??''];
        }
        return $plan;
    }

    public static function apply():void{
        if(!current_user_can('edit_posts'))wp_die('Not allowed.');check_admin_referer(self::NONCE);
        $campaign_id=absint($_POST['campaign_id']??0);$campaign=$campaign_id&&get_post_type($campaign_id)===self::CAMPAIGN?get_post($campaign_id):null;if(!$campaign)wp_die('Invalid campaign.');
        $rows=(array)($_POST['plan']??[]);$created=0;
        foreach($rows as $row){if(empty($row['use']))continue;$title=sanitize_text_field(wp_unslash($row['title']??''));if(!$title)continue;$id=wp_insert_post(['post_type'=>self::ITEM,'post_status'=>'draft','post_title'=>$title,'post_content'=>sanitize_textarea_field(wp_unslash($row['angle']??''))]);if(is_wp_error($id))continue;
            $pillar=sanitize_key(wp_unslash($row['pillar']??''));$format=sanitize_key(wp_unslash($row['format']??'reel'));$place=sanitize_text_field(wp_unslash($row['place']??''));$hook=sanitize_textarea_field(wp_unslash($row['hook']??''));$date=sanitize_text_field(wp_unslash($row['date']??''));
            update_post_meta($id,'_tng_campaign',$campaign->post_title);if($pillar)update_post_meta($id,'_tng_content_pillar',$pillar);update_post_meta($id,'_tng_content_format',$format);update_post_meta($id,'_tng_location_name',$place);update_post_meta($id,'_tng_hook',$hook);update_post_meta($id,'_tng_original_angle',sanitize_textarea_field(wp_unslash($row['angle']??'')));update_post_meta($id,'_tng_plan_status',$date?'planned':'idea');if($date)update_post_meta($id,'_tng_planned_date',$date);update_post_meta($id,'_tng_permission_status','not_needed');update_post_meta($id,'_tng_content_notes','Created from Campaign Auto Planner. Review and customize before production.');$created++;
        }
        wp_safe_redirect(add_query_arg(['page'=>'tng-content-auto-planner','campaign_id'=>$campaign->ID,'created'=>$created],admin_url('admin.php')));exit;
    }

    public static function render():void{
        if(!current_user_can('edit_posts'))return;$campaign=self::selected_campaign();echo '<div class="wrap tng-auto">';echo '<section class="auto-hero"><div><p class="eyebrow">CONTENT STUDIO</p><h1>Campaign Auto Planner</h1><p>Turn the remaining campaign gaps into a reviewable content plan. Nothing is published automatically.</p></div><a class="button" href="'.esc_url(admin_url('admin.php?page=tng-content-campaigns')).'">Campaign Planner</a></section>';
        if(isset($_GET['created']))echo '<div class="notice notice-success inline"><p>'.intval($_GET['created']).' draft content ideas added to the campaign.</p></div>';
        if(!$campaign){echo '<section class="empty">Create a campaign first.</section></div>';return;}
        $plan=self::build_plan($campaign);$stats=class_exists('TNG_Content_Campaigns')?TNG_Content_Campaigns::campaign_stats($campaign):['items'=>0,'target'=>0];
        echo '<section class="auto-toolbar"><div><p class="eyebrow">CAMPAIGN</p><h2>'.esc_html($campaign->post_title).'</h2><span>'.intval($stats['items']).'/'.intval($stats['target']).' created · '.count($plan).' suggestions remaining</span></div><form method="get"><input type="hidden" name="page" value="tng-content-auto-planner"><select name="campaign_id" onchange="this.form.submit()">';foreach(self::campaigns() as $c)echo '<option value="'.intval($c->ID).'" '.selected($campaign->ID,$c->ID,false).'>'.esc_html($c->post_title).'</option>';echo '</select></form></section>';
        if(!$plan){echo '<section class="complete"><strong>✓ Campaign plan covered</strong><p>There are no remaining pillar gaps for this campaign.</p></section></div>';return;}
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field(self::NONCE);echo '<input type="hidden" name="action" value="tng_apply_auto_plan"><input type="hidden" name="campaign_id" value="'.intval($campaign->ID).'">';echo '<div class="auto-grid">';
        foreach($plan as $i=>$r){$angle='Create an original '.ucwords(str_replace('_',' ',$r['format'])).' for '.$campaign->post_title.' that fulfills the '.$r['pillar_label'].' pillar. Use '.$r['place'].' as context where useful, connect naturally to The TN Game, and avoid copying source creators.';echo '<article class="auto-card"><div class="top"><label class="pick"><input type="checkbox" name="plan['.$i.'][use]" value="1" checked> Add</label><span>'.esc_html($r['pillar_label']).'</span></div><input type="hidden" name="plan['.$i.'][pillar]" value="'.esc_attr($r['pillar']).'"><label>IDEA<input name="plan['.$i.'][title]" value="'.esc_attr($r['title']).'"></label><label>HOOK<textarea name="plan['.$i.'][hook]" rows="2">'.esc_textarea($r['hook']).'</textarea></label><label>ANGLE<textarea name="plan['.$i.'][angle]" rows="3">'.esc_textarea($angle).'</textarea></label><div class="fields"><label>FORMAT<select name="plan['.$i.'][format]">';foreach(['reel'=>'Reel','carousel'=>'Carousel','photo'=>'Photo','story'=>'Story','long_video'=>'Long video','post'=>'Post'] as $k=>$l)echo '<option value="'.esc_attr($k).'" '.selected($r['format'],$k,false).'>'.esc_html($l).'</option>';echo '</select></label><label>DATE<input type="date" name="plan['.$i.'][date]" value="'.esc_attr($r['date']).'"></label></div><label>PLACE<input name="plan['.$i.'][place]" value="'.esc_attr($r['place']).'"></label></article>';}
        echo '</div><div class="apply"><div><strong>'.count($plan).' proposed posts</strong><span>Uncheck anything you do not want to create yet.</span></div><button class="button button-primary button-large">Add selected ideas to campaign</button></div></form></div>';
    }

    public static function assets():void{
        if(!isset($_GET['page'])||$_GET['page']!=='tng-content-auto-planner')return;wp_register_style('tng-content-auto-planner',false,[],defined('TNG_OS_VERSION')?TNG_OS_VERSION:null);wp_enqueue_style('tng-content-auto-planner');wp_add_inline_style('tng-content-auto-planner','.tng-auto{max-width:1450px}.auto-hero{margin:20px 0;background:linear-gradient(135deg,#073c2b,#176b45);color:#fff;border-radius:24px;padding:30px;display:flex;justify-content:space-between;gap:24px;align-items:flex-start}.auto-hero h1{color:#fff;font-size:36px;margin:5px 0 8px}.auto-hero p{max-width:760px;font-size:15px}.eyebrow{font-size:11px;font-weight:800;letter-spacing:.13em;color:#f05b25;margin:0}.auto-toolbar{background:#fff;border:1px solid #dfe5df;border-radius:18px;padding:18px 20px;display:flex;align-items:center;justify-content:space-between;gap:20px;margin-bottom:16px}.auto-toolbar h2{margin:3px 0;color:#153e2e}.auto-toolbar span{color:#6c7a72}.auto-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}.auto-card{background:#fff;border:1px solid #dfe5df;border-radius:18px;padding:18px}.auto-card .top{display:flex;justify-content:space-between;align-items:center;color:#6d7c74;font-size:11px;text-transform:uppercase;font-weight:800}.auto-card .pick{text-transform:none;color:#17402f}.auto-card>label,.auto-card .fields label{display:block;font-size:10px;font-weight:800;letter-spacing:.1em;color:#f05b25;margin-top:12px}.auto-card input[type=text],.auto-card input:not([type]),.auto-card textarea,.auto-card select,.auto-card input[type=date]{width:100%;box-sizing:border-box;margin-top:5px;border:1px solid #d9e0dc;border-radius:9px;padding:9px}.auto-card .fields{display:grid;grid-template-columns:1fr 1fr;gap:10px}.apply{position:sticky;bottom:0;margin-top:16px;background:#fff;border:1px solid #dfe5df;border-radius:16px;padding:14px 18px;display:flex;justify-content:space-between;align-items:center;box-shadow:0 -8px 24px rgba(18,54,39,.08)}.apply strong,.apply span{display:block}.apply span{color:#718078;font-size:12px;margin-top:3px}.complete,.empty{background:#fff;border:1px solid #dfe5df;border-radius:18px;padding:28px;color:#294c3b}@media(max-width:1100px){.auto-grid{grid-template-columns:1fr 1fr}}@media(max-width:760px){.auto-grid{grid-template-columns:1fr}.auto-hero,.auto-toolbar,.apply{display:block}.auto-hero .button,.apply .button{margin-top:12px}.auto-card .fields{grid-template-columns:1fr}}');
    }
}
TNG_Content_Auto_Planner::boot();
