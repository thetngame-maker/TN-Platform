<?php
namespace TNG_OS\Modules\Destinations;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;
use WP_Post;

if (!defined('ABSPATH')) exit;

final class Knowledge_Graph_Core implements Module_Interface {
    private const DB_VERSION = '1';
    private const DB_OPTION = 'tng_knowledge_graph_db_version';
    private const PAGE = 'tng-knowledge-graph';
    private const RADIUS_MILES = 25;

    public function id(): string { return 'knowledge_graph_core'; }

    public function register(Container $container): void {
        $container->set('knowledge_graph_core', $this);
        add_action('init', [$this, 'ensure_table'], 6);
        add_action('admin_menu', [$this, 'menu'], 24);
        add_action('admin_post_tng_rebuild_knowledge_graph', [$this, 'rebuild_action']);
        add_action('save_post', [$this, 'refresh_post'], 120, 2);
        add_action('before_delete_post', [$this, 'remove_post']);
    }

    public function boot(Container $container): void {}

    public function ensure_table(): void {
        if (get_option(self::DB_OPTION) === self::DB_VERSION) return;
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = $this->table();
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            source_id bigint(20) unsigned NOT NULL,
            target_id bigint(20) unsigned NOT NULL,
            relationship varchar(40) NOT NULL,
            distance_miles decimal(9,3) NOT NULL DEFAULT 0,
            score decimal(8,3) NOT NULL DEFAULT 0,
            source_type varchar(40) NOT NULL DEFAULT '',
            target_type varchar(40) NOT NULL DEFAULT '',
            generated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY source_target_rel (source_id,target_id,relationship),
            KEY source_id (source_id),
            KEY target_id (target_id),
            KEY relationship (relationship),
            KEY distance_miles (distance_miles)
        ) {$charset};");
        update_option(self::DB_OPTION, self::DB_VERSION, false);
    }

    public function menu(): void {
        add_submenu_page('tn-game-os', 'Destination Knowledge Graph', 'Knowledge Graph', 'manage_options', self::PAGE, [$this, 'page']);
    }

    public function page(): void {
        if (!current_user_can('manage_options')) wp_die('Unauthorized.');
        $this->ensure_table();
        global $wpdb;
        $table = $this->table();
        $nodes = $this->node_ids();
        $edges = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $connected = (int)$wpdb->get_var("SELECT COUNT(DISTINCT source_id) FROM {$table}");
        $nearby = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE relationship=%s", 'nearby'));
        $rows = $wpdb->get_results("SELECT g.*,s.post_title source_title,t.post_title target_title FROM {$table} g LEFT JOIN {$wpdb->posts} s ON s.ID=g.source_id LEFT JOIN {$wpdb->posts} t ON t.ID=g.target_id ORDER BY g.distance_miles ASC,g.score DESC LIMIT 100", ARRAY_A);
        $notice = isset($_GET['tng_graph_notice']) ? sanitize_text_field(wp_unslash($_GET['tng_graph_notice'])) : '';
        ?>
        <div class="wrap tng-knowledge-graph">
            <h1>Destination Knowledge Graph</h1>
            <p>The first Destination Intelligence layer. It connects published places and experiences by location and content type.</p>
            <?php if ($notice): ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>
            <style>
                .tng-kg-stats{display:grid;grid-template-columns:repeat(4,minmax(150px,1fr));gap:14px;max-width:1050px;margin:22px 0}.tng-kg-stat{background:#fff;border:1px solid #dcdcde;border-radius:16px;padding:18px}.tng-kg-stat strong{display:block;font-size:30px;color:#6438b3}.tng-kg-actions{display:flex;gap:10px;margin:18px 0 24px}.tng-kg-type{display:inline-block;padding:3px 8px;border-radius:999px;background:#f0ebff;color:#57309d;font-size:11px;font-weight:700}@media(max-width:800px){.tng-kg-stats{grid-template-columns:repeat(2,1fr)}}
            </style>
            <div class="tng-kg-stats">
                <div class="tng-kg-stat"><strong><?php echo number_format_i18n(count($nodes)); ?></strong><span>Eligible nodes</span></div>
                <div class="tng-kg-stat"><strong><?php echo number_format_i18n($edges); ?></strong><span>Relationships</span></div>
                <div class="tng-kg-stat"><strong><?php echo number_format_i18n($connected); ?></strong><span>Connected nodes</span></div>
                <div class="tng-kg-stat"><strong><?php echo number_format_i18n($nearby); ?></strong><span>Nearby links</span></div>
            </div>
            <form class="tng-kg-actions" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Rebuild all automatic destination relationships?');">
                <?php wp_nonce_field('tng_rebuild_knowledge_graph'); ?>
                <input type="hidden" name="action" value="tng_rebuild_knowledge_graph">
                <button class="button button-primary button-large">Rebuild knowledge graph</button>
            </form>
            <h2>Closest automatic relationships</h2>
            <table class="widefat striped"><thead><tr><th>Source</th><th>Relationship</th><th>Target</th><th>Distance</th><th>Score</th></tr></thead><tbody>
                <?php if (!$rows): ?><tr><td colspan="5">No relationships yet. Add coordinates to destination content, then rebuild the graph.</td></tr><?php endif; ?>
                <?php foreach ($rows as $row): ?><tr>
                    <td><a href="<?php echo esc_url(get_edit_post_link((int)$row['source_id'])); ?>"><?php echo esc_html($row['source_title'] ?: ('#'.$row['source_id'])); ?></a><br><span class="tng-kg-type"><?php echo esc_html($row['source_type']); ?></span></td>
                    <td><code><?php echo esc_html($row['relationship']); ?></code></td>
                    <td><a href="<?php echo esc_url(get_edit_post_link((int)$row['target_id'])); ?>"><?php echo esc_html($row['target_title'] ?: ('#'.$row['target_id'])); ?></a><br><span class="tng-kg-type"><?php echo esc_html($row['target_type']); ?></span></td>
                    <td><?php echo esc_html(number_format_i18n((float)$row['distance_miles'], 1)); ?> mi</td>
                    <td><?php echo esc_html(number_format_i18n((float)$row['score'], 1)); ?></td>
                </tr><?php endforeach; ?>
            </tbody></table>
        </div>
        <?php
    }

    public function rebuild_action(): void {
        if (!current_user_can('manage_options')) wp_die('Unauthorized.');
        check_admin_referer('tng_rebuild_knowledge_graph');
        $count = $this->rebuild();
        wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE . '&tng_graph_notice=' . rawurlencode(sprintf('Knowledge graph rebuilt with %d automatic relationships.', $count))));
        exit;
    }

    public function rebuild(): int {
        $this->ensure_table();
        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$this->table()}");
        $nodes = $this->nodes();
        $count = 0;
        $total = count($nodes);
        for ($i = 0; $i < $total; $i++) {
            for ($j = $i + 1; $j < $total; $j++) {
                $distance = $this->distance($nodes[$i]['lat'], $nodes[$i]['lng'], $nodes[$j]['lat'], $nodes[$j]['lng']);
                if ($distance > self::RADIUS_MILES) continue;
                $count += $this->write_pair($nodes[$i], $nodes[$j], $distance);
            }
        }
        update_option('tng_knowledge_graph_last_rebuild', current_time('mysql', true), false);
        return $count;
    }

    public function refresh_post(int $post_id, WP_Post $post): void {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;
        if (!in_array($post->post_type, $this->post_types(), true)) return;
        if ($post->post_status !== 'publish') { $this->remove_post($post_id); return; }
        $node = $this->node($post_id);
        if (!$node) { $this->remove_post($post_id); return; }
        $this->remove_post($post_id);
        foreach ($this->nodes($post_id) as $other) {
            $distance = $this->distance($node['lat'], $node['lng'], $other['lat'], $other['lng']);
            if ($distance <= self::RADIUS_MILES) $this->write_pair($node, $other, $distance);
        }
    }

    public function remove_post(int $post_id): void {
        global $wpdb;
        $wpdb->delete($this->table(), ['source_id' => $post_id], ['%d']);
        $wpdb->delete($this->table(), ['target_id' => $post_id], ['%d']);
    }

    private function write_pair(array $a, array $b, float $distance): int {
        $count = 0;
        $count += $this->insert($a, $b, $distance);
        $count += $this->insert($b, $a, $distance);
        return $count;
    }

    private function insert(array $source, array $target, float $distance): int {
        global $wpdb;
        $score = max(0, 100 - ($distance / self::RADIUS_MILES * 70));
        if ($source['destination'] && $source['destination'] === $target['destination']) $score += 15;
        if ($source['type'] !== $target['type']) $score += 5;
        return (int)$wpdb->replace($this->table(), [
            'source_id' => $source['id'],
            'target_id' => $target['id'],
            'relationship' => 'nearby',
            'distance_miles' => round($distance, 3),
            'score' => round(min(100, $score), 3),
            'source_type' => $source['type'],
            'target_type' => $target['type'],
            'generated_at' => current_time('mysql', true),
        ], ['%d','%d','%s','%f','%f','%s','%s','%s']);
    }

    private function nodes(int $exclude = 0): array {
        $nodes = [];
        foreach ($this->node_ids($exclude) as $id) {
            $node = $this->node($id);
            if ($node) $nodes[] = $node;
        }
        return $nodes;
    }

    private function node_ids(int $exclude = 0): array {
        return get_posts([
            'post_type' => $this->post_types(),
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'post__not_in' => $exclude ? [$exclude] : [],
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);
    }

    private function node(int $post_id): ?array {
        $coords = $this->coordinates($post_id);
        if (!$coords) return null;
        return [
            'id' => $post_id,
            'type' => $this->node_type($post_id),
            'lat' => $coords[0],
            'lng' => $coords[1],
            'destination' => absint(get_post_meta($post_id, '_tng_destination_id', true)),
        ];
    }

    private function coordinates(int $post_id): ?array {
        $pairs = [
            ['_tng_destination_lat','_tng_destination_lng'],
            ['map_lat','map_lng'],
            ['lat','lng'],
            ['latitude','longitude'],
            ['_lat','_lng'],
            ['st_latitude','st_longitude'],
        ];
        foreach ($pairs as [$lat_key, $lng_key]) {
            $lat = get_post_meta($post_id, $lat_key, true);
            $lng = get_post_meta($post_id, $lng_key, true);
            if (is_numeric($lat) && is_numeric($lng)) {
                $lat = (float)$lat; $lng = (float)$lng;
                if ($lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180 && ($lat != 0.0 || $lng != 0.0)) return [$lat, $lng];
            }
        }
        $location = get_post_meta($post_id, 'location_id', true);
        if ($location && is_numeric($location) && (int)$location !== $post_id) return $this->coordinates((int)$location);
        return null;
    }

    private function node_type(int $post_id): string {
        $post_type = get_post_type($post_id) ?: 'content';
        $map = ['st_activity'=>'activity','st_hotel'=>'lodging','st_tours'=>'tour','st_rental'=>'rental','top_sight'=>'sight','tng_destination'=>'destination'];
        return $map[$post_type] ?? sanitize_key($post_type);
    }

    private function post_types(): array {
        $types = ['tng_destination','st_activity','st_hotel','st_tours','st_rental','top_sight'];
        return array_values(array_filter($types, 'post_type_exists'));
    }

    private function distance(float $lat1, float $lng1, float $lat2, float $lng2): float {
        $earth = 3958.7613;
        $lat1 = deg2rad($lat1); $lat2 = deg2rad($lat2);
        $dlat = $lat2 - $lat1; $dlng = deg2rad($lng2 - $lng1);
        $a = sin($dlat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dlng / 2) ** 2;
        return $earth * 2 * atan2(sqrt($a), sqrt(max(0, 1 - $a)));
    }

    private function table(): string { global $wpdb; return $wpdb->prefix . 'tng_knowledge_graph'; }
}
