<?php
/**
 * TN Game Trail GPX Intelligence
 * Derives missing trail stats from GPX data and caches them on the trail post.
 */
if (!defined('ABSPATH')) exit;

final class TNG_Trail_GPX_Intelligence {
    public static function boot(): void {
        add_action('wp', [__CLASS__, 'maybe_enrich_current_trail'], 20);
        add_action('save_post', [__CLASS__, 'invalidate_cache'], 20, 2);
    }

    public static function invalidate_cache(int $post_id, $post): void {
        if (!is_object($post) || !in_array($post->post_type, ['st_activity','activity'], true)) return;
        delete_post_meta($post_id, '_tng_gpx_intelligence_hash');
    }

    private static function is_trail(int $id): bool {
        return class_exists('TNG_Trail_UI') && TNG_Trail_UI::is_trail() && get_queried_object_id() === $id;
    }

    private static function normalize_media_url($value): string {
        if (is_numeric($value)) {
            $url = wp_get_attachment_url(absint($value));
            return $url ? esc_url_raw($url) : '';
        }
        if (is_array($value)) {
            foreach (['url','file','src','ID','id'] as $key) {
                if (!isset($value[$key])) continue;
                $url = self::normalize_media_url($value[$key]);
                if ($url !== '') return $url;
            }
            return '';
        }
        $value = trim((string)$value);
        if ($value === '') return '';
        if (strpos($value, '/') === 0) return esc_url_raw(home_url($value));
        return esc_url_raw($value);
    }

    private static function route_url(int $id): string {
        foreach (['trail_gpx_url','trail_gpx','gpx_url','gpx_file','gpx','tng_gpx_url','tng_trail_gpx','route_gpx_url','route_gpx'] as $key) {
            $url = self::normalize_media_url(get_post_meta($id, $key, true));
            if ($url !== '') return $url;
            if (function_exists('get_field')) {
                $url = self::normalize_media_url(get_field($key, $id));
                if ($url !== '') return $url;
            }
        }
        return '';
    }

    private static function fetch_xml(string $url): string {
        if ($url === '') return '';
        $response = wp_safe_remote_get($url, ['timeout' => 8, 'redirection' => 3]);
        if (is_wp_error($response)) return '';
        if ((int) wp_remote_retrieve_response_code($response) !== 200) return '';
        return (string) wp_remote_retrieve_body($response);
    }

    private static function haversine_miles(float $lat1, float $lon1, float $lat2, float $lon2): float {
        $r = 3958.7613;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat/2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon/2) ** 2;
        return 2 * $r * asin(min(1, sqrt($a)));
    }

    private static function parse(string $xml): array {
        if ($xml === '') return [];
        libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        if (!$doc) return [];
        $points = [];
        $nodes = $doc->xpath('//*[local-name()="trkpt" or local-name()="rtept"]');
        if (!$nodes) return [];
        foreach ($nodes as $node) {
            $lat = isset($node['lat']) ? (float)$node['lat'] : null;
            $lon = isset($node['lon']) ? (float)$node['lon'] : null;
            if ($lat === null || $lon === null) continue;
            $eleNodes = $node->xpath('./*[local-name()="ele"]');
            $ele = ($eleNodes && isset($eleNodes[0])) ? (float)$eleNodes[0] : null;
            $points[] = ['lat'=>$lat,'lon'=>$lon,'ele'=>$ele];
        }
        if (count($points) < 2) return [];
        $distance = 0.0;
        $gain_m = 0.0;
        $loss_m = 0.0;
        for ($i=1; $i<count($points); $i++) {
            $a = $points[$i-1]; $b = $points[$i];
            $distance += self::haversine_miles($a['lat'],$a['lon'],$b['lat'],$b['lon']);
            if ($a['ele'] !== null && $b['ele'] !== null) {
                $delta = $b['ele'] - $a['ele'];
                if ($delta > 0) $gain_m += $delta; else $loss_m += abs($delta);
            }
        }
        $gain_ft = $gain_m * 3.28084;
        $loss_ft = $loss_m * 3.28084;
        $first = $points[0]; $last = $points[count($points)-1];
        $start_end = self::haversine_miles($first['lat'],$first['lon'],$last['lat'],$last['lon']);
        $route_type = ($distance > 0 && $start_end <= max(0.12, $distance * 0.08)) ? 'Loop' : 'Out-and-Back';
        $hours = max(0.25, ($distance / 2.5) + ($gain_ft / 2000));
        $minutes = (int) round($hours * 60 / 5) * 5;
        return [
            'distance_miles' => $distance,
            'gain_ft' => $gain_ft,
            'loss_ft' => $loss_ft,
            'minutes' => max(15, $minutes),
            'route_type' => $route_type,
            'point_count' => count($points),
        ];
    }

    private static function format_time(int $minutes): string {
        $hours = intdiv($minutes, 60);
        $mins = $minutes % 60;
        if ($hours && $mins) return $hours . ' hr ' . $mins . ' min';
        if ($hours) return $hours . ($hours === 1 ? ' hr' : ' hrs');
        return $mins . ' min';
    }

    private static function missing(int $id, array $keys): bool {
        foreach ($keys as $key) {
            $value = get_post_meta($id, $key, true);
            if (is_scalar($value) && trim((string)$value) !== '' && trim((string)$value) !== '—') return false;
        }
        return true;
    }

    public static function maybe_enrich_current_trail(): void {
        if (!is_singular(['st_activity','activity'])) return;
        $id = get_queried_object_id();
        if (!$id || !self::is_trail($id)) return;
        $url = self::route_url($id);
        if ($url === '') return;
        $hash = md5($url);
        $current_hash = (string)get_post_meta($id, '_tng_gpx_intelligence_hash', true);
        $needs = self::missing($id, ['trail_distance','distance','st_distance']) || self::missing($id, ['elevation_gain','trail_elevation_gain','gain']) || self::missing($id, ['estimated_time','trail_time','duration']);
        if (!$needs && $current_hash === $hash) return;
        $stats = self::parse(self::fetch_xml($url));
        if (!$stats) return;
        if (self::missing($id, ['trail_distance','distance','st_distance'])) update_post_meta($id, 'trail_distance', number_format_i18n($stats['distance_miles'], 1) . ' mi');
        if (self::missing($id, ['elevation_gain','trail_elevation_gain','gain'])) update_post_meta($id, 'elevation_gain', number_format_i18n((int)round($stats['gain_ft'] / 10) * 10) . ' ft');
        if (self::missing($id, ['estimated_time','trail_time','duration'])) update_post_meta($id, 'estimated_time', self::format_time((int)$stats['minutes']));
        if (self::missing($id, ['trail_type','route_type'])) update_post_meta($id, 'trail_type', $stats['route_type']);
        update_post_meta($id, '_tng_gpx_intelligence_hash', $hash);
        update_post_meta($id, '_tng_gpx_point_count', (int)$stats['point_count']);
        update_post_meta($id, '_tng_gpx_loss_ft', (int)round($stats['loss_ft']));
    }
}
TNG_Trail_GPX_Intelligence::boot();
