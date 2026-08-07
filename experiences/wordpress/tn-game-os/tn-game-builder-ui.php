<?php
/**
 * Plugin Name: TN Game Builder UI
 * Description: Native front-end builder for TN Game games and quests.
 * Version: 0.3.0
 * Author: The TN Game
 */
if (!defined('ABSPATH')) exit;
final class TNG_Game_Builder_UI {
    public static function boot(): void { add_action('init',[self::class,'register_post_type'],8); add_action('template_redirect',[self::class,'handle_submit'],1); }
    public static function register_post_type(): void {
        if (post_type_exists('tng_game')) return;
        register_post_type('tng_game',['labels'=>['name'=>'TN Games','singular_name'=>'TN Game','add_new_item'=>'Add New Game','edit_item'=>'Edit Game'],'public'=>true,'show_ui'=>true,'show_in_menu'=>true,'show_in_rest'=>true,'menu_icon'=>'dashicons-games','supports'=>['title','editor','excerpt','thumbnail','author','revisions'],'rewrite'=>['slug'=>'game','with_front'=>false],'has_archive'=>false]);
    }
    private static function parse_checkpoint_line(string $line): array {
        $parts=array_map('trim',explode('|',$line));
        $title=sanitize_text_field($parts[0]??'');
        $instructions=sanitize_textarea_field($parts[1]??'');
        $type=sanitize_key($parts[2]??'tap');
        if(!in_array($type,['tap','question','gps','photo'],true))$type='tap';
        $checkpoint=['title'=>$title,'instructions'=>$instructions,'type'=>$type];
        if($type==='question')$checkpoint['answer']=sanitize_text_field($parts[3]??'');
        if($type==='gps'){
            $checkpoint['latitude']=isset($parts[3])?(float)$parts[3]:0.0;
            $checkpoint['longitude']=isset($parts[4])?(float)$parts[4]:0.0;
            $radius=isset($parts[5])?absint($parts[5]):30;
            $checkpoint['radius']=$radius>0?min($radius,500):30;
        }
        return $checkpoint;
    }
    public static function handle_submit(): void {
        if (empty($_POST['tng_game_builder_submit'])) return;
        if (!is_user_logged_in() || !current_user_can('edit_posts')) wp_die('You do not have permission to create games.');
        check_admin_referer('tng_game_builder_create','tng_game_builder_nonce');
        $title=sanitize_text_field(wp_unslash($_POST['game_title']??'')); $description=wp_kses_post(wp_unslash($_POST['game_description']??''));
        if ($title===''){wp_safe_redirect(add_query_arg('game_error','title',home_url('/game-builder/')));exit;}
        $status=sanitize_key(wp_unslash($_POST['game_status']??'draft')); if(!in_array($status,['draft','publish'],true))$status='draft'; if($status==='publish'&&!current_user_can('publish_posts'))$status='draft';
        $post_id=wp_insert_post(['post_type'=>'tng_game','post_status'=>$status,'post_title'=>$title,'post_content'=>$description,'post_excerpt'=>sanitize_textarea_field(wp_unslash($_POST['game_summary']??'')),'post_author'=>get_current_user_id()],true);
        if(is_wp_error($post_id)){wp_safe_redirect(add_query_arg('game_error','save',home_url('/game-builder/')));exit;}
        $fields=['game_type'=>'game_type','game_difficulty'=>'difficulty','game_duration'=>'estimated_time','player_count'=>'players','xp_available'=>'xp_available','start_game_url'=>'start_game_url'];
        foreach($fields as $input=>$meta){$value=sanitize_text_field(wp_unslash($_POST[$input]??''));if($value!=='')update_post_meta($post_id,$meta,$value);}
        $plan=sanitize_textarea_field(wp_unslash($_POST['checkpoint_plan']??'')); $checkpoints=[];
        if($plan!==''){
            foreach(preg_split('/\r\n|\r|\n/',$plan) as $line){
                $line=trim($line);if($line==='')continue;
                $checkpoint=self::parse_checkpoint_line($line);
                if($checkpoint['title']!=='')$checkpoints[]=$checkpoint;
            }
        }
        if($checkpoints){update_post_meta($post_id,'tng_game_checkpoints',$checkpoints);update_post_meta($post_id,'checkpoint_count',count($checkpoints));}else{update_post_meta($post_id,'checkpoint_count',max(1,absint($_POST['checkpoint_count']??1)));}
        update_post_meta($post_id,'playable','1');
        $redirect=$status==='publish'?get_permalink($post_id):add_query_arg(['game_created'=>$post_id],home_url('/game-builder/'));wp_safe_redirect($redirect);exit;
    }
    public static function render(): string {
        if(!is_user_logged_in())return '<main class="tng-game-builder tng-app-shell"><section class="tng-game-builder-hero"><div><span class="tng-eyebrow">Creator tools</span><h1>Build a game.</h1><p>Sign in with an account that can create TN Game content.</p></div></section><section class="tng-game-builder-panel tng-game-builder-empty"><h2>Creator access required</h2><a class="tng-ui-button" href="'.esc_url(wp_login_url(home_url('/game-builder/'))).'">Sign in</a></section></main>';
        if(!current_user_can('edit_posts'))return '<main class="tng-game-builder tng-app-shell"><section class="tng-game-builder-hero"><div><span class="tng-eyebrow">Creator tools</span><h1>Build a game.</h1><p>Your Explorer account does not currently have creator access.</p></div></section></main>';
        $created=absint($_GET['game_created']??0);$error=sanitize_key($_GET['game_error']??'');ob_start();?>
        <main class="tng-game-builder tng-app-shell"><section class="tng-game-builder-hero"><div><span class="tng-eyebrow">Creator studio</span><h1>Build a TN Game.</h1><p>Create a scavenger hunt, city quest, quick challenge, trail game, or group adventure.</p></div><a class="tng-ui-button tng-ui-button--secondary" href="<?php echo esc_url(home_url('/games/'));?>">View games</a></section>
        <?php if($created):?><div class="tng-game-builder-notice is-success">✓ Draft created. <a href="<?php echo esc_url(get_edit_post_link($created));?>">Add a featured image or continue editing</a>.</div><?php endif;?><?php if($error):?><div class="tng-game-builder-notice is-error">The game could not be saved. Please check the required fields and try again.</div><?php endif;?>
        <form class="tng-game-builder-form" method="post"><?php wp_nonce_field('tng_game_builder_create','tng_game_builder_nonce');?><input type="hidden" name="tng_game_builder_submit" value="1">
        <section class="tng-game-builder-panel"><div class="tng-game-builder-heading"><span class="tng-eyebrow">Game identity</span><h2>Name the adventure</h2><p>This information appears on the Games directory and detail page.</p></div><div class="tng-game-builder-fields"><label class="is-wide"><span>Game title *</span><input name="game_title" type="text" required placeholder="Example: Tracy City Treasure Hunt"></label><label><span>Game format</span><select name="game_type"><option>Scavenger Hunt</option><option>City Game</option><option>Quick Play</option><option>Trail Quest</option><option>Top Sight Hunt</option><option>Group Game</option><option>TV Game</option></select></label><label><span>Difficulty</span><select name="game_difficulty"><option>Easy</option><option selected>Moderate</option><option>Hard</option><option>All explorers</option></select></label><label class="is-wide"><span>Short summary</span><textarea name="game_summary" rows="3" placeholder="A one-sentence description shown on game cards."></textarea></label><label class="is-wide"><span>How to play</span><textarea name="game_description" rows="7" placeholder="Explain the objective, rules, scoring, and what players should bring."></textarea></label></div></section>
        <section class="tng-game-builder-panel"><div class="tng-game-builder-heading"><span class="tng-eyebrow">Checkpoint plan</span><h2>Build the route</h2><p>Use one line per checkpoint. Pick a verification type when a stop needs GPS, a puzzle answer, or a photo.</p></div><div class="tng-game-builder-fields"><label class="is-wide"><span>Checkpoints</span><textarea name="checkpoint_plan" rows="11" placeholder="Historic Depot | Find the old station sign. | tap&#10;Railroad Riddle | What year is shown on the marker? | question | 1902&#10;Sycamore Falls | Get close enough to unlock the stop. | gps | 35.2500 | -85.7500 | 30&#10;Mural Photo | Take a photo at the mural. | photo"></textarea><small><strong>Formats:</strong><br>Tap: Name | Instructions | tap<br>Question: Name | Question | question | Correct answer<br>GPS: Name | Instructions | gps | Latitude | Longitude | Radius meters<br>Photo: Name | Instructions | photo</small></label><label><span>Fallback checkpoint count</span><input name="checkpoint_count" type="number" min="1" value="5"></label></div></section>
        <section class="tng-game-builder-panel"><div class="tng-game-builder-heading"><span class="tng-eyebrow">Play settings</span><h2>Set the game details</h2><p>These become the stat cards and actions on the native game page.</p></div><div class="tng-game-builder-fields"><label><span>Estimated time</span><input name="game_duration" type="text" placeholder="45–60 min"></label><label><span>Players</span><input name="player_count" type="text" placeholder="1–6 players"></label><label><span>XP available</span><input name="xp_available" type="number" min="0" step="5" value="100"></label><label class="is-wide"><span>Custom start URL</span><input name="start_game_url" type="url" placeholder="Leave blank to use the native TN Game runtime"></label><label><span>Save as</span><select name="game_status"><option value="draft">Draft</option><option value="publish">Publish now</option></select></label></div></section>
        <section class="tng-game-builder-submit"><div><strong>Ready to create?</strong><p>Published games can be played immediately with the native checkpoint runtime.</p></div><button class="tng-ui-button" type="submit">Create game</button></section></form></main><?php return(string)ob_get_clean();}
}
TNG_Game_Builder_UI::boot();
