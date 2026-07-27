<?php
namespace TNG_OS\Modules\Entities;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class Spatial_Integrity implements Module_Interface {
    private const ENTITY_TYPE = 'tng_entity';
    private const PAGE = 'tng-spatial-integrity';

    public function id(): string { return 'spatial_integrity'; }

    public function register(Container $container): void {
        $container->set('spatial_integrity', $this);
        add_action('admin_menu', [$this, 'menu'], 38);
        add_action('admin_post_tng_spatial_save', [$this, 'save']);
        add_action('admin_post_tng_spatial_auto_repair', [$this, 'auto_repair']);
    }

    public function boot(Container $container): void {}

    public function menu(): void {
        add_submenu_page('tn-game-os', 'Spatial Integrity', 'Spatial Integrity', 'manage_options', self::PAGE, [$this, 'page']);
    }

    public function page(): void {
        if (!current_user_can('manage_options')) return;
        $rows = $this->audit();
        $valid = count(array_filter($rows, static fn(array $r): bool => $r['status'] === 'valid'));
        $repairable = count(array_filter($rows, static fn(array $r): bool => $r['repairable']));
        $missing = count(array_filter($rows, static fn(array $r): bool => $r['status'] === 'missing'));
        ?>
        <div class="wrap tng-spatial">
            <style>
                .tng-spatial{max-width:1500px}.tng-spatial-hero{background:linear-gradient(135deg,#18213d,#4b2f68);color:#fff;border-radius:18px;padding:30px;margin:18px 0}.tng-spatial-hero h1{color:#fff;margin:0 0 7px}.tng-spatial-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:18px}.tng-spatial-stat,.tng-spatial-panel{background:#fff;border:1px solid #dcdcde;border-radius:14px;padding:18px}.tng-spatial-stat strong{display:block;font-size:28px;color:#18213d;margin-top:4px}.tng-spatial-table{width:100%;border-collapse:collapse}.tng-spatial-table th,.tng-spatial-table td{text-align:left;padding:12px 10px;border-bottom:1px solid #edf0f3;vertical-align:middle}.tng-spatial-table input{width:130px}.tng-spatial-badge{display:inline-flex;padding:5px 9px;border-radius:999px;font-size:12px;font-weight:800}.is-valid{background:#ecfdf3;color:#067647}.is-warning{background:#fff7ed;color:#b54708}.is-error{background:#fff1f0;color:#b42318}.tng-spatial-note{color:#667085;font-size:12px;margin-top:4px}.tng-spatial-actions{display:flex;gap:6px;flex-wrap:wrap}@media(max-width:900px){.tng-spatial-stats{grid-template-columns:repeat(2,minmax(0,1fr))}.tng-spatial-table{display:block;overflow:auto}}
            </style>
            <div class="tng-spatial-hero"><p style="text-transform:uppercase;letter-spacing:.12em;margin:0 0 8px;color:#f6bd3b;font-weight:700">TN Platform · Location Systems</p><h1>Spatial Integrity</h1><p>Audit, normalize, and repair the coordinates used by quests, maps, recommendations, and future location-aware experiences.</p></div>
            <?php if (isset($_GET['saved'])): ?><div class="notice notice-success inline"><p>Coordinates saved.</p></div><?php endif; ?>
            <?php if (isset($_GET['repaired'])): ?><div class="notice notice-success inline"><p><?php echo esc_html(absint($_GET['repaired'])); ?> coordinate record(s) repaired.</p></div><?php endif; ?>
            <div class="tng-spatial-stats">
                <div class="tng-spatial-stat"><span>Entities scanned</span><strong><?php echo esc_html(number_format_i18n(count($rows))); ?></strong></div>
                <div class="tng-spatial-stat"><span>Valid coordinates</span><strong><?php echo esc_html(number_format_i18n($valid)); ?></strong></div>
                <div class="tng-spatial-stat"><span>Auto-repairable</span><strong><?php echo esc_html(number_format_i18n($repairable)); ?></strong></div>
                <div class="tng-spatial-stat"><span>Missing coordinates</span><strong><?php echo esc_html(number_format_i18n($missing)); ?></strong></div>
            </div>
            <div class="tng-spatial-panel">
                <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:12px"><div><h2 style="margin:0">Coordinate audit</h2><p style="margin:5px 0 0;color:#667085">Tennessee validation uses a broad regional envelope. Review automatic suggestions before relying on them outside the region.</p></div><a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tng_spatial_auto_repair'), 'tng_spatial_auto_repair')); ?>">Repair obvious issues</a></div>
                <table class="tng-spatial-table"><thead><tr><th>Entity</th><th>Type</th><th>Status</th><th>Latitude</th><th>Longitude</th><th>Diagnosis</th><th>Actions</th></tr></thead><tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><strong><?php echo esc_html($row['title']); ?></strong><div class="tng-spatial-note"><?php echo esc_html($row['entity_id']); ?> · Post #<?php echo esc_html((string)$row['post_id']); ?></div></td>
                        <td><?php echo esc_html($row['type']); ?></td>
                        <td><span class="tng-spatial-badge <?php echo esc_attr($row['status_class']); ?>"><?php echo esc_html($row['status_label']); ?></span></td>
                        <td colspan="2">
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="tng-spatial-actions">
                                <input type="hidden" name="action" value="tng_spatial_save"><input type="hidden" name="post_id" value="<?php echo esc_attr((string)$row['post_id']); ?>">
                                <?php wp_nonce_field('tng_spatial_save_'.$row['post_id']); ?>
                                <input type="number" step="any" name="latitude" value="<?php echo esc_attr($row['suggested_lat'] !== null ? (string)$row['suggested_lat'] : (string)$row['lat']); ?>" placeholder="Latitude">
                                <input type="number" step="any" name="longitude" value="<?php echo esc_attr($row['suggested_lng'] !== null ? (string)$row['suggested_lng'] : (string)$row['lng']); ?>" placeholder="Longitude">
                                <button class="button button-small" type="submit">Save</button>
                            </form>
                        </td>
                        <td><?php echo esc_html($row['diagnosis']); ?><?php if ($row['repairable']): ?><div class="tng-spatial-note">Suggested repair is prefilled.</div><?php endif; ?></td>
                        <td><a class="button button-small" href="<?php echo esc_url(get_edit_post_link($row['post_id'])); ?>">Edit entity</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody></table>
            </div>
        </div>
        <?php
    }

    public function save(): void {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        $post_id = absint($_POST['post_id'] ?? 0);
        check_admin_referer('tng_spatial_save_'.$post_id);
        if (!$post_id || get_post_type($post_id) !== self::ENTITY_TYPE) $this->redirect();
        $lat = $this->number($_POST['latitude'] ?? null);
        $lng = $this->number($_POST['longitude'] ?? null);
        if ($lat === null || $lng === null || abs($lat) > 90 || abs($lng) > 180) $this->redirect(['invalid'=>'1']);
        $payload = (array)get_post_meta($post_id, '_tng_entity_payload', true);
        $payload['latitude'] = $lat;
        $payload['longitude'] = $lng;
        $payload['lat'] = $lat;
        $payload['lng'] = $lng;
        $payload['coordinates'] = ['lat'=>$lat, 'lng'=>$lng];
        update_post_meta($post_id, '_tng_entity_payload', $payload);
        clean_post_cache($post_id);
        $this->redirect(['saved'=>'1']);
    }

    public function auto_repair(): void {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        check_admin_referer('tng_spatial_auto_repair');
        $count = 0;
        foreach ($this->audit() as $row) {
            if (!$row['repairable'] || $row['suggested_lat'] === null || $row['suggested_lng'] === null) continue;
            $payload = (array)get_post_meta($row['post_id'], '_tng_entity_payload', true);
            $payload['latitude'] = $row['suggested_lat'];
            $payload['longitude'] = $row['suggested_lng'];
            $payload['lat'] = $row['suggested_lat'];
            $payload['lng'] = $row['suggested_lng'];
            $payload['coordinates'] = ['lat'=>$row['suggested_lat'], 'lng'=>$row['suggested_lng']];
            update_post_meta($row['post_id'], '_tng_entity_payload', $payload);
            clean_post_cache($row['post_id']);
            $count++;
        }
        $this->redirect(['repaired'=>(string)$count]);
    }

    private function audit(): array {
        $posts = get_posts(['post_type'=>self::ENTITY_TYPE,'post_status'=>['publish','draft','private'],'posts_per_page'=>1000,'orderby'=>'title','order'=>'ASC']);
        $rows = [];
        foreach ($posts as $post) {
            $payload = (array)get_post_meta($post->ID, '_tng_entity_payload', true);
            [$lat, $lng] = $this->raw_coordinates($payload);
            $result = $this->diagnose($lat, $lng);
            $rows[] = array_merge($result, [
                'post_id'=>(int)$post->ID,
                'entity_id'=>(string)get_post_meta($post->ID, '_tng_entity_id', true),
                'title'=>$post->post_title ?: 'Untitled entity',
                'type'=>(string)get_post_meta($post->ID, '_tng_entity_type', true) ?: 'place',
                'lat'=>$lat,
                'lng'=>$lng,
            ]);
        }
        usort($rows, static function(array $a, array $b): int {
            $rank = ['error'=>0,'warning'=>1,'missing'=>2,'valid'=>3];
            $cmp = ($rank[$a['status']] ?? 9) <=> ($rank[$b['status']] ?? 9);
            return $cmp ?: strcasecmp($a['title'], $b['title']);
        });
        return $rows;
    }

    private function diagnose(?float $lat, ?float $lng): array {
        if ($lat === null || $lng === null) return $this->result('missing','Missing','is-error','No usable latitude and longitude were found.');
        if (abs($lat) > 90 || abs($lng) > 180) return $this->result('error','Invalid','is-error','Coordinate values are outside valid geographic ranges.');
        if ($this->in_tennessee($lat, $lng)) return $this->result('valid','Valid','is-valid','Coordinates fall within the Tennessee regional envelope.');
        if ($this->in_tennessee($lat, -$lng) && $lng > 0) return $this->result('warning','Repair suggested','is-warning','Longitude is likely missing its negative sign.', true, $lat, -$lng);
        if ($this->in_tennessee($lng, $lat)) return $this->result('warning','Repair suggested','is-warning','Latitude and longitude appear to be swapped.', true, $lng, $lat);
        if ($this->in_tennessee($lng, -$lat)) return $this->result('warning','Repair suggested','is-warning','Values appear swapped and the longitude sign is likely wrong.', true, $lng, -$lat);
        return $this->result('warning','Outlier','is-warning','Coordinates are valid globally but outside the Tennessee regional envelope.');
    }

    private function result(string $status, string $label, string $class, string $diagnosis, bool $repairable=false, ?float $lat=null, ?float $lng=null): array {
        return ['status'=>$status,'status_label'=>$label,'status_class'=>$class,'diagnosis'=>$diagnosis,'repairable'=>$repairable,'suggested_lat'=>$lat,'suggested_lng'=>$lng];
    }

    private function raw_coordinates(array $payload): array {
        $lat = $payload['latitude'] ?? $payload['lat'] ?? null;
        $lng = $payload['longitude'] ?? $payload['lng'] ?? $payload['lon'] ?? null;
        if ((!is_numeric($lat) || !is_numeric($lng)) && isset($payload['coordinates']) && is_array($payload['coordinates'])) {
            $lat = $payload['coordinates']['lat'] ?? $payload['coordinates']['latitude'] ?? null;
            $lng = $payload['coordinates']['lng'] ?? $payload['coordinates']['longitude'] ?? null;
        }
        return [$this->number($lat), $this->number($lng)];
    }

    private function number($value): ?float { return is_numeric($value) ? (float)$value : null; }
    private function in_tennessee(float $lat, float $lng): bool { return $lat >= 34.7 && $lat <= 36.9 && $lng >= -90.6 && $lng <= -81.4; }
    private function redirect(array $args=[]): void { wp_safe_redirect(add_query_arg(array_merge(['page'=>self::PAGE],$args),admin_url('admin.php'))); exit; }
}
