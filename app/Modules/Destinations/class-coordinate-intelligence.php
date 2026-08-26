<?php
namespace TNG_OS\Modules\Destinations;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class Coordinate_Intelligence implements Module_Interface {
    private const PAGE = 'tng-coordinate-audit';

    public function id(): string { return 'coordinate_intelligence'; }

    public function register(Container $container): void {
        $container->set('coordinate_intelligence', $this);
        add_action('admin_menu', [$this, 'menu'], 25);
        add_action('admin_post_tng_apply_inherited_coordinates', [$this, 'apply_inherited']);
    }

    public function boot(Container $container): void {}

    public function menu(): void {
        add_submenu_page('tn-game-os', 'Coordinate Intelligence', 'Coordinate Audit', 'manage_options', self::PAGE, [$this, 'page']);
    }

    public function page(): void {
        if (!current_user_can('manage_options')) wp_die('Unauthorized.');
        $rows = [];
        $counts = ['exact' => 0, 'inherited' => 0, 'missing' => 0, 'suspicious' => 0];
        foreach ($this->node_ids() as $post_id) {
            $resolved = self::resolve($post_id);
            $status = $resolved['status'] ?? 'missing';
            $counts[$status] = ($counts[$status] ?? 0) + 1;
            $rows[] = [
                'id' => $post_id,
                'title' => get_the_title($post_id) ?: ('#' . $post_id),
                'type' => get_post_type($post_id),
                'resolved' => $resolved,
            ];
        }
        usort($rows, static function(array $a, array $b): int {
            $order = ['missing' => 0, 'suspicious' => 1, 'inherited' => 2, 'exact' => 3];
            $as = $a['resolved']['status'] ?? 'missing';
            $bs = $b['resolved']['status'] ?? 'missing';
            return ($order[$as] ?? 9) <=> ($order[$bs] ?? 9) ?: strcasecmp($a['title'], $b['title']);
        });
        $notice = isset($_GET['tng_coordinate_notice']) ? sanitize_text_field(wp_unslash($_GET['tng_coordinate_notice'])) : '';
        ?>
        <div class="wrap tng-coordinate-audit">
            <h1>Coordinate Intelligence</h1>
            <p>Audits exact, inherited, missing, and suspicious destination coordinates before they enter the Knowledge Graph.</p>
            <?php if ($notice): ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>
            <style>
                .tng-ci-stats{display:grid;grid-template-columns:repeat(4,minmax(150px,1fr));gap:14px;max-width:1100px;margin:22px 0}.tng-ci-stat{background:#fff;border:1px solid #dcdcde;border-radius:16px;padding:18px}.tng-ci-stat strong{display:block;font-size:30px;color:#6438b3}.tng-ci-badge{display:inline-block;padding:4px 9px;border-radius:999px;font-weight:700;font-size:11px}.tng-ci-exact{background:#dcfce7;color:#087a45}.tng-ci-inherited{background:#ede9fe;color:#6538b5}.tng-ci-missing{background:#fff4d6;color:#9a5b00}.tng-ci-suspicious{background:#fee2e2;color:#b42318}.tng-ci-coords{font-family:monospace;font-size:12px}.tng-ci-source{color:#667085;font-size:12px}@media(max-width:800px){.tng-ci-stats{grid-template-columns:repeat(2,1fr)}}
            </style>
            <div class="tng-ci-stats">
                <div class="tng-ci-stat"><strong><?php echo number_format_i18n($counts['exact']); ?></strong><span>Exact</span></div>
                <div class="tng-ci-stat"><strong><?php echo number_format_i18n($counts['inherited']); ?></strong><span>Inherited</span></div>
                <div class="tng-ci-stat"><strong><?php echo number_format_i18n($counts['missing']); ?></strong><span>Missing</span></div>
                <div class="tng-ci-stat"><strong><?php echo number_format_i18n($counts['suspicious']); ?></strong><span>Suspicious</span></div>
            </div>
            <table class="widefat striped"><thead><tr><th>Listing</th><th>Status</th><th>Coordinates</th><th>Source</th><th>Action</th></tr></thead><tbody>
            <?php foreach ($rows as $row): $r = $row['resolved']; $status = $r['status'] ?? 'missing'; ?>
                <tr>
                    <td><a href="<?php echo esc_url(get_edit_post_link($row['id'])); ?>"><strong><?php echo esc_html($row['title']); ?></strong></a><br><span class="tng-ci-source"><?php echo esc_html($row['type']); ?></span></td>
                    <td><span class="tng-ci-badge tng-ci-<?php echo esc_attr($status); ?>"><?php echo esc_html(ucfirst($status)); ?></span></td>
                    <td class="tng-ci-coords"><?php echo isset($r['lat'], $r['lng']) ? esc_html(number_format((float)$r['lat'], 6) . ', ' . number_format((float)$r['lng'], 6)) : '—'; ?></td>
                    <td><?php echo esc_html($r['label'] ?? 'No coordinate source found'); ?></td>
                    <td>
                        <?php if ($status === 'inherited' && !empty($r['source_id'])): ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <?php wp_nonce_field('tng_apply_inherited_coordinates_' . $row['id']); ?>
                            <input type="hidden" name="action" value="tng_apply_inherited_coordinates">
                            <input type="hidden" name="post_id" value="<?php echo (int)$row['id']; ?>">
                            <button class="button">Save as exact</button>
                        </form>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody></table>
        </div>
        <?php
    }

    public function apply_inherited(): void {
        if (!current_user_can('manage_options')) wp_die('Unauthorized.');
        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        check_admin_referer('tng_apply_inherited_coordinates_' . $post_id);
        $resolved = self::resolve($post_id);
        if ($post_id && ($resolved['status'] ?? '') === 'inherited') {
            update_post_meta($post_id, '_tng_destination_lat', (string)$resolved['lat']);
            update_post_meta($post_id, '_tng_destination_lng', (string)$resolved['lng']);
            $message = 'Inherited coordinates saved as exact coordinates.';
        } else {
            $message = 'Coordinates could not be applied.';
        }
        wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE . '&tng_coordinate_notice=' . rawurlencode($message)));
        exit;
    }

    public static function resolve(int $post_id, array $visited = []): array {
        if (!$post_id || in_array($post_id, $visited, true)) return ['status' => 'missing', 'label' => 'No coordinate source found'];
        $visited[] = $post_id;

        $exact = self::exact_coordinates($post_id);
        if ($exact) {
            $status = self::suspicious($exact[0], $exact[1]) ? 'suspicious' : 'exact';
            return ['lat' => $exact[0], 'lng' => $exact[1], 'status' => $status, 'source_id' => $post_id, 'label' => $status === 'exact' ? 'Exact listing coordinates' : 'Coordinates outside expected Tennessee region'];
        }

        foreach (self::parent_candidates($post_id) as $parent_id) {
            $parent = self::resolve($parent_id, $visited);
            if (!isset($parent['lat'], $parent['lng'])) continue;
            return [
                'lat' => $parent['lat'],
                'lng' => $parent['lng'],
                'status' => 'inherited',
                'source_id' => $parent_id,
                'label' => 'Inherited from ' . (get_the_title($parent_id) ?: ('#' . $parent_id)),
            ];
        }

        return ['status' => 'missing', 'label' => 'No coordinate source found'];
    }

    private static function exact_coordinates(int $post_id): ?array {
        $pairs = [
            ['_tng_destination_lat','_tng_destination_lng'], ['map_lat','map_lng'], ['lat','lng'],
            ['latitude','longitude'], ['_lat','_lng'], ['st_latitude','st_longitude'],
            ['map_latitude','map_longitude'], ['location_lat','location_lng'], ['address_lat','address_lng'],
        ];
        foreach ($pairs as [$lat_key, $lng_key]) {
            $coords = self::validate(get_post_meta($post_id, $lat_key, true), get_post_meta($post_id, $lng_key, true));
            if ($coords) return $coords;
        }
        foreach (['map_location','location','st_location','address','map_data','location_data'] as $key) {
            $value = get_post_meta($post_id, $key, true);
            $coords = self::coordinates_from_value($value);
            if ($coords) return $coords;
        }
        return null;
    }

    private static function coordinates_from_value($value): ?array {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) $value = $decoded;
            elseif (preg_match('/(-?\d{1,2}\.\d+)\s*[,| ]\s*(-?\d{1,3}\.\d+)/', $value, $m)) return self::validate($m[1], $m[2]);
        }
        if (!is_array($value)) return null;
        $lat_keys = ['lat','latitude','map_lat','st_latitude'];
        $lng_keys = ['lng','lon','longitude','map_lng','st_longitude'];
        foreach ($lat_keys as $lat_key) foreach ($lng_keys as $lng_key) {
            if (array_key_exists($lat_key, $value) && array_key_exists($lng_key, $value)) {
                $coords = self::validate($value[$lat_key], $value[$lng_key]);
                if ($coords) return $coords;
            }
        }
        foreach ($value as $child) {
            $coords = self::coordinates_from_value($child);
            if ($coords) return $coords;
        }
        return null;
    }

    private static function validate($lat, $lng): ?array {
        if (!is_numeric($lat) || !is_numeric($lng)) return null;
        $lat = (float)$lat; $lng = (float)$lng;
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || ($lat == 0.0 && $lng == 0.0)) return null;
        return [$lat, $lng];
    }

    private static function suspicious(float $lat, float $lng): bool {
        return $lat < 34.0 || $lat > 37.5 || $lng < -90.5 || $lng > -81.5;
    }

    private static function parent_candidates(int $post_id): array {
        $ids = [];
        foreach (['_tng_destination_id','location_id','st_location_id','destination_id','_tng_primary_destination_id'] as $key) {
            $value = get_post_meta($post_id, $key, true);
            if (is_numeric($value) && (int)$value !== $post_id) $ids[] = (int)$value;
        }
        foreach (['_tng_related_destination_ids','related_destination_ids','_tng_effective_destination_ids'] as $key) {
            $value = get_post_meta($post_id, $key, true);
            if (!is_array($value)) $value = maybe_unserialize($value);
            if (!is_array($value)) continue;
            foreach ($value as $id) if (is_numeric($id) && (int)$id !== $post_id) $ids[] = (int)$id;
        }
        $parent = wp_get_post_parent_id($post_id);
        if ($parent) $ids[] = $parent;
        return array_values(array_unique(array_filter($ids)));
    }

    private function node_ids(): array {
        return get_posts(['post_type' => $this->post_types(), 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'orderby' => 'title', 'order' => 'ASC']);
    }

    private function post_types(): array {
        return array_values(array_filter(['tng_destination','st_activity','st_hotel','st_tours','st_rental','top_sight'], 'post_type_exists'));
    }
}
