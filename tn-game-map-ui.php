<?php
/**
 * Component Name: TN Game Map UI
 * Description: Universal TN Game discovery map screen for the app router.
 * Version: 1.0.0
 * Author: The TN Game
 */
if (!defined('ABSPATH')) exit;

final class TNG_Map_UI {
    private static $dataset = null;

    public static function boot(): void {
        add_filter('template_include', [self::class, 'template'], 100000);
        add_action('wp_enqueue_scripts', [self::class, 'assets'], 130);
    }

    public static function template(string $template): string {
        if (!class_exists('TNG_OS\\Platform\\App_Router') || TNG_OS\Platform\App_Router::current_route() !== 'map') return $template;
        $map_template = TNG_OS_PATH . 'templates/map-shell.php';
        return is_readable($map_template) ? $map_template : $template;
    }

    private static function is_map(): bool {
        return class_exists('TNG_OS\\Platform\\App_Router') && TNG_OS\Platform\App_Router::current_route() === 'map';
    }

    private static function dataset(): array {
        if (is_array(self::$dataset)) return self::$dataset;
        if (class_exists('TNG_OS\\Platform\\Universal_Map_Registry')) self::$dataset = TNG_OS\Platform\Universal_Map_Registry::dataset();
        else self::$dataset = ['items' => [], 'categories' => [], 'coverage' => ['mapped' => 0, 'eligible' => 0, 'missing' => 0, 'suspicious' => 0]];
        return self::$dataset;
    }

    public static function assets(): void {
        if (!self::is_map()) return;
        $dataset = self::dataset();
        $items = $dataset['items'];
        wp_dequeue_style('tng-map-ui');
        wp_deregister_style('tng-map-ui');
        wp_enqueue_style('tng-leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', [], '1.9.4');
        wp_enqueue_style('tng-leaflet-markercluster', 'https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css', ['tng-leaflet'], '1.5.3');
        wp_enqueue_style('tng-leaflet-markercluster-default', 'https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css', ['tng-leaflet-markercluster'], '1.5.3');
        wp_enqueue_style('tng-map-ui', TNG_OS_URL . 'assets/css/map-ui.css', ['tng-ui-kit','tng-leaflet-markercluster-default'], TNG_OS_VERSION);
        wp_enqueue_style('tng-map-mobile-final', TNG_OS_URL . 'assets/css/map-mobile-final.css', ['tng-map-ui'], TNG_OS_VERSION);
        wp_enqueue_script('tng-leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', [], '1.9.4', true);
        wp_enqueue_script('tng-leaflet-markercluster', 'https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js', ['tng-leaflet'], '1.5.3', true);
        wp_enqueue_script('tng-map-ui-live', TNG_OS_URL . 'assets/js/map-ui.js', ['tng-leaflet-markercluster','tng-trip-data'], TNG_OS_VERSION, true);
        wp_enqueue_script('tng-map-mobile-final', TNG_OS_URL . 'assets/js/map-mobile-final.js', ['tng-map-ui-live'], TNG_OS_VERSION, true);
        $center = $items ? [(float) $items[0]['lat'], (float) $items[0]['lng']] : [35.8601, -86.6602];
        wp_localize_script('tng-map-ui-live', 'TNG_DISCOVERY_MAP', ['items' => $items, 'categories' => $dataset['categories'], 'coverage' => $dataset['coverage'], 'center' => $center, 'zoom' => 7]);
    }

    private static function cards(array $items): string {
        if (!$items) return '<div class="tng-map-empty">Map-ready discoveries will appear here as coordinates are added to TN Game content.</div>';
        ob_start();
        echo '<div class="tng-map-results" data-tng-map-results>';
        foreach ($items as $item) {
            $placeholder = empty($item['image']) ? ' is-placeholder' : '';
            $media_style = !empty($item['image']) ? ' style="background-image:url(' . esc_url($item['image']) . ')"' : '';
            echo '<article class="tng-map-result" data-tng-map-result="' . esc_attr((string) $item['id']) . '" data-kind="' . esc_attr($item['kind']) . '" data-search="' . esc_attr($item['search']) . '" data-lat="' . esc_attr((string) $item['lat']) . '" data-lng="' . esc_attr((string) $item['lng']) . '">';
            echo '<span class="tng-map-result__media' . esc_attr($placeholder) . '"' . $media_style . '><i>' . esc_html($item['icon']) . '</i></span>';
            echo '<span class="tng-map-result__copy"><small>' . esc_html($item['label']) . '</small><strong>' . esc_html($item['title']) . '</strong>';
            if (!empty($item['subtitle'])) echo '<em>' . esc_html($item['subtitle']) . '</em>';
            echo '<span class="tng-map-result__meta"><b data-tng-distance></b>';
            if (!empty($item['xp'])) echo '<b class="is-xp">+' . number_format_i18n((int) $item['xp']) . ' XP</b>';
            echo '</span><span class="tng-map-result__actions"><a class="is-primary" data-tng-open-details href="' . esc_url($item['actionUrl']) . '">' . esc_html($item['actionLabel']) . '</a><button type="button" data-tng-directions data-lat="' . esc_attr((string) $item['lat']) . '" data-lng="' . esc_attr((string) $item['lng']) . '">Directions</button><button type="button" data-tng-trip-toggle data-post-id="' . esc_attr((string) $item['id']) . '">＋ Add to trip</button></span></span></article>';
        }
        echo '</div><div class="tng-map-empty tng-map-empty--search" data-tng-map-empty hidden>No discoveries match this map search yet.</div>';
        return (string) ob_get_clean();
    }

    public static function render(): string {
        $dataset = self::dataset();
        $items = $dataset['items'];
        $categories = $dataset['categories'];
        $coverage = $dataset['coverage'];
        $cards = self::cards($items);
        ob_start(); ?>
        <main class="tng-map-screen tng-app-shell">
            <section class="tng-map-toolbar">
                <div><span class="tng-eyebrow">All of Tennessee, one map</span><h1>Universal map</h1><p>Explore every map-ready TN Game trail, game, sight, restaurant, event, stay, tour, rental, destination, and local place together.</p></div>
                <button class="tng-ui-button" type="button" data-tng-locate><span>⌖</span> Use my location</button>
            </section>
            <section class="tng-map-commandbar" aria-label="Search Universal Map">
                <label class="tng-map-search"><span class="screen-reader-text">Search the map</span><b aria-hidden="true">⌕</b><input type="search" data-tng-map-search placeholder="Search places, towns, trails…" autocomplete="off"><button type="button" data-tng-map-search-clear aria-label="Clear map search" hidden>×</button></label>
                <p><strong><?php echo number_format_i18n((int) $coverage['mapped']); ?></strong> mapped discoveries <span>across <?php echo number_format_i18n(count($categories)); ?> collections</span></p>
            </section>
            <section class="tng-map-filterbar" aria-label="Map filters">
                <button class="is-active" data-tng-map-filter="all" type="button">All <span><?php echo number_format_i18n(count($items)); ?></span></button>
                <?php foreach ($categories as $kind => $category): ?><button data-tng-map-filter="<?php echo esc_attr($kind); ?>" type="button"><?php echo esc_html($category['icon'] . ' ' . $category['label']); ?> <span><?php echo number_format_i18n((int) $category['count']); ?></span></button><?php endforeach; ?>
            </section>
            <section class="tng-map-nearest" data-tng-nearest hidden aria-live="polite"></section>
            <section class="tng-map-layout">
                <div class="tng-map-canvas-wrap"><div id="tng-discovery-map" class="tng-map-canvas" aria-label="Interactive Tennessee Universal Map"></div><div class="tng-map-live-status"><span class="tng-map-live-dot"></span><strong>Universal Map</strong><small data-tng-map-count><?php echo number_format_i18n(count($items)); ?> discoveries on map</small></div></div>
                <aside class="tng-map-panel"><div class="tng-map-panel__heading"><div><span class="tng-eyebrow">Around Tennessee</span><h2>Discoveries</h2></div><a href="<?php echo esc_url(home_url('/search/')); ?>">Search all</a></div><p class="tng-map-panel__intro" data-tng-panel-intro>Move the map to discover what is in view. Use your location to sort by distance.</p><?php echo $cards; ?></aside>
            </section>
            <div class="tng-map-sheet-backdrop" data-tng-map-sheet-close hidden></div>
            <section class="tng-map-sheet" data-tng-map-sheet role="dialog" aria-modal="true" aria-hidden="true" aria-label="Discovery details"><div class="tng-map-sheet__handle" data-tng-map-sheet-handle><span></span></div><button class="tng-map-sheet__close" type="button" data-tng-map-sheet-close aria-label="Close discovery details">×</button><div class="tng-map-sheet__content" data-tng-map-sheet-content></div></section>
        </main><?php
        return (string) ob_get_clean();
    }
}

TNG_Map_UI::boot();
