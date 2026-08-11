<?php
namespace TNG_OS\Modules\Sources;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class Local_Discovery_Destination_Linker implements Module_Interface {
    private Container $container;
    private bool $running = false;

    public function id(): string { return 'local_discovery_destination_linker'; }

    public function register(Container $container): void {
        $this->container = $container;
        add_action('added_post_meta', [$this, 'maybe_link_from_meta'], 30, 4);
        add_action('updated_post_meta', [$this, 'maybe_link_from_meta'], 30, 4);
        add_action('admin_post_tng_local_discovery_repair_destinations', [$this, 'repair_existing']);
        $container->set('local_discovery_destination_linker', $this);
    }

    public function boot(Container $container): void {}

    public function maybe_link_from_meta($meta_id, $post_id, $meta_key, $meta_value): void {
        if ($this->running) return;
        if (!in_array((string)$meta_key, ['_tng_source_address', '_tng_food_address'], true)) return;
        $post_id = absint($post_id);
        if (!$post_id || get_post_type($post_id) !== 'st_activity') return;
        if ((string)get_post_meta($post_id, '_tng_discovery_source', true) !== 'google_maps_apify') return;
        if (absint(get_post_meta($post_id, '_tng_destination_id', true))) return;
        $this->assign_from_address($post_id, (string)$meta_value);
    }

    private function city_from_address(string $address): string {
        $parts = array_values(array_filter(array_map('trim', explode(',', html_entity_decode($address, ENT_QUOTES | ENT_HTML5, 'UTF-8')))));
        if (!$parts) return '';

        foreach ($parts as $i => $part) {
            if (preg_match('/^TN(?:\s+\d{5}(?:-\d{4})?)?$/i', $part)) {
                return $i > 0 ? sanitize_text_field($parts[$i - 1]) : '';
            }
        }

        if (count($parts) >= 2) {
            $last = strtoupper($parts[count($parts) - 1]);
            if (in_array($last, ['USA', 'US', 'UNITED STATES'], true) && count($parts) >= 3) {
                return sanitize_text_field($parts[count($parts) - 3]);
            }
        }

        return count($parts) >= 3 ? sanitize_text_field($parts[count($parts) - 3]) : '';
    }

    private function assign_from_address(int $post_id, string $address): int {
        $city = $this->city_from_address($address);
        if (!$city || !post_type_exists('tng_destination')) return 0;

        $destinations = get_posts([
            'post_type' => 'tng_destination',
            'post_status' => ['publish', 'draft', 'private'],
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
        ]);

        foreach ($destinations as $destination) {
            if (strcasecmp(trim(wp_strip_all_tags($destination->post_title)), trim($city)) !== 0) continue;
            $this->running = true;
            try {
                $relationships = $this->container->get('destination_relationships');
                if ($relationships && is_callable([$relationships, 'assign'])) {
                    $relationships->assign($post_id, (int)$destination->ID, []);
                } else {
                    update_post_meta($post_id, '_tng_destination_id', (int)$destination->ID);
                }
                update_post_meta($post_id, '_tng_discovery_detected_community', $city);
                update_post_meta($post_id, '_tng_discovery_destination_id', (int)$destination->ID);
            } finally {
                $this->running = false;
            }
            return (int)$destination->ID;
        }

        update_post_meta($post_id, '_tng_discovery_detected_community', $city);
        return 0;
    }

    public function repair_existing(): void {
        if (!current_user_can('edit_posts')) wp_die('Permission denied.');
        check_admin_referer('tng_local_discovery_repair_destinations');

        $ids = get_posts([
            'post_type' => 'st_activity',
            'post_status' => ['publish', 'draft', 'pending', 'private'],
            'posts_per_page' => 250,
            'fields' => 'ids',
            'meta_query' => [
                ['key' => '_tng_discovery_source', 'value' => 'google_maps_apify'],
            ],
        ]);

        $fixed = 0;
        foreach ($ids as $post_id) {
            if (absint(get_post_meta($post_id, '_tng_destination_id', true))) continue;
            $address = (string)get_post_meta($post_id, '_tng_source_address', true);
            if (!$address) $address = (string)get_post_meta($post_id, '_tng_food_address', true);
            if ($address && $this->assign_from_address((int)$post_id, $address)) $fixed++;
        }

        wp_safe_redirect(add_query_arg([
            'page' => 'tng-local-discovery',
            'tng_notice' => rawurlencode($fixed . ' existing Local Discovery listing' . ($fixed === 1 ? '' : 's') . ' linked to destinations.'),
        ], admin_url('admin.php')));
        exit;
    }
}
