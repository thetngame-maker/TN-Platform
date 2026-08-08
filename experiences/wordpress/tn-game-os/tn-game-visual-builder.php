<?php
/**
 * Plugin Name: TN Game Visual Checkpoint Builder
 * Description: Visual map-based checkpoint creator for the TN Game front-end game builder.
 * Version: 0.3.1
 * Author: The TN Game
 */
if (!defined('ABSPATH')) exit;

final class TNG_Game_Visual_Builder {
    public static function boot(): void {
        add_action('wp_enqueue_scripts', [self::class, 'enqueue'], 125);
    }

    private static function is_builder(): bool {
        return class_exists('TNG_OS\\Platform\\App_Router')
            && TNG_OS\Platform\App_Router::current_route() === 'game-builder';
    }

    private static function first_meta(int $post_id, array $keys): string {
        foreach ($keys as $key) {
            $value = get_post_meta($post_id, $key, true);
            if (is_scalar($value) && trim((string)$value) !== '') return trim((string)$value);
            if (function_exists('get_field')) {
                $value = get_field($key, $post_id);
                if (is_scalar($value) && trim((string)$value) !== '') return trim((string)$value);
            }
        }
        return '';
    }

    private static function clean_text(string $text, int $words = 28): string {
        $text = strip_shortcodes($text);
        $text = preg_replace('/\[[^\]]+\]/', ' ', $text) ?? $text;
        $text = html_entity_decode(wp_strip_all_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        return wp_trim_words(trim($text), $words, '…');
    }

    private static function gpx_url(int $post_id): string {
        $keys = ['trail_gpx_url','gpx_url','trail_gpx','gpx_file','route_gpx','trail_route_gpx','_trail_gpx_url'];
        foreach ($keys as $key) {
            $value = get_post_meta($post_id, $key, true);
            if (is_array($value)) $value = $value['url'] ?? $value['file'] ?? '';
            if (is_numeric($value)) $value = wp_get_attachment_url((int) $value);
            if (is_string($value) && trim($value) !== '') {
                $value = trim($value);
                if (filter_var($value, FILTER_VALIDATE_URL)) return esc_url_raw($value);
                if (str_starts_with($value, '/')) return esc_url_raw(home_url($value));
            }
        }
        if (function_exists('get_field')) {
            foreach ($keys as $key) {
                $value = get_field($key, $post_id);
                if (is_array($value)) $value = $value['url'] ?? $value['file'] ?? '';
                if (is_numeric($value)) $value = wp_get_attachment_url((int) $value);
                if (is_string($value) && filter_var(trim($value), FILTER_VALIDATE_URL)) return esc_url_raw(trim($value));
            }
        }
        return '';
    }

    private static function coordinates(int $post_id): array {
        $pairs = [
            ['_sight_latitude','_sight_longitude'],['sight_latitude','sight_longitude'],
            ['latitude','longitude'],['lat','lng'],['trail_latitude','trail_longitude'],['st_latitude','st_longitude'],
            ['map_lat','map_lng'],['location_lat','location_lng'],['_latitude','_longitude'],['tng_latitude','tng_longitude']
        ];
        foreach ($pairs as $pair) {
            $lat = get_post_meta($post_id, $pair[0], true);
            $lng = get_post_meta($post_id, $pair[1], true);
            if (is_numeric($lat) && is_numeric($lng)) return [(float) $lat, (float) $lng];
        }
        foreach (['location','top_sight_location','coordinates','map_location'] as $key) {
            $values = [get_post_meta($post_id, $key, true)];
            if (function_exists('get_field')) $values[] = get_field($key, $post_id);
            foreach ($values as $value) {
                if (!is_array($value)) continue;
                $lat = $value['lat'] ?? $value['latitude'] ?? null;
                $lng = $value['lng'] ?? $value['longitude'] ?? null;
                if (is_numeric($lat) && is_numeric($lng)) return [(float) $lat, (float) $lng];
            }
        }
        return [0.0, 0.0];
    }

    private static function sight_post_types(): array {
        $out = [];
        foreach (get_post_types([], 'objects') as $slug => $obj) {
            $haystack = strtolower($slug . ' ' . ($obj->label ?? '') . ' ' . ($obj->labels->singular_name ?? ''));
            if (
                str_contains($haystack, 'top sight') || str_contains($haystack, 'topsight') ||
                str_contains($haystack, 'top_sight') || str_contains($haystack, 'point of interest') ||
                preg_match('/(^|[^a-z])poi([^a-z]|$)/', $haystack)
            ) $out[] = $slug;
        }
        foreach (['top_sight','top-sight','top_sights','top-sights','topsight','topsights','tng_top_sight','tng_top_sights','poi'] as $slug) {
            if (post_type_exists($slug)) $out[] = $slug;
        }
        return array_values(array_unique($out));
    }

    private static function sights(): array {
        $types = self::sight_post_types();
        if (!$types) return [];
        $posts = get_posts([
            'post_type'=>$types,
            'post_status'=>['publish','private'],
            'posts_per_page'=>1000,
            'orderby'=>'title',
            'order'=>'ASC',
            'suppress_filters'=>false,
        ]);
        $out = [];
        foreach ($posts as $post) {
            $id = (int)$post->ID;
            [$lat,$lng] = self::coordinates($id);
            if (!$lat && !$lng) continue;
            $sight_type = self::first_meta($id, ['sight_type','_sight_type','top_sight_type','type']);
            $short = self::first_meta($id, ['sight_short_description','_sight_short_description','short_description','summary']);
            if ($short === '') $short = (string)get_the_excerpt($id);
            $out[] = [
                'id'=>$id,
                'title'=>get_the_title($post),
                'lat'=>$lat,
                'lng'=>$lng,
                'postType'=>$post->post_type,
                'sightType'=>$sight_type,
                'shortDescription'=>self::clean_text($short, 18),
            ];
        }
        return $out;
    }

    private static function collect_ids($value, array &$ids): void {
        if ($value instanceof WP_Post) { $ids[] = (int)$value->ID; return; }
        if (is_object($value) && isset($value->ID) && is_numeric($value->ID)) { $ids[] = (int)$value->ID; return; }
        if (is_numeric($value)) { $ids[] = (int)$value; return; }
        if (is_string($value)) {
            $maybe = maybe_unserialize($value);
            if ($maybe !== $value) { self::collect_ids($maybe, $ids); return; }
            if (preg_match_all('/\b\d{1,10}\b/', $value, $m)) foreach ($m[0] as $id) $ids[] = (int)$id;
            return;
        }
        if (is_array($value)) foreach ($value as $item) self::collect_ids($item, $ids);
    }

    private static function value_contains_id($value, int $target): bool {
        $ids = [];
        self::collect_ids($value, $ids);
        return in_array($target, array_map('intval',$ids), true);
    }

    private static function linked_sight_ids(int $trail_id, array $sights): array {
        $valid = array_fill_keys(array_map(static fn($s)=>(int)$s['id'], $sights), true);
        $ids = [];

        foreach (['related_top_sights','_related_top_sights'] as $relationship_key) {
            $relationship = get_post_meta($trail_id, $relationship_key, true);
            self::collect_ids($relationship, $ids);
            if (function_exists('get_field')) self::collect_ids(get_field($relationship_key, $trail_id), $ids);
        }

        $trail_meta = get_post_meta($trail_id);
        foreach ($trail_meta as $key => $values) {
            if (!preg_match('/sight|checkpoint|poi|landmark|attraction/i', (string)$key)) continue;
            foreach ($values as $value) self::collect_ids($value, $ids);
        }
        if (function_exists('get_fields')) {
            $fields = get_fields($trail_id);
            if (is_array($fields)) foreach ($fields as $key=>$value) {
                if (preg_match('/sight|checkpoint|poi|landmark|attraction/i', (string)$key)) self::collect_ids($value, $ids);
            }
        }
        foreach ($sights as $sight) {
            $sid = (int)$sight['id'];
            foreach (get_post_meta($sid) as $key=>$values) {
                if (!preg_match('/trail|activity|route/i', (string)$key)) continue;
                foreach ($values as $value) if (self::value_contains_id($value, $trail_id)) { $ids[] = $sid; break 2; }
            }
            if (function_exists('get_fields')) {
                $fields = get_fields($sid);
                if (is_array($fields)) foreach ($fields as $key=>$value) {
                    if (preg_match('/trail|activity|route/i', (string)$key) && self::value_contains_id($value,$trail_id)) { $ids[]=$sid; break; }
                }
            }
        }
        $ids = array_values(array_unique(array_map('intval',$ids)));
        return array_values(array_filter($ids, static fn($id)=>isset($valid[$id])));
    }

    private static function trails(array $sights): array {
        $types = array_values(array_filter(['st_activity','activity'], 'post_type_exists'));
        if (!$types) return [];
        $posts = get_posts(['post_type'=>$types,'post_status'=>'publish','posts_per_page'=>300,'orderby'=>'title','order'=>'ASC']);
        $out = [];
        foreach ($posts as $post) {
            $id = (int)$post->ID;
            $sight_ids = self::linked_sight_ids($id,$sights);
            $difficulty = self::first_meta($id, ['trail_difficulty','_trail_difficulty','difficulty','activity_difficulty']);
            $duration = self::first_meta($id, ['trail_time','_trail_time','estimated_time','duration','activity_duration']);
            $players = self::first_meta($id, ['player_count','players','recommended_players']);
            $summary = self::first_meta($id, ['_tng_destination_ai_profile']);
            if ($summary !== '') {
                $decoded = maybe_unserialize($summary);
                if (is_array($decoded) && !empty($decoded['summary'])) $summary = (string)$decoded['summary'];
                else $summary = '';
            }
            if ($summary === '') $summary = (string)get_the_excerpt($id);
            $out[] = [
                'id'=>$id,
                'title'=>get_the_title($post),
                'gpxUrl'=>self::gpx_url($id),
                'sightIds'=>$sight_ids,
                'difficulty'=>$difficulty,
                'duration'=>$duration,
                'players'=>$players,
                'summary'=>self::clean_text($summary, 28),
            ];
        }
        return $out;
    }

    public static function enqueue(): void {
        if (!self::is_builder() || !is_user_logged_in() || !current_user_can('edit_posts')) return;
        $sights = self::sights();
        $trails = self::trails($sights);
        wp_enqueue_style('tng-builder-leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', [], '1.9.4');
        wp_enqueue_script('tng-builder-leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', [], '1.9.4', true);
        $css_path = TNG_OS_PATH . 'assets/css/game-visual-builder.css';
        $js_path = TNG_OS_PATH . 'assets/js/game-visual-builder.js';
        $css_version = is_file($css_path) ? (string)filemtime($css_path) : '0.3.1';
        $js_version = is_file($js_path) ? (string)filemtime($js_path) : '0.3.1';
        wp_enqueue_style('tng-game-visual-builder', TNG_OS_URL . 'assets/css/game-visual-builder.css', ['tng-game-builder-ui','tng-builder-leaflet'], $css_version);
        wp_enqueue_script('tng-game-visual-builder', TNG_OS_URL . 'assets/js/game-visual-builder.js', ['tng-builder-leaflet'], $js_version, true);
        wp_localize_script('tng-game-visual-builder', 'TNG_VISUAL_BUILDER', [
            'trails' => $trails,
            'sights' => $sights,
            'debug' => ['sightCount'=>count($sights),'sightPostTypes'=>self::sight_post_types()],
            'labels' => [
                'title' => 'Visual checkpoint builder',
                'subtitle' => 'Click the map to add a checkpoint. Existing Top Sights on the selected trail load automatically.',
                'addSight' => 'Add Top Sight',
                'chooseSight' => 'Choose an existing Top Sight…',
                'checkpoint' => 'Checkpoint',
            ],
        ]);
    }
}
TNG_Game_Visual_Builder::boot();
