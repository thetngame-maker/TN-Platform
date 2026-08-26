<?php
namespace TNG_OS\Modules\Destinations;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class Coordinate_Quality_Controls implements Module_Interface {
    private const PAGE = 'tng-coordinate-quality';

    public function id(): string { return 'coordinate_quality_controls'; }

    public function register(Container $container): void {
        $container->set('coordinate_quality_controls', $this);
        add_action('admin_menu', [$this, 'menu'], 26);
        add_action('admin_post_tng_bulk_apply_inherited_coordinates', [$this, 'bulk_apply']);
        add_action('admin_post_tng_toggle_graph_exclusion', [$this, 'toggle_exclusion']);
    }

    public function boot(Container $container): void {}

    public function menu(): void {
        add_submenu_page('tn-game-os', 'Coordinate Quality Controls', 'Graph Quality', 'manage_options', self::PAGE, [$this, 'page']);
    }

    public function page(): void {
        if (!current_user_can('manage_options')) wp_die('Unauthorized.');
        $ids = $this->node_ids();
        $inherited = [];
        $excluded = [];
        $groups = [];

        foreach ($ids as $post_id) {
            $resolved = Coordinate_Intelligence::resolve($post_id);
            if (($resolved['status'] ?? '') === 'inherited') $inherited[] = $post_id;
            if (get_post_meta($post_id, '_tng_graph_excluded', true)) $excluded[] = $post_id;
            if (isset($resolved['lat'], $resolved['lng'])) {
                $key = number_format((float)$resolved['lat'], 5, '.', '') . ',' . number_format((float)$resolved['lng'], 5, '.', '');
                $groups[$key][] = $post_id;
            }
        }

        $duplicates = array_filter($groups, static fn(array $group): bool => count($group) >= 3);
        uasort($duplicates, static fn(array $a, array $b): int => count($b) <=> count($a));
        $notice = isset($_GET['tng_quality_notice']) ? sanitize_text_field(wp_unslash($_GET['tng_quality_notice'])) : '';
        ?>
        <div class="wrap tng-coordinate-quality">
            <h1>Coordinate & Graph Quality</h1>
            <p>Bulk-repair inherited coordinates, review shared-coordinate clusters, and keep demo or placeholder content out of Destination AI recommendations.</p>
            <?php if ($notice): ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>
            <style>
                .tng-cq-stats{display:grid;grid-template-columns:repeat(3,minmax(170px,1fr));gap:14px;max-width:900px;margin:22px 0}.tng-cq-stat{background:#fff;border:1px solid #dcdcde;border-radius:16px;padding:18px}.tng-cq-stat strong{display:block;font-size:30px;color:#6438b3}.tng-cq-actions{display:flex;gap:10px;align-items:center;margin:18px 0 26px}.tng-cq-cluster{background:#fff;border:1px solid #dcdcde;border-radius:16px;padding:16px;margin:0 0 14px;max-width:1100px}.tng-cq-cluster h3{margin:0 0 12px}.tng-cq-list{display:grid;grid-template-columns:repeat(2,minmax(280px,1fr));gap:8px}.tng-cq-item{display:flex;justify-content:space-between;gap:12px;align-items:center;padding:10px;border:1px solid #e5e7eb;border-radius:10px}.tng-cq-meta{color:#667085;font-size:12px}.tng-cq-excluded{opacity:.58;background:#f6f7f7}@media(max-width:850px){.tng-cq-stats,.tng-cq-list{grid-template-columns:1fr}}
            </style>
            <div class="tng-cq-stats">
                <div class="tng-cq-stat"><strong><?php echo number_format_i18n(count($inherited)); ?></strong><span>Inherited coordinates ready</span></div>
                <div class="tng-cq-stat"><strong><?php echo number_format_i18n(count($duplicates)); ?></strong><span>Shared-coordinate clusters</span></div>
                <div class="tng-cq-stat"><strong><?php echo number_format_i18n(count($excluded)); ?></strong><span>Excluded from graph</span></div>
            </div>

            <form class="tng-cq-actions" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Save all currently inherited coordinates as exact listing coordinates?');">
                <?php wp_nonce_field('tng_bulk_apply_inherited_coordinates'); ?>
                <input type="hidden" name="action" value="tng_bulk_apply_inherited_coordinates">
                <button class="button button-primary button-large" <?php disabled(!$inherited); ?>>Save all inherited coordinates</button>
                <span class="description">Each repaired listing receives an immediate targeted Knowledge Graph refresh.</span>
            </form>

            <h2>Shared-coordinate clusters</h2>
            <p>Three or more listings at the same rounded coordinate may be a legitimate venue cluster, a destination-center inheritance, or imported placeholder content. Exclude only listings that should not influence recommendations.</p>
            <?php if (!$duplicates): ?><div class="notice notice-info inline"><p>No shared-coordinate clusters require review.</p></div><?php endif; ?>
            <?php foreach ($duplicates as $coordinate => $post_ids): ?>
                <section class="tng-cq-cluster">
                    <h3><?php echo esc_html($coordinate); ?> <span class="tng-cq-meta">· <?php echo number_format_i18n(count($post_ids)); ?> listings</span></h3>
                    <div class="tng-cq-list">
                        <?php foreach ($post_ids as $post_id): $is_excluded = (bool)get_post_meta($post_id, '_tng_graph_excluded', true); ?>
                        <div class="tng-cq-item <?php echo $is_excluded ? 'tng-cq-excluded' : ''; ?>">
                            <div>
                                <a href="<?php echo esc_url(get_edit_post_link($post_id)); ?>"><strong><?php echo esc_html(get_the_title($post_id) ?: ('#' . $post_id)); ?></strong></a>
                                <div class="tng-cq-meta"><?php echo esc_html(get_post_type($post_id)); ?><?php echo $is_excluded ? ' · excluded' : ''; ?></div>
                            </div>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                <?php wp_nonce_field('tng_toggle_graph_exclusion_' . $post_id); ?>
                                <input type="hidden" name="action" value="tng_toggle_graph_exclusion">
                                <input type="hidden" name="post_id" value="<?php echo (int)$post_id; ?>">
                                <button class="button"><?php echo $is_excluded ? 'Include' : 'Exclude'; ?></button>
                            </form>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>
        <?php
    }

    public function bulk_apply(): void {
        if (!current_user_can('manage_options')) wp_die('Unauthorized.');
        check_admin_referer('tng_bulk_apply_inherited_coordinates');
        $count = 0;
        foreach ($this->node_ids() as $post_id) {
            $resolved = Coordinate_Intelligence::resolve($post_id);
            if (($resolved['status'] ?? '') !== 'inherited' || !isset($resolved['lat'], $resolved['lng'])) continue;
            update_post_meta($post_id, '_tng_destination_lat', (string)$resolved['lat']);
            update_post_meta($post_id, '_tng_destination_lng', (string)$resolved['lng']);
            update_post_meta($post_id, '_tng_coordinate_confidence', 'inherited_saved');
            update_post_meta($post_id, '_tng_coordinate_source_id', absint($resolved['source_id'] ?? 0));
            do_action('tng_knowledge_graph_refresh_id', $post_id);
            $count++;
        }
        $this->redirect(sprintf('%d inherited coordinate records saved and refreshed.', $count));
    }

    public function toggle_exclusion(): void {
        if (!current_user_can('manage_options')) wp_die('Unauthorized.');
        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        check_admin_referer('tng_toggle_graph_exclusion_' . $post_id);
        if (!$post_id) $this->redirect('No listing was selected.');
        $excluded = (bool)get_post_meta($post_id, '_tng_graph_excluded', true);
        if ($excluded) {
            delete_post_meta($post_id, '_tng_graph_excluded');
            do_action('tng_knowledge_graph_refresh_id', $post_id);
            $message = 'Listing included in the Knowledge Graph.';
        } else {
            update_post_meta($post_id, '_tng_graph_excluded', '1');
            do_action('tng_knowledge_graph_remove_id', $post_id);
            $message = 'Listing excluded from the Knowledge Graph and future recommendations.';
        }
        $this->redirect($message);
    }

    private function redirect(string $message): void {
        wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE . '&tng_quality_notice=' . rawurlencode($message)));
        exit;
    }

    private function node_ids(): array {
        return get_posts(['post_type' => $this->post_types(), 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'orderby' => 'title', 'order' => 'ASC']);
    }

    private function post_types(): array {
        return array_values(array_filter(['tng_destination','st_activity','st_hotel','st_tours','st_rental','top_sight'], 'post_type_exists'));
    }
}
