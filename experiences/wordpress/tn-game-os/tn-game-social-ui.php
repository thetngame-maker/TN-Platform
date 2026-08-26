<?php
/**
 * Plugin Name: TN Game Social UI
 * Description: Native friends and explorer activity screens for The TN Game.
 * Version: 0.1.1
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

    private static function clean_excerpt(int $post_id): string {
        $raw = (string) get_post_field('post_content', $post_id);
        $raw = strip_shortcodes($raw);
        $raw = preg_replace('/\[[^\]]+\]/', ' ', $raw) ?: $raw;
        $raw = wp_strip_all_tags($raw);
        $raw = preg_replace('/\s+/', ' ', $raw) ?: $raw;
        return wp_trim_words(trim($raw), 18, '…');
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
            <section class="tng-social-panel"><div class="tng-social-heading"><div><span class="tng-eyebrow">Community</span><h2>Discover explorers</h2></div><a href="<?php echo esc_url(home_url('/challenges/')); ?>">Start a challenge</a></div>
                <div class="tng-friend-grid">
                    <?php foreach ($users as $user): if ((int)$user->ID === $current) continue; $xp=self::xp((int)$user->ID); $level=max(1,(int)floor($xp/500)+1); ?>
                        <article class="tng-friend-card"><div class="tng-friend-avatar" style="background-image:url('<?php echo esc_url(get_avatar_url($user->ID,['size'=>160])); ?>')"></div><div><h3><?php echo esc_html($user->display_name ?: $user->user_login); ?></h3><p>Level <?php echo esc_html((string)$level); ?> Explorer · <?php echo esc_html(number_format_i18n($xp)); ?> XP</p></div><div class="tng-friend-actions"><a href="<?php echo esc_url(home_url('/leaderboard/')); ?>">View rank</a><a class="is-primary" href="<?php echo esc_url(add_query_arg('opponent',(int)$user->ID,home_url('/challenges/'))); ?>">Challenge</a></div></article>
                    <?php endforeach; ?>
                    <?php if (count($users) <= 1): ?><div class="tng-social-empty"><span>👥</span><h3>Your Explorer community is ready.</h3><p>New TN Game members will appear here automatically.</p></div><?php endif; ?>
                </div>
            </section>
        </main>
        <?php return (string) ob_get_clean();
    }

    public static function activity(): string {
        $filter = sanitize_key(wp_unslash($_GET['feed'] ?? 'all'));
        if (!in_array($filter, ['all','photos','discoveries','milestones'], true)) $filter = 'all';
        $community = class_exists('TNG_OS\\Modules\\Frontend\\Community_Photos');
        $items = $community ? \TNG_OS\Modules\Frontend\Community_Photos::feed_items($filter, 24) : [];
        $places = $community ? \TNG_OS\Modules\Frontend\Community_Photos::upload_places() : [];
        $fallback = !$items ? self::activity_items() : [];
        $feed_count = count($items ?: $fallback);
        ob_start(); ?>
        <main class="tng-social-screen tng-app-shell">
            <section class="tng-social-hero"><div><span class="tng-eyebrow">Explorer community</span><h1>Activity</h1><p>See Tennessee discoveries, completed adventures, earned milestones, and approved photos from the Explorer community.</p></div><div class="tng-social-hero__badge"><span>◉</span><strong><?php echo esc_html((string)$feed_count); ?></strong><small>Updates</small></div></section>
            <nav class="tng-social-tabs"><a href="<?php echo esc_url(home_url('/friends/')); ?>">👥 Friends</a><a class="is-active" href="<?php echo esc_url(home_url('/activity/')); ?>">◉ Activity</a><a href="<?php echo esc_url(home_url('/leaderboard/')); ?>">🏆 Leaderboard</a></nav>
            <?php if (isset($_GET['photo_submitted'])): ?><div class="tng-community-notice is-success">Photo submitted. It will appear here after approval.</div><?php endif; ?>
            <?php if (isset($_GET['photo_error'])): ?><div class="tng-community-notice is-error">That photo could not be submitted. Choose a TN Game place and a JPG, PNG, or WebP under 8 MB.</div><?php endif; ?>
            <section class="tng-community-share">
                <?php if (is_user_logged_in()): ?>
                    <details><summary><span>📸</span><div><strong>Share a Tennessee moment</strong><small>Approved photos earn 25 Explorer XP</small></div><b>Upload</b></summary>
                        <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <?php wp_nonce_field('tng_community_photo_submit'); ?><input type="hidden" name="action" value="tng_community_photo_submit">
                            <label><span>TN Game place</span><select name="object_id" required><option value="">Choose a place…</option><?php foreach ($places as $place): ?><option value="<?php echo esc_attr((string)$place['id']); ?>"><?php echo esc_html($place['title'].' · '.$place['label']); ?></option><?php endforeach; ?></select></label>
                            <label><span>Photo</span><input name="community_photo" type="file" accept="image/jpeg,image/png,image/webp" capture="environment" required></label>
                            <label class="is-wide"><span>Caption <small>(optional)</small></span><textarea name="caption" rows="3" maxlength="280" placeholder="What made this Tennessee moment memorable?"></textarea></label>
                            <button type="submit">Submit for approval</button><p>Only share photos you took or have permission to use.</p>
                        </form>
                    </details>
                <?php else: ?>
                    <a class="tng-community-share__login" href="<?php echo esc_url(wp_login_url(home_url('/activity/'))); ?>"><span>📸</span><div><strong>Share a Tennessee moment</strong><small>Sign in to submit community photos and earn Explorer XP.</small></div><b>Sign in</b></a>
                <?php endif; ?>
            </section>
            <section class="tng-social-panel"><div class="tng-social-heading"><div><span class="tng-eyebrow">What’s new</span><h2>Explorer feed</h2></div><a href="<?php echo esc_url(home_url('/explore/')); ?>">Explore all</a></div>
                <nav class="tng-feed-filters" aria-label="Activity filters"><?php foreach (['all'=>'All','photos'=>'Photos','discoveries'=>'Discoveries','milestones'=>'Milestones'] as $key=>$label): ?><a class="<?php echo $filter===$key?'is-active':''; ?>" href="<?php echo esc_url($key==='all'?home_url('/activity/'):add_query_arg('feed',$key,home_url('/activity/'))); ?>"><?php echo esc_html($label); ?></a><?php endforeach; ?></nav>
                <div class="tng-activity-feed">
                    <?php foreach ($items as $item): ?>
                        <article class="tng-feed-card <?php echo $item['type']==='photo'?'is-photo':''; ?>"><a class="tng-feed-card__media" href="<?php echo esc_url($item['url']); ?>"<?php echo !empty($item['media'])?' style="background-image:url(\''.esc_url($item['media']).'\')"':''; ?>><?php if (empty($item['media'])): ?><span><?php echo esc_html($item['icon']??'◉'); ?></span><?php endif; ?></a><div class="tng-feed-card__body"><header><img src="<?php echo esc_url($item['avatar']); ?>" alt=""><div><strong><?php echo esc_html($item['user']); ?></strong><small><?php echo esc_html($item['time']); ?></small></div><?php if (!empty($item['xp'])): ?><b>+<?php echo esc_html((string)$item['xp']); ?> XP</b><?php endif; ?></header><a href="<?php echo esc_url($item['url']); ?>"><h3><?php echo esc_html($item['title']); ?></h3><?php if (!empty($item['copy'])): ?><p><?php echo esc_html($item['copy']); ?></p><?php endif; ?><span>View adventure →</span></a></div></article>
                    <?php endforeach; ?>
                    <?php foreach ($fallback as $item): $image=get_the_post_thumbnail_url($item->ID,'medium'); ?><a class="tng-activity-item" href="<?php echo esc_url(get_permalink($item->ID)); ?>"><span class="tng-activity-media"<?php echo $image?' style="background-image:url('.esc_url($image).')"':''; ?>></span><div><small>Recently updated</small><strong><?php echo esc_html(get_the_title($item)); ?></strong><p><?php echo esc_html(self::clean_excerpt((int)$item->ID)); ?></p></div><em>→</em></a><?php endforeach; ?>
                    <?php if (!$items && !$fallback): ?><div class="tng-social-empty"><span>◉</span><h3>Your Explorer feed is ready.</h3><p>Approved photos and Explorer milestones will appear here automatically.</p></div><?php endif; ?>
                </div>
            </section>
        </main>
        <?php return (string) ob_get_clean();
    }
}
