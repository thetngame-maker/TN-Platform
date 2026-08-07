<?php
/**
 * Plugin Name: TN Game Visual Checkpoint Builder
 * Description: Visual map-based checkpoint creator for the TN Game front-end game builder.
 * Version: 0.1.0
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
            ['latitude','longitude'],['lat','lng'],['trail_latitude','trail_longitude'],['st_latitude','st_longitude'],
            ['map_lat','map_lng'],['location_lat','location_lng'],['_latitude','_longitude'],['tng_latitude','tng_longitude']
        ];
        foreach ($pairs as $pair) {
            $lat = get_post_meta($post_id, $pair[0], true);
            $lng = get_post_meta($post_id, $pair[1], true);
            if (is_numeric($lat) && is_numeric($lng)) return [(float) $lat, (float) $lng];
        }
        foreach (['location','top_sight_location','coordinates'] as $key) {
            $value = get_post_meta($post_id, $key, true);
            if (is_array($value)) {
                $lat = $value['lat'] ?? $value['latitude'] ?? null;
                $lng = $value['lng'] ?? $value['longitude'] ?? null;
                if (is_numeric($lat) && is_numeric($lng)) return [(float) $lat, (float) $lng];
            }
            if (function_exists('get_field')) {
                $value = get_field($key, $post_id);
                if (is_array($value)) {
                    $lat = $value['lat'] ?? $value['latitude'] ?? null;
                    $lng = $value['lng'] ?? $value['longitude'] ?? null;
                    if (is_numeric($lat) && is_numeric($lng)) return [(float) $lat, (float) $lng];
                }
            }
        }
        return [0.0, 0.0];
    }

    private static function trails(): array {
        $types = array_values(array_filter(['st_activity','activity'], 'post_type_exists'));
        if (!$types) return [];
        $posts = get_posts(['post_type'=>$types,'post_status'=>'publish','posts_per_page'=>300,'orderby'=>'title','order'=>'ASC']);
        $out = [];
        foreach ($posts as $post) {
            $url = self::gpx_url((int) $post->ID);
            $out[] = ['id'=>(int)$post->ID,'title'=>get_the_title($post),'gpxUrl'=>$url];
        }
        return $out;
    }

    private static function sight_post_types(): array {
        $out = [];
        foreach (get_post_types(['public'=>true], 'objects') as $slug => $obj) {
            $haystack = strtolower($slug . ' ' . ($obj->label ?? '') . ' ' . ($obj->labels->singular_name ?? ''));
            if (str_contains($haystack, 'top sight') || str_contains($haystack, 'topsight')) $out[] = $slug;
        }
        foreach (['top_sight','top-sight','top_sights','top-sights'] as $slug) if (post_type_exists($slug)) $out[] = $slug;
        return array_values(array_unique($out));
    }

    private static function sights(): array {
        $types = self::sight_post_types();
        if (!$types) return [];
        $posts = get_posts(['post_type'=>$types,'post_status'=>'publish','posts_per_page'=>500,'orderby'=>'title','order'=>'ASC']);
        $out = [];
        foreach ($posts as $post) {
            [$lat,$lng] = self::coordinates((int)$post->ID);
            if (!$lat && !$lng) continue;
            $out[] = ['id'=>(int)$post->ID,'title'=>get_the_title($post),'lat'=>$lat,'lng'=>$lng];
        }
        return $out;
    }

    public static function enqueue(): void {
        if (!self::is_builder() || !is_user_logged_in() || !current_user_can('edit_posts')) return;
        wp_enqueue_style('tng-builder-leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', [], '1.9.4');
        wp_enqueue_script('tng-builder-leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', [], '1.9.4', true);
        wp_enqueue_style('tng-game-visual-builder', TNG_OS_URL . 'assets/css/game-visual-builder.css', ['tng-game-builder-ui','tng-builder-leaflet'], '0.1.0');
        wp_enqueue_script('tng-game-visual-builder', TNG_OS_URL . 'assets/js/game-visual-builder.js', ['tng-builder-leaflet'], '0.1.0', true);
        wp_localize_script('tng-game-visual-builder', 'TNG_VISUAL_BUILDER', [
            'trails' => self::trails(),
            'sights' => self::sights(),
            'labels' => [
                'title' => 'Visual checkpoint builder',
                'subtitle' => 'Click the map to add a checkpoint. Drag markers to fine-tune their position.',
                'addSight' => 'Add Top Sight',
                'chooseSight' => 'Choose an existing Top Sight…',
                'checkpoint' => 'Checkpoint',
            ],
        ]);
    }
}
TNG_Game_Visual_Builder::boot();
