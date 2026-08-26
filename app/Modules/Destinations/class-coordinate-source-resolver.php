<?php
namespace TNG_OS\Modules\Destinations;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;
use WP_Post;

if (!defined('ABSPATH')) exit;

final class Coordinate_Source_Resolver implements Module_Interface {
    private const PAGE = 'tng-coordinate-sources';

    public function id(): string { return 'coordinate_source_resolver'; }

    public function register(Container $container): void {
        $container->set('coordinate_source_resolver', $this);
        add_action('admin_menu', [$this, 'menu'], 27);
        add_action('admin_post_tng_resolve_source_coordinates', [$this, 'resolve_all_action']);
        add_action('save_post', [$this, 'resolve_on_save'], 115, 2);
    }

    public function boot(Container $container): void {}

    public function menu(): void {
        add_submenu_page('tn-game-os', 'Coordinate Sources', 'Coordinate Sources', 'manage_options', self::PAGE, [$this, 'page']);
    }

    public function page(): void {
        if (!current_user_can('manage_options')) wp_die('Unauthorized.');
        $rows = [];
        $counts = ['google' => 0, 'gpx' => 0, 'none' => 0, 'saved' => 0];
        foreach ($this->node_ids() as $post_id) {
            $found = $this->discover($post_id);
            $source = $found['source'] ?? 'none';
            $counts[$source] = ($counts[$source] ?? 0) + 1;
            if (get_post_meta($post_id, '_tng_coordinate_source_type', true)) $counts['saved']++;
            $rows[] = ['id' => $post_id, 'title' => get_the_title($post_id) ?: ('#' . $post_id), 'type' => get_post_type($post_id), 'found' => $found];
        }
        usort($rows, static function(array $a, array $b): int {
            $order = ['google' => 0, 'gpx' => 1, 'none' => 2];
            return ($order[$a['found']['source'] ?? 'none'] ?? 9) <=> ($order[$b['found']['source'] ?? 'none'] ?? 9) ?: strcasecmp($a['title'], $b['title']);
        });
        $notice = isset($_GET['tng_source_notice']) ? sanitize_text_field(wp_unslash($_GET['tng_source_notice'])) : '';
        ?>
        <div class="wrap tng-coordinate-sources">
            <h1>Coordinate Source Resolver</h1>
            <p>Promotes precise Google Places coordinates and trail-start coordinates from GPX files before destination-center inheritance is used.</p>
            <?php if ($notice): ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>
            <style>
                .tng-cs-stats{display:grid;grid-template-columns:repeat(4,minmax(150px,1fr));gap:14px;max-width:1000px;margin:22px 0}.tng-cs-stat{background:#fff;border:1px solid #dcdcde;border-radius:16px;padding:18px}.tng-cs-stat strong{display:block;font-size:30px;color:#6438b3}.tng-cs-badge{display:inline-block;padding:4px 9px;border-radius:999px;font-size:11px;font-weight:700}.tng-cs-google{background:#dcfce7;color:#087a45}.tng-cs-gpx{background:#ede9fe;color:#6538b5}.tng-cs-none{background:#f2f4f7;color:#667085}.tng-cs-coords{font-family:monospace;font-size:12px}@media(max-width:800px){.tng-cs-stats{grid-template-columns:repeat(2,1fr)}}
            </style>
            <div class="tng-cs-stats">
                <div class="tng-cs-stat"><strong><?php echo number_format_i18n($counts['google']); ?></strong><span>Google Places sources</span></div>
                <div class="tng-cs-stat"><strong><?php echo number_format_i18n($counts['gpx']); ?></strong><span>GPX trail sources</span></div>
                <div class="tng-cs-stat"><strong><?php echo number_format_i18n($counts['none']); ?></strong><span>No precise source found</span></div>
                <div class="tng-cs-stat"><strong><?php echo number_format_i18n($counts['saved']); ?></strong><span>Precise sources saved</span></div>
            </div>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Resolve and save all available Google Places and GPX coordinates?');" style="margin:18px 0 24px">
                <?php wp_nonce_field('tng_resolve_source_coordinates'); ?>
                <input type="hidden" name="action" value="tng_resolve_source_coordinates">
                <button class="button button-primary button-large">Resolve precise source coordinates</button>
            </form>
            <table class="widefat striped"><thead><tr><th>Listing</th><th>Source</th><th>Coordinates</th><th>Detail</th></tr></thead><tbody>
            <?php foreach ($rows as $row): $f = $row['found']; $source = $f['source'] ?? 'none'; ?>
                <tr>
                    <td><a href="<?php echo esc_url(get_edit_post_link($row['id'])); ?>"><strong><?php echo esc_html($row['title']); ?></strong></a><br><small><?php echo esc_html($row['type']); ?></small></td>
                    <td><span class="tng-cs-badge tng-cs-<?php echo esc_attr($source); ?>"><?php echo esc_html($source === 'none' ? 'No precise source' : strtoupper($source)); ?></span></td>
                    <td class="tng-cs-coords"><?php echo isset($f['lat'], $f['lng']) ? esc_html(number_format((float)$f['lat'], 6) . ', ' . number_format((float)$f['lng'], 6)) : '—'; ?></td>
                    <td><?php echo esc_html($f['label'] ?? 'Destination inheritance remains available.'); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table>
        </div>
        <?php
    }

    public function resolve_all_action(): void {
        if (!current_user_can('manage_options')) wp_die('Unauthorized.');
        check_admin_referer('tng_resolve_source_coordinates');
        $google = 0; $gpx = 0;
        foreach ($this->node_ids() as $post_id) {
            $found = $this->discover($post_id);
            if (!isset($found['lat'], $found['lng']) || ($found['source'] ?? 'none') === 'none') continue;
            $this->save($post_id, $found);
            if ($found['source'] === 'google') $google++; else $gpx++;
        }
        $message = sprintf('Saved %d Google Places coordinate sets and %d GPX trail-start coordinate sets.', $google, $gpx);
        wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE . '&tng_source_notice=' . rawurlencode($message)));
        exit;
    }

    public function resolve_on_save(int $post_id, WP_Post $post): void {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;
        if (!in_array($post->post_type, $this->post_types(), true) || $post->post_status !== 'publish') return;
        if (get_post_meta($post_id, '_tng_coordinate_source_lock', true)) return;
        $found = $this->discover($post_id);
        if (isset($found['lat'], $found['lng']) && ($found['source'] ?? 'none') !== 'none') $this->save($post_id, $found);
    }

    private function save(int $post_id, array $found): void {
        update_post_meta($post_id, '_tng_destination_lat', (string)$found['lat']);
        update_post_meta($post_id, '_tng_destination_lng', (string)$found['lng']);
        update_post_meta($post_id, '_tng_coordinate_source_type', sanitize_key($found['source']));
        update_post_meta($post_id, '_tng_coordinate_source_label', sanitize_text_field($found['label'] ?? 'Precise source'));
        update_post_meta($post_id, '_tng_coordinate_confidence', 'precise_source');
        do_action('tng_knowledge_graph_refresh_id', $post_id);
    }

    private function discover(int $post_id): array {
        $saved = $this->saved_source_coordinates($post_id);
        if ($saved) return $saved;
        $google = $this->google_coordinates($post_id);
        if ($google) return ['lat' => $google[0], 'lng' => $google[1], 'source' => 'google', 'label' => 'Google Places geometry'];
        $gpx = $this->gpx_coordinates($post_id);
        if ($gpx) return ['lat' => $gpx[0], 'lng' => $gpx[1], 'source' => 'gpx', 'label' => 'First route point in GPX file'];
        return ['source' => 'none', 'label' => 'No Google Places geometry or readable GPX route found'];
    }

    private function saved_source_coordinates(int $post_id): ?array {
        $source = sanitize_key((string)get_post_meta($post_id, '_tng_coordinate_source_type', true));
        if (!in_array($source, ['google', 'gpx'], true)) return null;
        $coords = $this->validate(
            get_post_meta($post_id, '_tng_destination_lat', true),
            get_post_meta($post_id, '_tng_destination_lng', true)
        );
        if (!$coords) return null;
        $label = (string)get_post_meta($post_id, '_tng_coordinate_source_label', true);
        return ['lat' => $coords[0], 'lng' => $coords[1], 'source' => $source, 'label' => $label ?: ($source === 'google' ? 'Saved Google Places geometry' : 'Saved GPX trail start')];
    }

    private function google_coordinates(int $post_id): ?array {
        $all = get_post_meta($post_id);

        $scalar = $this->find_scalar_meta_pair($all);
        if ($scalar) return $scalar;

        foreach ($all as $key => $values) {
            $key_lc = strtolower((string)$key);
            if (!preg_match('/google|place|geometry|food|drink|restaurant|address|location|map|provider|source|fact/', $key_lc)) continue;
            foreach ((array)$values as $value) {
                $value = maybe_unserialize($value);
                if (is_string($value)) {
                    $decoded = json_decode($value, true);
                    if (is_array($decoded)) $value = $decoded;
                }
                $coords = $this->find_lat_lng($value);
                if ($coords) return $coords;
            }
        }
        return null;
    }

    private function find_scalar_meta_pair(array $all): ?array {
        $lat_candidates = [];
        $lng_candidates = [];
        foreach ($all as $key => $values) {
            $key_lc = strtolower((string)$key);
            $value = maybe_unserialize((array)$values ? reset($values) : '');
            if (!is_scalar($value) || !is_numeric($value)) continue;
            if (preg_match('/(?:^|_)(?:google|place|geometry|location|map|food|drink|restaurant|provider|fact).*?(?:lat|latitude)$|(?:^|_)(?:lat|latitude)(?:_|$)/', $key_lc)) $lat_candidates[$this->meta_prefix($key_lc)] = $value;
            if (preg_match('/(?:^|_)(?:google|place|geometry|location|map|food|drink|restaurant|provider|fact).*?(?:lng|lon|longitude)$|(?:^|_)(?:lng|lon|longitude)(?:_|$)/', $key_lc)) $lng_candidates[$this->meta_prefix($key_lc)] = $value;
        }
        foreach ($lat_candidates as $prefix => $lat) {
            if (array_key_exists($prefix, $lng_candidates)) {
                $coords = $this->validate($lat, $lng_candidates[$prefix]);
                if ($coords) return $coords;
            }
        }
        if (count($lat_candidates) === 1 && count($lng_candidates) === 1) {
            $coords = $this->validate(reset($lat_candidates), reset($lng_candidates));
            if ($coords) return $coords;
        }
        return null;
    }

    private function meta_prefix(string $key): string {
        return trim((string)preg_replace('/(?:lat(?:itude)?|lng|lon(?:gitude)?)$/', '', $key), '_-');
    }

    private function find_lat_lng($value): ?array {
        if (!is_array($value)) return null;
        $normalized = [];
        foreach ($value as $key => $child) $normalized[strtolower((string)$key)] = $child;
        $lat_keys = ['lat','latitude']; $lng_keys = ['lng','lon','longitude'];
        foreach ($lat_keys as $lat_key) foreach ($lng_keys as $lng_key) {
            if (array_key_exists($lat_key, $normalized) && array_key_exists($lng_key, $normalized)) {
                $coords = $this->validate($normalized[$lat_key], $normalized[$lng_key]);
                if ($coords) return $coords;
            }
        }
        foreach ($value as $child) {
            $coords = $this->find_lat_lng($child);
            if ($coords) return $coords;
        }
        return null;
    }

    private function gpx_coordinates(int $post_id): ?array {
        $urls = [];
        foreach (['trail_gpx_url','gpx_url','_gpx_url','st_gpx_url','trail_route_gpx','_tng_gpx_url','route_gpx_url'] as $key) {
            $value = get_post_meta($post_id, $key, true);
            if (is_string($value) && $value !== '') $urls[] = $value;
        }
        foreach (get_post_meta($post_id) as $key => $values) {
            if (stripos((string)$key, 'gpx') === false) continue;
            foreach ((array)$values as $value) if (is_string($value) && stripos($value, '.gpx') !== false) $urls[] = $value;
        }
        foreach (array_unique($urls) as $url) {
            $xml = $this->read_gpx($url);
            if (!$xml) continue;
            if (preg_match('/<(?:trkpt|rtept|wpt)\b[^>]*\blat=["\']([^"\']+)["\'][^>]*\b(?:lon|lng)=["\']([^"\']+)["\']/i', $xml, $m)) {
                $coords = $this->validate($m[1], $m[2]);
                if ($coords) return $coords;
            }
            if (preg_match('/<(?:trkpt|rtept|wpt)\b[^>]*\b(?:lon|lng)=["\']([^"\']+)["\'][^>]*\blat=["\']([^"\']+)["\']/i', $xml, $m)) {
                $coords = $this->validate($m[2], $m[1]);
                if ($coords) return $coords;
            }
        }
        return null;
    }

    private function read_gpx(string $value): string {
        $value = trim($value);
        if ($value === '') return '';
        if (ctype_digit($value)) {
            $path = get_attached_file((int)$value);
            return $path && is_readable($path) ? (string)file_get_contents($path) : '';
        }
        $uploads = wp_get_upload_dir();
        if (!empty($uploads['baseurl']) && strpos($value, $uploads['baseurl']) === 0) {
            $path = $uploads['basedir'] . substr($value, strlen($uploads['baseurl']));
            if (is_readable($path)) return (string)file_get_contents($path);
        }
        if (is_readable($value)) return (string)file_get_contents($value);
        if (preg_match('#^https?://#i', $value)) {
            $response = wp_safe_remote_get($value, ['timeout' => 8, 'redirection' => 3, 'limit_response_size' => 2000000]);
            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) return (string)wp_remote_retrieve_body($response);
        }
        return '';
    }

    private function validate($lat, $lng): ?array {
        if (!is_numeric($lat) || !is_numeric($lng)) return null;
        $lat = (float)$lat; $lng = (float)$lng;
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || ($lat == 0.0 && $lng == 0.0)) return null;
        return [$lat, $lng];
    }

    private function node_ids(): array {
        return get_posts(['post_type' => $this->post_types(), 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'orderby' => 'title', 'order' => 'ASC']);
    }

    private function post_types(): array {
        return array_values(array_filter(['tng_destination','st_activity','st_hotel','st_tours','st_rental','top_sight'], 'post_type_exists'));
    }
}
