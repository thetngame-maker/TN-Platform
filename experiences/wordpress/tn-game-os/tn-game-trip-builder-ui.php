<?php
/**
 * Plugin Name: TN Game Trip Builder UI
 * Description: Native route builder for saved TN Game places.
 * Version: 0.4.0
 * Author: The TN Game
 */
if (!defined('ABSPATH')) exit;

final class TNG_Trip_Builder_UI {
    public static function boot(): void {
        add_action('wp_enqueue_scripts', [self::class, 'assets'], 90);
    }

    public static function assets(): void {
        if (!class_exists('TNG_OS\\Platform\\App_Router') || TNG_OS\Platform\App_Router::current_route() !== 'trip-builder') return;
        wp_enqueue_style('tng-trip-builder-leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', [], '1.9.4');
        wp_enqueue_style('tng-trip-builder-map', TNG_OS_URL . 'assets/css/trip-builder-map.css', ['tng-trip-builder-leaflet'], '0.4.0');
        wp_enqueue_script('tng-trip-builder-leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', [], '1.9.4', true);
    }

    private static function valid_coords($lat, $lng): bool {
        if (!is_numeric($lat) || !is_numeric($lng)) return false;
        $lat = (float) $lat; $lng = (float) $lng;
        return $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180 && !($lat === 0.0 && $lng === 0.0);
    }

    private static function coords_from_value($value): array {
        if (is_array($value)) {
            $lat = $value['lat'] ?? $value['latitude'] ?? null;
            $lng = $value['lng'] ?? $value['lon'] ?? $value['longitude'] ?? null;
            if (self::valid_coords($lat, $lng)) return [(float) $lat, (float) $lng];
        }
        if (is_string($value) && preg_match('/(-?\d{1,2}\.\d+)\s*[,| ]\s*(-?\d{1,3}\.\d+)/', $value, $m) && self::valid_coords($m[1], $m[2])) {
            return [(float) $m[1], (float) $m[2]];
        }
        return [];
    }

    private static function coordinates(int $id): array {
        foreach ([
            ['_sight_latitude','_sight_longitude'], ['sight_latitude','sight_longitude'],
            ['_tng_destination_lat','_tng_destination_lng'], ['tng_destination_lat','tng_destination_lng'],
            ['latitude','longitude'], ['lat','lng'], ['_latitude','_longitude'], ['_lat','_lng']
        ] as [$lat_key, $lng_key]) {
            $lat = get_post_meta($id, $lat_key, true); $lng = get_post_meta($id, $lng_key, true);
            if (self::valid_coords($lat, $lng)) return [(float) $lat, (float) $lng];
        }
        foreach (['st_google_map','location','map','google_map','coordinates'] as $key) {
            $coords = self::coords_from_value(get_post_meta($id, $key, true));
            if ($coords) return $coords;
            if (function_exists('get_field')) {
                $coords = self::coords_from_value(get_field($key, $id));
                if ($coords) return $coords;
            }
        }
        if (get_post_type($id) === 'tng_game') {
            $raw = get_post_meta($id, 'tng_game_checkpoints', true);
            if (is_array($raw)) foreach ($raw as $cp) {
                if (!is_array($cp)) continue;
                $lat = $cp['latitude'] ?? null; $lng = $cp['longitude'] ?? null;
                if (self::valid_coords($lat, $lng)) return [(float) $lat, (float) $lng];
            }
        }
        return [];
    }

    public static function render(): string {
        $logged_in = is_user_logged_in();
        $posts = ($logged_in && class_exists('TNG_Trip_Data')) ? TNG_Trip_Data::posts() : [];
        $mapped = 0;
        $coords_by_id = [];
        foreach ($posts as $post) {
            $coords = self::coordinates((int) $post->ID);
            if ($coords) { $coords_by_id[(int) $post->ID] = $coords; $mapped++; }
        }
        ob_start(); ?>
        <main class="tng-builder-screen tng-app-shell">
            <section class="tng-builder-hero">
                <div><span class="tng-eyebrow">Plan the day</span><h1>Build your trip.</h1><p>Put saved stops in the order you want to visit them, then start Trip Mode when you are ready to go.</p></div>
                <div class="tng-builder-hero__count"><strong><?php echo esc_html((string) count($posts)); ?></strong><small>Stops</small></div>
            </section>

            <nav class="tng-trip-tabs" aria-label="Trip planning">
                <a href="<?php echo esc_url(home_url('/trips/')); ?>">🗺 Trips</a>
                <a href="<?php echo esc_url(home_url('/saved/')); ?>">♡ Saved places</a>
                <a class="is-active" href="<?php echo esc_url(home_url('/trip-builder/')); ?>">☰ Trip builder</a>
                <a href="<?php echo esc_url(home_url('/active-trip/')); ?>">▶ Trip mode</a>
            </nav>

            <?php if (!$logged_in): ?>
                <section class="tng-builder-empty"><span>🗺</span><h2>Sign in to build a trip.</h2><p>Your route will stay synced to your Explorer account.</p><a class="tng-ui-button" href="<?php echo esc_url(wp_login_url(home_url('/trip-builder/'))); ?>">Sign in</a></section>
            <?php elseif (!$posts): ?>
                <section class="tng-builder-empty"><span>＋</span><h2>Add a few places first.</h2><p>Save trails, food, sights, destinations, and events, then arrange them here.</p><a class="tng-ui-button" href="<?php echo esc_url(home_url('/explore/')); ?>">Explore places</a></section>
            <?php else: ?>
                <section class="tng-builder-map-card" aria-labelledby="tng-builder-map-title">
                    <div class="tng-builder-map-heading">
                        <div><span class="tng-eyebrow">Visual itinerary</span><h2 id="tng-builder-map-title">Your route on the map</h2><p>Stop numbers follow the same order as your itinerary below.</p></div>
                        <div class="tng-builder-map-tools">
                            <div class="tng-builder-map-meta"><strong data-tng-builder-map-count><?php echo esc_html((string) $mapped); ?></strong><small>of <?php echo esc_html((string) count($posts)); ?> mapped</small></div>
                            <?php if ($mapped > 2): ?><button type="button" class="tng-builder-optimize" data-tng-optimize-route>⚡ Optimize route</button><?php endif; ?>
                        </div>
                    </div>
                    <div id="tng-trip-builder-map" class="tng-builder-map" aria-label="Map of saved trip stops"></div>
                    <div class="tng-builder-map-note"><span>↕</span><p>Reorder a stop below and this route updates automatically.</p><a href="<?php echo esc_url(home_url('/map/')); ?>">Add stops from map</a></div>
                </section>

                <div class="tng-builder-layout">
                    <section class="tng-builder-route">
                        <div class="tng-builder-heading"><div><span class="tng-eyebrow">Your route</span><h2>Arrange your stops</h2><p>Drag cards or use the arrow buttons to change the order.</p></div><span class="tng-builder-status" data-tng-builder-status>Saved</span></div>
                        <ol class="tng-builder-list" data-tng-builder-list>
                            <?php foreach ($posts as $index => $post): $image = get_the_post_thumbnail_url($post->ID, 'medium'); $coords = $coords_by_id[(int)$post->ID] ?? []; ?>
                                <li class="tng-builder-stop" draggable="true" data-post-id="<?php echo esc_attr((string)$post->ID); ?>"<?php if ($coords): ?> data-lat="<?php echo esc_attr((string)$coords[0]); ?>" data-lng="<?php echo esc_attr((string)$coords[1]); ?>"<?php endif; ?> data-title="<?php echo esc_attr(get_the_title($post)); ?>">
                                    <span class="tng-builder-stop__number"><?php echo esc_html((string)($index + 1)); ?></span>
                                    <span class="tng-builder-stop__media"<?php echo $image ? ' style="background-image:url(' . esc_url($image) . ')"' : ''; ?>></span>
                                    <div class="tng-builder-stop__copy"><small><?php echo esc_html(get_post_type_object(get_post_type($post->ID))->labels->singular_name ?? 'Place'); ?></small><h3><a href="<?php echo esc_url(get_permalink($post)); ?>"><?php echo esc_html(get_the_title($post)); ?></a></h3><?php if (!$coords): ?><span class="tng-builder-stop__map-warning">Location needed for map</span><?php else: ?><span class="tng-builder-stop__leg" data-tng-leg-distance><?php echo $index === 0 ? 'Start here' : 'Calculating…'; ?></span><?php endif; ?></div>
                                    <div class="tng-builder-stop__actions"><button type="button" data-move="up" aria-label="Move up">↑</button><button type="button" data-move="down" aria-label="Move down">↓</button><button type="button" class="is-remove" data-tng-trip-toggle data-post-id="<?php echo esc_attr((string)$post->ID); ?>" aria-label="Remove stop">×</button></div>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                    </section>

                    <aside class="tng-builder-summary">
                        <span class="tng-eyebrow">Trip summary</span><h2>Your Tennessee day</h2>
                        <dl>
                            <div><dt>Stops</dt><dd data-tng-builder-count><?php echo esc_html((string)count($posts)); ?></dd></div>
                            <div><dt>Route distance</dt><dd data-tng-route-distance>Calculating…</dd></div>
                            <div><dt>Travel time</dt><dd data-tng-route-time>Calculating…</dd></div>
                            <div><dt>Adventure time</dt><dd><?php echo esc_html((string) max(2, count($posts) * 2)); ?>–<?php echo esc_html((string) max(4, count($posts) * 3)); ?> hr</dd></div>
                        </dl>
                        <p class="tng-builder-summary__estimate">Distance and travel time are planning estimates based on stop locations. Trip Mode can hand each leg off to your navigation app.</p>
                        <a class="tng-ui-button" href="<?php echo esc_url(home_url('/active-trip/')); ?>">Start trip mode</a>
                        <a class="tng-ui-button tng-ui-button--secondary" href="<?php echo esc_url(home_url('/map/')); ?>">Add more from map</a>
                    </aside>
                </div>
            <?php endif; ?>
        </main>
        <?php return (string) ob_get_clean();
    }
}
TNG_Trip_Builder_UI::boot();
