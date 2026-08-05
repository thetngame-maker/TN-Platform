<?php
/**
 * Plugin Name: TN Game Map UI
 * Description: Native TN Game discovery map screen for the app router.
 * Version: 0.2.0
 * Author: The TN Game
 */
if (!defined('ABSPATH')) exit;

final class TNG_Map_UI {
    public static function boot(): void {
        add_filter('template_include', [self::class, 'template'], 100000);
    }

    public static function template(string $template): string {
        if (!class_exists('TNG_OS\\Platform\\App_Router')) return $template;
        if (TNG_OS\Platform\App_Router::current_route() !== 'map') return $template;
        $map_template = TNG_OS_PATH . 'templates/map-shell.php';
        return is_readable($map_template) ? $map_template : $template;
    }

    private static function nearby_posts(): array {
        $types = array_values(array_filter(['st_activity','activity','top_sight','tng_destination','st_location'], 'post_type_exists'));
        if (!$types) return [];
        $query = new WP_Query([
            'post_type' => $types,
            'post_status' => 'publish',
            'posts_per_page' => 6,
            'ignore_sticky_posts' => true,
            'orderby' => 'modified',
            'order' => 'DESC',
        ]);
        return $query->posts;
    }

    private static function cards(): string {
        $posts = self::nearby_posts();
        if (!$posts) return '<div class="tng-map-empty">Nearby places will appear here as map-ready content is published.</div>';
        ob_start();
        echo '<div class="tng-map-results">';
        foreach ($posts as $post) {
            $id = $post->ID;
            $image = get_the_post_thumbnail_url($id, 'medium_large');
            $type = get_post_type_object(get_post_type($id));
            $label = $type && !empty($type->labels->singular_name) ? $type->labels->singular_name : 'Place';
            echo '<a class="tng-map-result" href="' . esc_url(get_permalink($id)) . '">';
            echo '<span class="tng-map-result__media"' . ($image ? ' style="background-image:url(' . esc_url($image) . ')"' : '') . '></span>';
            echo '<span class="tng-map-result__copy"><small>' . esc_html($label) . '</small><strong>' . esc_html(get_the_title($id)) . '</strong><em>View details →</em></span>';
            echo '</a>';
        }
        echo '</div>';
        return (string) ob_get_clean();
    }

    public static function render(): string {
        $cards = self::cards();
        ob_start(); ?>
        <main class="tng-map-screen tng-app-shell">
            <section class="tng-map-toolbar">
                <div><span class="tng-eyebrow">Explore nearby</span><h1>Adventure map</h1><p>Find trails, games, sights, food, and local places around you.</p></div>
                <button class="tng-ui-button" type="button" data-tng-locate><span>⌖</span> Use my location</button>
            </section>

            <section class="tng-map-layout">
                <div class="tng-map-canvas" aria-label="Interactive map area">
                    <div class="tng-map-canvas__grid"></div>
                    <div class="tng-map-pin tng-map-pin--one"><span>🥾</span></div>
                    <div class="tng-map-pin tng-map-pin--two"><span>🎮</span></div>
                    <div class="tng-map-pin tng-map-pin--three"><span>📍</span></div>
                    <div class="tng-map-you"><span></span>You are here</div>
                    <div class="tng-map-controls"><button type="button">＋</button><button type="button">−</button><button type="button">⌖</button></div>
                    <div class="tng-map-status"><strong>Map connection ready</strong><small>The existing TN Game map data will plug into this surface.</small></div>
                </div>

                <aside class="tng-map-panel">
                    <div class="tng-map-filters" aria-label="Map filters">
                        <button class="is-active" type="button">All</button>
                        <button type="button">Trails</button>
                        <button type="button">Games</button>
                        <button type="button">Sights</button>
                        <button type="button">Food</button>
                    </div>
                    <div class="tng-map-panel__heading"><div><span class="tng-eyebrow">Around you</span><h2>Nearby discoveries</h2></div><a href="<?php echo esc_url(home_url('/search/')); ?>">View all</a></div>
                    <?php echo $cards; ?>
                </aside>
            </section>
        </main>
        <?php return (string) ob_get_clean();
    }
}

TNG_Map_UI::boot();
