<?php
/**
 * Plugin Name: TN Game Place UI
 * Description: Native TN Game detail template for Top Sights, destinations, restaurants, attractions, shops, and local places.
 * Version: 0.1.0
 * Author: The TN Game
 */
if (!defined('ABSPATH')) exit;

final class TNG_Place_UI {
    private static bool $active = false;

    public static function boot(): void {
        add_action('template_redirect', [self::class, 'detect'], 2);
        add_filter('template_include', [self::class, 'template'], 99990);
        add_action('wp_enqueue_scripts', [self::class, 'assets'], 95);
        add_filter('body_class', [self::class, 'body_class'], 999);
    }

    public static function detect(): void {
        self::$active = self::is_place_request();
    }

    private static function is_place_request(): bool {
        if (!is_singular()) return false;
        $id = get_queried_object_id();
        if (!$id) return false;
        $type = get_post_type($id);
        if (in_array($type, ['top_sight', 'tng_destination', 'st_location'], true)) return true;
        if (!in_array($type, ['st_activity', 'activity'], true)) return false;

        $text = strtolower(get_the_title($id) . ' ' . get_post_field('post_content', $id));
        $terms = wp_get_post_terms($id, get_object_taxonomies($type), ['fields' => 'names']);
        if (!is_wp_error($terms)) $text .= ' ' . strtolower(implode(' ', $terms));
        if (preg_match('/trail|hiking|concert|show|festival|caverns/', $text)) return false;
        return (bool) preg_match('/restaurant|food|dining|coffee|cafe|shop|store|attraction|sight|museum|park|waterfall|viewpoint|local place/', $text);
    }

    public static function template(string $template): string {
        if (!self::$active) return $template;
        $native = TNG_OS_PATH . 'templates/place-shell.php';
        return is_readable($native) ? $native : $template;
    }

    public static function assets(): void {
        if (!self::$active) return;
        wp_enqueue_style('tng-platform-ui', TNG_OS_URL . 'assets/css/platform-ui.css', [], '1.0.0');
        wp_enqueue_style('tng-app-router', TNG_OS_URL . 'assets/css/app-router.css', ['tng-platform-ui'], '1.5.2');
        wp_enqueue_style('tng-ui-kit', TNG_OS_URL . 'assets/css/ui-kit.css', ['tng-platform-ui'], '1.5.0');
        wp_enqueue_style('tng-place-ui', TNG_OS_URL . 'assets/css/place-ui.css', ['tng-ui-kit'], '0.1.0');
        wp_enqueue_script('tng-platform-ui', TNG_OS_URL . 'assets/js/platform-ui.js', [], '1.0.0', true);
    }

    public static function body_class(array $classes): array {
        if (!self::$active) return $classes;
        $classes[] = 'tng-platform-ui';
        $classes[] = 'tng-place-page';
        $classes[] = 'tng-hide-traveler-chrome';
        return array_values(array_unique($classes));
    }

    private static function meta(int $id, array $keys, string $fallback = ''): string {
        foreach ($keys as $key) {
            $value = get_post_meta($id, $key, true);
            if (is_scalar($value) && trim((string) $value) !== '') return trim((string) $value);
        }
        return $fallback;
    }

    private static function label(int $id): string {
        $type = get_post_type($id);
        if ($type === 'top_sight') return 'Top Sight';
        if (in_array($type, ['tng_destination', 'st_location'], true)) return 'Destination';
        $text = strtolower(get_the_title($id) . ' ' . get_post_field('post_content', $id));
        if (preg_match('/restaurant|food|dining|coffee|cafe/', $text)) return 'Food & Drink';
        if (preg_match('/shop|store|retail/', $text)) return 'Local Shop';
        return 'Local Attraction';
    }

    public static function render(): string {
        $id = get_queried_object_id();
        $title = get_the_title($id);
        $image = get_the_post_thumbnail_url($id, 'full');
        $address = self::meta($id, ['address', 'location', 'st_address']);
        $hours = self::meta($id, ['hours', 'opening_hours', 'business_hours']);
        $phone = self::meta($id, ['phone', 'contact_phone']);
        $website = self::meta($id, ['website', 'external_url', 'url']);
        $lat = self::meta($id, ['latitude', 'lat', 'map_lat']);
        $lng = self::meta($id, ['longitude', 'lng', 'map_lng']);
        $content = apply_filters('the_content', strip_shortcodes((string) get_post_field('post_content', $id)));
        $directions = ($lat && $lng) ? 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode($lat . ',' . $lng) : ($address ? 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($address) : home_url('/map/'));
        ob_start(); ?>
        <main class="tng-place tng-app-shell">
            <section class="tng-place-hero<?php echo $image ? '' : ' is-placeholder'; ?>"<?php echo $image ? ' style="background-image:url(' . esc_url($image) . ')"' : ''; ?>>
                <div class="tng-place-hero__overlay"></div>
                <div class="tng-place-hero__content">
                    <span class="tng-eyebrow"><?php echo esc_html(self::label($id)); ?></span>
                    <h1><?php echo esc_html($title); ?></h1>
                    <?php if ($address): ?><p>📍 <?php echo esc_html($address); ?></p><?php endif; ?>
                </div>
            </section>

            <section class="tng-place-actions">
                <a class="tng-ui-button" href="<?php echo esc_url($directions); ?>" target="_blank" rel="noopener">Directions</a>
                <a class="tng-ui-button tng-ui-button--secondary" href="<?php echo esc_url(home_url('/trips/')); ?>">＋ Add to trip</a>
                <button class="tng-ui-button tng-ui-button--secondary" type="button" onclick="navigator.share?navigator.share({title:document.title,url:location.href}):navigator.clipboard.writeText(location.href)">Share</button>
            </section>

            <div class="tng-place-layout">
                <div class="tng-place-main">
                    <section class="tng-place-card"><span class="tng-eyebrow">Overview</span><h2>About <?php echo esc_html($title); ?></h2><div class="tng-place-copy"><?php echo $content ?: '<p>Discover this Tennessee destination and add it to your next adventure.</p>'; ?></div></section>
                    <section class="tng-place-card"><div class="tng-place-section-heading"><div><span class="tng-eyebrow">Location</span><h2>Find it on the map</h2></div><a href="<?php echo esc_url($directions); ?>" target="_blank" rel="noopener">Open directions</a></div><div class="tng-place-map"><span>⌖</span><strong><?php echo esc_html($address ?: 'TN Game map location'); ?></strong><small>The live TN Game map data will connect to this surface.</small></div></section>
                </div>
                <aside class="tng-place-sidebar">
                    <section class="tng-place-card"><span class="tng-eyebrow">Plan your visit</span><h2>Details</h2><dl>
                        <?php if ($hours): ?><div><dt>Hours</dt><dd><?php echo esc_html($hours); ?></dd></div><?php endif; ?>
                        <?php if ($phone): ?><div><dt>Phone</dt><dd><a href="tel:<?php echo esc_attr(preg_replace('/[^0-9+]/', '', $phone)); ?>"><?php echo esc_html($phone); ?></a></dd></div><?php endif; ?>
                        <?php if ($address): ?><div><dt>Address</dt><dd><?php echo esc_html($address); ?></dd></div><?php endif; ?>
                    </dl><?php if ($website): ?><a class="tng-ui-button" href="<?php echo esc_url($website); ?>" target="_blank" rel="noopener">Visit website</a><?php endif; ?></section>
                    <section class="tng-place-adventure"><span class="tng-eyebrow">Make it an adventure</span><h2>Build this stop into your day.</h2><p>Save it, combine it with nearby trails and places, then earn XP while you explore.</p><a href="<?php echo esc_url(home_url('/trips/')); ?>">Plan a trip</a></section>
                </aside>
            </div>
        </main>
        <?php return (string) ob_get_clean();
    }
}

TNG_Place_UI::boot();
