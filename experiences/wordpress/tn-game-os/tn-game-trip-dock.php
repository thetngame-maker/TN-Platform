<?php
/**
 * Plugin Name: TN Game Trip Dock
 * Description: Native synchronized trip dock and legacy trip overlay replacement.
 * Version: 0.1.0
 * Author: The TN Game
 */
if (!defined('ABSPATH')) exit;

final class TNG_Trip_Dock {
    public static function boot(): void {
        add_action('wp_enqueue_scripts', [self::class, 'assets'], 120);
        add_action('wp_footer', [self::class, 'render'], 40);
    }

    public static function assets(): void {
        if (is_admin()) return;
        wp_enqueue_style('tng-trip-dock', TNG_OS_URL . 'assets/css/trip-dock.css', [], '0.1.0');
        wp_enqueue_script('tng-trip-dock', TNG_OS_URL . 'assets/js/trip-dock.js', [], '0.1.0', true);
    }

    private static function completed_ids(): array {
        if (!is_user_logged_in()) return [];
        $ids = get_user_meta(get_current_user_id(), 'tng_active_trip_completed', true);
        return is_array($ids) ? array_values(array_unique(array_map('absint', $ids))) : [];
    }

    public static function render(): void {
        if (is_admin() || !is_user_logged_in() || !class_exists('TNG_Trip_Data')) return;
        if (class_exists('TNG_OS\\Platform\\App_Router')) {
            $route = \TNG_OS\Platform\App_Router::current_route();
            if (in_array($route, ['active-trip', 'trip-mode'], true)) return;
        }
        $posts = TNG_Trip_Data::posts();
        if (!$posts) return;
        $completed = self::completed_ids();
        $done = count(array_intersect(array_map(static fn($post) => (int)$post->ID, $posts), $completed));
        $total = count($posts);
        $next = null;
        foreach ($posts as $post) {
            if (!in_array((int)$post->ID, $completed, true)) { $next = $post; break; }
        }
        $percent = $total ? (int)round(($done / $total) * 100) : 0;
        ?>
        <aside class="tng-trip-dock" data-tng-trip-dock aria-label="Current trip">
            <a class="tng-trip-dock__main" href="<?php echo esc_url(home_url('/active-trip/')); ?>">
                <span class="tng-trip-dock__icon">▶</span>
                <span class="tng-trip-dock__copy">
                    <small><?php echo $done === $total ? 'Trip complete' : 'Active trip'; ?></small>
                    <strong><?php echo esc_html($next ? 'Next: ' . get_the_title($next) : 'Your Tennessee day'); ?></strong>
                    <span><?php echo esc_html($done . ' of ' . $total . ' stops complete'); ?></span>
                </span>
            </a>
            <span class="tng-trip-dock__progress" aria-hidden="true"><i style="width:<?php echo esc_attr((string)$percent); ?>%"></i></span>
            <div class="tng-trip-dock__actions">
                <a href="<?php echo esc_url(home_url('/trip-builder/')); ?>">Edit</a>
                <a class="is-primary" href="<?php echo esc_url(home_url('/active-trip/')); ?>"><?php echo $done === $total ? 'Finish trip' : 'Trip mode'; ?></a>
            </div>
        </aside>
        <?php
    }
}
TNG_Trip_Dock::boot();
