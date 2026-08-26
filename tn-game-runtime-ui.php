<?php
/**
 * Plugin Name: TN Game Runtime UI
 * Description: Native checkpoint runtime and saved progress for playable TN Games.
 * Version: 0.3.0
 * Author: The TN Game
 */
if (!defined('ABSPATH')) exit;

final class TNG_Game_Runtime_UI {
    public static function boot() {
        add_action('template_redirect', array(__CLASS__, 'handle_action'), 2);
    }

    private static function game_id() {
        $raw = isset($_GET['game']) ? $_GET['game'] : (isset($_POST['game_id']) ? $_POST['game_id'] : 0);
        $id = absint($raw);
        if (!$id) return 0;
        $post = get_post($id);
        if (!$post || $post->post_status !== 'publish') return 0;
        if (!in_array($post->post_type, array('tng_game','game','st_activity','activity'), true)) return 0;
        return $id;
    }

    private static function default_checkpoint_xp($type) {
        $defaults = array('tap'=>10,'gps'=>25,'question'=>50,'photo'=>40);
        return isset($defaults[$type]) ? $defaults[$type] : 25;
    }

    private static function normalize_checkpoint($item, $index) {
        $title=''; $instructions=''; $type='tap'; $answer=''; $latitude=0.0; $longitude=0.0; $radius=30; $xp=0;
        if (is_array($item)) {
            $title = isset($item['title']) ? sanitize_text_field((string)$item['title']) : '';
            $instructions = isset($item['instructions']) ? sanitize_textarea_field((string)$item['instructions']) : '';
            $type = isset($item['type']) ? sanitize_key((string)$item['type']) : 'tap';
            $answer = isset($item['answer']) ? sanitize_text_field((string)$item['answer']) : '';
            $latitude = isset($item['latitude']) ? (float)$item['latitude'] : 0.0;
            $longitude = isset($item['longitude']) ? (float)$item['longitude'] : 0.0;
            $radius = isset($item['radius']) ? absint($item['radius']) : 30;
            $xp = isset($item['xp']) ? absint($item['xp']) : 0;
        } else {
            $title = sanitize_text_field((string)$item);
        }
        if (!in_array($type, array('tap','question','gps','photo'), true)) $type='tap';
        if ($title==='') $title='Checkpoint '.($index+1);
        if ($radius<1) $radius=30;
        if ($radius>500) $radius=500;
        if ($xp<1) $xp=self::default_checkpoint_xp($type);
        if ($xp>5000) $xp=5000;
        return array('title'=>$title,'instructions'=>$instructions,'type'=>$type,'answer'=>$answer,'latitude'=>$latitude,'longitude'=>$longitude,'radius'=>$radius,'xp'=>$xp);
    }

    private static function checkpoints($id) {
        $saved=get_post_meta($id,'tng_game_checkpoints',true); $out=array();
        if (is_array($saved) && !empty($saved)) foreach ($saved as $index=>$item) $out[]=self::normalize_checkpoint($item,$index);
        if (!empty($out)) return $out;
        $count=absint(get_post_meta($id,'checkpoint_count',true));
        if ($count<1) $count=1; if ($count>30) $count=30;
        for ($i=1;$i<=$count;$i++) $out[]=self::normalize_checkpoint(array('title'=>'Checkpoint '.$i,'instructions'=>'Follow the game instructions to complete this stop.','type'=>'tap'),$i-1);
        return $out;
    }

    private static function progress_key($id) { return '_tng_game_progress_'.absint($id); }
    private static function award_key($id,$index) { return '_tng_game_checkpoint_xp_'.absint($id).'_'.absint($index); }

    private static function completed($id) {
        if (!is_user_logged_in()) return array();
        $value=get_user_meta(get_current_user_id(),self::progress_key($id),true);
        if (!is_array($value)) return array();
        return array_values(array_unique(array_map('absint',$value)));
    }

    private static function next_index($checkpoints,$completed) {
        for ($i=0;$i<count($checkpoints);$i++) if (!in_array($i,$completed,true)) return $i;
        return -1;
    }

    private static function points_type() {
        $configured=sanitize_key((string)get_option('tng_gamipress_points_type',''));
        if ($configured!=='') return $configured;
        if (!function_exists('gamipress_get_points_types')) return '';
        $types=gamipress_get_points_types();
        if (!is_array($types) || empty($types)) return '';
        foreach (array('explorer-xp','xp','points') as $preferred) if (isset($types[$preferred])) return $preferred;
        foreach ($types as $slug=>$data) {
            $text=strtolower((string)$slug.' '.wp_json_encode($data));
            if (strpos($text,'explorer')!==false && strpos($text,'xp')!==false) return sanitize_key((string)$slug);
        }
        if (count($types)===1) return sanitize_key((string)array_key_first($types));
        return '';
    }

    private static function award_checkpoint_xp($id,$index,$checkpoint) {
        $uid=get_current_user_id();
        $xp=absint(isset($checkpoint['xp'])?$checkpoint['xp']:0);
        if (!$uid || $xp<1) return 0;
        $key=self::award_key($id,$index);
        if (absint(get_user_meta($uid,$key,true))>0) return 0;
        $type=self::points_type();
        if ($type==='' || !function_exists('gamipress_award_points_to_user')) return 0;
        gamipress_award_points_to_user($uid,$xp,$type);
        update_user_meta($uid,$key,$xp);
        update_user_meta($uid,$key.'_at',current_time('mysql'));
        return $xp;
    }

    private static function mark_game_complete($id) {
        $uid=get_current_user_id(); if (!$uid) return;
        $games=get_user_meta($uid,'_tng_completed_games',true); if (!is_array($games)) $games=array();
        $clean=array_map('absint',$games); if (!in_array(absint($id),$clean,true)) $clean[]=absint($id);
        update_user_meta($uid,'_tng_completed_games',array_values(array_unique($clean)));
        update_user_meta($uid,'_tng_game_completed_at_'.absint($id),current_time('mysql'));
    }

    private static function normalize_answer($value) {
        $value=sanitize_text_field((string)$value); $value=trim(preg_replace('/\s+/',' ',$value));
        return function_exists('mb_strtolower')?mb_strtolower($value):strtolower($value);
    }

    private static function distance_meters($lat1,$lon1,$lat2,$lon2) {
        $earth=6371000.0; $phi1=deg2rad((float)$lat1); $phi2=deg2rad((float)$lat2);
        $dphi=deg2rad((float)$lat2-(float)$lat1); $dlambda=deg2rad((float)$lon2-(float)$lon1);
        $a=sin($dphi/2)*sin($dphi/2)+cos($phi1)*cos($phi2)*sin($dlambda/2)*sin($dlambda/2);
        return $earth*(2*atan2(sqrt($a),sqrt(1-$a)));
    }

    private static function save_photo($id,$index) {
        if (empty($_FILES['checkpoint_photo']) || empty($_FILES['checkpoint_photo']['tmp_name'])) return false;
        $file=$_FILES['checkpoint_photo']; if (!empty($file['size']) && (int)$file['size']>8*1024*1024) return false;
        $checked=wp_check_filetype_and_ext($file['tmp_name'],$file['name']); if (empty($checked['type']) || strpos($checked['type'],'image/')!==0) return false;
        require_once ABSPATH.'wp-admin/includes/file.php'; require_once ABSPATH.'wp-admin/includes/media.php'; require_once ABSPATH.'wp-admin/includes/image.php';
        $attachment_id=media_handle_upload('checkpoint_photo',$id,array(),array('test_form'=>false)); if (is_wp_error($attachment_id)) return false;
        update_user_meta(get_current_user_id(),'_tng_game_photo_'.absint($id).'_'.absint($index),absint($attachment_id));
        if(class_exists('TNG_OS\\Modules\\Frontend\\Community_Photos'))\TNG_OS\Modules\Frontend\Community_Photos::register_checkpoint_photo(absint($attachment_id),absint($id),absint($index));
        return true;
    }

    private static function redirect_with_status($id,$error='',$award=0) {
        $args=array('game'=>absint($id));
        if ($error!=='') $args['runtime_error']=sanitize_key($error);
        if ($award>0) $args['runtime_xp']=absint($award);
        wp_safe_redirect(add_query_arg($args,home_url('/game-play/'))); exit;
    }

    public static function handle_action() {
        if (empty($_POST['tng_game_runtime_action']) || !is_user_logged_in()) return;
        $id=self::game_id(); if (!$id) return;
        check_admin_referer('tng_game_runtime_'.$id,'tng_game_runtime_nonce');
        $action=sanitize_key(wp_unslash($_POST['tng_game_runtime_action']));
        $completed=self::completed($id); $checkpoints=self::checkpoints($id); $award=0;

        if ($action==='complete') {
            $index=isset($_POST['checkpoint'])?absint($_POST['checkpoint']):-1;
            $next=self::next_index($checkpoints,$completed);
            if ($index<0 || $index>=count($checkpoints) || $index!==$next) self::redirect_with_status($id,'sequence');
            $checkpoint=$checkpoints[$index]; $type=$checkpoint['type'];
            if ($type==='question') {
                $given=self::normalize_answer(wp_unslash($_POST['checkpoint_answer']??'')); $expected=self::normalize_answer($checkpoint['answer']);
                if ($expected==='' || $given==='' || !hash_equals($expected,$given)) self::redirect_with_status($id,'answer');
            }
            if ($type==='gps') {
                $lat=isset($_POST['player_lat'])?(float)$_POST['player_lat']:0.0; $lng=isset($_POST['player_lng'])?(float)$_POST['player_lng']:0.0;
                if (!$lat && !$lng) self::redirect_with_status($id,'location');
                if (self::distance_meters($lat,$lng,$checkpoint['latitude'],$checkpoint['longitude'])>(float)$checkpoint['radius']) self::redirect_with_status($id,'distance');
            }
            if ($type==='photo' && !self::save_photo($id,$index)) self::redirect_with_status($id,'photo');
            $completed[]=$index; $completed=array_values(array_unique($completed));
            update_user_meta(get_current_user_id(),self::progress_key($id),$completed);
            $award=self::award_checkpoint_xp($id,$index,$checkpoint);
            do_action('tng_gameplay_external_event',get_current_user_id(),'checkpoint_completed','checkpoint',absint($id).':'.absint($index),absint($award),array('title'=>sanitize_text_field($checkpoint['title']??'Checkpoint'),'object_id'=>absint($id)));
            if (count($completed)>=count($checkpoints)) {
                self::mark_game_complete($id);
                do_action('tng_gameplay_external_event',get_current_user_id(),'game_completed','game',(string)absint($id),0,array('title'=>get_the_title($id)));
            }
        }

        if ($action==='reset') {
            delete_user_meta(get_current_user_id(),self::progress_key($id));
            foreach (array_keys($checkpoints) as $index) delete_user_meta(get_current_user_id(),'_tng_game_photo_'.absint($id).'_'.absint($index));
            $games=get_user_meta(get_current_user_id(),'_tng_completed_games',true);
            if (is_array($games)) {
                $kept=array(); foreach ($games as $game_id) { $game_id=absint($game_id); if ($game_id!==$id) $kept[]=$game_id; }
                update_user_meta(get_current_user_id(),'_tng_completed_games',$kept);
            }
            delete_user_meta(get_current_user_id(),'_tng_game_completed_at_'.$id);
        }
        self::redirect_with_status($id,'',$award);
    }

    private static function error_message($code) {
        $messages=array('sequence'=>'Finish the current checkpoint before moving ahead.','answer'=>'That answer is not quite right. Try again.','location'=>'Your location could not be read. Allow location access and try again.','distance'=>'You are not close enough to this checkpoint yet.','photo'=>'The photo could not be uploaded. Try a JPG, PNG, or WebP under 8 MB.');
        return isset($messages[$code])?$messages[$code]:'';
    }
    private static function type_label($type) {
        $labels=array('tap'=>'Check in','question'=>'Puzzle','gps'=>'Location','photo'=>'Photo'); return isset($labels[$type])?$labels[$type]:'Checkpoint';
    }

    public static function render() {
        $id=self::game_id();
        if (!$id) return '<main class="tng-game-runtime tng-app-shell"><section class="tng-runtime-empty"><span>🎮</span><h1>Game not found.</h1><p>Choose a published game to start playing.</p><a class="tng-ui-button" href="'.esc_url(home_url('/games/')).'">Browse games</a></section></main>';
        if (!is_user_logged_in()) {
            $return_url=add_query_arg('game',$id,home_url('/game-play/'));
            return '<main class="tng-game-runtime tng-app-shell"><section class="tng-runtime-empty"><span>🎮</span><h1>'.esc_html(get_the_title($id)).'</h1><p>Sign in so your checkpoint progress stays synced to your Explorer profile.</p><a class="tng-ui-button" href="'.esc_url(wp_login_url($return_url)).'">Sign in to play</a></section></main>';
        }
        $checkpoints=self::checkpoints($id); $completed=self::completed($id); $total=count($checkpoints); $done=0;
        for ($i=0;$i<$total;$i++) if (in_array($i,$completed,true)) $done++;
        $percent=$total>0?(int)round(($done/$total)*100):0; if ($percent>100) $percent=100;
        $finished=($total>0 && $done>=$total); $next=self::next_index($checkpoints,$completed);
        $xp=get_post_meta($id,'xp_available',true); if ($xp==='') $xp=get_post_meta($id,'xp',true); if ($xp==='') $xp='XP';
        $format=get_post_meta($id,'game_type',true); if ($format==='') $format='TN Game';
        $error=sanitize_key(wp_unslash($_GET['runtime_error']??'')); $error_message=self::error_message($error); $awarded=absint($_GET['runtime_xp']??0);
        ob_start(); ?>
        <main class="tng-game-runtime tng-app-shell">
            <section class="tng-runtime-hero"><div><span class="tng-eyebrow"><?php echo esc_html($format); ?></span><h1><?php echo esc_html(get_the_title($id)); ?></h1><p><?php echo esc_html($finished?'Adventure complete. You finished every checkpoint.':'Complete each checkpoint to finish this adventure.'); ?></p></div><div class="tng-runtime-score"><strong><?php echo esc_html($done.'/'.$total); ?></strong><small>Complete</small></div></section>
            <?php if ($error_message!==''): ?><div class="tng-runtime-notice is-error"><?php echo esc_html($error_message); ?></div><?php endif; ?>
            <?php if ($awarded>0): ?><div class="tng-runtime-notice is-success">+<?php echo esc_html((string)$awarded); ?> Explorer XP earned!</div><?php endif; ?>
            <section class="tng-runtime-progress"><div><span class="tng-eyebrow"><?php echo esc_html($finished?'Game complete':'Current progress'); ?></span><h2><?php echo esc_html($finished?'You did it!':'Next: '.$checkpoints[$next]['title']); ?></h2></div><strong><?php echo esc_html((string)$percent); ?>%</strong><div class="tng-runtime-progressbar"><span style="width:<?php echo esc_attr((string)$percent); ?>%"></span></div></section>
            <div class="tng-runtime-layout"><section class="tng-runtime-list"><div class="tng-section__heading"><div><span class="tng-eyebrow">Game route</span><h2>Checkpoints</h2><p>Your progress is saved automatically to your Explorer account.</p></div></div>
            <?php foreach ($checkpoints as $index=>$checkpoint): $is_done=in_array($index,$completed,true); $is_next=(!$finished&&$index===$next); $type=$checkpoint['type']; ?>
                <article class="tng-runtime-stop<?php echo $is_done?' is-complete':($is_next?' is-next':''); ?>" data-checkpoint-type="<?php echo esc_attr($type); ?>">
                    <div class="tng-runtime-stop__number"><?php echo $is_done?'✓':esc_html((string)($index+1)); ?></div>
                    <div class="tng-runtime-stop__copy"><small><?php echo esc_html($is_done?'Completed':($is_next?self::type_label($type):'Locked until previous stop')); ?> · <?php echo esc_html((string)$checkpoint['xp']); ?> XP</small><h3><?php echo esc_html($checkpoint['title']); ?></h3><?php if (!empty($checkpoint['instructions'])): ?><p><?php echo esc_html($checkpoint['instructions']); ?></p><?php endif; ?><?php if ($is_done&&$type==='photo'): $photo_id=absint(get_user_meta(get_current_user_id(),'_tng_game_photo_'.$id.'_'.$index,true)); if ($photo_id): ?><div class="tng-runtime-photo-thumb"><?php echo wp_get_attachment_image($photo_id,'thumbnail'); ?></div><?php endif; endif; ?></div>
                    <?php if ($is_next): ?><div class="tng-runtime-action">
                        <?php if ($type==='tap'): ?><form method="post"><?php wp_nonce_field('tng_game_runtime_'.$id,'tng_game_runtime_nonce'); ?><input type="hidden" name="game_id" value="<?php echo esc_attr((string)$id); ?>"><input type="hidden" name="checkpoint" value="<?php echo esc_attr((string)$index); ?>"><button class="tng-ui-button" name="tng_game_runtime_action" value="complete" type="submit">Complete stop · +<?php echo esc_html((string)$checkpoint['xp']); ?> XP</button></form><?php endif; ?>
                        <?php if ($type==='question'): ?><form method="post" class="tng-runtime-verify-form"><?php wp_nonce_field('tng_game_runtime_'.$id,'tng_game_runtime_nonce'); ?><input type="hidden" name="game_id" value="<?php echo esc_attr((string)$id); ?>"><input type="hidden" name="checkpoint" value="<?php echo esc_attr((string)$index); ?>"><label><span>Your answer</span><input name="checkpoint_answer" type="text" required autocomplete="off" placeholder="Enter answer"></label><button class="tng-ui-button" name="tng_game_runtime_action" value="complete" type="submit">Check answer · +<?php echo esc_html((string)$checkpoint['xp']); ?> XP</button></form><?php endif; ?>
                        <?php if ($type==='gps'): ?><form method="post" class="tng-runtime-gps-form" data-radius="<?php echo esc_attr((string)$checkpoint['radius']); ?>"><?php wp_nonce_field('tng_game_runtime_'.$id,'tng_game_runtime_nonce'); ?><input type="hidden" name="game_id" value="<?php echo esc_attr((string)$id); ?>"><input type="hidden" name="checkpoint" value="<?php echo esc_attr((string)$index); ?>"><input type="hidden" name="player_lat" value=""><input type="hidden" name="player_lng" value=""><input type="hidden" name="tng_game_runtime_action" value="complete"><button class="tng-ui-button tng-runtime-location-button" type="button">Use my location · +<?php echo esc_html((string)$checkpoint['xp']); ?> XP</button><small class="tng-runtime-location-status">Get within <?php echo esc_html((string)$checkpoint['radius']); ?> m to unlock.</small></form><?php endif; ?>
                        <?php if ($type==='photo'): ?><form method="post" enctype="multipart/form-data" class="tng-runtime-verify-form"><?php wp_nonce_field('tng_game_runtime_'.$id,'tng_game_runtime_nonce'); ?><input type="hidden" name="game_id" value="<?php echo esc_attr((string)$id); ?>"><input type="hidden" name="checkpoint" value="<?php echo esc_attr((string)$index); ?>"><label class="tng-runtime-photo-input"><span>Add a photo</span><input name="checkpoint_photo" type="file" accept="image/jpeg,image/png,image/webp" capture="environment" required></label><button class="tng-ui-button" name="tng_game_runtime_action" value="complete" type="submit">Submit photo · +<?php echo esc_html((string)$checkpoint['xp']); ?> XP</button></form><?php endif; ?>
                    </div><?php endif; ?>
                </article>
            <?php endforeach; ?>
            </section><aside class="tng-runtime-side"><div class="tng-runtime-card"><span class="tng-eyebrow"><?php echo esc_html($finished?'Adventure complete':'Total reward'); ?></span><h2><?php echo esc_html((string)$xp); ?><?php echo is_numeric($xp)?' XP':''; ?></h2><p><?php echo esc_html($finished?'This game is recorded as completed on your Explorer account.':'XP is earned checkpoint by checkpoint as you explore.'); ?></p><?php if ($finished): ?><a class="tng-ui-button" href="<?php echo esc_url(home_url('/games/')); ?>">Play another game</a><?php endif; ?></div><div class="tng-runtime-card is-light"><h3>Game controls</h3><a href="<?php echo esc_url(get_permalink($id)); ?>">View game details</a><form method="post"><?php wp_nonce_field('tng_game_runtime_'.$id,'tng_game_runtime_nonce'); ?><input type="hidden" name="game_id" value="<?php echo esc_attr((string)$id); ?>"><button name="tng_game_runtime_action" value="reset" type="submit">Reset progress</button><small>Resetting the route keeps XP already earned.</small></form></div></aside></div>
        </main>
        <?php return (string)ob_get_clean();
    }
}
TNG_Game_Runtime_UI::boot();
