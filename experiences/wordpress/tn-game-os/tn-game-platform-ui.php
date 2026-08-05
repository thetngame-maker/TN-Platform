<?php
/**
 * Plugin Name: TN Game Platform UI
 * Plugin URI: https://thetngame.com
 * Description: Mobile-first TN Game app shell, private preview, Traveler chrome replacement controls, and Explore page components.
 * Version: 0.2.0
 * Author: The TN Game
 * Text Domain: tn-game-platform-ui
 */
if (!defined('ABSPATH')) exit;

final class TNG_Platform_UI {
    private const OPTION = 'tng_platform_ui_settings';
    private const PREVIEW = 'tng_ui_preview';

    public static function boot(): void {
        add_action('admin_menu', [self::class, 'admin_menu']);
        add_action('admin_init', [self::class, 'register_settings']);
        add_action('wp_enqueue_scripts', [self::class, 'enqueue'], 40);
        add_filter('body_class', [self::class, 'body_class']);
        add_action('wp_body_open', [self::class, 'header'], 5);
        add_action('wp_footer', [self::class, 'footer'], 50);
        add_action('wp_footer', [self::class, 'navigation'], 60);
        add_shortcode('tng_explore_app', [self::class, 'explore']);
    }

    private static function settings(): array {
        return wp_parse_args((array) get_option(self::OPTION, []), [
            'enabled' => 0,
            'replace_traveler' => 0,
            'explore_page_id' => 0,
            'content_limit' => 6,
        ]);
    }

    public static function active(): bool {
        $settings = self::settings();
        $preview = current_user_can('manage_options') && isset($_GET[self::PREVIEW]) && $_GET[self::PREVIEW] === '1';
        return !is_admin() && (!empty($settings['enabled']) || $preview);
    }

    public static function admin_menu(): void {
        add_options_page('TN Game Platform UI', 'TN Game Platform UI', 'manage_options', 'tng-platform-ui', [self::class, 'settings_page']);
    }

    public static function register_settings(): void {
        register_setting('tng_platform_ui', self::OPTION, [
            'type' => 'array',
            'sanitize_callback' => static function ($value): array {
                return [
                    'enabled' => empty($value['enabled']) ? 0 : 1,
                    'replace_traveler' => empty($value['replace_traveler']) ? 0 : 1,
                    'explore_page_id' => absint($value['explore_page_id'] ?? 0),
                    'content_limit' => min(12, max(3, absint($value['content_limit'] ?? 6))),
                ];
            },
            'default' => [],
        ]);
    }

    public static function settings_page(): void {
        if (!current_user_can('manage_options')) return;
        $settings = self::settings();
        $preview_url = add_query_arg(self::PREVIEW, '1', home_url('/'));
        ?>
        <div class="wrap">
            <h1>TN Game Platform UI</h1>
            <p>Preview and progressively enable the new TN Game application interface without editing Traveler core.</p>
            <p><a class="button button-secondary" target="_blank" href="<?php echo esc_url($preview_url); ?>">Open private preview</a></p>
            <form method="post" action="options.php">
                <?php settings_fields('tng_platform_ui'); ?>
                <table class="form-table" role="presentation">
                    <tr><th scope="row">Enable Platform UI</th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[enabled]" value="1" <?php checked($settings['enabled'], 1); ?>> Show the app shell publicly</label></td></tr>
                    <tr><th scope="row">Replace Traveler chrome</th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[replace_traveler]" value="1" <?php checked($settings['replace_traveler'], 1); ?>> Hide common Traveler header and footer elements</label></td></tr>
                    <tr><th scope="row">Explore page</th><td><?php wp_dropdown_pages(['name' => self::OPTION . '[explore_page_id]', 'selected' => $settings['explore_page_id'], 'show_option_none' => 'Use homepage', 'option_none_value' => 0]); ?><p class="description">Add <code>[tng_explore_app]</code> to this page.</p></td></tr>
                    <tr><th scope="row">Explore card count</th><td><input class="small-text" type="number" min="3" max="12" name="<?php echo esc_attr(self::OPTION); ?>[content_limit]" value="<?php echo esc_attr($settings['content_limit']); ?>"></td></tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public static function enqueue(): void {
        if (!self::active()) return;
        $base = plugin_dir_url(__FILE__);
        wp_enqueue_style('tng-platform-ui', $base . 'assets/css/platform-ui.css', [], '0.2.0');
        wp_enqueue_script('tng-platform-ui', $base . 'assets/js/platform-ui.js', [], '0.2.0', true);
    }

    public static function body_class(array $classes): array {
        if (!self::active()) return $classes;
        $classes[] = 'tng-platform-ui';
        if (!empty(self::settings()['replace_traveler'])) $classes[] = 'tng-hide-traveler-chrome';
        return $classes;
    }

    public static function header(): void {
        if (!self::active()) return;
        echo '<header class="tng-topbar"><a class="tng-brand" href="' . esc_url(home_url('/')) . '"><span class="tng-brand__mark">TN</span><span>The TN Game</span></a><a class="tng-topbar__action" href="' . esc_url(home_url('/search/')) . '" aria-label="Search">⌕</a></header>';
    }

    public static function footer(): void {
        if (!self::active()) return;
        echo '<footer class="tng-footer"><strong>The TN Game</strong><span>Explore Tennessee. Play locally. Earn your story.</span></footer>';
    }

    public static function navigation(): void {
        if (!self::active()) return;
        $profile = is_user_logged_in() ? get_author_posts_url(get_current_user_id()) : wp_login_url();
        $items = [['Explore','⌂',home_url('/')],['Map','⌖',home_url('/map/')],['Play','▶',home_url('/play/'),'primary'],['Trips','◇',home_url('/trips/')],['Profile','○',$profile]];
        echo '<nav class="tng-app-nav" aria-label="TN Game navigation"><div class="tng-app-nav__inner">';
        foreach ($items as $item) {
            $class = 'tng-app-nav__item' . (!empty($item[3]) ? ' is-primary' : '');
            echo '<a class="' . esc_attr($class) . '" href="' . esc_url($item[2]) . '"><span class="tng-app-nav__icon" aria-hidden="true">' . esc_html($item[1]) . '</span><span>' . esc_html($item[0]) . '</span></a>';
        }
        echo '</div></nav>';
    }

    private static function discoverable_post_types(): array {
        $preferred = ['st_activity', 'activity', 'top_sight', 'tng_destination', 'st_location'];
        return array_values(array_filter($preferred, 'post_type_exists'));
    }

    private static function content_cards(): string {
        $post_types = self::discoverable_post_types();
        if (!$post_types) return '';
        $query = new WP_Query([
            'post_type' => $post_types,
            'post_status' => 'publish',
            'posts_per_page' => (int) self::settings()['content_limit'],
            'ignore_sticky_posts' => true,
            'orderby' => 'modified',
            'order' => 'DESC',
        ]);
        if (!$query->have_posts()) return '';
        ob_start();
        echo '<div class="tng-content-grid">';
        while ($query->have_posts()) {
            $query->the_post();
            $id = get_the_ID();
            $type = get_post_type_object(get_post_type($id));
            $label = $type && !empty($type->labels->singular_name) ? $type->labels->singular_name : 'Explore';
            $image = get_the_post_thumbnail_url($id, 'large');
            $excerpt = wp_trim_words(wp_strip_all_tags(get_the_excerpt($id)), 14);
            echo '<article class="tng-content-card">';
            echo '<a class="tng-content-card__media" href="' . esc_url(get_permalink($id)) . '"' . ($image ? ' style="background-image:url(' . esc_url($image) . ')"' : '') . '><span>' . esc_html($label) . '</span></a>';
            echo '<div class="tng-content-card__body"><h3><a href="' . esc_url(get_permalink($id)) . '">' . esc_html(get_the_title($id)) . '</a></h3>';
            if ($excerpt) echo '<p>' . esc_html($excerpt) . '</p>';
            echo '<a class="tng-content-card__link" href="' . esc_url(get_permalink($id)) . '">Explore <span aria-hidden="true">→</span></a></div></article>';
        }
        echo '</div>';
        wp_reset_postdata();
        return (string) ob_get_clean();
    }

    public static function explore(): string {
        $categories = [['🥾','Trails','/trails/'],['🎮','Games','/play/'],['📍','Top Sights','/top-sights/'],['🎵','Events','/events/'],['🍽️','Food','/food/'],['🗺️','Destinations','/destinations/']];
        $cards = self::content_cards();
        ob_start(); ?>
        <main class="tng-explore tng-app-shell">
            <section class="tng-hero">
                <span class="tng-eyebrow">Your Tennessee adventure starts here</span>
                <h1>Explore more.<br>Play everywhere.</h1>
                <p>Discover trails, local places, games and experiences—then earn XP as you explore.</p>
                <form class="tng-search" role="search" method="get" action="<?php echo esc_url(home_url('/')); ?>"><span aria-hidden="true">⌕</span><input name="s" type="search" placeholder="Search trails, places and adventures"><button type="submit">Search</button></form>
            </section>
            <section class="tng-section"><div class="tng-section__heading"><div><span class="tng-eyebrow">Choose your adventure</span><h2>What are you looking for?</h2></div></div><div class="tng-category-grid">
                <?php foreach ($categories as $category): ?><a class="tng-category-card" href="<?php echo esc_url(home_url($category[2])); ?>"><span><?php echo esc_html($category[0]); ?></span><strong><?php echo esc_html($category[1]); ?></strong><small>Explore now</small></a><?php endforeach; ?>
            </div></section>
            <?php if ($cards): ?><section class="tng-section tng-discover"><div class="tng-section__heading"><div><span class="tng-eyebrow">Fresh discoveries</span><h2>Start exploring</h2></div><a href="<?php echo esc_url(home_url('/search/')); ?>">View all</a></div><?php echo $cards; ?></section><?php endif; ?>
            <section class="tng-play-card"><div><span class="tng-eyebrow">Ready to play?</span><h2>Turn your next outing into a game.</h2><p>Find nearby challenges, complete checkpoints and grow your Explorer profile.</p></div><a class="tng-button" href="<?php echo esc_url(home_url('/play/')); ?>">Start Playing</a></section>
        </main>
        <?php return (string) ob_get_clean();
    }
}

TNG_Platform_UI::boot();
