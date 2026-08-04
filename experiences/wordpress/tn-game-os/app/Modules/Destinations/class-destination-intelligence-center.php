<?php
namespace TNG_OS\Modules\Destinations;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class Destination_Intelligence_Center implements Module_Interface {
    private const PAGE = 'tng-destination-intelligence';

    public function id(): string { return 'destination_intelligence_center'; }

    public function register(Container $container): void {
        $container->set('destination_intelligence_center', $this);
        add_action('admin_menu', [$this, 'menu'], 22);
    }

    public function boot(Container $container): void {}

    public function menu(): void {
        add_submenu_page('tn-game-os', 'Destination Intelligence', 'Destination Intelligence', 'manage_options', self::PAGE, [$this, 'page']);
    }

    public function page(): void {
        if (!current_user_can('manage_options')) wp_die('Unauthorized.');
        $ids = get_posts(['post_type' => $this->post_types(), 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids']);
        $exact = $inherited = $missing = $suspicious = $excluded = $precise = $ai_ready = 0;
        foreach ($ids as $post_id) {
            $resolved = Coordinate_Intelligence::resolve($post_id);
            $status = $resolved['status'] ?? 'missing';
            if ($status === 'exact') $exact++;
            elseif ($status === 'inherited') $inherited++;
            elseif ($status === 'suspicious') $suspicious++;
            else $missing++;
            if (get_post_meta($post_id, '_tng_graph_excluded', true)) $excluded++;
            if (get_post_meta($post_id, '_tng_coordinate_source_type', true)) $precise++;
            if (class_exists(Destination_AI_Profiles::class) && Destination_AI_Profiles::completeness(Destination_AI_Profiles::profile($post_id)) >= 80) $ai_ready++;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'tng_knowledge_graph';
        $table_exists = (string)$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        $relationships = $table_exists === $table ? (int)$wpdb->get_var("SELECT COUNT(*) FROM `{$table}`") : 0;
        $usable = $exact + $inherited;
        $coordinate_score = count($ids) ? ($usable / count($ids)) * 55 : 0;
        $graph_score = $relationships > 0 ? 20 : 0;
        $profile_score = count($ids) ? ($ai_ready / count($ids)) * 15 : 0;
        $quality_score = count($ids) ? max(0, 10 - (($suspicious + $missing) / count($ids) * 10)) : 0;
        $score = (int)round(min(100, $coordinate_score + $graph_score + $profile_score + $quality_score));
        ?>
        <div class="wrap tng-di-center">
            <style>
                .tng-di-hero{background:linear-gradient(135deg,#17213f,#674091);color:#fff;border-radius:22px;padding:28px 30px;margin:18px 0}.tng-di-hero h1{color:#fff;margin:0 0 8px;font-size:34px}.tng-di-grid{display:grid;grid-template-columns:repeat(4,minmax(150px,1fr));gap:14px;margin:18px 0}.tng-di-card,.tng-di-tool{background:#fff;border:1px solid #dcdcde;border-radius:16px;padding:18px}.tng-di-card strong{display:block;color:#6538b5;font-size:30px}.tng-di-tools{display:grid;grid-template-columns:repeat(2,minmax(280px,1fr));gap:16px;margin-top:20px}.tng-di-tool h2{margin-top:0}.tng-di-tool p{min-height:42px;color:#667085}.tng-di-score{font-size:48px;font-weight:800;line-height:1}.tng-di-score-label{margin-top:5px}.tng-di-bar{height:10px;background:rgba(255,255,255,.2);border-radius:999px;overflow:hidden;margin-top:14px}.tng-di-bar span{display:block;height:100%;background:linear-gradient(90deg,#8b5cf6,#34d399);width:<?php echo (int)$score; ?>%}@media(max-width:900px){.tng-di-grid,.tng-di-tools{grid-template-columns:1fr 1fr}}@media(max-width:620px){.tng-di-grid,.tng-di-tools{grid-template-columns:1fr}}
            </style>
            <section class="tng-di-hero">
                <div style="display:flex;justify-content:space-between;gap:20px;align-items:center;flex-wrap:wrap">
                    <div><div style="letter-spacing:.18em;font-size:12px;font-weight:700;color:#ffd34e">TN GAME OS</div><h1>Destination Intelligence Center</h1><p>Resolve geography, improve content quality, and prepare destination knowledge for recommendations and trip planning.</p></div>
                    <div><div class="tng-di-score"><?php echo (int)$score; ?>%</div><div class="tng-di-score-label">Intelligence readiness</div></div>
                </div>
                <div class="tng-di-bar"><span></span></div>
            </section>
            <div class="tng-di-grid">
                <div class="tng-di-card"><strong><?php echo number_format_i18n(count($ids)); ?></strong><span>Published nodes</span></div>
                <div class="tng-di-card"><strong><?php echo number_format_i18n($precise); ?></strong><span>Precise sources saved</span></div>
                <div class="tng-di-card"><strong><?php echo number_format_i18n($relationships); ?></strong><span>Graph relationships</span></div>
                <div class="tng-di-card"><strong><?php echo number_format_i18n($ai_ready); ?></strong><span>AI-ready profiles</span></div>
                <div class="tng-di-card"><strong><?php echo number_format_i18n($exact); ?></strong><span>Exact coordinates</span></div>
                <div class="tng-di-card"><strong><?php echo number_format_i18n($inherited); ?></strong><span>Inherited coordinates</span></div>
                <div class="tng-di-card"><strong><?php echo number_format_i18n($missing); ?></strong><span>Missing coordinates</span></div>
                <div class="tng-di-card"><strong><?php echo number_format_i18n($suspicious); ?></strong><span>Suspicious coordinates</span></div>
            </div>
            <div class="tng-di-tools">
                <?php $this->tool('Destination Health','Score every listing for coordinates, description, image, destination assignment, and graph connectivity.','admin.php?page=tng-destination-health','Open health dashboard'); ?>
                <?php $this->tool('AI Profiles','Create structured visitor traits, suitability scores, visit time, cost, seasons, and AI-ready summaries.','admin.php?page=tng-destination-ai-profiles','Open AI profiles'); ?>
                <?php $this->tool('Coordinate Sources','Promote Google Places geometry and GPX trail starts to precise listing coordinates.','admin.php?page=tng-coordinate-sources','Open resolver'); ?>
                <?php $this->tool('Coordinate Audit','Review exact, inherited, missing, and suspicious geographic records.','admin.php?page=tng-coordinate-audit','Open audit'); ?>
                <?php $this->tool('Graph Quality','Review duplicate coordinate clusters and exclude demo or placeholder content.','admin.php?page=tng-coordinate-quality','Review quality'); ?>
                <?php $this->tool('Knowledge Graph','Rebuild and inspect automatic relationships between destination content.','admin.php?page=tng-knowledge-graph','Open graph'); ?>
            </div>
        </div>
        <?php
    }

    private function tool(string $title, string $description, string $path, string $label): void {
        echo '<section class="tng-di-tool"><h2>' . esc_html($title) . '</h2><p>' . esc_html($description) . '</p><a class="button button-primary" href="' . esc_url(admin_url($path)) . '">' . esc_html($label) . '</a></section>';
    }

    private function post_types(): array {
        return array_values(array_filter(['tng_destination','st_activity','st_hotel','st_tours','st_rental','top_sight'], 'post_type_exists'));
    }
}
