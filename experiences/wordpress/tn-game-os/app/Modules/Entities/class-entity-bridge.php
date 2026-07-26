<?php

namespace TNG_OS\Modules\Entities;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class Entity_Bridge implements Module_Interface {
    private const ENTITY_TYPE = 'tng_entity';
    private const MAX_SNAPSHOTS = 50;
    private Container $container;

    public function id(): string { return 'entity_bridge'; }

    public function register(Container $container): void {
        $this->container = $container;
        $container->set('entity_bridge', $this);

        add_action('init', [$this, 'register_type'], 8);
        add_action('admin_menu', [$this, 'register_admin_page'], 29);
        add_action('added_post_meta', [$this, 'capture_activity_link'], 10, 4);
        add_action('updated_post_meta', [$this, 'capture_activity_link'], 10, 4);
    }

    public function boot(Container $container): void {}

    public function register_type(): void {
        register_post_type(self::ENTITY_TYPE, [
            'labels' => ['name' => 'Platform Entities', 'singular_name' => 'Platform Entity'],
            'public' => false,
            'show_ui' => false,
            'show_in_rest' => true,
            'supports' => ['title', 'custom-fields'],
        ]);
    }

    public function register_admin_page(): void {
        add_submenu_page(
            'tn-game-os',
            'Entity Explorer',
            'Entity Explorer',
            'manage_options',
            'tng-entity-explorer',
            [$this, 'render_entity_explorer']
        );
    }

    public function render_entity_explorer(): void {
        if (!current_user_can('manage_options')) return;

        $entity_post_id = isset($_GET['entity_post']) ? absint($_GET['entity_post']) : 0;
        $type = isset($_GET['entity_type']) ? sanitize_key(wp_unslash($_GET['entity_type'])) : '';
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';

        $args = [
            'post_type' => self::ENTITY_TYPE,
            'post_status' => ['publish', 'draft', 'private'],
            'posts_per_page' => 100,
            'orderby' => 'modified',
            'order' => 'DESC',
        ];
        if ($search !== '') $args['s'] = $search;
        if ($type !== '') {
            $args['meta_key'] = '_tng_entity_type';
            $args['meta_value'] = $type;
        }
        $entities = get_posts($args);
        if (!$entity_post_id && $entities) $entity_post_id = (int)$entities[0]->ID;

        $selected = $entity_post_id ? get_post($entity_post_id) : null;
        if ($selected && $selected->post_type !== self::ENTITY_TYPE) $selected = null;

        $counts = $this->entity_counts();
        ?>
        <div class="wrap tng-entity-explorer">
            <style>
                .tng-entity-explorer{max-width:1500px}.tng-entity-hero{background:#162747;color:#fff;border-radius:16px;padding:24px 28px;margin:18px 0}.tng-entity-hero h1{color:#fff;margin:0 0 6px}.tng-entity-stats{display:grid;grid-template-columns:repeat(4,minmax(140px,1fr));gap:12px;margin:16px 0}.tng-entity-stat,.tng-entity-card{background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:16px}.tng-entity-stat strong{display:block;font-size:26px}.tng-entity-layout{display:grid;grid-template-columns:minmax(320px,38%) 1fr;gap:16px}.tng-entity-list{max-height:720px;overflow:auto}.tng-entity-row{display:block;text-decoration:none;color:#1d2327;border:1px solid #e4e6eb;border-radius:10px;padding:12px;margin-bottom:9px}.tng-entity-row.is-active{border-color:#3858e9;background:#f3f6ff}.tng-entity-row small{display:block;color:#646970;margin-top:4px}.tng-entity-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.tng-entity-code{background:#f6f7f7;border-radius:8px;padding:12px;white-space:pre-wrap;overflow:auto;max-height:360px}.tng-entity-badge{display:inline-block;background:#eef2ff;color:#273b8c;border-radius:999px;padding:4px 9px;font-weight:600;margin-right:6px}.tng-entity-table{width:100%;border-collapse:collapse}.tng-entity-table th,.tng-entity-table td{text-align:left;border-bottom:1px solid #e4e6eb;padding:9px 6px;vertical-align:top}@media(max-width:1000px){.tng-entity-layout,.tng-entity-grid{grid-template-columns:1fr}.tng-entity-stats{grid-template-columns:repeat(2,1fr)}}
            </style>
            <div class="tng-entity-hero">
                <p style="text-transform:uppercase;letter-spacing:.12em;margin:0 0 8px;color:#f6bd3b;font-weight:700">TN Platform · Entity Engine</p>
                <h1>Entity Explorer</h1>
                <p>Inspect canonical identity, provenance, relationships, version history, and Traveler links.</p>
            </div>

            <div class="tng-entity-stats">
                <div class="tng-entity-stat"><strong><?php echo esc_html((string)$counts['total']); ?></strong><span>Total entities</span></div>
                <div class="tng-entity-stat"><strong><?php echo esc_html((string)$counts['event']); ?></strong><span>Events</span></div>
                <div class="tng-entity-stat"><strong><?php echo esc_html((string)$counts['venue']); ?></strong><span>Venues</span></div>
                <div class="tng-entity-stat"><strong><?php echo esc_html((string)$counts['snapshots']); ?></strong><span>Snapshots</span></div>
            </div>

            <form method="get" style="display:flex;gap:8px;margin:14px 0;align-items:center">
                <input type="hidden" name="page" value="tng-entity-explorer">
                <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Search entity titles" style="min-width:300px">
                <select name="entity_type">
                    <option value="">All types</option>
                    <option value="event" <?php selected($type, 'event'); ?>>Events</option>
                    <option value="venue" <?php selected($type, 'venue'); ?>>Venues</option>
                    <option value="place" <?php selected($type, 'place'); ?>>Places</option>
                </select>
                <button class="button button-primary">Filter</button>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=tng-entity-explorer')); ?>">Reset</a>
            </form>

            <div class="tng-entity-layout">
                <div class="tng-entity-card tng-entity-list">
                    <h2>Canonical entities</h2>
                    <?php if (!$entities): ?><p>No canonical entities have been created yet.</p><?php endif; ?>
                    <?php foreach ($entities as $entity):
                        $id = (string)get_post_meta($entity->ID, '_tng_entity_id', true);
                        $entity_type = (string)get_post_meta($entity->ID, '_tng_entity_type', true);
                        $version = absint(get_post_meta($entity->ID, '_tng_entity_version', true));
                        $url = add_query_arg(['page' => 'tng-entity-explorer', 'entity_post' => $entity->ID, 'entity_type' => $type, 's' => $search], admin_url('admin.php'));
                        ?>
                        <a class="tng-entity-row <?php echo $entity_post_id === (int)$entity->ID ? 'is-active' : ''; ?>" href="<?php echo esc_url($url); ?>">
                            <strong><?php echo esc_html($entity->post_title); ?></strong>
                            <small><?php echo esc_html($entity_type ?: 'unknown'); ?> · v<?php echo esc_html((string)$version); ?></small>
                            <small><?php echo esc_html($id); ?></small>
                        </a>
                    <?php endforeach; ?>
                </div>

                <div>
                    <?php if ($selected): $this->render_entity_detail((int)$selected->ID, $selected->post_title); else: ?>
                        <div class="tng-entity-card"><h2>Select an entity</h2><p>Choose an entity to inspect its canonical record.</p></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    private function render_entity_detail(int $post_id, string $title): void {
        $entity_id = (string)get_post_meta($post_id, '_tng_entity_id', true);
        $type = (string)get_post_meta($post_id, '_tng_entity_type', true);
        $version = absint(get_post_meta($post_id, '_tng_entity_version', true));
        $lifecycle = (string)get_post_meta($post_id, '_tng_entity_lifecycle', true);
        $source_key = (string)get_post_meta($post_id, '_tng_entity_source_key', true);
        $updated = (string)get_post_meta($post_id, '_tng_entity_updated_at', true);
        $payload = get_post_meta($post_id, '_tng_entity_payload', true);
        $relationships = get_post_meta($post_id, '_tng_entity_relationships', true);
        $snapshots = get_post_meta($post_id, '_tng_entity_snapshots', true);
        $payload = is_array($payload) ? $payload : [];
        $relationships = is_array($relationships) ? $relationships : [];
        $snapshots = is_array($snapshots) ? array_reverse($snapshots) : [];
        $activity_id = absint($payload['traveler_activity_id'] ?? 0);
        $queue_id = absint($payload['queue_id'] ?? 0);
        ?>
        <div class="tng-entity-card">
            <h2 style="margin-top:0"><?php echo esc_html($title); ?></h2>
            <p><span class="tng-entity-badge"><?php echo esc_html($type ?: 'unknown'); ?></span><span class="tng-entity-badge"><?php echo esc_html($lifecycle ?: 'unknown'); ?></span><span class="tng-entity-badge">Version <?php echo esc_html((string)$version); ?></span></p>
            <table class="tng-entity-table">
                <tr><th>Canonical ID</th><td><code><?php echo esc_html($entity_id); ?></code></td></tr>
                <tr><th>Source key</th><td><code><?php echo esc_html($source_key); ?></code></td></tr>
                <tr><th>Updated</th><td><?php echo esc_html($updated ?: 'Not recorded'); ?></td></tr>
                <tr><th>Traveler activity</th><td><?php echo $activity_id ? '<a href="' . esc_url(get_edit_post_link($activity_id)) . '">Edit Activity #' . esc_html((string)$activity_id) . '</a>' : 'Not linked'; ?></td></tr>
                <tr><th>Review item</th><td><?php echo $queue_id ? '<a href="' . esc_url(get_edit_post_link($queue_id)) . '">Open Queue Item #' . esc_html((string)$queue_id) . '</a>' : 'Not linked'; ?></td></tr>
            </table>
        </div>

        <div class="tng-entity-grid" style="margin-top:12px">
            <div class="tng-entity-card">
                <h2>Relationships</h2>
                <?php if (!$relationships): ?><p>No relationships recorded.</p><?php endif; ?>
                <?php foreach ($relationships as $relationship): ?>
                    <p><strong><?php echo esc_html((string)($relationship['type'] ?? 'unknown')); ?></strong><br><code><?php echo esc_html((string)($relationship['target_entity_id'] ?? '')); ?></code><br><small>Confidence <?php echo esc_html((string)($relationship['confidence'] ?? '')); ?> · <?php echo esc_html((string)($relationship['source_provider'] ?? '')); ?></small></p>
                <?php endforeach; ?>
            </div>
            <div class="tng-entity-card">
                <h2>Payload</h2>
                <pre class="tng-entity-code"><?php echo esc_html((string)wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre>
            </div>
        </div>

        <div class="tng-entity-card" style="margin-top:12px">
            <h2>Version history</h2>
            <?php if (!$snapshots): ?><p>No snapshots recorded.</p><?php endif; ?>
            <?php foreach ($snapshots as $snapshot): ?>
                <details style="border-top:1px solid #e4e6eb;padding:10px 0">
                    <summary><strong>Version <?php echo esc_html((string)($snapshot['version'] ?? '')); ?></strong> · <?php echo esc_html((string)($snapshot['recorded_at'] ?? '')); ?></summary>
                    <pre class="tng-entity-code"><?php echo esc_html((string)wp_json_encode($snapshot['payload'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre>
                </details>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /** @return array{total:int,event:int,venue:int,snapshots:int} */
    private function entity_counts(): array {
        $entities = get_posts([
            'post_type' => self::ENTITY_TYPE,
            'post_status' => ['publish', 'draft', 'private'],
            'posts_per_page' => -1,
            'fields' => 'ids',
        ]);
        $counts = ['total' => count($entities), 'event' => 0, 'venue' => 0, 'snapshots' => 0];
        foreach ($entities as $id) {
            $type = (string)get_post_meta($id, '_tng_entity_type', true);
            if (isset($counts[$type])) $counts[$type]++;
            $snapshots = get_post_meta($id, '_tng_entity_snapshots', true);
            if (is_array($snapshots)) $counts['snapshots'] += count($snapshots);
        }
        return $counts;
    }

    public function capture_activity_link(int $meta_id, int $object_id, string $meta_key, $meta_value): void {
        if ($meta_key !== '_tng_ci_activity_id') return;
        $activity_id = absint($meta_value);
        if (!$activity_id || get_post_type($object_id) !== 'tng_concert_import') return;
        $this->upsert_concert($object_id, $activity_id);
    }

    /** @return array{entity_id:string,version:int,changed:bool,venue_entity_id:string} */
    public function upsert_concert(int $queue_id, int $activity_id): array {
        $event = get_post_meta($queue_id, '_tng_ci_event_data', true);
        if (!is_array($event) || empty($event['title'])) {
            return ['entity_id' => '', 'version' => 0, 'changed' => false, 'venue_entity_id' => ''];
        }

        $provider = sanitize_key((string)($event['provider'] ?? 'tixr')) ?: 'tixr';
        $external_id = sanitize_text_field((string)($event['external_id'] ?? ''));
        $external_key = (string)get_post_meta($queue_id, '_tng_ci_external_key', true);
        if ($external_key === '') $external_key = $provider . ':' . ($external_id ?: md5((string)($event['url'] ?? $queue_id)));

        $venue_entity_id = $this->upsert_venue($event, $queue_id);
        $entity_post_id = $this->find_by_source_key($external_key);
        $is_new = !$entity_post_id;

        if ($is_new) {
            $entity_post_id = wp_insert_post([
                'post_type' => self::ENTITY_TYPE,
                'post_status' => 'publish',
                'post_title' => sanitize_text_field((string)$event['title']),
            ]);
            if (!$entity_post_id || is_wp_error($entity_post_id)) {
                return ['entity_id' => '', 'version' => 0, 'changed' => false, 'venue_entity_id' => $venue_entity_id];
            }
            update_post_meta($entity_post_id, '_tng_entity_id', $this->generate_id('ent_'));
            update_post_meta($entity_post_id, '_tng_entity_type', 'event');
            update_post_meta($entity_post_id, '_tng_entity_source_key', $external_key);
            update_post_meta($entity_post_id, '_tng_entity_version', 0);
            update_post_meta($entity_post_id, '_tng_entity_snapshots', []);
        }

        $entity_id = (string)get_post_meta($entity_post_id, '_tng_entity_id', true);
        $payload = $this->event_payload($event, $queue_id, $activity_id, $venue_entity_id, $provider, $external_id, $external_key);
        $checksum = hash('sha256', wp_json_encode($payload));
        $old_checksum = (string)get_post_meta($entity_post_id, '_tng_entity_checksum', true);
        $changed = $is_new || !hash_equals($old_checksum, $checksum);
        $version = max(0, absint(get_post_meta($entity_post_id, '_tng_entity_version', true)));

        if ($changed) {
            $version++;
            wp_update_post(['ID' => $entity_post_id, 'post_title' => sanitize_text_field((string)$event['title'])]);
            update_post_meta($entity_post_id, '_tng_entity_version', $version);
            update_post_meta($entity_post_id, '_tng_entity_lifecycle', 'published');
            update_post_meta($entity_post_id, '_tng_entity_payload', $payload);
            update_post_meta($entity_post_id, '_tng_entity_checksum', $checksum);
            update_post_meta($entity_post_id, '_tng_entity_updated_at', current_time('mysql', true));
            update_post_meta($entity_post_id, '_tng_entity_relationships', $this->relationships($entity_id, $venue_entity_id, $provider));
            $this->append_snapshot($entity_post_id, $entity_id, $version, $payload, $checksum);
        }

        update_post_meta($queue_id, '_tng_entity_id', $entity_id);
        update_post_meta($queue_id, '_tng_entity_version', $version);
        update_post_meta($activity_id, '_tng_entity_id', $entity_id);
        update_post_meta($activity_id, '_tng_entity_version', $version);
        update_post_meta($activity_id, '_tng_entity_source_key', $external_key);

        return compact('entity_id', 'version', 'changed', 'venue_entity_id');
    }

    private function upsert_venue(array $event, int $queue_id): string {
        $venue_post_id = absint($event['venue_id'] ?? get_post_meta($queue_id, '_tng_ci_venue_id', true));
        $venue_name = $venue_post_id ? get_the_title($venue_post_id) : sanitize_text_field((string)($event['venue'] ?? ''));
        if ($venue_name === '') return '';

        $source_key = $venue_post_id ? 'wordpress-venue:' . $venue_post_id : 'venue-name:' . sanitize_title($venue_name);
        $entity_post_id = $this->find_by_source_key($source_key);
        if (!$entity_post_id) {
            $entity_post_id = wp_insert_post([
                'post_type' => self::ENTITY_TYPE,
                'post_status' => 'publish',
                'post_title' => $venue_name,
            ]);
            if (!$entity_post_id || is_wp_error($entity_post_id)) return '';
            update_post_meta($entity_post_id, '_tng_entity_id', $this->generate_id('ent_'));
            update_post_meta($entity_post_id, '_tng_entity_type', 'venue');
            update_post_meta($entity_post_id, '_tng_entity_source_key', $source_key);
            update_post_meta($entity_post_id, '_tng_entity_version', 1);
            update_post_meta($entity_post_id, '_tng_entity_lifecycle', 'canonical');
            update_post_meta($entity_post_id, '_tng_entity_payload', [
                'name' => $venue_name,
                'wordpress_venue_id' => $venue_post_id,
                'address' => sanitize_text_field((string)($event['address'] ?? '')),
            ]);
        }

        $entity_id = (string)get_post_meta($entity_post_id, '_tng_entity_id', true);
        if ($venue_post_id) update_post_meta($venue_post_id, '_tng_entity_id', $entity_id);
        return $entity_id;
    }

    private function find_by_source_key(string $source_key): int {
        $ids = get_posts([
            'post_type' => self::ENTITY_TYPE,
            'post_status' => ['publish', 'draft', 'private'],
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_key' => '_tng_entity_source_key',
            'meta_value' => $source_key,
        ]);
        return $ids ? (int)$ids[0] : 0;
    }

    private function event_payload(array $event, int $queue_id, int $activity_id, string $venue_entity_id, string $provider, string $external_id, string $external_key): array {
        return [
            'type' => 'event',
            'title' => sanitize_text_field((string)$event['title']),
            'description' => wp_kses_post((string)($event['description'] ?? '')),
            'start' => sanitize_text_field((string)($event['start'] ?? '')),
            'end' => sanitize_text_field((string)($event['end'] ?? '')),
            'doors' => sanitize_text_field((string)($event['doors'] ?? '')),
            'status' => sanitize_key((string)($event['status'] ?? 'scheduled')),
            'price' => sanitize_text_field((string)($event['price'] ?? '')),
            'age' => sanitize_text_field((string)($event['age'] ?? '')),
            'image' => esc_url_raw((string)($event['image'] ?? '')),
            'ticket_url' => esc_url_raw((string)($event['url'] ?? '')),
            'provider' => $provider,
            'external_id' => $external_id,
            'external_key' => $external_key,
            'queue_id' => $queue_id,
            'traveler_activity_id' => $activity_id,
            'venue_entity_id' => $venue_entity_id,
        ];
    }

    private function relationships(string $event_entity_id, string $venue_entity_id, string $provider): array {
        if ($event_entity_id === '' || $venue_entity_id === '') return [];
        return [[
            'id' => $this->stable_relationship_id($event_entity_id, 'held_at', $venue_entity_id),
            'type' => 'held_at',
            'source_entity_id' => $event_entity_id,
            'target_entity_id' => $venue_entity_id,
            'confidence' => 1.0,
            'source_provider' => $provider,
            'created_at' => current_time('mysql', true),
        ]];
    }

    private function append_snapshot(int $post_id, string $entity_id, int $version, array $payload, string $checksum): void {
        $snapshots = get_post_meta($post_id, '_tng_entity_snapshots', true);
        $snapshots = is_array($snapshots) ? $snapshots : [];
        $snapshots[] = [
            'entity_id' => $entity_id,
            'version' => $version,
            'payload' => $payload,
            'checksum' => $checksum,
            'recorded_at' => current_time('mysql', true),
        ];
        if (count($snapshots) > self::MAX_SNAPSHOTS) $snapshots = array_slice($snapshots, -self::MAX_SNAPSHOTS);
        update_post_meta($post_id, '_tng_entity_snapshots', $snapshots);
    }

    private function stable_relationship_id(string $source, string $type, string $target): string {
        return 'rel_' . strtoupper(substr(hash('sha256', $source . '|' . $type . '|' . $target), 0, 26));
    }

    private function generate_id(string $prefix): string {
        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        $bytes = random_bytes(16);
        $bits = '';
        foreach (str_split($bytes) as $byte) $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        $bits = str_pad($bits, 130, '0', STR_PAD_LEFT);
        $value = '';
        foreach (str_split($bits, 5) as $chunk) $value .= $alphabet[bindec($chunk)];
        return $prefix . substr($value, 0, 26);
    }
}
