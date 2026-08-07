<?php
/**
 * Plugin Name: TN Game Runtime UI
 * Description: Native checkpoint runtime and saved progress for playable TN Games.
 * Version: 0.1.1
 * Author: The TN Game
 */
if (!defined('ABSPATH')) exit;

final class TNG_Game_Runtime_UI {
    public static function boot() {
        add_action('template_redirect', array(__CLASS__, 'handle_action'), 2);
    }

    private static function game_id() {
        $raw = 0;
        if (isset($_GET['game'])) $raw = $_GET['game'];
        elseif (isset($_POST['game_id'])) $raw = $_POST['game_id'];
        $id = absint($raw);
        if (!$id) return 0;
        $post = get_post($id);
        if (!$post || $post->post_status !== 'publish') return 0;
        if (!in_array($post->post_type, array('tng_game','game','st_activity','activity'), true)) return 0;
        return $id;
    }

    private static function checkpoints($id) {
        $saved = get_post_meta($id, 'tng_game_checkpoints', true);
        $out = array();
        if (is_array($saved) && !empty($saved)) {
            foreach ($saved as $index => $item) {
                $title = '';
                $instructions = '';
                if (is_array($item)) {
                    $title = isset($item['title']) ? sanitize_text_field((string)$item['title']) : '';
                    $instructions = isset($item['instructions']) ? sanitize_textarea_field((string)$item['instructions']) : '';
                } else {
                    $title = sanitize_text_field((string)$item);
                }
                if ($title === '') $title = 'Checkpoint '.($index + 1);
                $out[] = array('title' => $title, 'instructions' => $instructions);
            }
        }
        if (!empty($out)) return $out;

        $count = absint(get_post_meta($id, 'checkpoint_count', true));
        if ($count < 1) $count = 1;
        if ($count > 30) $count = 30;
        for ($i = 1; $i <= $count; $i++) {
            $out[] = array(
                'title' => 'Checkpoint '.$i,
                'instructions' => 'Follow the game instructions to complete this stop.'
            );
        }
        return $out;
    }

    private static function progress_key($id) {
        return '_tng_game_progress_'.absint($id);
    }

    private static function completed($id) {
        if (!is_user_logged_in()) return array();
        $value = get_user_meta(get_current_user_id(), self::progress_key($id), true);
        if (!is_array($value)) return array();
        $clean = array();
        foreach ($value as $item) $clean[] = absint($item);
        return array_values(array_unique($clean));
    }

    private static function mark_game_complete($id) {
        $uid = get_current_user_id();
        if (!$uid) return;
        $games = get_user_meta($uid, '_tng_completed_games', true);
        if (!is_array($games)) $games = array();
        $clean = array();
        foreach ($games as $game_id) $clean[] = absint($game_id);
        if (!in_array(absint($id), $clean, true)) $clean[] = absint($id);
        update_user_meta($uid, '_tng_completed_games', array_values(array_unique($clean)));
        update_user_meta($uid, '_tng_game_completed_at_'.absint($id), current_time('mysql'));
    }

    public static function handle_action() {
        if (empty($_POST['tng_game_runtime_action'])) return;
        if (!is_user_logged_in()) return;

        $id = self::game_id();
        if (!$id) return;
        check_admin_referer('tng_game_runtime_'.$id, 'tng_game_runtime_nonce');

        $action = sanitize_key(wp_unslash($_POST['tng_game_runtime_action']));
        $completed = self::completed($id);
        $checkpoints = self::checkpoints($id);

        if ($action === 'complete') {
            $index = isset($_POST['checkpoint']) ? absint($_POST['checkpoint']) : -1;
            if ($index >= 0 && $index < count($checkpoints)) $completed[] = $index;
            $completed = array_values(array_unique($completed));
            update_user_meta(get_current_user_id(), self::progress_key($id), $completed);
            if (count($completed) >= count($checkpoints)) self::mark_game_complete($id);
        }

        if ($action === 'reset') {
            delete_user_meta(get_current_user_id(), self::progress_key($id));
            $games = get_user_meta(get_current_user_id(), '_tng_completed_games', true);
            if (is_array($games)) {
                $kept = array();
                foreach ($games as $game_id) {
                    $game_id = absint($game_id);
                    if ($game_id !== $id) $kept[] = $game_id;
                }
                update_user_meta(get_current_user_id(), '_tng_completed_games', $kept);
            }
            delete_user_meta(get_current_user_id(), '_tng_game_completed_at_'.$id);
        }

        wp_safe_redirect(add_query_arg('game', $id, home_url('/game-play/')));
        exit;
    }

    public static function render() {
        $id = self::game_id();
        if (!$id) {
            return '<main class="tng-game-runtime tng-app-shell"><section class="tng-runtime-empty"><span>🎮</span><h1>Game not found.</h1><p>Choose a published game to start playing.</p><a class="tng-ui-button" href="'.esc_url(home_url('/games/')).'">Browse games</a></section></main>';
        }

        if (!is_user_logged_in()) {
            $return_url = add_query_arg('game', $id, home_url('/game-play/'));
            return '<main class="tng-game-runtime tng-app-shell"><section class="tng-runtime-empty"><span>🎮</span><h1>'.esc_html(get_the_title($id)).'</h1><p>Sign in so your checkpoint progress stays synced to your Explorer profile.</p><a class="tng-ui-button" href="'.esc_url(wp_login_url($return_url)).'">Sign in to play</a></section></main>';
        }

        $checkpoints = self::checkpoints($id);
        $completed = self::completed($id);
        $total = count($checkpoints);
        $done = 0;
        for ($i = 0; $i < $total; $i++) {
            if (in_array($i, $completed, true)) $done++;
        }
        $percent = $total > 0 ? (int) round(($done / $total) * 100) : 0;
        if ($percent > 100) $percent = 100;
        $finished = ($total > 0 && $done >= $total);
        $next = 0;
        if (!$finished) {
            for ($i = 0; $i < $total; $i++) {
                if (!in_array($i, $completed, true)) { $next = $i; break; }
            }
        }

        $xp = get_post_meta($id, 'xp_available', true);
        if ($xp === '') $xp = get_post_meta($id, 'xp', true);
        if ($xp === '') $xp = 'XP';
        $format = get_post_meta($id, 'game_type', true);
        if ($format === '') $format = 'TN Game';

        ob_start(); ?>
        <main class="tng-game-runtime tng-app-shell">
            <section class="tng-runtime-hero">
                <div>
                    <span class="tng-eyebrow"><?php echo esc_html($format); ?></span>
                    <h1><?php echo esc_html(get_the_title($id)); ?></h1>
                    <p><?php echo esc_html($finished ? 'Adventure complete. You finished every checkpoint.' : 'Complete each checkpoint to finish this adventure.'); ?></p>
                </div>
                <div class="tng-runtime-score"><strong><?php echo esc_html($done.'/'.$total); ?></strong><small>Complete</small></div>
            </section>

            <section class="tng-runtime-progress">
                <div><span class="tng-eyebrow"><?php echo esc_html($finished ? 'Game complete' : 'Current progress'); ?></span><h2><?php echo esc_html($finished ? 'You did it!' : 'Next: '.$checkpoints[$next]['title']); ?></h2></div>
                <strong><?php echo esc_html((string)$percent); ?>%</strong>
                <div class="tng-runtime-progressbar"><span style="width:<?php echo esc_attr((string)$percent); ?>%"></span></div>
            </section>

            <div class="tng-runtime-layout">
                <section class="tng-runtime-list">
                    <div class="tng-section__heading"><div><span class="tng-eyebrow">Game route</span><h2>Checkpoints</h2><p>Your progress is saved automatically to your Explorer account.</p></div></div>
                    <?php foreach ($checkpoints as $index => $checkpoint):
                        $is_done = in_array($index, $completed, true);
                        $is_next = (!$finished && $index === $next); ?>
                        <article class="tng-runtime-stop<?php echo $is_done ? ' is-complete' : ($is_next ? ' is-next' : ''); ?>">
                            <div class="tng-runtime-stop__number"><?php echo $is_done ? '✓' : esc_html((string)($index + 1)); ?></div>
                            <div class="tng-runtime-stop__copy">
                                <small><?php echo esc_html($is_done ? 'Completed' : ($is_next ? 'Up next' : 'Locked until previous stop')); ?></small>
                                <h3><?php echo esc_html($checkpoint['title']); ?></h3>
                                <?php if (!empty($checkpoint['instructions'])): ?><p><?php echo esc_html($checkpoint['instructions']); ?></p><?php endif; ?>
                            </div>
                            <?php if ($is_next): ?>
                                <form method="post">
                                    <?php wp_nonce_field('tng_game_runtime_'.$id, 'tng_game_runtime_nonce'); ?>
                                    <input type="hidden" name="game_id" value="<?php echo esc_attr((string)$id); ?>">
                                    <input type="hidden" name="checkpoint" value="<?php echo esc_attr((string)$index); ?>">
                                    <button class="tng-ui-button" name="tng_game_runtime_action" value="complete" type="submit">Complete stop</button>
                                </form>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </section>

                <aside class="tng-runtime-side">
                    <div class="tng-runtime-card">
                        <span class="tng-eyebrow"><?php echo esc_html($finished ? 'Adventure complete' : 'Reward'); ?></span>
                        <h2><?php echo esc_html((string)$xp); ?><?php echo is_numeric($xp) ? ' XP' : ''; ?></h2>
                        <p><?php echo esc_html($finished ? 'This game is recorded as completed on your Explorer account.' : 'Finish all checkpoints to complete the game.'); ?></p>
                        <?php if ($finished): ?><a class="tng-ui-button" href="<?php echo esc_url(home_url('/games/')); ?>">Play another game</a><?php endif; ?>
                    </div>
                    <div class="tng-runtime-card is-light">
                        <h3>Game controls</h3>
                        <a href="<?php echo esc_url(get_permalink($id)); ?>">View game details</a>
                        <form method="post">
                            <?php wp_nonce_field('tng_game_runtime_'.$id, 'tng_game_runtime_nonce'); ?>
                            <input type="hidden" name="game_id" value="<?php echo esc_attr((string)$id); ?>">
                            <button name="tng_game_runtime_action" value="reset" type="submit">Reset progress</button>
                        </form>
                    </div>
                </aside>
            </div>
        </main>
        <?php
        return (string) ob_get_clean();
    }
}
TNG_Game_Runtime_UI::boot();
