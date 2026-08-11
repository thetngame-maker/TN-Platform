<?php
/**
 * Plugin Name: TN Game Progress UI
 * Description: Native Explorer leaderboard and achievements screens for the TN Game app router.
 * Version: 0.1.1
 * Author: The TN Game
 */
if (!defined('ABSPATH')) exit;

final class TNG_Progress_UI {
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

    private static function explorers(): array {
        $query = new WP_User_Query([
            'number' => 100,
            'orderby' => 'registered',
            'order' => 'DESC',
            'fields' => 'all_with_meta',
        ]);
        $rows = [];
        foreach ((array) $query->get_results() as $user) {
            $xp = self::points((int) $user->ID);
            if ($xp <= 0 && !user_can($user, 'manage_options')) continue;
            $rows[] = [
                'id' => (int) $user->ID,
                'name' => $user->display_name ?: $user->user_login,
                'xp' => $xp,
                'avatar' => get_avatar_url((int) $user->ID, ['size' => 128]),
            ];
        }
        usort($rows, static fn(array $a, array $b): int => $b['xp'] <=> $a['xp']);
        return array_slice($rows, 0, 50);
    }

    private static function achievement_posts(): array {
        $types = [];
        if (function_exists('gamipress_get_achievement_types')) {
            $registered = gamipress_get_achievement_types();
            if (is_array($registered)) $types = array_keys($registered);
        }
        foreach (['achievement', 'achievements', 'gamipress-achievement'] as $candidate) {
            if (post_type_exists($candidate)) $types[] = $candidate;
        }
        $types = array_values(array_unique(array_filter($types, 'post_type_exists')));
        if (!$types) return [];
        $query = new WP_Query([
            'post_type' => $types,
            'post_status' => 'publish',
            'posts_per_page' => 24,
            'orderby' => ['menu_order' => 'ASC', 'date' => 'DESC'],
            'ignore_sticky_posts' => true,
        ]);
        return $query->posts;
    }

    private static function earned_ids(int $user_id): array {
        if (!$user_id) return [];
        if (function_exists('gamipress_get_user_achievements')) {
            $earned = gamipress_get_user_achievements(['user_id' => $user_id, 'limit' => -1]);
            $ids = [];
            foreach ((array) $earned as $item) {
                if (is_object($item) && isset($item->achievement_id)) $ids[] = (int) $item->achievement_id;
                elseif (is_array($item) && isset($item['achievement_id'])) $ids[] = (int) $item['achievement_id'];
                elseif (is_numeric($item)) $ids[] = (int) $item;
            }
            return array_values(array_unique(array_filter($ids)));
        }
        $fallback = get_user_meta($user_id, 'gamipress_achievements', true);
        return is_array($fallback) ? array_map('intval', $fallback) : [];
    }

    private static function tabs(string $active): string {
        $items = [
            'leaderboard' => ['🏆', 'Leaderboard', '/leaderboard/'],
            'achievements' => ['⭐', 'Achievements', '/achievements/'],
            'profile' => ['○', 'My profile', '/profile/'],
        ];
        $html = '<nav class="tng-progress-tabs" aria-label="Explorer progression">';
        foreach ($items as $key => $item) {
            $class = $key === $active ? ' is-active' : '';
            $html .= '<a class="' . esc_attr($class) . '" href="' . esc_url(home_url($item[2])) . '"><span>' . esc_html($item[0]) . '</span><strong>' . esc_html($item[1]) . '</strong></a>';
        }
        return $html . '</nav>';
    }

    public static function leaderboard(): string {
        $rows = self::explorers();
        $current_id = get_current_user_id();
        $current_rank = 0;
        foreach ($rows as $index => $row) if ($row['id'] === $current_id) $current_rank = $index + 1;
        ob_start(); ?>
        <main class="tng-progress-screen tng-app-shell">
            <section class="tng-progress-hero">
                <div><span class="tng-eyebrow">Explorer rankings</span><h1>Leaderboard</h1><p>See who is earning XP, completing adventures, and exploring Tennessee.</p></div>
                <div class="tng-progress-hero__badge"><span>🏆</span><strong><?php echo $current_rank ? '#' . esc_html((string) $current_rank) : '—'; ?></strong><small>Your rank</small></div>
            </section>
            <?php echo self::tabs('leaderboard'); ?>
            <section class="tng-leaderboard-panel">
                <div class="tng-progress-heading"><div><span class="tng-eyebrow">Top explorers</span><h2>XP standings</h2></div><span><?php echo esc_html((string) count($rows)); ?> ranked</span></div>
                <?php if ($rows): ?>
                    <ol class="tng-leaderboard-list">
                        <?php foreach ($rows as $index => $row): $rank = $index + 1; ?>
                            <li class="<?php echo $row['id'] === $current_id ? 'is-current' : ''; ?>">
                                <span class="tng-leaderboard-rank"><?php echo $rank <= 3 ? ['🥇','🥈','🥉'][$rank - 1] : '#' . esc_html((string) $rank); ?></span>
                                <span class="tng-leaderboard-avatar"<?php echo $row['avatar'] ? ' style="background-image:url(' . esc_url($row['avatar']) . ')"' : ''; ?>></span>
                                <span class="tng-leaderboard-name"><strong><?php echo esc_html($row['name']); ?></strong><small>Level <?php echo esc_html((string) (max(1, (int) floor($row['xp'] / 500) + 1))); ?></small></span>
                                <span class="tng-leaderboard-xp"><strong><?php echo esc_html(number_format_i18n($row['xp'])); ?></strong><small>XP</small></span>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php else: ?>
                    <div class="tng-progress-empty"><span>🏆</span><h3>The leaderboard is ready.</h3><p>Explorers will appear here as they begin earning XP.</p><a class="tng-ui-button" href="<?php echo esc_url(home_url('/play/')); ?>">Start playing</a></div>
                <?php endif; ?>
            </section>
        </main>
        <?php return (string) ob_get_clean();
    }

    public static function achievements(): string {
        $posts = self::achievement_posts();
        $earned = self::earned_ids(get_current_user_id());
        $earned_count = count(array_intersect($earned, array_map(static fn($post): int => (int) $post->ID, $posts)));
        ob_start(); ?>
        <main class="tng-progress-screen tng-app-shell">
            <section class="tng-progress-hero">
                <div><span class="tng-eyebrow">Explorer milestones</span><h1>Achievements</h1><p>Unlock badges by visiting places, completing trails, sharing photos, and playing games.</p></div>
                <div class="tng-progress-hero__badge"><span>⭐</span><strong><?php echo esc_html((string) $earned_count); ?></strong><small>Unlocked</small></div>
            </section>
            <?php echo self::tabs('achievements'); ?>
            <section class="tng-achievement-panel">
                <div class="tng-progress-heading"><div><span class="tng-eyebrow">Your collection</span><h2>Milestones to earn</h2></div><span><?php echo esc_html((string) count($posts)); ?> available</span></div>
                <?php if ($posts): ?>
                    <div class="tng-achievement-grid">
                        <?php foreach ($posts as $post):
                            $id = (int) $post->ID;
                            $is_earned = in_array($id, $earned, true);
                            $image = get_the_post_thumbnail_url($id, 'medium');
                            $copy = wp_trim_words(wp_strip_all_tags(strip_shortcodes((string) get_post_field('post_content', $id))), 18, '…');
                        ?>
                            <article class="tng-achievement-card <?php echo $is_earned ? 'is-earned' : 'is-locked'; ?>">
                                <div class="tng-achievement-icon"<?php echo $image ? ' style="background-image:url(' . esc_url($image) . ')"' : ''; ?>><?php if (!$image): ?><span><?php echo $is_earned ? '🏆' : '☆'; ?></span><?php endif; ?></div>
                                <div><span class="tng-ui-badge"><?php echo $is_earned ? 'Unlocked' : 'Locked'; ?></span><h3><?php echo esc_html(get_the_title($id)); ?></h3><?php if ($copy): ?><p><?php echo esc_html($copy); ?></p><?php endif; ?></div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="tng-progress-empty"><span>⭐</span><h3>Your achievement gallery is ready.</h3><p>Published GamiPress achievements will automatically appear here.</p><a class="tng-ui-button" href="<?php echo esc_url(home_url('/play/')); ?>">Earn XP</a></div>
                <?php endif; ?>
            </section>
        </main>
        <?php return (string) ob_get_clean();
    }
}
