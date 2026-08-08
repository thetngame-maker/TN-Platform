<?php
/**
 * Plugin Name: TN Game Trail UI
 * Description: Native TN Game trail-detail template and reusable trail components.
 * Version: 0.1.0
 * Author: The TN Game
 */
if (!defined('ABSPATH')) exit;

final class TNG_Trail_UI {
    public static function boot(): void {
        add_filter('template_include', [self::class, 'template'], 99998);
        add_filter('body_class', [self::class, 'body_class'], 998);
        add_action('wp_enqueue_scripts', [self::class, 'enqueue'], 110);
    }

    public static function is_trail(): bool {
        if (!is_singular(['st_activity', 'activity'])) return false;
        $id = get_queried_object_id();
        if (!$id) return false;

        foreach (['activity_types', 'st_activity_type', 'activity_type', 'category'] as $taxonomy) {
            if (!taxonomy_exists($taxonomy)) continue;
            $terms = wp_get_post_terms($id, $taxonomy, ['fields' => 'slugs']);
            if (!is_wp_error($terms) && array_intersect($terms, ['hiking-trails', 'hiking-trail', 'trails', 'trail'])) return true;
        }

        foreach (['trail_distance', 'distance', 'elevation_gain', 'trail_difficulty', 'gpx_file', 'gpx_url'] as $key) {
            if (get_post_meta($id, $key, true) !== '') return true;
        }
        return false;
    }

    public static function template(string $template): string {
        if (!self::is_trail()) return $template;
        $native = TNG_OS_PATH . 'templates/trail-shell.php';
        return is_readable($native) ? $native : $template;
    }

    public static function body_class(array $classes): array {
        if (!self::is_trail()) return $classes;
        $classes[] = 'tng-platform-ui';
        $classes[] = 'tng-native-trail-page';
        $classes[] = 'tng-hide-traveler-chrome';
        return array_values(array_unique($classes));
    }

    public static function enqueue(): void {
        if (!self::is_trail()) return;
        wp_enqueue_style('tng-platform-ui', TNG_OS_URL . 'assets/css/platform-ui.css', [], '0.8.0');
        wp_enqueue_style('tng-app-router', TNG_OS_URL . 'assets/css/app-router.css', ['tng-platform-ui'], '1.5.0');
        wp_enqueue_style('tng-ui-kit', TNG_OS_URL . 'assets/css/ui-kit.css', ['tng-platform-ui'], '1.4.0');
        wp_enqueue_style('tng-trail-ui', TNG_OS_URL . 'assets/css/trail-ui.css', ['tng-ui-kit'], '0.1.0');
        wp_enqueue_script('tng-platform-ui', TNG_OS_URL . 'assets/js/platform-ui.js', [], '0.8.0', true);
    }

    private static function first_meta(int $id, array $keys, string $fallback = ''): string {
        foreach ($keys as $key) {
            $value = get_post_meta($id, $key, true);
            if (is_scalar($value) && trim((string) $value) !== '') return trim((string) $value);
        }
        return $fallback;
    }

    private static function clean_content(int $id): string {
        $content = strip_shortcodes((string) get_post_field('post_content', $id));
        $content = preg_replace('/\[[^\]]+\]/', ' ', $content);
        return wpautop(wp_kses_post($content));
    }

    public static function render(int $id): string {
        $title = get_the_title($id);
        $image = get_the_post_thumbnail_url($id, 'full');
        $distance = self::first_meta($id, ['trail_distance','distance','st_distance'], '—');
        $gain = self::first_meta($id, ['elevation_gain','trail_elevation_gain','gain'], '—');
        $time = self::first_meta($id, ['estimated_time','trail_time','duration'], '—');
        $type = self::first_meta($id, ['trail_type','route_type'], 'Trail');
        $difficulty = self::first_meta($id, ['trail_difficulty','difficulty'], 'Explore');
        $xp = self::first_meta($id, ['xp_available','xp','trail_xp'], '');
        $address = self::first_meta($id, ['address','location'], 'Tennessee South Cumberland');
        $content = self::clean_content($id);
        $map_url = add_query_arg(['trail' => $id], home_url('/map/'));
        $play_url = add_query_arg(['trail' => $id], home_url('/play/'));

        ob_start(); ?>
        <main class="tng-trail tng-app-shell">
            <section class="tng-trail-hero<?php echo $image ? '' : ' is-placeholder'; ?>"<?php echo $image ? ' style="background-image:linear-gradient(90deg,rgba(10,30,19,.86),rgba(10,30,19,.25)),url(' . esc_url($image) . ')"' : ''; ?>>
                <div class="tng-trail-hero__content">
                    <span class="tng-eyebrow">Hiking trail</span>
                    <h1><?php echo esc_html($title); ?></h1>
                    <p>📍 <?php echo esc_html($address); ?></p>
                    <div class="tng-trail-badges"><span><?php echo esc_html($difficulty); ?></span><?php if ($xp): ?><span>⭐ <?php echo esc_html($xp); ?> XP available</span><?php endif; ?></div>
                </div>
            </section>

            <section class="tng-trail-stats" aria-label="Trail information">
                <div><span>↔</span><strong><?php echo esc_html($distance); ?></strong><small>Distance</small></div>
                <div><span>↗</span><strong><?php echo esc_html($gain); ?></strong><small>Elevation gain</small></div>
                <div><span>◷</span><strong><?php echo esc_html($time); ?></strong><small>Estimated time</small></div>
                <div><span>◇</span><strong><?php echo esc_html($type); ?></strong><small>Trail type</small></div>
            </section>

            <section class="tng-trail-actions">
                <a class="tng-ui-button tng-ui-button--secondary" href="<?php echo esc_url($map_url); ?>">⌖ Open map</a>
                <a class="tng-ui-button" href="<?php echo esc_url($play_url); ?>">▶ Start game</a>
            </section>

            <section class="tng-trail-layout">
                <article class="tng-trail-main">
                    <section class="tng-trail-panel"><span class="tng-eyebrow">Trail overview</span><h2>About this trail</h2><div class="tng-trail-copy"><?php echo $content ?: '<p>Trail details are being prepared.</p>'; ?></div></section>
                    <section class="tng-trail-panel"><div class="tng-trail-panel__heading"><div><span class="tng-eyebrow">Route</span><h2>Map and checkpoints</h2></div><a href="<?php echo esc_url($map_url); ?>">Full screen</a></div><div class="tng-trail-map-preview"><span>⌖</span><strong>TN Game trail map</strong><small>The existing GPX route, live location, and Top Sights will connect here.</small></div></section>
                    <section class="tng-trail-panel"><span class="tng-eyebrow">Elevation</span><h2>Elevation profile</h2><div class="tng-trail-elevation"><svg viewBox="0 0 800 190" preserveAspectRatio="none" aria-hidden="true"><path d="M0 160 C90 150 120 100 210 125 S340 70 420 105 S555 40 640 80 S735 32 800 50 L800 190 L0 190 Z"/></svg><div><span>Start</span><span>Route profile</span><span>Finish</span></div></div></section>
                </article>
                <aside class="tng-trail-side">
                    <section class="tng-trail-panel tng-trail-ready"><span class="tng-eyebrow">Ready to explore?</span><h2>Turn this hike into an adventure.</h2><p>Follow the route, visit checkpoints, discover Top Sights, and earn XP.</p><a class="tng-ui-button" href="<?php echo esc_url($play_url); ?>">Start trail game</a></section>
                    <section class="tng-trail-panel"><span class="tng-eyebrow">Plan ahead</span><h2>Before you go</h2><ul><li>Check weather and trail conditions.</li><li>Bring water and appropriate footwear.</li><li>Download or open the route before starting.</li></ul></section>
                </aside>
            </section>
        </main>
        <?php return (string) ob_get_clean();
    }
}

TNG_Trail_UI::boot();
