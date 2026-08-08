<?php
/**
 * Plugin Name: TN Game Trip Dock
 * Description: Native synchronized trip dock and legacy trip overlay replacement.
 * Version: 0.2.0
 * Author: The TN Game
 */
if (!defined('ABSPATH')) exit;

final class TNG_Trip_Dock {
    private const COMPLETED_META = 'tng_active_trip_completed';
    private const SKIPPED_META = 'tng_active_trip_skipped';

    public static function boot(): void {
        add_action('wp_enqueue_scripts', [self::class, 'assets'], 120);
        add_action('wp_footer', [self::class, 'render'], 40);
    }

    public static function assets(): void {
        if (is_admin()) return;
        wp_enqueue_style('tng-trip-dock', TNG_OS_URL . 'assets/css/trip-dock.css', [], '0.2.0');
        wp_enqueue_script('tng-trip-dock', TNG_OS_URL . 'assets/js/trip-dock.js', [], '0.2.0', true);
    }

    private static function completed_ids(): array {
        if (!is_user_logged_in()) return [];
        $ids = get_user_meta(get_current_user_id(), self::COMPLETED_META, true);
        return is_array($ids) ? array_values(array_unique(array_map('absint', $ids))) : [];
    }

    private static function skipped_ids(): array {
        if (!is_user_logged_in()) return [];
        $raw = get_user_meta(get_current_user_id(), self::SKIPPED_META, true);
        if (!is_array($raw)) return [];
        return array_values(array_filter(array_unique(array_map('absint', array_keys($raw)))));
    }

    public static function render(): void {
        if (is_admin() || !is_user_logged_in() || !class_exists('TNG_Trip_Data')) return;

        // Keep the dock visible on Trip Mode too. It is the persistent trip status/control
        // surface across the TN Game app and should not disappear while a trip is active.
        $posts = TNG_Trip_Data::posts();
        if (!$posts) return;

        $post_ids = array_values(array_map(static fn($post) => (int) $post->ID, $posts));
        $completed = self::completed_ids();
        $skipped = self::skipped_ids();
        $done = count(array_intersect($post_ids, $completed));
        $skipped_count = count(array_intersect($post_ids, $skipped));
        $resolved_ids = array_values(array_unique(array_merge($completed, $skipped)));
        $resolved = count(array_intersect($post_ids, $resolved_ids));
        $total = count($posts);
        $remaining = max(0, $total - $resolved);

        $next = null;
        foreach ($posts as $post) {
            $id = (int) $post->ID;
            if (!in_array($id, $resolved_ids, true)) {
                $next = $post;
                break;
            }
        }

        $percent = $total ? (int) round(($resolved / $total) * 100) : 0;
        $perfect = $total > 0 && $done === $total;
        $finished_with_skips = $total > 0 && $resolved === $total && $skipped_count > 0;

        if ($perfect) {
            $eyebrow = 'Trip complete';
            $title = 'Your Tennessee day is complete';
            $status = $done . ' of ' . $total . ' stops completed';
            $primary_label = 'View recap';
        } elseif ($finished_with_skips) {
            $eyebrow = 'Trip finished';
            $title = 'Your Tennessee day is finished';
            $status = $done . ' completed · ' . $skipped_count . ' skipped';
            $primary_label = 'Review trip';
        } else {
            $eyebrow = 'Active trip';
            $title = $next ? 'Next: ' . get_the_title($next) : 'Your Tennessee day';
            $status_parts = [$done . ' completed'];
            if ($skipped_count) $status_parts[] = $skipped_count . ' skipped';
            if ($remaining) $status_parts[] = $remaining . ' remaining';
            $status = implode(' · ', $status_parts);
            $primary_label = 'Trip mode';
        }
        ?>
        <aside class="tng-trip-dock" data-tng-trip-dock aria-label="Current trip" data-trip-resolved="<?php echo esc_attr((string)$resolved); ?>" data-trip-total="<?php echo esc_attr((string)$total); ?>">
            <a class="tng-trip-dock__main" href="<?php echo esc_url(home_url('/active-trip/')); ?>">
                <span class="tng-trip-dock__icon">▶</span>
                <span class="tng-trip-dock__copy">
                    <small><?php echo esc_html($eyebrow); ?></small>
                    <strong><?php echo esc_html($title); ?></strong>
                    <span><?php echo esc_html($status); ?></span>
                </span>
            </a>
            <span class="tng-trip-dock__progress" aria-hidden="true"><i style="width:<?php echo esc_attr((string)$percent); ?>%"></i></span>
            <div class="tng-trip-dock__actions">
                <a href="<?php echo esc_url(home_url('/trip-builder/')); ?>">Edit</a>
                <a class="is-primary" href="<?php echo esc_url(home_url('/active-trip/')); ?>"><?php echo esc_html($primary_label); ?></a>
            </div>
        </aside>
        <?php
    }
}
TNG_Trip_Dock::boot();
