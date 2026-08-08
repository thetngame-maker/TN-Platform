<?php
/**
 * Plugin Name: TN Game Profile UI
 * Description: Native Explorer profile dashboard for the TN Game app router.
 * Version: 0.1.1
 * Author: The TN Game
 */
if (!defined('ABSPATH')) exit;

final class TNG_Profile_UI {
    private static function points_type(): string {
        $configured = sanitize_key((string) get_option('tng_gamipress_points_type', ''));
        if ($configured !== '') return $configured;
        if (!function_exists('gamipress_get_points_types')) return '';
        $types = gamipress_get_points_types();
        if (!is_array($types) || empty($types)) return '';
        foreach (['explorer-xp', 'xp', 'points'] as $preferred) {
            if (isset($types[$preferred])) return $preferred;
        }
        foreach ($types as $slug => $data) {
            $text = strtolower((string) $slug . ' ' . wp_json_encode($data));
            if (strpos($text, 'explorer') !== false && strpos($text, 'xp') !== false) return sanitize_key((string) $slug);
        }
        if (count($types) === 1) return sanitize_key((string) array_key_first($types));
        return '';
    }

    private static function points(int $user_id): int {
        if (!$user_id) return 0;
        if (function_exists('gamipress_get_user_points')) {
            $type = self::points_type();
            if ($type !== '') return max(0, (int) gamipress_get_user_points($user_id, $type));
        }
        foreach (['tng_xp', 'gamipress_xp', '_gamipress_xp'] as $key) {
            $value = (int) get_user_meta($user_id, $key, true);
            if ($value > 0) return $value;
        }
        return 0;
    }

    private static function count_meta(int $user_id, array $keys): int {
        foreach ($keys as $key) {
            $value = get_user_meta($user_id, $key, true);
            if (is_array($value)) return count($value);
            if (is_numeric($value) && (int) $value > 0) return (int) $value;
        }
        return 0;
    }

    private static function recent_items(): array {
        $types = array_values(array_filter(['st_activity','activity','top_sight'], 'post_type_exists'));
        if (!$types) return [];
        $query = new WP_Query([
            'post_type' => $types,
            'post_status' => 'publish',
            'posts_per_page' => 3,
            'ignore_sticky_posts' => true,
            'orderby' => 'modified',
            'order' => 'DESC',
        ]);
        return $query->posts;
    }

    public static function render(): string {
        $logged_in = is_user_logged_in();
        $user = $logged_in ? wp_get_current_user() : null;
        $user_id = $logged_in ? (int) $user->ID : 0;
        $name = $logged_in ? ($user->display_name ?: $user->user_login) : 'Explorer';
        $xp = self::points($user_id);
        $level = max(1, (int) floor($xp / 500) + 1);
        $level_floor = ($level - 1) * 500;
        $level_progress = min(100, max(0, (int) round((($xp - $level_floor) / 500) * 100)));
        $completed = self::count_meta($user_id, ['tng_completed_trails','tng_completed_adventures','completed_trails']);
        $photos = self::count_meta($user_id, ['tng_photo_count','tng_approved_photos','photo_count']);
        $achievements = self::count_meta($user_id, ['tng_achievement_count','gamipress_achievements','achievement_count']);
        $friends = self::count_meta($user_id, ['tng_friend_count','friends_count']);
        $recent = self::recent_items();
        $avatar = $logged_in ? get_avatar_url($user_id, ['size' => 192]) : '';
        ob_start(); ?>
        <main class="tng-profile-screen tng-app-shell">
            <section class="tng-profile-hero">
                <div class="tng-profile-avatar"<?php echo $avatar ? ' style="background-image:url(' . esc_url($avatar) . ')"' : ''; ?>><?php if (!$avatar): ?><span>TN</span><?php endif; ?></div>
                <div class="tng-profile-copy">
                    <span class="tng-eyebrow">Explorer profile</span>
                    <h1><?php echo esc_html($name); ?></h1>
                    <p><?php echo $logged_in ? 'Your Tennessee adventures, achievements, photos, and progress in one place.' : 'Create an account to earn XP, save adventures, upload photos, and compete with friends.'; ?></p>
                </div>
                <div class="tng-profile-actions">
                    <?php if ($logged_in): ?>
                        <a class="tng-ui-button tng-ui-button--secondary" href="<?php echo esc_url(home_url('/profile-settings/')); ?>">Edit profile</a>
                        <a class="tng-ui-button" href="<?php echo esc_url(home_url('/play/')); ?>">Keep exploring</a>
                    <?php else: ?>
                        <a class="tng-ui-button tng-ui-button--secondary" href="<?php echo esc_url(wp_login_url(home_url('/profile/'))); ?>">Sign in</a>
                        <a class="tng-ui-button" href="<?php echo esc_url(wp_registration_url()); ?>">Create account</a>
                    <?php endif; ?>
                </div>
            </section>

            <section class="tng-profile-level">
                <div class="tng-level-badge"><small>Level</small><strong><?php echo esc_html((string) $level); ?></strong></div>
                <div class="tng-level-copy"><div><span class="tng-eyebrow">Explorer XP</span><h2><?php echo esc_html(number_format_i18n($xp)); ?> XP</h2></div><p><?php echo esc_html(number_format_i18n(max(0, ($level * 500) - $xp))); ?> XP to Level <?php echo esc_html((string) ($level + 1)); ?></p><div class="tng-profile-progress"><span style="width:<?php echo esc_attr((string) $level_progress); ?>%"></span></div></div>
            </section>

            <section class="tng-profile-stats" aria-label="Explorer statistics">
                <a href="<?php echo esc_url(home_url('/achievements/')); ?>"><span>🏆</span><strong><?php echo esc_html((string) $achievements); ?></strong><small>Achievements</small></a>
                <a href="<?php echo esc_url(home_url('/completed/')); ?>"><span>🥾</span><strong><?php echo esc_html((string) $completed); ?></strong><small>Adventures</small></a>
                <a href="<?php echo esc_url(home_url('/my-photos/')); ?>"><span>📸</span><strong><?php echo esc_html((string) $photos); ?></strong><small>Photos</small></a>
                <a href="<?php echo esc_url(home_url('/friends/')); ?>"><span>👥</span><strong><?php echo esc_html((string) $friends); ?></strong><small>Friends</small></a>
            </section>

            <section class="tng-profile-grid">
                <article class="tng-profile-panel">
                    <div class="tng-profile-panel__heading"><div><span class="tng-eyebrow">Your journey</span><h2>Recent activity</h2></div><a href="<?php echo esc_url(home_url('/explorer-journal/')); ?>">View journal</a></div>
                    <div class="tng-profile-timeline">
                        <?php if ($recent): foreach ($recent as $item): ?>
                            <a href="<?php echo esc_url(get_permalink($item->ID)); ?>"><span class="tng-profile-timeline__dot"></span><div><strong><?php echo esc_html(get_the_title($item)); ?></strong><small>Ready for your next visit</small></div><em>→</em></a>
                        <?php endforeach; else: ?>
                            <div class="tng-profile-empty">Your completed adventures and XP activity will appear here.</div>
                        <?php endif; ?>
                    </div>
                </article>

                <article class="tng-profile-panel">
                    <div class="tng-profile-panel__heading"><div><span class="tng-eyebrow">Next goals</span><h2>Keep progressing</h2></div></div>
                    <div class="tng-profile-goals">
                        <a href="<?php echo esc_url(home_url('/play/')); ?>"><span>🎮</span><div><strong>Complete a game</strong><small>Earn XP from checkpoints</small></div></a>
                        <a href="<?php echo esc_url(home_url('/map/')); ?>"><span>📍</span><div><strong>Visit a Top Sight</strong><small>Discover somewhere new</small></div></a>
                        <a href="<?php echo esc_url(home_url('/my-photos/')); ?>"><span>📸</span><div><strong>Share a photo</strong><small>Build your Explorer story</small></div></a>
                    </div>
                </article>
            </section>
        </main>
        <?php return (string) ob_get_clean();
    }
}
