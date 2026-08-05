<?php
/**
 * Plugin Name: TN Game Social UI
 * Description: Native friends and explorer activity screens for The TN Game.
 * Version: 0.1.0
 * Author: The TN Game
 */
if (!defined('ABSPATH')) exit;

final class TNG_Social_UI {
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

    private static function explorers(): array {
        $users = get_users(['number'=>24,'orderby'=>'registered','order'=>'DESC','fields'=>'all']);
        usort($users, static fn($a,$b) => self::xp((int)$b->ID) <=> self::xp((int)$a->ID));
        return $users;
    }

    private static function activity_items(): array {
        $types = array_values(array_filter(['st_activity','activity','top_sight','tng_destination'], 'post_type_exists'));
        if (!$types) return [];
        $query = new WP_Query(['post_type'=>$types,'post_status'=>'publish','posts_per_page'=>10,'orderby'=>'modified','order'=>'DESC','ignore_sticky_posts'=>true]);
        return $query->posts;
    }

    public static function friends(): string {
        $current = get_current_user_id();
        $users = self::explorers();
        ob_start(); ?>
        <main class="tng-social-screen tng-app-shell">
            <section class="tng-social-hero"><div><span class="tng-eyebrow">Explore together</span><h1>Friends</h1><p>Find other explorers, compare progress, and challenge your group to a Tennessee adventure.</p></div><div class="tng-social-hero__badge"><span>👥</span><strong><?php echo esc_html((string) max(0,count($users)-1)); ?></strong><small>Explorers</small></div></section>
            <nav class="tng-social-tabs"><a class="is-active" href="<?php echo esc_url(home_url('/friends/')); ?>">👥 Friends</a><a href="<?php echo esc_url(home_url('/activity/')); ?>">◉ Activity</a><a href="<?php echo esc_url(home_url('/leaderboard/')); ?>">🏆 Leaderboard</a></nav>
            <section class="tng-social-panel"><div class="tng-social-heading"><div><span class="tng-eyebrow">Community</span><h2>Discover explorers</h2></div><a href="<?php echo esc_url(home_url('/play/')); ?>">Start a challenge</a></div>
                <div class="tng-friend-grid">
                    <?php foreach ($users as $user): if ((int)$user->ID === $current) continue; $xp=self::xp((int)$user->ID); $level=max(1,(int)floor($xp/500)+1); ?>
                        <article class="tng-friend-card"><div class="tng-friend-avatar" style="background-image:url('<?php echo esc_url(get_avatar_url($user->ID,['size'=>160])); ?>')"></div><div><h3><?php echo esc_html($user->display_name ?: $user->user_login); ?></h3><p>Level <?php echo esc_html((string)$level); ?> Explorer · <?php echo esc_html(number_format_i18n($xp)); ?> XP</p></div><div class="tng-friend-actions"><a href="<?php echo esc_url(home_url('/leaderboard/')); ?>">View rank</a><a class="is-primary" href="<?php echo esc_url(home_url('/play/')); ?>">Challenge</a></div></article>
                    <?php endforeach; ?>
                    <?php if (count($users) <= 1): ?><div class="tng-social-empty"><span>👥</span><h3>Your Explorer community is ready.</h3><p>New TN Game members will appear here automatically.</p></div><?php endif; ?>
                </div>
            </section>
        </main>
        <?php return (string) ob_get_clean();
    }

    public static function activity(): string {
        $items = self::activity_items();
        ob_start(); ?>
        <main class="tng-social-screen tng-app-shell">
            <section class="tng-social-hero"><div><span class="tng-eyebrow">Explorer community</span><h1>Activity</h1><p>See newly added adventures, recently updated places, and milestones worth exploring next.</p></div><div class="tng-social-hero__badge"><span>◉</span><strong><?php echo esc_html((string)count($items)); ?></strong><small>Updates</small></div></section>
            <nav class="tng-social-tabs"><a href="<?php echo esc_url(home_url('/friends/')); ?>">👥 Friends</a><a class="is-active" href="<?php echo esc_url(home_url('/activity/')); ?>">◉ Activity</a><a href="<?php echo esc_url(home_url('/leaderboard/')); ?>">🏆 Leaderboard</a></nav>
            <section class="tng-social-panel"><div class="tng-social-heading"><div><span class="tng-eyebrow">What’s new</span><h2>Explorer feed</h2></div><a href="<?php echo esc_url(home_url('/explore/')); ?>">Explore all</a></div>
                <div class="tng-activity-feed">
                    <?php foreach ($items as $item): $image=get_the_post_thumbnail_url($item->ID,'medium'); ?>
                        <a class="tng-activity-item" href="<?php echo esc_url(get_permalink($item->ID)); ?>"><span class="tng-activity-media"<?php echo $image ? ' style="background-image:url('.esc_url($image).')"' : ''; ?>></span><div><small>Recently updated</small><strong><?php echo esc_html(get_the_title($item)); ?></strong><p><?php echo esc_html(wp_trim_words(wp_strip_all_tags(strip_shortcodes((string)get_post_field('post_content',$item->ID))),18,'…')); ?></p></div><em>→</em></a>
                    <?php endforeach; ?>
                    <?php if (!$items): ?><div class="tng-social-empty"><span>◉</span><h3>Your Explorer feed is ready.</h3><p>New activity will appear here as the platform grows.</p></div><?php endif; ?>
                </div>
            </section>
        </main>
        <?php return (string) ob_get_clean();
    }
}
