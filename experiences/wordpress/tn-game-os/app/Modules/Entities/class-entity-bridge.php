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
