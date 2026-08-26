<?php
namespace TNG_OS\Platform;

use TNG_OS\Modules\Destinations\Coordinate_Intelligence;
use WP_Post;

if (!defined('ABSPATH')) exit;

/**
 * Canonical registry for every public, map-capable TN Game object.
 *
 * The registry deliberately normalizes legacy Traveler records, native TN Game
 * objects, games, destinations, and concert venues into one stable payload.
 */
final class Universal_Map_Registry {
    private const AUDIT_PAGE = 'tng-universal-map-audit';

    public static function boot(): void {
        add_action('admin_menu', [self::class, 'admin_menu'], 26);
    }

    public static function categories(): array {
        return [
            'trail'       => ['label' => 'Trails',       'singular' => 'Trail',        'icon' => '🥾'],
            'game'        => ['label' => 'Games',        'singular' => 'Game',         'icon' => '🎮'],
            'sight'       => ['label' => 'Sights',       'singular' => 'Top Sight',    'icon' => '📍'],
            'food'        => ['label' => 'Food',         'singular' => 'Food & Drink', 'icon' => '🍽️'],
            'event'       => ['label' => 'Events',       'singular' => 'Event',        'icon' => '🎵'],
            'lodging'     => ['label' => 'Stay',         'singular' => 'Lodging',      'icon' => '🛏️'],
            'tour'        => ['label' => 'Tours',        'singular' => 'Tour',         'icon' => '🚌'],
            'rental'      => ['label' => 'Rentals',      'singular' => 'Rental',       'icon' => '🏡'],
            'transport'   => ['label' => 'Transport',    'singular' => 'Transport',    'icon' => '🚗'],
            'destination' => ['label' => 'Destinations', 'singular' => 'Destination',  'icon' => '🗺️'],
            'venue'       => ['label' => 'Venues',       'singular' => 'Venue',        'icon' => '🎤'],
            'place'       => ['label' => 'Places',       'singular' => 'Place',        'icon' => '•'],
        ];
    }

    public static function post_types(): array {
        $types = [
            'tng_game','game',
            'st_activity','activity',
            'top_sight','top-sight','topsight','top-sights','tng_top_sight',
            'tng_destination','st_location',
            'st_hotel','hotel',
            'st_tours','st_tour','tour',
            'st_rental','rental',
            'st_cars','st_car','car',
            'tng_venue',
        ];
        $types = apply_filters('tng_universal_map_post_types', $types);
        return array_values(array_unique(array_filter((array) $types, 'post_type_exists')));
    }

    public static function dataset(): array {
        $items = [];
        $counts = array_fill_keys(array_keys(self::categories()), 0);
        $coverage = ['eligible' => 0, 'mapped' => 0, 'missing' => 0, 'suspicious' => 0, 'unavailable' => 0];

        foreach (self::posts() as $post) {
            $coverage['eligible']++;
            $id = (int) $post->ID;
            if (self::unavailable($post)) {
                $coverage['unavailable']++;
                continue;
            }
            $resolved = self::coordinates($id);
            if (empty($resolved['lat']) || empty($resolved['lng'])) {
                $coverage['missing']++;
                continue;
            }
            if (($resolved['status'] ?? '') === 'suspicious') {
                $coverage['suspicious']++;
                continue;
            }
            $kind = self::kind($id);
            $item = self::item($post, $kind, $resolved);
            if (!$item) continue;
            $items[] = $item;
            $counts[$kind] = ($counts[$kind] ?? 0) + 1;
            $coverage['mapped']++;
        }

        usort($items, static function(array $a, array $b): int {
            $order = array_flip(array_keys(self::categories()));
            return ($order[$a['kind']] ?? 99) <=> ($order[$b['kind']] ?? 99)
                ?: strcasecmp($a['title'], $b['title']);
        });

        $active_categories = [];
        foreach (self::categories() as $kind => $category) {
            if (empty($counts[$kind])) continue;
            $active_categories[$kind] = $category + ['count' => (int) $counts[$kind]];
        }

        return [
            'items' => $items,
            'categories' => $active_categories,
            'coverage' => $coverage,
        ];
    }

    private static function posts(): array {
        $types = self::post_types();
        if (!$types) return [];
        return get_posts([
            'post_type' => $types,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'orderby' => 'modified',
            'order' => 'DESC',
            'ignore_sticky_posts' => true,
            'no_found_rows' => true,
            'suppress_filters' => false,
        ]);
    }

    private static function unavailable(WP_Post $post): bool {
        return $post->post_type === 'tng_game'
            && class_exists('TNG_Games_UI')
            && !\TNG_Games_UI::is_player_ready((int) $post->ID);
    }

    private static function item(WP_Post $post, string $kind, array $resolved): array {
        $id = (int) $post->ID;
        $title = html_entity_decode(wp_strip_all_tags(get_the_title($id)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($title === '') return [];
        $category = self::categories()[$kind] ?? self::categories()['place'];
        $excerpt = self::excerpt($id);
        $search = trim($title . ' ' . $category['label'] . ' ' . self::searchable_text($id));
        $xp = self::xp($id);
        return [
            'id' => $id,
            'title' => $title,
            'kind' => $kind,
            'label' => $category['singular'],
            'icon' => $category['icon'],
            'lat' => round((float) $resolved['lat'], 7),
            'lng' => round((float) $resolved['lng'], 7),
            'url' => get_permalink($id) ?: '',
            'actionUrl' => self::action_url($id, $kind),
            'actionLabel' => self::action_label($kind),
            'image' => get_the_post_thumbnail_url($id, 'medium_large') ?: '',
            'subtitle' => $excerpt,
            'search' => strtolower(wp_strip_all_tags($search)),
            'sourceType' => $post->post_type,
            'coordinateStatus' => (string) ($resolved['status'] ?? 'exact'),
            'xp' => $xp,
        ];
    }

    private static function action_url(int $id, string $kind): string {
        if ($kind === 'game') return add_query_arg('game', $id, home_url('/game-play/'));
        return get_permalink($id) ?: home_url('/map/');
    }

    private static function action_label(string $kind): string {
        return [
            'game' => 'Play game', 'trail' => 'View trail', 'sight' => 'View sight',
            'food' => 'View place', 'event' => 'View event', 'lodging' => 'View stay',
            'tour' => 'View tour', 'rental' => 'View rental', 'transport' => 'View option',
            'destination' => 'Explore', 'venue' => 'View venue',
        ][$kind] ?? 'View';
    }

    private static function kind(int $id): string {
        $type = (string) get_post_type($id);
        if (in_array($type, ['tng_game','game'], true)) return 'game';
        if (in_array($type, ['top_sight','top-sight','topsight','top-sights','tng_top_sight'], true)) return 'sight';
        if (in_array($type, ['tng_destination','st_location'], true)) return 'destination';
        if (in_array($type, ['st_hotel','hotel'], true)) return 'lodging';
        if (in_array($type, ['st_tours','st_tour','tour'], true)) return 'tour';
        if (in_array($type, ['st_rental','rental'], true)) return 'rental';
        if (in_array($type, ['st_cars','st_car','car'], true)) return 'transport';
        if ($type === 'tng_venue') return 'venue';
        if (class_exists('TNG_Games_UI') && \TNG_Games_UI::is_game($id)) return 'game';

        $text = self::searchable_text($id);
        if (preg_match('/restaurant|food|cafe|coffee|burger|kitchen|grill|dining|barbecue|bbq|bakery|brewery|pizza|cantina/', $text)) return 'food';
        if (preg_match('/trail|hike|hiking|loop|overlook|waterfall|falls|state park|climb|cave|outdoor/', $text)) return 'trail';
        if (preg_match('/concert|festival|show|event|live music|performance|the caverns/', $text)) return 'event';
        foreach (['_tng_event_start','start_date','event_date','date','st_start_date'] as $key) {
            if (get_post_meta($id, $key, true) !== '') return 'event';
        }
        return 'place';
    }

    private static function searchable_text(int $id): string {
        $text = strtolower(get_the_title($id) . ' ' . get_post_field('post_excerpt', $id) . ' ' . get_post_field('post_content', $id));
        foreach (get_object_taxonomies((string) get_post_type($id)) as $taxonomy) {
            $terms = wp_get_post_terms($id, $taxonomy, ['fields' => 'names']);
            if (!is_wp_error($terms)) $text .= ' ' . strtolower(implode(' ', $terms));
        }
        $profile = get_post_meta($id, '_tng_destination_ai_profile', true);
        if (is_array($profile)) $text .= ' ' . strtolower(wp_json_encode($profile));
        return preg_replace('/\s+/', ' ', wp_strip_all_tags($text));
    }

    private static function excerpt(int $id): string {
        $source = has_excerpt($id) ? get_post_field('post_excerpt', $id) : get_post_field('post_content', $id);
        $source = preg_replace('/\[[^\]]+\]/', ' ', strip_shortcodes((string) $source));
        $source = preg_replace('/\s+/', ' ', trim(wp_strip_all_tags((string) $source)));
        return html_entity_decode(wp_trim_words($source, 14, '…'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private static function xp(int $id): int {
        foreach (['_tng_xp','tng_xp_reward','sight_xp','xp','xp_reward'] as $key) {
            $value = get_post_meta($id, $key, true);
            if (is_numeric($value) && (int) $value > 0) return (int) $value;
        }
        if (get_post_type($id) === 'tng_game') {
            $checkpoints = get_post_meta($id, 'tng_game_checkpoints', true);
            $xp = 0;
            if (is_array($checkpoints)) foreach ($checkpoints as $checkpoint) {
                if (is_array($checkpoint)) $xp += max(0, (int) ($checkpoint['xp'] ?? 0));
            }
            return $xp;
        }
        return 0;
    }

    private static function coordinates(int $id): array {
        $pairs = [
            ['_sight_latitude','_sight_longitude'], ['sight_latitude','sight_longitude'],
            ['top_sight_latitude','top_sight_longitude'], ['_tng_destination_lat','_tng_destination_lng'],
            ['tng_destination_lat','tng_destination_lng'], ['_tng_venue_lat','_tng_venue_lng'],
            ['map_lat','map_lng'], ['latitude','longitude'], ['lat','lng'], ['_latitude','_longitude'],
            ['_lat','_lng'], ['st_latitude','st_longitude'], ['map_latitude','map_longitude'],
            ['location_lat','location_lng'], ['address_lat','address_lng'],
        ];
        foreach ($pairs as [$lat_key, $lng_key]) {
            $coords = self::validate(get_post_meta($id, $lat_key, true), get_post_meta($id, $lng_key, true));
            if ($coords) return ['lat' => $coords[0], 'lng' => $coords[1], 'status' => self::suspicious($coords[0], $coords[1]) ? 'suspicious' : 'exact', 'source' => $lat_key];
            if (function_exists('get_field')) {
                $coords = self::validate(get_field($lat_key, $id), get_field($lng_key, $id));
                if ($coords) return ['lat' => $coords[0], 'lng' => $coords[1], 'status' => self::suspicious($coords[0], $coords[1]) ? 'suspicious' : 'exact', 'source' => $lat_key];
            }
        }
        foreach (['st_google_map','location','map','google_map','coordinates','map_location','top_sight_location','map_data','location_data','address'] as $key) {
            $value = get_post_meta($id, $key, true);
            $coords = self::coordinates_from_value($value);
            if (!$coords && function_exists('get_field')) $coords = self::coordinates_from_value(get_field($key, $id));
            if ($coords) return ['lat' => $coords[0], 'lng' => $coords[1], 'status' => self::suspicious($coords[0], $coords[1]) ? 'suspicious' : 'exact', 'source' => $key];
        }
        if (get_post_type($id) === 'tng_game') {
            $checkpoints = get_post_meta($id, 'tng_game_checkpoints', true);
            if (is_array($checkpoints)) foreach ($checkpoints as $checkpoint) {
                if (!is_array($checkpoint)) continue;
                $coords = self::validate($checkpoint['latitude'] ?? null, $checkpoint['longitude'] ?? null);
                if ($coords) return ['lat' => $coords[0], 'lng' => $coords[1], 'status' => self::suspicious($coords[0], $coords[1]) ? 'suspicious' : 'game_start', 'source' => 'first checkpoint'];
            }
        }
        if (class_exists(Coordinate_Intelligence::class)) {
            $resolved = Coordinate_Intelligence::resolve($id);
            if (isset($resolved['lat'], $resolved['lng'])) return [
                'lat' => (float) $resolved['lat'], 'lng' => (float) $resolved['lng'],
                'status' => (string) ($resolved['status'] ?? 'inherited'), 'source' => (string) ($resolved['label'] ?? 'Coordinate Intelligence'),
            ];
        }
        return [];
    }

    private static function coordinates_from_value($value): ?array {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) $value = $decoded;
            elseif (preg_match('/(-?\d{1,2}\.\d+)\s*[,| ]\s*(-?\d{1,3}\.\d+)/', $value, $matches)) return self::validate($matches[1], $matches[2]);
        }
        if (!is_array($value)) return null;
        foreach (['lat','latitude','map_lat','st_latitude'] as $lat_key) foreach (['lng','lon','longitude','map_lng','st_longitude'] as $lng_key) {
            if (!array_key_exists($lat_key, $value) || !array_key_exists($lng_key, $value)) continue;
            $coords = self::validate($value[$lat_key], $value[$lng_key]);
            if ($coords) return $coords;
        }
        foreach ($value as $child) {
            $coords = self::coordinates_from_value($child);
            if ($coords) return $coords;
        }
        return null;
    }

    private static function validate($lat, $lng): ?array {
        if (!is_numeric($lat) || !is_numeric($lng)) return null;
        $lat = (float) $lat; $lng = (float) $lng;
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || ($lat == 0.0 && $lng == 0.0)) return null;
        return [$lat, $lng];
    }

    private static function suspicious(float $lat, float $lng): bool {
        return $lat < 34.0 || $lat > 37.5 || $lng < -90.5 || $lng > -81.5;
    }

    public static function admin_menu(): void {
        add_submenu_page('tn-game-os', 'Universal Map Audit', 'Universal Map', 'edit_posts', self::AUDIT_PAGE, [self::class, 'audit_page']);
    }

    public static function audit_page(): void {
        if (!current_user_can('edit_posts')) wp_die('Unauthorized.');
        $dataset = self::dataset();
        $coverage = $dataset['coverage'];
        $missing = [];
        foreach (self::posts() as $post) {
            if (self::unavailable($post)) continue;
            $coords = self::coordinates((int) $post->ID);
            if ($coords && ($coords['status'] ?? '') !== 'suspicious') continue;
            $missing[] = ['post' => $post, 'status' => $coords ? 'Suspicious' : 'Missing'];
        }
        ?>
        <div class="wrap tng-universal-map-audit">
            <h1>Universal Map Audit</h1>
            <p>Coverage for every published, map-capable TN Game object. Suspicious coordinates stay off the public map until corrected.</p>
            <style>
                .tng-uma-stats{display:grid;grid-template-columns:repeat(4,minmax(140px,1fr));gap:14px;max-width:1050px;margin:22px 0}.tng-uma-stat{padding:18px;border:1px solid #dcdcde;border-radius:16px;background:#fff}.tng-uma-stat strong{display:block;color:#145b3b;font-size:30px}.tng-uma-chips{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 22px}.tng-uma-chip{padding:7px 10px;border-radius:999px;background:#edf7ef;color:#145b3b;font-weight:700}@media(max-width:760px){.tng-uma-stats{grid-template-columns:repeat(2,1fr)}}
            </style>
            <div class="tng-uma-stats">
                <div class="tng-uma-stat"><strong><?php echo number_format_i18n($coverage['mapped']); ?></strong><span>Mapped</span></div>
                <div class="tng-uma-stat"><strong><?php echo number_format_i18n($coverage['eligible']); ?></strong><span>Eligible objects</span></div>
                <div class="tng-uma-stat"><strong><?php echo number_format_i18n($coverage['missing']); ?></strong><span>Missing coordinates</span></div>
                <div class="tng-uma-stat"><strong><?php echo number_format_i18n($coverage['suspicious']); ?></strong><span>Suspicious coordinates</span></div>
            </div>
            <div class="tng-uma-chips">
                <?php foreach ($dataset['categories'] as $category): ?><span class="tng-uma-chip"><?php echo esc_html($category['icon'] . ' ' . $category['label'] . ' ' . number_format_i18n($category['count'])); ?></span><?php endforeach; ?>
            </div>
            <h2>Needs map data</h2>
            <?php if (!$missing): ?><div class="notice notice-success inline"><p>Every eligible object has valid Tennessee coordinates.</p></div><?php else: ?>
            <table class="widefat striped"><thead><tr><th>Object</th><th>Type</th><th>Status</th><th>Action</th></tr></thead><tbody>
                <?php foreach ($missing as $row): $post = $row['post']; ?><tr>
                    <td><strong><?php echo esc_html(get_the_title($post) ?: ('#' . $post->ID)); ?></strong></td>
                    <td><?php echo esc_html($post->post_type); ?></td>
                    <td><?php echo esc_html($row['status']); ?></td>
                    <td><a class="button" href="<?php echo esc_url(get_edit_post_link($post->ID)); ?>">Edit map data</a></td>
                </tr><?php endforeach; ?>
            </tbody></table><?php endif; ?>
        </div>
        <?php
    }
}

Universal_Map_Registry::boot();
