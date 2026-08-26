<?php
/**
 * Plugin Name: TN Game Challenges UI
 * Description: Native challenge dashboard for The TN Game app router.
 * Version: 0.1.0
 * Author: The TN Game
 */
if (!defined('ABSPATH')) exit;

final class TNG_Challenges_UI {
    private static function xp(int $user_id): int {
        if (function_exists('gamipress_get_user_points')) {
            foreach (['xp','explorer-xp','points'] as $type) {
                $value = (int) gamipress_get_user_points($user_id, $type);
                if ($value > 0) return $value;
            }
        }
        foreach (['tng_xp','gamipress_xp','_gamipress_xp'] as $key) {
            $value = (int) get_user_meta($user_id, $key, true);
            if ($value > 0) return $value;
        }
        return 0;
    }

    private static function opponent(): ?WP_User {
        $id = isset($_GET['opponent']) ? absint($_GET['opponent']) : 0;
        if (!$id || $id === get_current_user_id()) return null;
        $user = get_user_by('id', $id);
        return $user instanceof WP_User ? $user : null;
    }

    private static function trails(): array {
        $types = array_values(array_filter(['st_activity','activity'], 'post_type_exists'));
        if (!$types) return [];
        $query = new WP_Query([
            'post_type'=>$types,
            'post_status'=>'publish',
            'posts_per_page'=>3,
            'ignore_sticky_posts'=>true,
            'orderby'=>'modified',
            'order'=>'DESC',
        ]);
        return $query->posts;
    }

    public static function render(): string {
        $opponent = self::opponent();
        $trails = self::trails();
        $current = wp_get_current_user();
        $signed_in = is_user_logged_in();
        ob_start(); ?>
        <main class="tng-challenge-screen tng-app-shell">
            <section class="tng-challenge-hero">
                <div><span class="tng-eyebrow">Play together</span><h1>Challenges</h1><p>Create a friendly competition, choose an adventure, and see who earns the most XP.</p></div>
                <div class="tng-challenge-hero__badge"><span>⚔️</span><strong><?php echo $opponent ? '1v1' : 'XP'; ?></strong><small><?php echo $opponent ? 'Challenge ready' : 'Compete and explore'; ?></small></div>
            </section>

            <nav class="tng-challenge-tabs"><a class="is-active" href="<?php echo esc_url(home_url('/challenges/')); ?>">⚔️ Challenges</a><a href="<?php echo esc_url(home_url('/friends/')); ?>">👥 Friends</a><a href="<?php echo esc_url(home_url('/leaderboard/')); ?>">🏆 Leaderboard</a></nav>

            <?php if ($opponent): ?>
                <section class="tng-matchup-card">
                    <div class="tng-matchup-player"><span style="background-image:url('<?php echo esc_url(get_avatar_url($current->ID,['size'=>160])); ?>')"></span><strong><?php echo esc_html($current->display_name ?: 'You'); ?></strong><small><?php echo esc_html(number_format_i18n(self::xp((int)$current->ID))); ?> XP</small></div>
                    <div class="tng-matchup-vs"><span>VS</span><strong>Choose the challenge</strong><small>The invitation system will connect here next.</small></div>
                    <div class="tng-matchup-player"><span style="background-image:url('<?php echo esc_url(get_avatar_url($opponent->ID,['size'=>160])); ?>')"></span><strong><?php echo esc_html($opponent->display_name ?: $opponent->user_login); ?></strong><small><?php echo esc_html(number_format_i18n(self::xp((int)$opponent->ID))); ?> XP</small></div>
                </section>
            <?php elseif (!$signed_in): ?>
                <section class="tng-challenge-signin"><div><span>⚔️</span><h2>Sign in to challenge friends.</h2><p>Your challenges, scores, and XP will stay connected to your Explorer profile.</p></div><a class="tng-ui-button" href="<?php echo esc_url(wp_login_url(home_url('/challenges/'))); ?>">Sign in</a></section>
            <?php endif; ?>

            <section class="tng-challenge-panel">
                <div class="tng-challenge-heading"><div><span class="tng-eyebrow">Choose a format</span><h2>How do you want to compete?</h2></div></div>
                <div class="tng-challenge-types">
                    <a href="<?php echo esc_url(home_url('/play/')); ?>"><span>⚡</span><div><strong>XP Sprint</strong><small>Earn the most XP in one session</small></div><em>Play now</em></a>
                    <a href="<?php echo esc_url(home_url('/trails/')); ?>"><span>🥾</span><div><strong>Trail Race</strong><small>Complete the same trail and checkpoints</small></div><em>Pick a trail</em></a>
                    <a href="<?php echo esc_url(home_url('/map/')); ?>"><span>📍</span><div><strong>Top Sight Hunt</strong><small>Visit the most checkpoints</small></div><em>Open map</em></a>
                    <a href="<?php echo esc_url(home_url('/play/')); ?>"><span>👥</span><div><strong>Group Game</strong><small>Build a challenge for your whole group</small></div><em>Create game</em></a>
                </div>
            </section>

            <section class="tng-challenge-panel">
                <div class="tng-challenge-heading"><div><span class="tng-eyebrow">Suggested adventures</span><h2>Start with one of these</h2></div><a href="<?php echo esc_url(home_url('/trails/')); ?>">View all trails</a></div>
                <div class="tng-challenge-adventures">
                    <?php foreach ($trails as $trail): $image=get_the_post_thumbnail_url($trail->ID,'large'); ?>
                        <a href="<?php echo esc_url(get_permalink($trail->ID)); ?>"><span<?php echo $image ? ' style="background-image:url('.esc_url($image).')"' : ''; ?>></span><div><small>Adventure challenge</small><strong><?php echo esc_html(get_the_title($trail)); ?></strong><em>View challenge →</em></div></a>
                    <?php endforeach; ?>
                    <?php if (!$trails): ?><div class="tng-challenge-empty">Playable adventures will appear here automatically.</div><?php endif; ?>
                </div>
            </section>
        </main>
        <?php return (string) ob_get_clean();
    }
}
