<?php
namespace TNG_OS\Modules\Destinations;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class Destination_Health_Dashboard implements Module_Interface {
    private const PAGE = 'tng-destination-health';

    public function id(): string { return 'destination_health_dashboard'; }

    public function register(Container $container): void {
        $container->set('destination_health_dashboard', $this);
        add_action('admin_menu', [$this, 'menu'], 23);
    }

    public function boot(Container $container): void {}

    public function menu(): void {
        add_submenu_page('tn-game-os', 'Destination Health', 'Destination Health', 'manage_options', self::PAGE, [$this, 'page']);
    }

    public function page(): void {
        if (!current_user_can('manage_options')) wp_die('Unauthorized.');

        $rows = [];
        $totals = ['nodes'=>0,'healthy'=>0,'needs_work'=>0,'missing_coords'=>0,'missing_image'=>0,'missing_description'=>0,'missing_destination'=>0,'unconnected'=>0,'suspicious'=>0,'excluded'=>0];
        $sum = 0;

        foreach ($this->node_ids() as $post_id) {
            $row = $this->inspect($post_id);
            $rows[] = $row;
            $totals['nodes']++;
            $sum += $row['score'];
            if ($row['score'] >= 80) $totals['healthy']++; else $totals['needs_work']++;
            foreach ($row['issues'] as $issue) if (isset($totals[$issue])) $totals[$issue]++;
            if ($row['excluded']) $totals['excluded']++;
        }

        usort($rows, static fn(array $a, array $b): int => $a['score'] <=> $b['score'] ?: strcasecmp($a['title'], $b['title']));
        $overall = $totals['nodes'] ? (int)round($sum / $totals['nodes']) : 0;
        ?>
        <div class="wrap tng-health">
            <style>
                .tng-health-hero{background:linear-gradient(135deg,#12203d,#684196);color:#fff;padding:28px 30px;border-radius:22px;margin:18px 0}.tng-health-hero h1{color:#fff;font-size:34px;margin:0 0 8px}.tng-health-score{font-size:52px;font-weight:800;line-height:1}.tng-health-bar{height:10px;background:rgba(255,255,255,.2);border-radius:999px;overflow:hidden;margin-top:16px}.tng-health-bar span{display:block;height:100%;width:<?php echo (int)$overall; ?>%;background:linear-gradient(90deg,#8b5cf6,#34d399)}.tng-health-grid{display:grid;grid-template-columns:repeat(4,minmax(145px,1fr));gap:14px;margin:18px 0}.tng-health-card{background:#fff;border:1px solid #dcdcde;border-radius:16px;padding:18px}.tng-health-card strong{display:block;font-size:30px;color:#6438b3}.tng-health-pill{display:inline-block;padding:4px 9px;border-radius:999px;font-weight:700;font-size:11px}.tng-health-good{background:#dcfce7;color:#087a45}.tng-health-warn{background:#fff4d6;color:#9a5b00}.tng-health-bad{background:#fee2e2;color:#b42318}.tng-health-issues{display:flex;flex-wrap:wrap;gap:5px}.tng-health-score-cell{font-weight:800;font-size:18px}@media(max-width:900px){.tng-health-grid{grid-template-columns:repeat(2,1fr)}}
            </style>
            <section class="tng-health-hero">
                <div style="display:flex;justify-content:space-between;gap:20px;align-items:center;flex-wrap:wrap">
                    <div><div style="letter-spacing:.18em;font-size:12px;font-weight:700;color:#ffd34e">DESTINATION INTELLIGENCE</div><h1>Destination Health</h1><p>Measures whether each listing is ready for search, recommendations, and future AI trip planning.</p></div>
                    <div><div class="tng-health-score"><?php echo $overall; ?>%</div><div>Overall content health</div></div>
                </div>
                <div class="tng-health-bar"><span></span></div>
            </section>

            <div class="tng-health-grid">
                <?php $this->metric($totals['nodes'],'Published nodes'); ?>
                <?php $this->metric($totals['healthy'],'Healthy listings'); ?>
                <?php $this->metric($totals['missing_coords'],'Missing coordinates'); ?>
                <?php $this->metric($totals['unconnected'],'No graph connections'); ?>
                <?php $this->metric($totals['missing_description'],'Missing descriptions'); ?>
                <?php $this->metric($totals['missing_image'],'Missing images'); ?>
                <?php $this->metric($totals['missing_destination'],'Missing destination'); ?>
                <?php $this->metric($totals['suspicious'],'Suspicious coordinates'); ?>
            </div>

            <h2>Listings needing attention</h2>
            <table class="widefat striped">
                <thead><tr><th>Listing</th><th>Type</th><th>Health</th><th>Coordinate source</th><th>Issues</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><a href="<?php echo esc_url(get_edit_post_link($row['id'])); ?>"><strong><?php echo esc_html($row['title']); ?></strong></a></td>
                        <td><?php echo esc_html($row['type']); ?></td>
                        <td class="tng-health-score-cell"><?php echo (int)$row['score']; ?>%</td>
                        <td><?php echo esc_html($row['coordinate_label']); ?></td>
                        <td><div class="tng-health-issues">
                            <?php if (!$row['issues']): ?><span class="tng-health-pill tng-health-good">Ready</span><?php endif; ?>
                            <?php foreach ($row['issue_labels'] as $label): ?><span class="tng-health-pill tng-health-warn"><?php echo esc_html($label); ?></span><?php endforeach; ?>
                            <?php if ($row['excluded']): ?><span class="tng-health-pill tng-health-bad">Excluded</span><?php endif; ?>
                        </div></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function inspect(int $post_id): array {
        $post = get_post($post_id);
        $resolved = Coordinate_Intelligence::resolve($post_id);
        $status = $resolved['status'] ?? 'missing';
        $issues = [];
        $score = 0;

        if (in_array($status, ['exact','inherited'], true)) $score += 25;
        else $issues[] = $status === 'suspicious' ? 'suspicious' : 'missing_coords';

        $description = trim(wp_strip_all_tags((string)$post->post_content));
        if (mb_strlen($description) >= 80) $score += 20; else $issues[] = 'missing_description';

        if (has_post_thumbnail($post_id)) $score += 15; else $issues[] = 'missing_image';

        if ($this->has_destination($post_id) || get_post_type($post_id) === 'tng_destination') $score += 15; else $issues[] = 'missing_destination';

        $connections = $this->connection_count($post_id);
        if ($connections > 0) $score += 20; else $issues[] = 'unconnected';

        if (get_post_meta($post_id, '_tng_coordinate_source_type', true)) $score += 5;

        $labels = [
            'missing_coords'=>'Coordinates','suspicious'=>'Suspicious coordinates','missing_description'=>'Description','missing_image'=>'Featured image','missing_destination'=>'Destination','unconnected'=>'Graph connections'
        ];

        return [
            'id'=>$post_id,
            'title'=>get_the_title($post_id) ?: ('#'.$post_id),
            'type'=>get_post_type($post_id),
            'score'=>min(100,$score),
            'issues'=>$issues,
            'issue_labels'=>array_values(array_map(static fn($key) => $labels[$key] ?? $key, $issues)),
            'coordinate_label'=>$resolved['label'] ?? 'No source',
            'excluded'=>(bool)get_post_meta($post_id, '_tng_graph_excluded', true),
        ];
    }

    private function connection_count(int $post_id): int {
        global $wpdb;
        $table = $wpdb->prefix . 'tng_knowledge_graph';
        if ((string)$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) return 0;
        return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$table}` WHERE source_id=%d", $post_id));
    }

    private function has_destination(int $post_id): bool {
        foreach (['_tng_destination_id','location_id','st_location_id','destination_id','_tng_primary_destination_id'] as $key) if (absint(get_post_meta($post_id,$key,true))) return true;
        foreach (['_tng_related_destination_ids','related_destination_ids','_tng_effective_destination_ids'] as $key) {
            $value = maybe_unserialize(get_post_meta($post_id,$key,true));
            if (is_array($value) && array_filter(array_map('absint',$value))) return true;
        }
        return false;
    }

    private function metric(int $value, string $label): void {
        echo '<div class="tng-health-card"><strong>'.number_format_i18n($value).'</strong><span>'.esc_html($label).'</span></div>';
    }

    private function node_ids(): array {
        return get_posts(['post_type'=>$this->post_types(),'post_status'=>'publish','posts_per_page'=>-1,'fields'=>'ids','orderby'=>'title','order'=>'ASC']);
    }

    private function post_types(): array {
        return array_values(array_filter(['tng_destination','st_activity','st_hotel','st_tours','st_rental','top_sight'], 'post_type_exists'));
    }
}
