<?php
/**
 * Plugin Name: TN Game Platform UI
 * Plugin URI: https://thetngame.com
 * Description: Mobile-first TN Game app shell, private preview, Traveler chrome replacement controls, and Explore page components.
 * Version: 0.5.0
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
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_mobile_audit'], 999);
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
        $app_route = class_exists('TNG_OS\\Platform\\App_Router') && \TNG_OS\Platform\App_Router::is_app_request();
        return !is_admin() && (!empty($settings['enabled']) || $preview || $app_route);
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
        wp_enqueue_style('tng-platform-ui', $base . 'assets/css/platform-ui.css', [], '0.5.0');
        wp_enqueue_style('tng-platform-ui-refinements', $base . 'assets/css/platform-ui-refinements.css', ['tng-platform-ui'], '0.5.0');
        wp_enqueue_script('tng-platform-ui', $base . 'assets/js/platform-ui.js', [], '0.5.0', true);
    }

    public static function enqueue_mobile_audit(): void {
        if (!self::active()) return;
        $base = plugin_dir_url(__FILE__);
        wp_enqueue_style('tng-mobile-audit', $base . 'assets/css/mobile-audit.css', [], '0.5.0');
        wp_enqueue_script('tng-mobile-audit', $base . 'assets/js/mobile-audit.js', ['tng-platform-ui'], '0.5.0', true);
    }

    public static function body_class(array $classes): array {
        if (!self::active()) return $classes;
        $classes[] = 'tng-platform-ui';
        if (!empty(self::settings()['replace_traveler'])) $classes[] = 'tng-hide-traveler-chrome';
        return $classes;
    }

    public static function header(): void {
        if (!self::active()) return;
        echo '<header class="tng-topbar"><a class="tng-brand" href="' . esc_url(home_url('/explore/')) . '"><span class="tng-brand__mark">TN</span><span>The TN Game</span></a><a class="tng-topbar__action" href="' . esc_url(home_url('/search/')) . '" aria-label="Search The TN Game">⌕</a></header>';
    }

    public static function footer(): void {
        if (!self::active()) return;
        if (class_exists('TNG_OS\\Platform\\App_Router') && \TNG_OS\Platform\App_Router::is_app_request()) return;
        echo '<footer class="tng-footer"><strong>The TN Game</strong><span>Explore Tennessee. Play locally. Earn your story.</span></footer>';
    }

    public static function navigation(): void {
        if (!self::active()) return;
        $route = class_exists('TNG_OS\\Platform\\App_Router') ? \TNG_OS\Platform\App_Router::current_route() : '';
        $sections = [
            'explore' => ['explore','search','trails','events','food','top-sights','destinations'],
            'map' => ['map'],
            'play' => ['play','games','game-builder','game-play'],
            'trips' => ['trips','adventure-ai','saved','trip-builder','active-trip','trip-mode','past-trips','recaps'],
            'profile' => ['profile','profile-settings','leaderboard','achievements','friends','activity','challenges','journal','explorer-journal','completed','my-photos'],
        ];
        $items = [
            ['Explore','⌂',home_url('/explore/'),'explore'],
            ['Map','⌖',home_url('/map/'),'map'],
            ['Play','▶',home_url('/play/'),'play','primary'],
            ['Trips','◇',home_url('/trips/'),'trips'],
            ['Profile','○',home_url('/profile/'),'profile'],
        ];
        echo '<nav class="tng-app-nav" aria-label="TN Game navigation"><div class="tng-app-nav__inner">';
        foreach ($items as $item) {
            $section = $item[3];
            $active = $route !== '' && in_array($route, $sections[$section], true);
            $class = 'tng-app-nav__item' . (!empty($item[4]) ? ' is-primary' : '') . ($active ? ' is-active' : '');
            echo '<a class="' . esc_attr($class) . '" href="' . esc_url($item[2]) . '" data-tng-section="' . esc_attr($section) . '"' . ($active ? ' aria-current="page"' : '') . '><span class="tng-app-nav__icon" aria-hidden="true">' . esc_html($item[1]) . '</span><span>' . esc_html($item[0]) . '</span></a>';
        }
        echo '</div></nav>';
    }

    private static function discoverable_post_types(): array {
        return array_values(array_filter(['st_activity', 'activity', 'top_sight', 'tng_destination', 'st_location'], 'post_type_exists'));
    }

    private static function clean_excerpt(int $id): string {
        $source = has_excerpt($id) ? get_post_field('post_excerpt', $id) : get_post_field('post_content', $id);
        $source = strip_shortcodes((string) $source);
        $source = preg_replace('/\[[^\]]+\]/', ' ', $source);
        $source = html_entity_decode(wp_strip_all_tags((string) $source), ENT_QUOTES, get_bloginfo('charset') ?: 'UTF-8');
        $source = preg_replace('/\s+/', ' ', trim((string) $source));
        return wp_trim_words($source, 16, '…');
    }

    private static function event_timestamp(int $id): int {
        foreach (['start_date', 'event_date', 'date', 'st_start_date'] as $key) {
            $value = get_post_meta($id, $key, true);
            if (!$value) continue;
            $timestamp = is_numeric($value) ? (int) $value : strtotime((string) $value);
            if ($timestamp) return $timestamp;
        }
        return 0;
    }

    private static function is_event(int $id): bool {
        return self::event_timestamp($id) > 0 || stripos(get_the_title($id), 'caverns') !== false;
    }

    private static function card_meta(int $id, string $post_type): array {
        $meta = [];
        $timestamp = self::event_timestamp($id);
        if ($timestamp) $meta[] = '📅 ' . wp_date('M j', $timestamp);
        $location = get_post_meta($id, 'address', true) ?: get_post_meta($id, 'location', true);
        if ($location) $meta[] = '📍 ' . wp_trim_words(wp_strip_all_tags((string) $location), 5, '');
        if ($post_type === 'tng_destination' || $post_type === 'st_location') $meta[] = '🗺️ Destination';
        if ($post_type === 'top_sight') $meta[] = '📍 Top Sight';
        return array_slice(array_unique(array_filter($meta)), 0, 2);
    }

    private static function render_cards(array $posts, string $layout = 'grid'): string {
        if (!$posts) return '';
        ob_start();
        echo '<div class="tng-content-grid tng-content-grid--' . esc_attr($layout) . '">';
        foreach ($posts as $post) {
            $id = $post->ID;
            $post_type = get_post_type($id);
            $type = get_post_type_object($post_type);
            $label = $type && !empty($type->labels->singular_name) ? $type->labels->singular_name : 'Explore';
            $image = get_the_post_thumbnail_url($id, 'large');
            $excerpt = self::clean_excerpt($id);
            $meta = self::card_meta($id, $post_type);
            $classes = ['tng-content-card'];
            if (self::is_event($id)) $classes[] = 'tng-content-card--event';
            if ($post_type === 'tng_destination' || $post_type === 'st_location') $classes[] = 'tng-content-card--destination';
            echo '<article class="' . esc_attr(implode(' ', $classes)) . '">';
            $media_class = 'tng-content-card__media' . ($image ? '' : ' is-placeholder');
            $style = $image ? ' style="background-image:url(' . esc_url($image) . ')"' : '';
            echo '<a class="' . esc_attr($media_class) . '" href="' . esc_url(get_permalink($id)) . '"' . $style . '><span>' . esc_html($label) . '</span></a>';
            echo '<div class="tng-content-card__body"><h3><a href="' . esc_url(get_permalink($id)) . '">' . esc_html(get_the_title($id)) . '</a></h3>';
            if ($meta) {
                echo '<div class="tng-content-card__meta">';
                foreach ($meta as $item) echo '<span>' . esc_html($item) . '</span>';
                echo '</div>';
            }
            if ($excerpt) echo '<p>' . esc_html($excerpt) . '</p>';
            echo '<a class="tng-content-card__link" href="' . esc_url(get_permalink($id)) . '">Explore <span aria-hidden="true">→</span></a></div></article>';
        }
        echo '</div>';
        return (string) ob_get_clean();
    }

    private static function curated_content(): array {
        $types = self::discoverable_post_types();
        if (!$types) return ['discoveries' => '', 'events' => ''];
        $query = new WP_Query([
            'post_type' => $types,
            'post_status' => 'publish',
            'posts_per_page' => max(12, (int) self::settings()['content_limit'] * 3),
            'ignore_sticky_posts' => true,
            'orderby' => 'modified',
            'order' => 'DESC',
        ]);
        $events = [];
        $discoveries = [];
        foreach ($query->posts as $post) {
            if (self::is_event($post->ID)) $events[] = $post;
            else $discoveries[] = $post;
        }
        usort($events, static function ($a, $b): int {
            $a_time = self::event_timestamp($a->ID) ?: PHP_INT_MAX;
            $b_time = self::event_timestamp($b->ID) ?: PHP_INT_MAX;
            return $a_time <=> $b_time;
        });
        $limit = (int) self::settings()['content_limit'];
        return [
            'discoveries' => self::render_cards(array_slice($discoveries, 0, $limit), 'grid'),
            'events' => self::render_cards(array_slice($events, 0, min(4, $limit)), 'rail'),
        ];
    }

    public static function explore(): string {
        $categories = [['🥾','Trails','/trails/'],['🎮','Games','/play/'],['📍','Top Sights','/top-sights/'],['🎵','Events','/events/'],['🍽️','Food','/food/'],['🗺️','Destinations','/destinations/']];
        $content = self::curated_content();
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
            <?php if ($content['discoveries']): ?><section class="tng-section tng-discover"><div class="tng-section__heading"><div><span class="tng-eyebrow">Fresh discoveries</span><h2>Continue exploring</h2><p class="tng-curated-note">Places, trails and local favorites from across The TN Game.</p></div><a href="<?php echo esc_url(home_url('/search/')); ?>">View all</a></div><?php echo $content['discoveries']; ?></section><?php endif; ?>
            <?php if ($content['events']): ?><section class="tng-section tng-upcoming"><div class="tng-section__heading"><div><span class="tng-eyebrow">Plan ahead</span><h2>Upcoming events</h2><p class="tng-curated-note">Concerts and experiences worth building a trip around.</p></div><a href="<?php echo esc_url(home_url('/events/')); ?>">All events</a></div><?php echo $content['events']; ?></section><?php endif; ?>
            <section class="tng-play-card"><div><span class="tng-eyebrow">Ready to play?</span><h2>Turn your next outing into a game.</h2><p>Find nearby challenges, complete checkpoints and grow your Explorer profile.</p></div><a class="tng-button" href="<?php echo esc_url(home_url('/play/')); ?>">Start Playing</a></section>
        </main>
        <?php return (string) ob_get_clean();
    }
}

TNG_Platform_UI::boot();
