<?php

namespace TNG_OS\Modules\Entities;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class Platform_Health implements Module_Interface {
    private const ENTITY_TYPE = 'tng_entity';
    private const QUEUE_TYPE = 'tng_concert_import';

    public function id(): string { return 'platform_health'; }

    public function register(Container $container): void {
        $container->set('platform_health', $this);
        add_action('admin_menu', [$this, 'menu'], 29);
    }

    public function boot(Container $container): void {}

    public function menu(): void {
        add_submenu_page(
            'tn-game-os',
            'Platform Health',
            'Platform Health',
            'manage_options',
            'tng-platform-health',
            [$this, 'render']
        );
    }

    public function render(): void {
        if (!current_user_can('manage_options')) return;

        $entity_ids = get_posts([
            'post_type' => self::ENTITY_TYPE,
            'post_status' => ['publish', 'draft', 'private'],
            'posts_per_page' => -1,
            'fields' => 'ids',
        ]);

        $types = [];
        $snapshots = 0;
        $relationships = 0;
        $broken = 0;
        $orphaned = 0;
        $source_keys = [];
        $duplicates = 0;
        $known_entity_ids = [];

        foreach ($entity_ids as $post_id) {
            $entity_id = (string)get_post_meta($post_id, '_tng_entity_id', true);
            if ($entity_id !== '') $known_entity_ids[$entity_id] = true;
        }

        foreach ($entity_ids as $post_id) {
            $type = (string)get_post_meta($post_id, '_tng_entity_type', true) ?: 'unknown';
            $types[$type] = ($types[$type] ?? 0) + 1;

            $entity_snapshots = get_post_meta($post_id, '_tng_entity_snapshots', true);
            $snapshots += is_array($entity_snapshots) ? count($entity_snapshots) : 0;

            $entity_relationships = get_post_meta($post_id, '_tng_entity_relationships', true);
            $entity_relationships = is_array($entity_relationships) ? $entity_relationships : [];
            $relationships += count($entity_relationships);

            if (!$entity_relationships && $type !== 'venue') $orphaned++;
            foreach ($entity_relationships as $relationship) {
                $target = is_array($relationship) ? (string)($relationship['target_entity_id'] ?? '') : '';
                if ($target === '' || !isset($known_entity_ids[$target])) $broken++;
            }

            $source_key = (string)get_post_meta($post_id, '_tng_entity_source_key', true);
            if ($source_key !== '') {
                if (isset($source_keys[$source_key])) $duplicates++;
                $source_keys[$source_key] = true;
            }
        }

        $pending = wp_count_posts(self::QUEUE_TYPE);
        $review_queue = isset($pending->pending) ? (int)$pending->pending : 0;
        $published_queue = isset($pending->publish) ? (int)$pending->publish : 0;
        $healthy = $broken === 0 && $duplicates === 0;

        $cards = [
            ['Entities', count($entity_ids)],
            ['Events', (int)($types['event'] ?? 0)],
            ['Venues', (int)($types['venue'] ?? 0)],
            ['Relationships', $relationships],
            ['Snapshots', $snapshots],
            ['Review Queue', $review_queue],
            ['Published Imports', $published_queue],
            ['Plugin Release', TNG_OS_VERSION],
        ];
        ?>
        <div class="wrap">
            <h1>TN Platform Health</h1>
            <p>Operational visibility for canonical entities, relationships, snapshots, and the Review Studio publishing pipeline.</p>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px;max-width:1200px;margin:20px 0;">
                <?php foreach ($cards as [$label, $value]): ?>
                    <div style="background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:18px;">
                        <div style="font-size:12px;text-transform:uppercase;color:#646970;font-weight:600;letter-spacing:.05em;"><?php echo esc_html($label); ?></div>
                        <div style="font-size:30px;font-weight:700;margin-top:8px;"><?php echo esc_html((string)$value); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div style="background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:22px;max-width:1155px;">
                <h2 style="margin-top:0;">Integrity checks</h2>
                <table class="widefat striped">
                    <tbody>
                        <tr><td><strong>Graph integrity</strong></td><td><?php echo $healthy ? 'Healthy' : 'Attention required'; ?></td></tr>
                        <tr><td><strong>Broken relationships</strong></td><td><?php echo esc_html((string)$broken); ?></td></tr>
                        <tr><td><strong>Duplicate source identities</strong></td><td><?php echo esc_html((string)$duplicates); ?></td></tr>
                        <tr><td><strong>Orphaned event entities</strong></td><td><?php echo esc_html((string)$orphaned); ?></td></tr>
                        <tr><td><strong>Canonical entity types</strong></td><td><?php echo esc_html(implode(', ', array_keys(array_filter($types)))); ?></td></tr>
                    </tbody>
                </table>
                <p style="margin-bottom:0;margin-top:18px;"><a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=tng-entity-explorer')); ?>">Open Entity Explorer</a> <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=tng-review-studio')); ?>">Open Review Studio</a></p>
            </div>
        </div>
        <?php
    }
}
