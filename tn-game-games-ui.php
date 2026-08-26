<?php
/**
 * Plugin Name: TN Game Games UI
 * Description: Native game directory and playable activity detail template.
 * Version: 0.5.0
 * Author: The TN Game
 */
if (!defined('ABSPATH')) exit;
final class TNG_Games_UI {
    public static function boot(): void {
        add_filter('template_include',[self::class,'template'],99999);
        add_filter('body_class',[self::class,'body_class'],999);
        add_action('wp_enqueue_scripts',[self::class,'assets'],110);
        add_action('template_redirect',[self::class,'protect_blocked_game'],1);
    }

    public static function is_game(int $id=0): bool {
        $id=$id?:get_queried_object_id(); if(!$id)return false; $type=get_post_type($id); if(in_array($type,['tng_game','game'],true))return true; if(!in_array($type,['st_activity','activity'],true))return false;
        if(class_exists('TNG_Trail_UI')&&TNG_Trail_UI::is_trail())return false; if(class_exists('TNG_Event_UI')&&TNG_Event_UI::is_event())return false;
        $text=strtolower(get_the_title($id).' '.get_post_field('post_content',$id)); foreach(get_object_taxonomies($type) as $taxonomy){$terms=wp_get_post_terms($id,$taxonomy,['fields'=>'names']);if(!is_wp_error($terms))$text.=' '.strtolower(implode(' ',$terms));}
        foreach(['game_type','play_mode','checkpoint_count','xp_available','game_url','start_game_url'] as $key)if(get_post_meta($id,$key,true)!=='')return true;
        return(bool)preg_match('/scavenger hunt|city game|self-guided game|quest|puzzle|color clash|quick play|tv game|playable/',$text);
    }

    private static function valid_coords($lat,$lng): bool {
        if(!is_numeric($lat)||!is_numeric($lng))return false;
        $lat=(float)$lat;$lng=(float)$lng;
        return $lat>=-90&&$lat<=90&&$lng>=-180&&$lng<=180&&!($lat===0.0&&$lng===0.0);
    }

    private static function trail_has_route(int $trail_id): bool {
        if(!$trail_id||!get_post($trail_id))return false;
        foreach(['trail_gpx_url','_trail_gpx_url','gpx_url','_gpx_url'] as $key){if(trim((string)get_post_meta($trail_id,$key,true))!=='')return true;}
        return false;
    }

    public static function is_player_ready(int $id): bool {
        $post=get_post($id); if(!$post||$post->post_status!=='publish')return false;
        if($post->post_type!=='tng_game')return self::is_game($id);
        if((bool)get_post_meta($id,'_tng_game_archived',true))return false;
        $raw=get_post_meta($id,'tng_game_checkpoints',true);
        $checkpoints=is_array($raw)?array_values(array_filter($raw,'is_array')):[];
        if(!$checkpoints)return false;
        $calc_xp=0;
        foreach($checkpoints as $cp){
            if(trim((string)($cp['title']??''))==='')return false;
            $xp=absint($cp['xp']??0); if($xp<1)return false; $calc_xp+=$xp;
            $cp_type=sanitize_key((string)($cp['type']??'tap'));
            if($cp_type==='gps'){
                if(!self::valid_coords($cp['latitude']??null,$cp['longitude']??null))return false;
                $radius=absint($cp['radius']??0); if($radius<1||$radius>150)return false;
            }
            $sight_id=absint($cp['sight_id']??0);
            if($sight_id){$sight=get_post($sight_id);if(!$sight||$sight->post_status!=='publish')return false;}
        }
        if(absint(get_post_meta($id,'xp_available',true))!==$calc_xp)return false;
        $type=strtolower(trim((string)get_post_meta($id,'game_type',true)));
        $trail_id=absint(get_post_meta($id,'tng_trail_id',true));
        $is_trail=strpos($type,'trail')!==false;
        if($trail_id){if(!self::trail_has_route($trail_id))return false;}
        elseif($is_trail)return false;
        if(!has_post_thumbnail($id))return false;
        $last_pass=trim((string)get_post_meta($id,'_tng_last_guided_test_pass',true));
        $receipt=get_post_meta($id,'_tng_guided_test_receipt',true);
        if($last_pass===''||!is_array($receipt))return false;
        if(empty($receipt['game_modified_gmt'])||$receipt['game_modified_gmt']!==$post->post_modified_gmt)return false;
        return true;
    }

    public static function posts(): array {
        $types=array_values(array_filter(['tng_game','game','st_activity','activity'],'post_type_exists'));if(!$types)return[];
        $query=new WP_Query(['post_type'=>$types,'post_status'=>'publish','posts_per_page'=>80,'orderby'=>'modified','order'=>'DESC','ignore_sticky_posts'=>true]);
        return array_values(array_filter($query->posts,static fn($post)=>self::is_game((int)$post->ID)&&self::is_player_ready((int)$post->ID)));
    }

    public static function protect_blocked_game(): void {
        if(!is_singular('tng_game'))return;
        $id=get_queried_object_id();if(!$id||self::is_player_ready($id))return;
        if(is_user_logged_in()&&current_user_can('edit_post',$id))return;
        wp_safe_redirect(add_query_arg('game_unavailable','1',home_url('/games/')));exit;
    }

    public static function template(string $template): string {if(!is_singular()||!self::is_game())return$template;$native=TNG_OS_PATH.'templates/game-shell.php';return is_readable($native)?$native:$template;}
    public static function body_class(array $classes): array {if(!is_singular()||!self::is_game())return$classes;$classes[]='tng-platform-ui';$classes[]='tng-game-detail-page';$classes[]='tng-hide-traveler-chrome';return array_values(array_unique($classes));}
    public static function assets(): void {$route=class_exists('TNG_OS\\Platform\\App_Router')?\TNG_OS\Platform\App_Router::current_route():'';if($route!=='games'&&!(is_singular()&&self::is_game()))return;wp_enqueue_style('tng-platform-ui',TNG_OS_URL.'assets/css/platform-ui.css',[],'2.2.0');wp_enqueue_style('tng-app-router',TNG_OS_URL.'assets/css/app-router.css',['tng-platform-ui'],'3.3.0');wp_enqueue_style('tng-ui-kit',TNG_OS_URL.'assets/css/ui-kit.css',['tng-platform-ui'],'2.7.0');wp_enqueue_style('tng-games-ui',TNG_OS_URL.'assets/css/games-ui.css',['tng-ui-kit'],'0.5.0');wp_enqueue_script('tng-platform-ui',TNG_OS_URL.'assets/js/platform-ui.js',[],'2.2.0',true);}
    private static function meta(int $id,array $keys,string $fallback=''): string {foreach($keys as $key){$value=get_post_meta($id,$key,true);if(is_scalar($value)&&trim((string)$value)!=='')return trim((string)$value);}return$fallback;}
    private static function excerpt(int $id,int $words=24): string {$text=(string)get_post_field('post_excerpt',$id);if(!$text)$text=(string)get_post_field('post_content',$id);$text=preg_replace('/\[[^\]]+\]/',' ',$text);return wp_trim_words(wp_strip_all_tags($text),$words,'…');}
    private static function checkpoints(int $id): array {$raw=get_post_meta($id,'tng_game_checkpoints',true);return is_array($raw)?array_values(array_filter($raw,'is_array')):[];}
    private static function game_type(int $id): string {$type=self::meta($id,['game_type','play_mode'],'Adventure');return $type!==''?$type:'Adventure';}
    private static function progress(int $id): array {
        $total=count(self::checkpoints($id)); if($total<1)$total=absint(get_post_meta($id,'checkpoint_count',true));
        $done=0;$complete=false;
        if(is_user_logged_in()){
            $saved=get_user_meta(get_current_user_id(),'_tng_game_progress_'.$id,true);
            if(is_array($saved))$done=count(array_unique(array_map('absint',$saved)));
            $games=get_user_meta(get_current_user_id(),'_tng_completed_games',true);
            if(is_array($games))$complete=in_array($id,array_map('absint',$games),true);
        }
        if($total>0&&$done>$total)$done=$total;
        if($total>0&&$done>=$total)$complete=true;
        $percent=$total>0?(int)round(($done/$total)*100):0;
        $state=$complete?'complete':($done>0?'progress':'new');
        return ['done'=>$done,'total'=>$total,'percent'=>$percent,'state'=>$state];
    }
    private static function play_url(int $id): string {return add_query_arg('game',$id,home_url('/game-play/'));}

    private static function card(WP_Post $game,bool $compact=false): string {
        $id=$game->ID;$image=get_the_post_thumbnail_url($id,'large');$difficulty=self::meta($id,['difficulty','game_difficulty'],'Adventure');$xp=self::meta($id,['xp_available','xp'],'XP');$type=self::game_type($id);$progress=self::progress($id);$play=self::play_url($id);
        $status=$progress['state']==='complete'?'Completed':($progress['state']==='progress'?'Continue':'Playable');
        $action=$progress['state']==='complete'?'View completion':($progress['state']==='progress'?'Continue game':'View game');
        ob_start(); ?>
        <article class="tng-games-card is-<?php echo esc_attr($progress['state']);?><?php echo $compact?' is-continue':'';?>">
            <a class="tng-games-card__media<?php echo$image?'':' is-placeholder';?>" href="<?php echo esc_url($progress['state']==='new'?get_permalink($id):$play);?>"<?php echo$image?' style="background-image:url('.esc_url($image).')"':'';?>><span><?php echo esc_html($status);?></span><?php if($progress['state']==='complete'):?><b class="tng-games-card__complete-mark">✓</b><?php endif;?></a>
            <div class="tng-games-card__body">
                <div class="tng-games-card__type"><?php echo esc_html($type);?></div>
                <h3><a href="<?php echo esc_url(get_permalink($id));?>"><?php echo esc_html(get_the_title($id));?></a></h3>
                <div class="tng-games-card__meta"><span>⚡ <?php echo esc_html($difficulty);?></span><?php if($progress['total']):?><span>📍 <?php echo esc_html((string)$progress['total']);?> stops</span><?php endif;?><span>⭐ <?php echo esc_html($xp);?> XP</span></div>
                <?php if($progress['state']!=='new'&&$progress['total']):?><div class="tng-games-card__progress"><div><strong><?php echo esc_html($progress['state']==='complete'?'Adventure complete':$progress['done'].'/'.$progress['total'].' checkpoints');?></strong><span><?php echo esc_html((string)$progress['percent']);?>%</span></div><i><b style="width:<?php echo esc_attr((string)$progress['percent']);?>%"></b></i></div><?php endif;?>
                <?php if(!$compact):?><p><?php echo esc_html(self::excerpt($id,16));?></p><?php endif;?>
                <div class="tng-games-card__actions"><a class="tng-ui-button" href="<?php echo esc_url($progress['state']==='new'?get_permalink($id):$play);?>"><?php echo esc_html($action);?></a><?php if($progress['state']!=='new'):?><a class="tng-games-card__details" href="<?php echo esc_url(get_permalink($id));?>">Details</a><?php endif;?></div>
            </div>
        </article>
        <?php return (string)ob_get_clean();
    }

    public static function directory(): string {
        $games=self::posts();$can_create=is_user_logged_in()&&current_user_can('edit_posts');$unavailable=!empty($_GET['game_unavailable']);$continue=[];
        foreach($games as $game){$p=self::progress((int)$game->ID);if($p['state']==='progress')$continue[]=$game;}
        ob_start();?>
        <main class="tng-games-directory tng-app-shell">
            <section class="tng-games-hero"><div><span class="tng-eyebrow">Choose how to play</span><h1>Games and quests</h1><p>Play quick challenges, scavenger hunts, city games, trail quests, and group adventures.</p></div><div class="tng-games-count"><strong><?php echo esc_html((string)count($games));?></strong><small>Playable</small></div></section>
            <nav class="tng-games-modes"><a class="is-active" href="<?php echo esc_url(home_url('/games/'));?>">🎮 All games</a><a href="<?php echo esc_url(home_url('/map/'));?>">📍 Nearby</a><a href="<?php echo esc_url(home_url('/challenges/'));?>">⚔ Challenges</a><a href="<?php echo esc_url(home_url('/play/'));?>">▶ Play hub</a></nav>
            <?php if($continue):?><section class="tng-games-continue"><div class="tng-section__heading"><div><span class="tng-eyebrow">Your adventure</span><h2>Continue playing</h2><p>Pick up exactly where you left off.</p></div></div><div class="tng-games-continue-grid"><?php foreach(array_slice($continue,0,3) as $game)echo self::card($game,true);?></div></section><?php endif;?>
            <section class="tng-games-content"><?php if($unavailable):?><div class="tng-ui-notice" style="margin-bottom:18px;padding:14px 16px;border:1px solid #f0c6b5;border-radius:12px;background:#fff3ed;color:#8f3c22;font-weight:700;">That game is temporarily unavailable while it is being updated or tested.</div><?php endif;?><div class="tng-section__heading"><div><span class="tng-eyebrow">Playable now</span><h2><?php echo $continue?'Explore more games':'Pick an adventure';?></h2><p>Only games that have passed the TN Game release checks appear here.</p></div><?php if($can_create):?><a href="<?php echo esc_url(home_url('/game-builder/'));?>">＋ Build a game</a><?php endif;?></div>
            <?php if(!$games):?><div class="tng-games-empty"><span>🎮</span><h3>No player-ready games are published yet.</h3><p>Games appear here automatically after they pass the release gate and a current Guided Test Run.</p><?php if($can_create):?><a class="tng-ui-button" href="<?php echo esc_url(home_url('/game-builder/'));?>">Build a game</a><?php endif;?></div><?php else:?><div class="tng-games-grid"><?php foreach($games as $game)echo self::card($game);?></div><?php endif;?></section>
        </main><?php return(string)ob_get_clean();
    }

    public static function detail(): string {$id=get_queried_object_id();$image=get_the_post_thumbnail_url($id,'full');$difficulty=self::meta($id,['difficulty','game_difficulty'],'All explorers');$duration=self::meta($id,['duration','estimated_time','game_duration'],'Flexible');$players=self::meta($id,['players','player_count','group_size'],'1+ players');$xp=self::meta($id,['xp_available','xp'],'Earn XP');$cp_array=self::checkpoints($id);$checkpoints=$cp_array?count($cp_array):self::meta($id,['checkpoint_count','checkpoints'],'Multiple stops');$custom=self::meta($id,['start_game_url','game_url','play_url'],'');$start=$custom!==''?$custom:self::play_url($id);$progress=self::progress($id);$start_label=$progress['state']==='complete'?'View completed game':($progress['state']==='progress'?'Continue game':'Start game');ob_start();?>
        <main class="tng-game-detail tng-app-shell"><section class="tng-game-detail-hero<?php echo$image?' has-image':'';?>"<?php echo$image?' style="background-image:linear-gradient(90deg,rgba(5,38,25,.96),rgba(5,38,25,.45)),url('.esc_url($image).')"':'';?>><div><span class="tng-eyebrow"><?php echo esc_html(self::game_type($id));?></span><h1><?php echo esc_html(get_the_title($id));?></h1><p><?php echo esc_html(self::excerpt($id,28));?></p><?php if($progress['state']!=='new'&&$progress['total']):?><div class="tng-game-detail-progress"><strong><?php echo esc_html($progress['state']==='complete'?'Adventure complete':$progress['done'].' of '.$progress['total'].' checkpoints complete');?></strong><span><?php echo esc_html((string)$progress['percent']);?>%</span><i><b style="width:<?php echo esc_attr((string)$progress['percent']);?>%"></b></i></div><?php endif;?><div class="tng-game-detail-actions"><a class="tng-ui-button" href="<?php echo esc_url($start);?>">▶ <?php echo esc_html($start_label);?></a><button type="button" class="tng-ui-button tng-ui-button--secondary" data-tng-trip-toggle data-post-id="<?php echo esc_attr((string)$id);?>">＋ Add to trip</button></div></div></section><section class="tng-game-stats"><div><span>⚡</span><strong><?php echo esc_html($difficulty);?></strong><small>Difficulty</small></div><div><span>◷</span><strong><?php echo esc_html($duration);?></strong><small>Estimated time</small></div><div><span>👥</span><strong><?php echo esc_html($players);?></strong><small>Players</small></div><div><span>⭐</span><strong><?php echo esc_html($xp);?></strong><small>Rewards</small></div></section><div class="tng-game-detail-layout"><section class="tng-game-overview"><span class="tng-eyebrow">How it works</span><h2>About this game</h2><div class="tng-game-description"><?php echo apply_filters('the_content',get_post_field('post_content',$id));?></div><div class="tng-game-checkpoints"><span>📍</span><div><strong><?php echo esc_html((string)$checkpoints);?><?php echo is_numeric($checkpoints)?' checkpoints':'';?></strong><p>Follow the game instructions, visit checkpoints, complete challenges, and earn Explorer XP.</p></div></div></section><aside class="tng-game-start-card"><span class="tng-eyebrow"><?php echo esc_html($progress['state']==='complete'?'Saved adventure':($progress['state']==='progress'?'Adventure in progress':'Ready to play?'));?></span><h2><?php echo esc_html($progress['state']==='complete'?'You did it.':($progress['state']==='progress'?'Keep going.':'Start the adventure.'));?></h2><p><?php echo esc_html($progress['state']==='new'?'Your progress stays connected to your Explorer profile.':($progress['state']==='complete'?'Your completion and Explorer XP are saved to your profile.':'Your checkpoint progress is saved. Continue from your next mission.'));?></p><a class="tng-ui-button" href="<?php echo esc_url($start);?>"><?php echo esc_html($start_label);?></a><a class="tng-ui-button tng-ui-button--secondary" href="<?php echo esc_url(home_url('/games/'));?>">Browse games</a></aside></div></main><?php return(string)ob_get_clean();}
}
TNG_Games_UI::boot();
