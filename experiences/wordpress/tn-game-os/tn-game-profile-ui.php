<?php
/**
 * Component Name: TN Game Profile UI
 * Description: Explorer Profile 2.0 dashboard for the TN Game app router.
 * Version: 0.3.0
 * Author: The TN Game
 */
if (!defined('ABSPATH')) exit;

final class TNG_Profile_UI {
    private static function level_title(int $level): string {
        if ($level >= 10) return 'Grand Tennessee Explorer';
        if ($level >= 7) return 'Volunteer Pathfinder';
        if ($level >= 5) return 'Tennessee Explorer';
        if ($level >= 3) return 'Ridge Walker';
        if ($level >= 2) return 'Trail Scout';
        return 'New Explorer';
    }

    private static function points_type(): string {
        $configured = sanitize_key((string) get_option('tng_gamipress_points_type', ''));
        if ($configured !== '') return $configured;
        if (!function_exists('gamipress_get_points_types')) return '';
        $types = gamipress_get_points_types();
        if (!is_array($types) || empty($types)) return '';
        foreach (['explorer-xp', 'xp', 'points'] as $preferred) if (isset($types[$preferred])) return $preferred;
        foreach ($types as $slug => $data) {
            $text = strtolower((string) $slug . ' ' . wp_json_encode($data));
            if (strpos($text, 'explorer') !== false && strpos($text, 'xp') !== false) return sanitize_key((string) $slug);
        }
        return count($types) === 1 ? sanitize_key((string) array_key_first($types)) : '';
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
            if (is_array($value) && $value) return count($value);
            if (is_numeric($value) && (int) $value > 0) return (int) $value;
        }
        return 0;
    }

    private static function earned_achievement_ids(int $user_id): array {
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
        return is_array($fallback) ? array_values(array_unique(array_map('intval', $fallback))) : [];
    }

    private static function achievement_posts(): array {
        $types = [];
        if (function_exists('gamipress_get_achievement_types')) {
            $registered = gamipress_get_achievement_types();
            if (is_array($registered)) $types = array_keys($registered);
        }
        foreach (['achievement','achievements','gamipress-achievement'] as $candidate) if (post_type_exists($candidate)) $types[] = $candidate;
        $types = array_values(array_unique(array_filter($types, 'post_type_exists')));
        if (!$types) return [];
        $q = new WP_Query(['post_type'=>$types,'post_status'=>'publish','posts_per_page'=>50,'orderby'=>['menu_order'=>'ASC','date'=>'ASC'],'ignore_sticky_posts'=>true]);
        return $q->posts;
    }

    private static function next_achievement(int $user_id, array $earned): array {
        foreach (self::achievement_posts() as $post) {
            $id = (int) $post->ID;
            if (in_array($id, $earned, true)) continue;
            $copy = wp_trim_words(wp_strip_all_tags(strip_shortcodes((string) get_post_field('post_content', $id))), 15, '…');
            return ['id'=>$id,'title'=>get_the_title($id),'description'=>$copy ?: 'Keep exploring to unlock this milestone.','url'=>home_url('/achievements/')];
        }
        return [];
    }

    private static function rank_name(int $user_id): string {
        if (function_exists('gamipress_get_rank_types') && function_exists('gamipress_get_user_rank_id')) {
            $types = gamipress_get_rank_types();
            if (is_array($types) && $types) {
                $type = (string) array_key_first($types);
                $rank_id = $type ? absint(gamipress_get_user_rank_id($user_id, $type)) : 0;
                if ($rank_id && get_post($rank_id)) return get_the_title($rank_id);
            }
        }
        return 'Explorer';
    }

    private static function explorer_stats(int $user_id): array {
        $stats = apply_filters('tng_os_explorer_profile_stats', [], $user_id);
        return is_array($stats) ? $stats : [];
    }

    private static function recent_activity(int $user_id): array {
        $events = apply_filters('tng_os_adventure_journal_events', [], $user_id);
        $events = is_array($events) ? $events : [];
        usort($events, static fn($a,$b) => (strtotime($b['date'] ?? '') ?: 0) <=> (strtotime($a['date'] ?? '') ?: 0));
        $out = [];
        foreach (array_slice($events, 0, 4) as $event) {
            $meta = is_array($event['meta'] ?? null) ? $event['meta'] : [];
            $out[] = [
                'title' => sanitize_text_field($event['title'] ?? 'Explorer activity'),
                'description' => sanitize_text_field($event['description'] ?? ''),
                'url' => esc_url_raw($meta['url'] ?? home_url('/explorer-journal/')),
            ];
        }
        if ($out) return $out;
        $types = array_values(array_filter(['st_activity','activity','top_sight'], 'post_type_exists'));
        if (!$types) return [];
        $q = new WP_Query(['post_type'=>$types,'post_status'=>'publish','posts_per_page'=>3,'ignore_sticky_posts'=>true,'orderby'=>'modified','order'=>'DESC']);
        foreach ($q->posts as $item) $out[] = ['title'=>get_the_title($item),'description'=>'Ready for your next visit','url'=>get_permalink($item)];
        return $out;
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
        $xp_to_next = max(0, ($level * 500) - $xp);
        $stats = self::explorer_stats($user_id);
        $earned_ids = self::earned_achievement_ids($user_id);
        $achievements = count($earned_ids) + absint($stats['native_achievements'] ?? 0);
        $completed_games = absint($stats['completed_games'] ?? 0);
        $completed_trips = absint($stats['completed_trips'] ?? $stats['trips'] ?? 0);
        $completed_legacy = self::count_meta($user_id, ['tng_completed_trails','tng_completed_adventures','completed_trails']);
        $completed = max($completed_legacy, $completed_games + $completed_trips, $completed_games, $completed_trips);
        $checkpoints = max(absint($stats['game_checkpoints'] ?? 0), absint($stats['checkpoints'] ?? 0));
        $photos = max(self::count_meta($user_id, ['tng_photo_count','tng_approved_photos','photo_count']), absint($stats['photos'] ?? 0));
        $rank = sanitize_text_field($stats['rank_name'] ?? self::rank_name($user_id));
        if ($rank === '' || strtolower($rank) === 'explorer') $rank = self::level_title($level);
        $discoveries = max(absint($stats['discoveries'] ?? 0), absint($stats['top_sights'] ?? 0));
        $mapped_discoveries = absint($stats['mapped_discoveries'] ?? 0);
        $tennessee_total = absint($stats['tennessee_completion_total'] ?? 0);
        $tennessee_remaining = absint($stats['tennessee_completion_remaining'] ?? 0);
        $tennessee_percent = min(100, max(0, (float)($stats['tennessee_completion'] ?? 0)));
        $discovery_categories = is_array($stats['discovery_categories'] ?? null) ? $stats['discovery_categories'] : [];
        $category_progression = is_array($stats['category_progression'] ?? null) ? $stats['category_progression'] : [];
        $next_achievement = self::next_achievement($user_id, $earned_ids);
        $recent = self::recent_activity($user_id);
        $avatar = $logged_in ? get_avatar_url($user_id, ['size' => 192]) : '';
        ob_start(); ?>
        <main class="tng-profile-screen tng-app-shell">
            <section class="tng-profile-hero">
                <div class="tng-profile-avatar"<?php echo $avatar ? ' style="background-image:url(' . esc_url($avatar) . ')"' : ''; ?>><?php if (!$avatar): ?><span>TN</span><?php endif; ?></div>
                <div class="tng-profile-copy"><span class="tng-eyebrow">Explorer profile</span><h1><?php echo esc_html($name); ?></h1><p><?php echo $logged_in ? 'Your Tennessee adventures, achievements, photos, and progress in one place.' : 'Create an account to earn XP, save adventures, upload photos, and compete with friends.'; ?></p></div>
                <div class="tng-profile-actions"><?php if ($logged_in): ?><a class="tng-ui-button tng-ui-button--secondary" href="<?php echo esc_url(home_url('/profile-settings/')); ?>">Edit profile</a><a class="tng-ui-button" href="<?php echo esc_url(home_url('/play/')); ?>">Keep exploring</a><?php else: ?><a class="tng-ui-button tng-ui-button--secondary" href="<?php echo esc_url(wp_login_url(home_url('/profile/'))); ?>">Sign in</a><a class="tng-ui-button" href="<?php echo esc_url(wp_registration_url()); ?>">Create account</a><?php endif; ?></div>
            </section>

            <section class="tng-profile-level">
                <div class="tng-level-badge"><small>Level</small><strong><?php echo esc_html((string) $level); ?></strong></div>
                <div class="tng-level-copy">
                    <div><div><span class="tng-eyebrow">Explorer XP</span><h2><?php echo esc_html(number_format_i18n($xp)); ?> XP</h2></div><span class="tng-profile-rank-pill"><?php echo esc_html($rank ?: 'Explorer'); ?></span></div>
                    <p><?php echo esc_html(number_format_i18n($xp_to_next)); ?> XP to Level <?php echo esc_html((string) ($level + 1)); ?></p>
                    <div class="tng-profile-progress"><span style="width:<?php echo esc_attr((string) $level_progress); ?>%"></span></div>
                </div>
            </section>

            <section class="tng-profile-stats" aria-label="Explorer statistics">
                <a data-tng-profile-stat="achievements" href="<?php echo esc_url(home_url('/achievements/')); ?>"><span>🏆</span><strong><?php echo esc_html(number_format_i18n($achievements)); ?></strong><small>Achievements</small></a>
                <a data-tng-profile-stat="adventures" href="<?php echo esc_url(home_url('/completed/')); ?>"><span>🥾</span><strong><?php echo esc_html(number_format_i18n($completed)); ?></strong><small>Adventures</small></a>
                <a data-tng-profile-stat="checkpoints" href="<?php echo esc_url(home_url('/completed/')); ?>"><span>📍</span><strong><?php echo esc_html(number_format_i18n($checkpoints)); ?></strong><small>Checkpoints</small></a>
                <a data-tng-profile-stat="photos" href="<?php echo esc_url(home_url('/my-photos/')); ?>"><span>📸</span><strong><?php echo esc_html(number_format_i18n($photos)); ?></strong><small>Photos</small></a>
                <a data-tng-profile-stat="discoveries" href="<?php echo esc_url(home_url('/map/')); ?>"><span>🧭</span><strong><?php echo esc_html(number_format_i18n($discoveries)); ?></strong><small>Discoveries</small></a>
            </section>

            <section class="tng-tennessee-completion" aria-label="Tennessee completion">
                <div class="tng-tennessee-completion__summary">
                    <div class="tng-tennessee-completion__ring" style="--tng-completion:<?php echo esc_attr((string)$tennessee_percent); ?>"><span><strong><?php echo esc_html(number_format_i18n($tennessee_percent, 1)); ?>%</strong><small>Tennessee</small></span></div>
                    <div><span class="tng-eyebrow">Tennessee completion</span><h2>Your statewide discovery map</h2><p><?php echo $tennessee_total ? esc_html(number_format_i18n($mapped_discoveries) . ' of ' . number_format_i18n($tennessee_total) . ' mapped TN Game discoveries completed. ' . number_format_i18n($tennessee_remaining) . ' remain.') : 'Mapped TN Game discoveries will build your statewide completion here.'; ?></p><a href="<?php echo esc_url(home_url('/map/')); ?>">Explore the Universal Map →</a></div>
                </div>
                <?php if ($discovery_categories): ?><div class="tng-tennessee-completion__categories">
                    <?php foreach ($discovery_categories as $category): if (empty($category['total'])) continue; ?>
                    <article><span><?php echo esc_html((string)($category['icon'] ?? '•')); ?></span><div><strong><?php echo esc_html((string)($category['label'] ?? 'Places')); ?></strong><small><?php echo esc_html(number_format_i18n(absint($category['discovered'] ?? 0)) . ' / ' . number_format_i18n(absint($category['total'] ?? 0))); ?></small><i><b style="width:<?php echo esc_attr((string)min(100,max(0,(float)($category['percent'] ?? 0)))); ?>%"></b></i></div></article>
                    <?php endforeach; ?>
                </div><?php endif; ?>
            </section>

            <?php if ($category_progression): ?><section class="tng-category-progression tng-profile-v2-categories">
                <div class="tng-category-progression__head"><div><span class="tng-eyebrow">Explorer paths</span><h2>Level every part of your journey</h2></div><p>Games, checkpoints, sights, trips, and photos each unlock their own milestones.</p></div>
                <div class="tng-category-progression__grid">
                    <?php foreach ($category_progression as $category): ?><article class="tng-category-card"><div class="tng-category-card__top"><span class="tng-category-card__icon"><?php echo esc_html((string)($category['icon'] ?? '•')); ?></span><span class="tng-category-card__value"><?php echo esc_html(number_format_i18n(absint($category['value'] ?? 0))); ?></span></div><strong><?php echo esc_html((string)($category['label'] ?? 'Progress')); ?></strong><small><?php echo !empty($category['remaining']) ? esc_html(number_format_i18n(absint($category['remaining'])) . ' to ' . number_format_i18n(absint($category['target'] ?? 0))) : 'Top tier reached'; ?></small><div class="tng-category-card__bar"><i style="width:<?php echo esc_attr((string)min(100,max(0,absint($category['progress'] ?? 0)))); ?>%"></i></div></article><?php endforeach; ?>
                </div>
            </section><?php endif; ?>

            <section class="tng-profile-grid">
                <article class="tng-profile-panel">
                    <div class="tng-profile-panel__heading"><div><span class="tng-eyebrow">Your journey</span><h2>Recent activity</h2></div><a href="<?php echo esc_url(home_url('/explorer-journal/')); ?>">View journal</a></div>
                    <div class="tng-profile-timeline">
                        <?php if ($recent): foreach ($recent as $item): ?>
                            <a href="<?php echo esc_url($item['url'] ?: home_url('/explorer-journal/')); ?>"><span class="tng-profile-timeline__dot"></span><div><strong><?php echo esc_html($item['title']); ?></strong><small><?php echo esc_html($item['description'] ?: 'Explorer progress saved'); ?></small></div><em>→</em></a>
                        <?php endforeach; else: ?><div class="tng-profile-empty">Your completed adventures and XP activity will appear here.</div><?php endif; ?>
                    </div>
                </article>

                <article class="tng-profile-panel">
                    <div class="tng-profile-panel__heading"><div><span class="tng-eyebrow">Next goals</span><h2>Keep progressing</h2></div></div>
                    <div class="tng-profile-goals">
                        <a href="<?php echo esc_url(home_url('/profile/')); ?>"><span>⚡</span><div><strong>Reach Level <?php echo esc_html((string)($level + 1)); ?></strong><small><?php echo esc_html(number_format_i18n($xp_to_next)); ?> XP remaining</small><div class="tng-profile-goal-meter"><i style="width:<?php echo esc_attr((string)$level_progress); ?>%"></i></div></div></a>
                        <?php if ($next_achievement): ?><a href="<?php echo esc_url($next_achievement['url']); ?>"><span>🏆</span><div><strong><?php echo esc_html($next_achievement['title']); ?></strong><small><?php echo esc_html($next_achievement['description']); ?></small></div></a><?php else: ?><a href="<?php echo esc_url(home_url('/achievements/')); ?>"><span>🏆</span><div><strong>Achievement collection</strong><small>See every milestone you have unlocked.</small></div></a><?php endif; ?>
                        <a href="<?php echo esc_url(home_url('/play/')); ?>"><span>🎮</span><div><strong>Complete another adventure</strong><small><?php echo $completed_games ? esc_html($completed_games . ' game' . ($completed_games === 1 ? '' : 's') . ' completed so far') : 'Earn XP from checkpoints'; ?></small></div></a>
                    </div>
                </article>
            </section>
        </main>
        <?php return (string) ob_get_clean();
    }
}
