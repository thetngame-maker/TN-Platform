<?php

namespace TNG_OS\Modules\Entities;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class Relationship_Manager implements Module_Interface {
    private const ENTITY_TYPE = 'tng_entity';

    public function id(): string { return 'relationship_manager'; }

    public function register(Container $container): void {
        $container->set('relationship_manager', $this);
        add_action('admin_menu', [$this, 'menu'], 28);
        add_action('admin_post_tng_add_relationship', [$this, 'add_relationship']);
        add_action('admin_post_tng_delete_relationship', [$this, 'delete_relationship']);
    }

    public function boot(Container $container): void {}

    public function menu(): void {
        add_submenu_page(
            'tn-game-os',
            'Relationships',
            'Relationships',
            'manage_options',
            'tng-relationships',
            [$this, 'page']
        );
    }

    public static function vocabulary(): array {
        return [
            'held_at' => ['label' => 'Held at', 'inverse' => 'hosts'],
            'located_in' => ['label' => 'Located in', 'inverse' => 'contains'],
            'near' => ['label' => 'Near', 'inverse' => 'near'],
            'part_of' => ['label' => 'Part of', 'inverse' => 'contains'],
            'contains' => ['label' => 'Contains', 'inverse' => 'part_of'],
            'starts_at' => ['label' => 'Starts at', 'inverse' => 'starting_point_for'],
            'ends_at' => ['label' => 'Ends at', 'inverse' => 'ending_point_for'],
            'connects_to' => ['label' => 'Connects to', 'inverse' => 'connects_to'],
            'featured_in' => ['label' => 'Featured in', 'inverse' => 'features'],
            'serves' => ['label' => 'Serves', 'inverse' => 'served_by'],
            'offers' => ['label' => 'Offers', 'inverse' => 'offered_by'],
            'operated_by' => ['label' => 'Operated by', 'inverse' => 'operates'],
            'related_to' => ['label' => 'Related to', 'inverse' => 'related_to'],
        ];
    }

    public function page(): void {
        if (!current_user_can('manage_options')) return;
        $entities = $this->entities();
        $notice = isset($_GET['tng_notice']) ? sanitize_key(wp_unslash($_GET['tng_notice'])) : '';
        ?>
        <div class="wrap tng-relationships">
            <h1>Relationship Manager</h1>
            <p>Create and audit canonical connections used by Graph Explorer, recommendations, itineraries, and TN Game experiences.</p>
            <?php if ($notice === 'added'): ?><div class="notice notice-success inline"><p>Relationship added.</p></div><?php endif; ?>
            <?php if ($notice === 'deleted'): ?><div class="notice notice-success inline"><p>Relationship removed.</p></div><?php endif; ?>
            <?php if ($notice === 'invalid'): ?><div class="notice notice-error inline"><p>The relationship could not be saved. Check both entities and try again.</p></div><?php endif; ?>

            <div class="tng-rel-grid">
                <section class="tng-rel-card">
                    <h2>Add relationship</h2>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="tng_add_relationship">
                        <?php wp_nonce_field('tng_add_relationship'); ?>
                        <p><label><strong>Source entity</strong><br><?php $this->entity_select('source_entity_id', $entities); ?></label></p>
                        <p><label><strong>Relationship</strong><br><select name="relationship_type" required>
                            <?php foreach (self::vocabulary() as $key => $definition): ?>
                                <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($definition['label'] . ' (' . $key . ')'); ?></option>
                            <?php endforeach; ?>
                        </select></label></p>
                        <p><label><strong>Target entity</strong><br><?php $this->entity_select('target_entity_id', $entities); ?></label></p>
                        <p><label><strong>Confidence</strong><br><input type="number" name="confidence" min="0" max="1" step="0.05" value="1"></label></p>
                        <p><label><strong>Evidence / note</strong><br><textarea name="evidence" rows="3" placeholder="Manual editorial relationship"></textarea></label></p>
                        <p><button class="button button-primary">Add relationship</button></p>
                    </form>
                </section>

                <section class="tng-rel-card">
                    <h2>Relationship vocabulary</h2>
                    <table class="widefat striped"><thead><tr><th>Type</th><th>Meaning</th><th>Inverse</th></tr></thead><tbody>
                    <?php foreach (self::vocabulary() as $key => $definition): ?>
                        <tr><td><code><?php echo esc_html($key); ?></code></td><td><?php echo esc_html($definition['label']); ?></td><td><code><?php echo esc_html($definition['inverse']); ?></code></td></tr>
                    <?php endforeach; ?>
                    </tbody></table>
                </section>
            </div>

            <section class="tng-rel-card" style="margin-top:16px">
                <h2>Recorded relationships</h2>
                <?php $rows = $this->relationship_rows($entities); ?>
                <?php if (!$rows): ?><p>No relationships have been recorded.</p><?php else: ?>
                <table class="widefat striped"><thead><tr><th>Source</th><th>Relationship</th><th>Target</th><th>Confidence</th><th>Provider</th><th></th></tr></thead><tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><strong><?php echo esc_html($row['source_title']); ?></strong><br><code><?php echo esc_html($row['source_id']); ?></code></td>
                        <td><code><?php echo esc_html($row['type']); ?></code></td>
                        <td><strong><?php echo esc_html($row['target_title']); ?></strong><br><code><?php echo esc_html($row['target_id']); ?></code></td>
                        <td><?php echo esc_html((string)$row['confidence']); ?></td>
                        <td><?php echo esc_html($row['provider']); ?></td>
                        <td><a class="button button-small" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tng_delete_relationship&source_post_id=' . $row['source_post_id'] . '&relationship_key=' . rawurlencode($row['key'])), 'tng_delete_relationship')); ?>" onclick="return confirm('Remove this relationship?')">Remove</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody></table>
                <?php endif; ?>
            </section>
        </div>
        <style>.tng-rel-grid{display:grid;grid-template-columns:minmax(320px,420px) 1fr;gap:16px;margin-top:18px}.tng-rel-card{background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:18px}.tng-rel-card select,.tng-rel-card textarea{width:100%}@media(max-width:950px){.tng-rel-grid{grid-template-columns:1fr}}</style>
        <?php
    }

    public function add_relationship(): void {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        check_admin_referer('tng_add_relationship');
        $source_id = sanitize_text_field(wp_unslash($_POST['source_entity_id'] ?? ''));
        $target_id = sanitize_text_field(wp_unslash($_POST['target_entity_id'] ?? ''));
        $type = sanitize_key(wp_unslash($_POST['relationship_type'] ?? ''));
        $confidence = max(0, min(1, (float)($_POST['confidence'] ?? 1)));
        $evidence = sanitize_textarea_field(wp_unslash($_POST['evidence'] ?? ''));
        $source_post = $this->post_id_for_entity($source_id);
        $target_post = $this->post_id_for_entity($target_id);
        if (!$source_post || !$target_post || $source_id === $target_id || !isset(self::vocabulary()[$type])) $this->redirect('invalid');

        $relationships = get_post_meta($source_post, '_tng_entity_relationships', true);
        $relationships = is_array($relationships) ? $relationships : [];
        $key = $source_id . '|' . $type . '|' . $target_id;
        foreach ($relationships as $relationship) {
            if ($this->relationship_key((array)$relationship) === $key) $this->redirect('added');
        }
        $relationships[] = [
            'relationship_id' => 'rel_' . strtoupper(wp_generate_password(20, false, false)),
            'source_entity_id' => $source_id,
            'target_entity_id' => $target_id,
            'type' => $type,
            'confidence' => $confidence,
            'source_provider' => 'wordpress-manual',
            'evidence' => $evidence,
            'created_at' => current_time('mysql', true),
        ];
        update_post_meta($source_post, '_tng_entity_relationships', array_values($relationships));
        $this->redirect('added');
    }

    public function delete_relationship(): void {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        check_admin_referer('tng_delete_relationship');
        $post_id = absint($_GET['source_post_id'] ?? 0);
        $key = sanitize_text_field(wp_unslash($_GET['relationship_key'] ?? ''));
        if (!$post_id || get_post_type($post_id) !== self::ENTITY_TYPE) $this->redirect('invalid');
        $relationships = get_post_meta($post_id, '_tng_entity_relationships', true);
        $relationships = is_array($relationships) ? $relationships : [];
        $relationships = array_values(array_filter($relationships, fn($relationship) => $this->relationship_key((array)$relationship) !== $key));
        update_post_meta($post_id, '_tng_entity_relationships', $relationships);
        $this->redirect('deleted');
    }

    private function entities(): array {
        $posts = get_posts(['post_type' => self::ENTITY_TYPE, 'post_status' => ['publish','draft','private'], 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC']);
        $entities = [];
        foreach ($posts as $post) {
            $id = (string)get_post_meta($post->ID, '_tng_entity_id', true);
            if ($id === '') continue;
            $entities[$id] = ['post_id' => (int)$post->ID, 'id' => $id, 'title' => $post->post_title ?: $id, 'type' => (string)get_post_meta($post->ID, '_tng_entity_type', true) ?: 'other'];
        }
        return $entities;
    }

    private function entity_select(string $name, array $entities): void {
        echo '<select name="' . esc_attr($name) . '" required><option value="">Select an entity</option>';
        foreach ($entities as $entity) echo '<option value="' . esc_attr($entity['id']) . '">' . esc_html($entity['title'] . ' · ' . $entity['type']) . '</option>';
        echo '</select>';
    }

    private function relationship_rows(array $entities): array {
        $rows = [];
        foreach ($entities as $source) {
            $relationships = get_post_meta($source['post_id'], '_tng_entity_relationships', true);
            foreach (is_array($relationships) ? $relationships : [] as $relationship) {
                $relationship = (array)$relationship;
                $target_id = (string)($relationship['target_entity_id'] ?? '');
                $rows[] = ['source_post_id' => $source['post_id'], 'source_id' => $source['id'], 'source_title' => $source['title'], 'target_id' => $target_id, 'target_title' => $entities[$target_id]['title'] ?? 'Missing entity', 'type' => sanitize_key((string)($relationship['type'] ?? 'related_to')), 'confidence' => $relationship['confidence'] ?? '', 'provider' => (string)($relationship['source_provider'] ?? ''), 'key' => $this->relationship_key($relationship)];
            }
        }
        return $rows;
    }

    private function relationship_key(array $relationship): string {
        return (string)($relationship['source_entity_id'] ?? '') . '|' . sanitize_key((string)($relationship['type'] ?? 'related_to')) . '|' . (string)($relationship['target_entity_id'] ?? '');
    }

    private function post_id_for_entity(string $entity_id): int {
        $posts = get_posts(['post_type' => self::ENTITY_TYPE, 'post_status' => ['publish','draft','private'], 'posts_per_page' => 1, 'fields' => 'ids', 'meta_key' => '_tng_entity_id', 'meta_value' => $entity_id]);
        return $posts ? (int)$posts[0] : 0;
    }

    private function redirect(string $notice): void {
        wp_safe_redirect(add_query_arg(['page' => 'tng-relationships', 'tng_notice' => $notice], admin_url('admin.php')));
        exit;
    }
}
