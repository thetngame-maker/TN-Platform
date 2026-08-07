<?php
/**
 * Plugin Name: TN Game Runtime UI
 * Description: Native checkpoint runtime and saved progress for playable TN Games.
 * Version: 0.1.0
 * Author: The TN Game
 */
if (!defined('ABSPATH')) exit;

final class TNG_Game_Runtime_UI {
    public static function boot(): void {
        add_action('template_redirect', [self::class, 'handle_action'], 2);
    }

    private static function game_id(): int {
        $id = absint($_GET['game'] ?? $_POST['game_id'] ?? 0);
        return $id && get_post_status($id) ? $id : 0;
    }

    private static function checkpoints(int $id): array {
        $saved = get_post_meta($id, 'tng_game_checkpoints', true);
        if (is_array($saved) && $saved) {
            $out = [];
            foreach ($saved as $index => $item) {
                if (is_array($item)) {
                    $title = sanitize_text_field((string)($item['title'] ?? 'Checkpoint '.($index + 1)));
                    $instructions = sanitize_textarea_field((string)($item['instructions'] ?? ''));
                } else {
                    $title = sanitize_text_field((string)$item);
                    $instructions = '';
                }
                if ($title !== '') $out[] = ['title' => $title, 'instructions' => $instructions];
            }
            if ($out) return $out;
        }
        $count = max(1, min(30, absint(get_post_meta($id, 'checkpoint_count', true) ?: 1)));
        $out = [];
        for ($i = 1; $i <= $count; $i++) $out[] = ['title' => 'Checkpoint '.$i, 'instructions' => 'Follow the game instructions to complete this stop.'];
        return $out;
    }

    private static function meta_key(int $id): string { return '_tng_game_progress_'.$id; }

    private static function completed(int $id): array {
        if (!is_user_logged_in()) return [];
        $value = get_user_meta(get_current_user_id(), self::meta_key($id), true);
        if (!is_array($value)) return [];
        return array_values(array_unique(array_map('absint', $value)));
    }

    private static function mark_game_complete(int $id): void {
        $uid = get_current_user_id();
        $games = get_user_meta($uid, '_tng_completed_games', true);
        if (!is_array($games)) $games = [];
        if (!in_array($id, array_map('absint', $games), true)) {
            $games[] = $id;
            update_user_meta($uid, '_tng_completed_games', array_values(array_unique(array_map('absint', $games))));
        }
        update_user_meta($uid, '_tng_game_completed_at_'.$id, current_time('mysql'));
    }

    public static function handle_action(): void {
        if (empty($_POST['tng_game_runtime_action'])) return;
        if (!is_user_logged_in()) return;
        $id = self::game_id();
        if (!$id) return;
        check_admin_referer('tng_game_runtime_'.$id, 'tng_game_runtime_nonce');
        $action = sanitize_key(wp_unslash($_POST['tng_game_runtime_action']));
        $completed = self::completed($id);
        $checkpoints = self::checkpoints($id);

        if ($action === 'complete') {
            $index = absint($_POST['checkpoint'] ?? 0);
            if ($index < count($checkpoints)) $completed[] = $index;
            $completed = array_values(array_unique(array_map('absint', $completed)));
            update_user_meta(get_current_user_id(), self::meta_key($id), $completed);
            if (count($completed) >= count($checkpoints)) self::mark_game_complete($id);
        } elseif ($action === 'reset') {
            delete_user_meta(get_current_user_id(), self::meta_key($id));
            $games = get_user_meta(get_current_user_id(), '_tng_completed_games', true);
            if (is_array($games)) {
                $games = array_values(array_filter(array_map('absint', $games), static fn($game_id) => $game_id !== $id));
                update_user_meta(get_current_user_id(), '_tng_completed_games', $games);
            }
            delete_user_meta(get_current_user_id(), '_tng_game_completed_at_'.$id);
        }
        wp_safe_redirect(add_query_arg('game', $id, home_url('/game-play/')));
        exit;
    }

    public static function render(): string {
        $id = self::game_id();
        if (!$id || !class_exists('TNG_Games_UI') || !TNG_Games_UI::is_game($id)) {
            return '<main class="tng-game-runtime tng-app-shell"><section class="tng-runtime-empty"><span>🎮</span><h1>Game not found.</h1><p>Choose a published game to start playing.</p><a class="tng-ui-button" href="'.esc_url(home_url('/games/')).'">Browse games</a></section></main>';
        }
        if (!is_user_logged_in()) {
            return '<main class="tng-game-runtime tng-app-shell"><section class="tng-runtime-empty"><span>🎮</span><h1>'.esc_html(get_the_title($id)).'</h1><p>Sign in so your checkpoint progress can stay synced to your Explorer profile.</p><a class="tng-ui-button" href="'.esc_url(wp_login_url(add_query_arg('game',$id,home_url('/game-play/')))).'">Sign in to play</a></section></main>';
        }

        $checkpoints = self::checkpoints($id);
        $completed = self::completed($id);
        $total = count($checkpoints);
        $done = count(array_intersect(range(0, max(0,$total-1)), $completed));
        $percent = $total ? min(100, (int)round(($done / $total) * 100)) : 0;
        $finished = $done >= $total;
        $next = 0;
        for ($i=0; $i<$total; $i++) { if (!in_array($i,$completed,true)) { $next=$i; break; } }
        $xp = get_post_meta($id,'xp_available',true) ?: get_post_meta($id,'xp',true) ?: 'XP';
        $format = get_post_meta($id,'game_type',true) ?: 'TN Game';
        ob_start(); ?>
        <main class="tng-game-runtime tng-app-shell">
            <section class="tng-runtime-hero"><div><span class="tng-eyebrow"><?php echo esc_html($format); ?></span><h1><?php echo esc_html(get_the_title($id)); ?></h1><p><?php echo $finished ? 'Adventure complete. You finished every checkpoint.' : 'Complete each checkpoint to finish this adventure.'; ?></p></div><div class="tng-runtime-score"><strong><?php echo esc_html($done.'/'.$total); ?></strong><small>Complete</small></div></section>
            <section class="tng-runtime-progress"><div><span class="tng-eyebrow"><?php echo $finished ? 'Game complete' : 'Current progress'; ?></span><h2><?php echo $finished ? 'You did it!' : 'Next: '.esc_html($checkpoints[$next]['title']); ?></h2></div><strong><?php echo esc_html((string)$percent); ?>%</strong><div class="tng-runtime-progressbar"><span style="width:<?php echo esc_attr((string)$percent); ?>%"></span></div></section>
            <div class="tng-runtime-layout"><section class="tng-runtime-list"><div class="tng-section__heading"><div><span class="tng-eyebrow">Game route</span><h2>Checkpoints</h2><p>Your progress is saved automatically to your Explorer account.</p></div></div>
            <?php foreach ($checkpoints as $index => $checkpoint): $is_done=in_array($index,$completed,true); $is_next=!$finished && $index===$next; ?>
                <article class="tng-runtime-stop<?php echo $is_done?' is-complete':($is_next?' is-next':''); ?>"><div class="tng-runtime-stop__number"><?php echo $is_done?'✓':esc_html((string)($index+1)); ?></div><div class="tng-runtime-stop__copy"><small><?php echo $is_done?'Completed':($is_next?'Up next':'Locked until previous stop'); ?></small><h3><?php echo esc_html($checkpoint['title']); ?></h3><?php if ($checkpoint['instructions']): ?><p><?php echo esc_html($checkpoint['instructions']); ?></p><?php endif; ?></div><?php if ($is_next): ?><form method="post"><?php wp_nonce_field('tng_game_runtime_'.$id,'tng_game_runtime_nonce'); ?><input type="hidden" name="game_id" value="<?php echo esc_attr((string)$id); ?>"><input type="hidden" name="checkpoint" value="<?php echo esc_attr((string)$index); ?>"><button class="tng-ui-button" name="tng_game_runtime_action" value="complete" type="submit">Complete stop</button></form><?php endif; ?></article>
            <?php endforeach; ?></section>
            <aside class="tng-runtime-side"><div class="tng-runtime-card"><span class="tng-eyebrow"><?php echo $finished?'Adventure complete':'Reward'; ?></span><h2><?php echo esc_html((string)$xp); ?><?php echo is_numeric($xp)?' XP':''; ?></h2><p><?php echo $finished?'This game is recorded as completed on your Explorer account.':'Finish all checkpoints to complete the game.'; ?></p><?php if ($finished): ?><a class="tng-ui-button" href="<?php echo esc_url(home_url('/games/')); ?>">Play another game</a><?php endif; ?></div><div class="tng-runtime-card is-light"><h3>Game controls</h3><a href="<?php echo esc_url(get_permalink($id)); ?>">View game details</a><form method="post" onsubmit="return confirm('Reset your progress for this game?');"><?php wp_nonce_field('tng_game_runtime_'.$id,'tng_game_runtime_nonce'); ?><input type="hidden" name="game_id" value="<?php echo esc_attr((string)$id); ?>"><button name="tng_game_runtime_action" value="reset" type="submit">Reset progress</button></form></div></aside></div>
        </main>
        <?php return (string)ob_get_clean();
    }
}
TNG_Game_Runtime_UI::boot();
