<?php
/**
 * TN Game Trail Game Admin
 * Admin management for trail-linked TN Games and their checkpoints.
 */
if (!defined('ABSPATH')) exit;

final class TNG_Trail_Game_Admin {
    public static function boot(): void {
        add_action('add_meta_boxes', [__CLASS__, 'meta_boxes']);
        add_action('save_post_tng_game', [__CLASS__, 'save_game'], 20, 2);
        add_filter('manage_st_activity_posts_columns', [__CLASS__, 'trail_columns']);
        add_action('manage_st_activity_posts_custom_column', [__CLASS__, 'trail_column'], 10, 2);
        add_filter('manage_activity_posts_columns', [__CLASS__, 'trail_columns']);
        add_action('manage_activity_posts_custom_column', [__CLASS__, 'trail_column'], 10, 2);
        add_filter('manage_tng_game_posts_columns', [__CLASS__, 'game_columns']);
        add_action('manage_tng_game_posts_custom_column', [__CLASS__, 'game_column'], 10, 2);
        add_action('admin_head', [__CLASS__, 'styles']);
    }

    private static function linked_game_id(int $trail_id): int {
        if (!$trail_id || !post_type_exists('tng_game')) return 0;
        $ids=get_posts(['post_type'=>'tng_game','post_status'=>['publish','draft','private'],'posts_per_page'=>1,'fields'=>'ids','meta_key'=>'tng_trail_id','meta_value'=>$trail_id,'orderby'=>'modified','order'=>'DESC','no_found_rows'=>true]);
        return $ids ? absint($ids[0]) : 0;
    }

    private static function trail_id_for_game(int $game_id): int { return absint(get_post_meta($game_id,'tng_trail_id',true)); }
    private static function checkpoints(int $game_id): array { $raw=get_post_meta($game_id,'tng_game_checkpoints',true); return is_array($raw)?$raw:[]; }
    private static function checkpoint_xp(array $checkpoints): int { $xp=0; foreach($checkpoints as $cp) if(is_array($cp)) $xp+=absint($cp['xp']??0); return $xp; }

    public static function meta_boxes(): void {
        foreach(['st_activity','activity'] as $type) if(post_type_exists($type)) add_meta_box('tng-trail-game','TN Game — Trail Game',[__CLASS__,'trail_box'],$type,'side','high');
        if(post_type_exists('tng_game')) add_meta_box('tng-game-checkpoints','TN Game — Checkpoints',[__CLASS__,'game_box'],'tng_game','normal','high');
    }

    public static function trail_box(WP_Post $post): void {
        $game_id=self::linked_game_id($post->ID);
        echo '<div class="tng-admin-card">';
        if(!$game_id){
            echo '<span class="tng-admin-pill is-muted">Not created yet</span><p>This trail does not have a linked TN Game yet. The first <strong>Start Trail Game</strong> click will bootstrap one from its Top Sights or GPX route.</p>';
            $preview=add_query_arg(['trail'=>$post->ID],home_url('/play/'));
            echo '<p><a class="button button-primary" target="_blank" href="'.esc_url($preview).'">Start / Bootstrap Game</a></p></div>';
            return;
        }
        $cps=self::checkpoints($game_id);$xp=self::checkpoint_xp($cps);$auto=get_post_meta($game_id,'tng_auto_generated_from_trail',true)==='1';$status=get_post_status($game_id);
        echo '<p><span class="tng-admin-pill '.($auto?'is-auto':'is-manual').'">'.esc_html($auto?'Auto-generated':'Managed game').'</span> <span class="tng-admin-pill is-status">'.esc_html(ucfirst((string)$status)).'</span></p>';
        echo '<strong class="tng-admin-game-title">'.esc_html(get_the_title($game_id)).'</strong>';
        echo '<div class="tng-admin-stats"><span><b>'.esc_html((string)count($cps)).'</b> checkpoints</span><span><b>'.esc_html((string)$xp).'</b> XP</span></div>';
        echo '<p class="tng-admin-actions"><a class="button button-primary" href="'.esc_url(get_edit_post_link($game_id)).'">Edit Game</a> <a class="button" target="_blank" href="'.esc_url(add_query_arg(['game'=>$game_id],home_url('/game-play/'))).'">Test Game</a></p>';
        echo '</div>';
    }

    public static function game_box(WP_Post $post): void {
        $trail_id=self::trail_id_for_game($post->ID);$checkpoints=self::checkpoints($post->ID);
        wp_nonce_field('tng_save_game_checkpoints','tng_game_checkpoints_nonce');
        echo '<div class="tng-checkpoint-admin">';
        if($trail_id&&get_post($trail_id)) echo '<p class="tng-linked-trail"><strong>Linked trail:</strong> <a href="'.esc_url(get_edit_post_link($trail_id)).'">'.esc_html(get_the_title($trail_id)).'</a> &nbsp; <a target="_blank" href="'.esc_url(get_permalink($trail_id)).'">View trail ↗</a></p>';
        echo '<p>Edit the playable route below. XP totals and checkpoint count are recalculated when you update the game.</p>';
        echo '<div class="tng-checkpoint-table"><div class="tng-cp-row tng-cp-head"><span>#</span><span>Checkpoint</span><span>Type</span><span>GPS / radius</span><span>XP</span><span></span></div><div id="tng-checkpoint-rows">';
        foreach($checkpoints as $i=>$cp) self::checkpoint_row((int)$i,is_array($cp)?$cp:[]);
        echo '</div></div><button type="button" class="button" id="tng-add-checkpoint">+ Add checkpoint</button>';
        echo '<script type="text/html" id="tng-checkpoint-template">';self::checkpoint_row(9999,[]);echo '</script>';
        echo '<script>(function(){const rows=document.getElementById("tng-checkpoint-rows"),btn=document.getElementById("tng-add-checkpoint"),tpl=document.getElementById("tng-checkpoint-template");if(!rows||!btn||!tpl)return;function renumber(){rows.querySelectorAll(".tng-cp-row").forEach((r,i)=>{r.querySelector(".tng-cp-num").textContent=i+1;r.querySelectorAll("[name]").forEach(el=>el.name=el.name.replace(/tng_cp\[\d+\]/,"tng_cp["+i+"]"));});}btn.addEventListener("click",()=>{const d=document.createElement("div");d.innerHTML=tpl.innerHTML.replaceAll("9999",rows.children.length);rows.appendChild(d.firstElementChild);renumber();});rows.addEventListener("click",e=>{const b=e.target.closest(".tng-remove-cp");if(!b)return;b.closest(".tng-cp-row").remove();renumber();});})();</script>';
        echo '</div>';
    }

    private static function checkpoint_row(int $i,array $cp): void {
        $type=sanitize_key((string)($cp['type']??'gps'));if(!in_array($type,['gps','tap','photo','question'],true))$type='gps';
        $title=(string)($cp['title']??'');$instructions=(string)($cp['instructions']??'');$lat=(string)($cp['latitude']??'');$lng=(string)($cp['longitude']??'');$radius=absint($cp['radius']??30);$xp=absint($cp['xp']??25);$sight=absint($cp['sight_id']??$cp['top_sight_id']??0);
        echo '<div class="tng-cp-row"><span class="tng-cp-num">'.esc_html((string)($i+1)).'</span><span><input class="widefat" name="tng_cp['.$i.'][title]" value="'.esc_attr($title).'" placeholder="Checkpoint name"><textarea class="widefat" rows="2" name="tng_cp['.$i.'][instructions]" placeholder="Player instructions">'.esc_textarea($instructions).'</textarea>'.($sight?'<small>Top Sight #'.esc_html((string)$sight).'</small><input type="hidden" name="tng_cp['.$i.'][sight_id]" value="'.esc_attr((string)$sight).'">':'').'</span>';
        echo '<span><select name="tng_cp['.$i.'][type]"><option value="gps" '.selected($type,'gps',false).'>GPS</option><option value="tap" '.selected($type,'tap',false).'>Tap</option><option value="photo" '.selected($type,'photo',false).'>Photo</option><option value="question" '.selected($type,'question',false).'>Question</option></select></span>';
        echo '<span class="tng-cp-gps"><input name="tng_cp['.$i.'][latitude]" value="'.esc_attr($lat).'" placeholder="Latitude"><input name="tng_cp['.$i.'][longitude]" value="'.esc_attr($lng).'" placeholder="Longitude"><label>Radius <input type="number" min="5" max="500" name="tng_cp['.$i.'][radius]" value="'.esc_attr((string)$radius).'"> m</label></span>';
        echo '<span><input class="small-text" type="number" min="0" max="5000" name="tng_cp['.$i.'][xp]" value="'.esc_attr((string)$xp).'"> XP</span><span><button type="button" class="button-link-delete tng-remove-cp">Remove</button></span></div>';
    }

    public static function save_game(int $post_id, WP_Post $post): void {
        if(defined('DOING_AUTOSAVE')&&DOING_AUTOSAVE)return;if(wp_is_post_revision($post_id))return;if(!current_user_can('edit_post',$post_id))return;
        $nonce=sanitize_text_field(wp_unslash($_POST['tng_game_checkpoints_nonce']??''));if(!$nonce||!wp_verify_nonce($nonce,'tng_save_game_checkpoints'))return;
        $input=$_POST['tng_cp']??[];if(!is_array($input))$input=[];$clean=[];
        foreach($input as $row){if(!is_array($row))continue;$title=sanitize_text_field(wp_unslash($row['title']??''));if($title==='')continue;$type=sanitize_key(wp_unslash($row['type']??'gps'));if(!in_array($type,['gps','tap','photo','question'],true))$type='gps';$cp=['title'=>$title,'instructions'=>sanitize_textarea_field(wp_unslash($row['instructions']??'')),'type'=>$type,'xp'=>min(5000,absint($row['xp']??25))];$lat=wp_unslash($row['latitude']??'');$lng=wp_unslash($row['longitude']??'');if(is_numeric($lat))$cp['latitude']=(float)$lat;if(is_numeric($lng))$cp['longitude']=(float)$lng;$cp['radius']=max(5,min(500,absint($row['radius']??30)));$sight=absint($row['sight_id']??0);if($sight){$cp['sight_id']=$sight;$cp['top_sight_id']=$sight;}$clean[]=$cp;}
        update_post_meta($post_id,'tng_game_checkpoints',$clean);update_post_meta($post_id,'checkpoint_count',count($clean));update_post_meta($post_id,'xp_available',self::checkpoint_xp($clean));update_post_meta($post_id,'playable',$clean?'1':'0');
    }

    public static function trail_columns(array $columns): array {$columns['tng_trail_game']='TN Game';return$columns;}
    public static function trail_column(string $column,int $post_id): void {if($column!=='tng_trail_game')return;$game=self::linked_game_id($post_id);if(!$game){echo '<span class="tng-admin-pill is-muted">Not created</span>';return;}$count=count(self::checkpoints($game));echo '<a href="'.esc_url(get_edit_post_link($game)).'"><strong>'.esc_html((string)$count).' checkpoints</strong></a><br><small>'.esc_html((string)self::checkpoint_xp(self::checkpoints($game))).' XP · '.esc_html(ucfirst((string)get_post_status($game))).'</small>';}
    public static function game_columns(array $columns): array {$columns['tng_linked_trail']='Linked trail';$columns['tng_route_stats']='Route';return$columns;}
    public static function game_column(string $column,int $post_id): void {if($column==='tng_linked_trail'){$trail=self::trail_id_for_game($post_id);echo $trail&&get_post($trail)?'<a href="'.esc_url(get_edit_post_link($trail)).'">'.esc_html(get_the_title($trail)).'</a>':'—';}if($column==='tng_route_stats'){$c=self::checkpoints($post_id);echo esc_html((string)count($c)).' checkpoints<br><small>'.esc_html((string)self::checkpoint_xp($c)).' XP</small>';}}

    public static function styles(): void {echo '<style>.tng-admin-pill{display:inline-block;padding:4px 8px;border-radius:999px;font-size:11px;font-weight:700;background:#edf2ef;color:#506259}.tng-admin-pill.is-auto{background:#fff0e8;color:#d95015}.tng-admin-pill.is-manual{background:#e8f5ee;color:#12613d}.tng-admin-pill.is-status{background:#edf1ff;color:#3b4b86}.tng-admin-game-title{display:block;font-size:14px;margin:10px 0}.tng-admin-stats{display:flex;gap:8px;margin:12px 0}.tng-admin-stats span{flex:1;background:#f6f7f7;border-radius:8px;padding:9px;text-align:center}.tng-admin-stats b{display:block;font-size:17px}.tng-checkpoint-table{margin:16px 0;overflow-x:auto}.tng-cp-row{display:grid;grid-template-columns:34px minmax(240px,2fr) 100px minmax(220px,1.3fr) 90px 70px;gap:10px;align-items:start;padding:12px;border-bottom:1px solid #e5e5e5;background:#fff}.tng-cp-row:nth-child(even){background:#fafafa}.tng-cp-head{font-weight:700;background:#f0f3f1!important}.tng-cp-row textarea{margin-top:6px}.tng-cp-row small{display:block;margin-top:4px;color:#667}.tng-cp-gps input{width:100%;margin-bottom:5px}.tng-cp-gps label{font-size:11px;color:#555}.tng-cp-gps label input{width:68px;margin:0 3px}.tng-linked-trail{padding:10px 12px;background:#f2f7f3;border-left:4px solid #176b43}@media(max-width:1100px){.tng-cp-row{grid-template-columns:28px minmax(210px,2fr) 90px minmax(180px,1fr) 80px 60px}}</style>';}
}
TNG_Trail_Game_Admin::boot();
