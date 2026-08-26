<?php
namespace TNG_OS\Modules\Destinations;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;
use WP_Post;

if (!defined('ABSPATH')) exit;

final class Knowledge_Graph_Inspector implements Module_Interface {
    public function id(): string { return 'knowledge_graph_inspector'; }

    public function register(Container $container): void {
        $container->set('knowledge_graph_inspector', $this);
        add_action('add_meta_boxes', [$this, 'meta_boxes'], 110);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
    }

    public function boot(Container $container): void {}

    public function meta_boxes(): void {
        foreach ($this->post_types() as $post_type) {
            add_meta_box(
                'tng_knowledge_graph_inspector',
                'Destination Intelligence',
                [$this, 'render'],
                $post_type,
                'normal',
                'high'
            );
        }
    }

    public function admin_assets(string $hook): void {
        if (!in_array($hook, ['post.php', 'post-new.php'], true)) return;
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->post_type, $this->post_types(), true)) return;
        wp_add_inline_style('wp-admin', $this->styles());
    }

    public function render(WP_Post $post): void {
        $coordinates = $this->coordinates($post->ID);
        $relationships = $this->relationships($post->ID, 60);
        $groups = [];
        foreach ($relationships as $row) {
            $type = $this->label_type((string)$row['target_type']);
            $groups[$type][] = $row;
        }

        $connected_types = count($groups);
        $closest = $relationships ? (float)$relationships[0]['distance_miles'] : null;
        $score = $this->knowledge_score($post, $coordinates, $relationships, $connected_types);

        echo '<div class="tng-kgi">';
        echo '<div class="tng-kgi-summary">';
        printf('<div><strong>%d%%</strong><span>Knowledge score</span></div>', $score);
        printf('<div><strong>%d</strong><span>Nearby relationships</span></div>', count($relationships));
        printf('<div><strong>%d</strong><span>Connected categories</span></div>', $connected_types);
        printf('<div><strong>%s</strong><span>Closest connection</span></div>', $closest !== null ? esc_html(number_format_i18n($closest, 1) . ' mi') : '—');
        echo '</div>';

        if (!$coordinates) {
            echo '<div class="notice notice-warning inline"><p><strong>Coordinates missing.</strong> Add valid latitude and longitude values, save the listing, and rebuild the Knowledge Graph.</p></div>';
        } elseif (!$relationships) {
            echo '<div class="notice notice-info inline"><p>This place has coordinates but no nearby graph connections yet. Save it again or rebuild the Knowledge Graph.</p></div>';
        }

        echo '<div class="tng-kgi-health">';
        $this->health_item('Coordinates', (bool)$coordinates, $coordinates ? number_format_i18n($coordinates[0], 5) . ', ' . number_format_i18n($coordinates[1], 5) : 'Missing');
        $this->health_item('Description', trim(wp_strip_all_tags($post->post_content)) !== '', trim(wp_strip_all_tags($post->post_content)) !== '' ? 'Available' : 'Missing');
        $this->health_item('Featured image', has_post_thumbnail($post->ID), has_post_thumbnail($post->ID) ? 'Available' : 'Missing');
        $this->health_item('Destination assignment', (bool)absint(get_post_meta($post->ID, '_tng_destination_id', true)), absint(get_post_meta($post->ID, '_tng_destination_id', true)) ? get_the_title(absint(get_post_meta($post->ID, '_tng_destination_id', true))) : 'Not assigned');
        echo '</div>';

        if ($groups) {
            echo '<h3>Automatically discovered nearby places</h3>';
            echo '<div class="tng-kgi-groups">';
            foreach ($groups as $label => $items) {
                echo '<section class="tng-kgi-group">';
                printf('<h4>%s <span>%d</span></h4>', esc_html($label), count($items));
                foreach (array_slice($items, 0, 6) as $item) {
                    $edit = get_edit_post_link((int)$item['target_id']);
                    $view = get_permalink((int)$item['target_id']);
                    echo '<article>';
                    echo '<div>';
                    printf('<strong>%s</strong>', esc_html($item['target_title'] ?: ('#' . $item['target_id'])));
                    printf('<small>%s mi away · score %s</small>', esc_html(number_format_i18n((float)$item['distance_miles'], 1)), esc_html(number_format_i18n((float)$item['score'], 0)));
                    echo '</div><div class="tng-kgi-links">';
                    if ($edit) printf('<a href="%s">Edit</a>', esc_url($edit));
                    if ($view) printf('<a href="%s" target="_blank" rel="noopener">View</a>', esc_url($view));
                    echo '</div></article>';
                }
                if (count($items) > 6) printf('<p class="description">%d more connections are stored in the graph.</p>', count($items) - 6);
                echo '</section>';
            }
            echo '</div>';
        }

        echo '<p class="tng-kgi-footer">Relationships are calculated automatically from coordinates, destination assignments, content type, and distance. Edit coordinates or destination assignment, then save this listing to refresh it.</p>';
        echo '</div>';
    }

    private function relationships(int $post_id, int $limit = 60): array {
        global $wpdb;
        $table = $wpdb->prefix . 'tng_knowledge_graph';
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists !== $table) return [];
        return $wpdb->get_results($wpdb->prepare(
            "SELECT g.*, p.post_title target_title, p.post_status target_status
             FROM {$table} g
             LEFT JOIN {$wpdb->posts} p ON p.ID = g.target_id
             WHERE g.source_id = %d AND p.post_status = 'publish'
             ORDER BY g.score DESC, g.distance_miles ASC
             LIMIT %d",
            $post_id,
            $limit
        ), ARRAY_A) ?: [];
    }

    private function knowledge_score(WP_Post $post, ?array $coordinates, array $relationships, int $connected_types): int {
        $score = 0;
        if ($coordinates) $score += 30;
        if (trim(wp_strip_all_tags($post->post_content)) !== '') $score += 15;
        if (has_post_thumbnail($post->ID)) $score += 15;
        if (absint(get_post_meta($post->ID, '_tng_destination_id', true))) $score += 10;
        if (count($relationships) >= 1) $score += 10;
        if (count($relationships) >= 5) $score += 10;
        if ($connected_types >= 2) $score += 5;
        if ($connected_types >= 4) $score += 5;
        return min(100, $score);
    }

    private function health_item(string $label, bool $healthy, string $detail): void {
        printf(
            '<div class="tng-kgi-health-item %s"><span class="dashicons %s"></span><div><strong>%s</strong><small>%s</small></div></div>',
            $healthy ? 'is-good' : 'is-missing',
            $healthy ? 'dashicons-yes-alt' : 'dashicons-warning',
            esc_html($label),
            esc_html($detail)
        );
    }

    private function coordinates(int $post_id): ?array {
        foreach ([
            ['_tng_destination_lat','_tng_destination_lng'],
            ['map_lat','map_lng'],
            ['lat','lng'],
            ['latitude','longitude'],
            ['_lat','_lng'],
            ['st_latitude','st_longitude'],
        ] as [$lat_key, $lng_key]) {
            $lat = get_post_meta($post_id, $lat_key, true);
            $lng = get_post_meta($post_id, $lng_key, true);
            if (!is_numeric($lat) || !is_numeric($lng)) continue;
            $lat = (float)$lat; $lng = (float)$lng;
            if ($lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180 && ($lat != 0.0 || $lng != 0.0)) return [$lat, $lng];
        }
        $location = absint(get_post_meta($post_id, 'location_id', true));
        return ($location && $location !== $post_id) ? $this->coordinates($location) : null;
    }

    private function label_type(string $type): string {
        $labels = [
            'activity' => 'Activities & Trails',
            'lodging' => 'Lodging',
            'tour' => 'Tours',
            'rental' => 'Rentals',
            'sight' => 'Top Sights',
            'destination' => 'Destinations',
        ];
        return $labels[$type] ?? ucwords(str_replace(['-', '_'], ' ', $type));
    }

    private function post_types(): array {
        return array_values(array_filter(['tng_destination','st_activity','st_hotel','st_tours','st_rental','top_sight'], 'post_type_exists'));
    }

    private function styles(): string {
        return '.tng-kgi-summary{display:grid;grid-template-columns:repeat(4,minmax(130px,1fr));gap:12px;margin:4px 0 18px}.tng-kgi-summary>div{padding:16px;border:1px solid #ddd8ea;border-radius:14px;background:#faf8ff}.tng-kgi-summary strong{display:block;font-size:25px;color:#6538b5}.tng-kgi-summary span{font-size:12px;color:#667085}.tng-kgi-health{display:grid;grid-template-columns:repeat(4,minmax(140px,1fr));gap:10px;margin:18px 0}.tng-kgi-health-item{display:flex;align-items:center;gap:9px;padding:12px;border:1px solid #e1e3e8;border-radius:12px}.tng-kgi-health-item .dashicons{font-size:22px;width:22px;height:22px}.tng-kgi-health-item.is-good .dashicons{color:#12a866}.tng-kgi-health-item.is-missing .dashicons{color:#d97706}.tng-kgi-health-item strong,.tng-kgi-health-item small{display:block}.tng-kgi-health-item small{color:#667085;margin-top:2px}.tng-kgi-groups{display:grid;grid-template-columns:repeat(2,minmax(260px,1fr));gap:14px}.tng-kgi-group{border:1px solid #ddd8ea;border-radius:14px;padding:14px;background:#fff}.tng-kgi-group h4{margin:0 0 8px;font-size:15px}.tng-kgi-group h4 span{float:right;background:#eee8ff;color:#6538b5;border-radius:99px;padding:2px 8px;font-size:11px}.tng-kgi-group article{display:flex;justify-content:space-between;gap:10px;padding:9px 0;border-top:1px solid #edf0f4}.tng-kgi-group article:first-of-type{border-top:0}.tng-kgi-group strong,.tng-kgi-group small{display:block}.tng-kgi-group small{color:#667085;margin-top:3px}.tng-kgi-links{display:flex;gap:7px;white-space:nowrap}.tng-kgi-footer{margin-top:16px;color:#667085}@media(max-width:900px){.tng-kgi-summary,.tng-kgi-health{grid-template-columns:repeat(2,1fr)}.tng-kgi-groups{grid-template-columns:1fr}}';
    }
}
