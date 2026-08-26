<?php
namespace TNG_OS\Modules\Destinations;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;
use WP_Post;

if (!defined('ABSPATH')) exit;

final class Destination_Relationships implements Module_Interface {
    private Container $container;

    public function id(): string { return 'destination_relationships'; }

    public function register(Container $container): void {
        $this->container = $container;
        $container->set('destination_relationships', $this);

        add_action('add_meta_boxes', [$this, 'replace_relationship_boxes'], 99);
        add_action('save_post', [$this, 'save_listing_relationships'], 99, 2);
        add_action('save_post_tng_destination', [$this, 'save_destination_hierarchy'], 99, 2);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
    }

    public function boot(Container $container): void {}

    public function admin_assets(): void {
        $screen = get_current_screen();
        if (!$screen) return;
        if ($screen->post_type === 'tng_destination' || in_array($screen->post_type, $this->supported_post_types(), true)) {
            wp_enqueue_style('tng-destination-relationships-admin', TNG_OS_URL . 'assets/admin/destination-relationships.css', [], TNG_OS_VERSION);
        }
    }

    public function replace_relationship_boxes(): void {
        foreach ($this->supported_post_types() as $post_type) {
            remove_meta_box('tng_destination_relationship', $post_type, 'side');
            add_meta_box(
                'tng_destination_relationships',
                'TN Game Destinations',
                [$this, 'relationship_box'],
                $post_type,
                'side',
                'high'
            );
        }

        remove_meta_box('tng_destination_details', 'tng_destination', 'normal');
        add_meta_box(
            'tng_destination_details',
            'TN Game Destination Details',
            [$this, 'destination_box'],
            'tng_destination',
            'normal',
            'high'
        );
    }

    private function supported_post_types(): array {
        $module = $this->container->get('destinations');
        $types = $module && is_callable([$module, 'supported_post_types'])
            ? $module->supported_post_types()
            : ['st_activity','st_hotel','st_tours','st_rental','st_cars','top_sight','post','page'];

        return array_values(array_filter(
            array_unique($types),
            static fn(string $type): bool => $type !== 'tng_destination' && post_type_exists($type)
        ));
    }

    public function relationship_box(WP_Post $post): void {
        wp_nonce_field('tng_destination_relationships_save', 'tng_destination_relationships_nonce');

        $primary = absint(get_post_meta($post->ID, '_tng_destination_id', true));
        $related = get_post_meta($post->ID, '_tng_related_destination_ids', true);
        $related = is_array($related) ? array_values(array_filter(array_map('absint', $related))) : [];

        $destinations = get_posts([
            'post_type' => 'tng_destination',
            'post_status' => ['publish','draft','private'],
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
        ]);

        echo '<div class="tng-destination-rel-box">';
        echo '<label class="tng-rel-label">Primary destination</label>';
        echo '<select class="widefat" name="_tng_destination_id"><option value="0">— Not assigned —</option>';
        foreach ($destinations as $destination) {
            printf(
                '<option value="%d" %s>%s</option>',
                (int)$destination->ID,
                selected($primary, $destination->ID, false),
                esc_html($destination->post_title)
            );
        }
        echo '</select>';
        echo '<p class="description">The main destination displayed for this listing.</p>';

        echo '<div class="tng-rel-related">';
        echo '<label class="tng-rel-label">Related destinations</label>';
        echo '<div class="tng-rel-checklist">';
        foreach ($destinations as $destination) {
            printf(
                '<label><input type="checkbox" name="_tng_related_destination_ids[]" value="%d" %s> <span>%s</span></label>',
                (int)$destination->ID,
                checked(in_array((int)$destination->ID, $related, true), true, false),
                esc_html($destination->post_title)
            );
        }
        echo '</div>';
        echo '<p class="description">The listing also appears in these destination pages and search results.</p>';
        echo '</div>';

        $effective = $this->effective_destination_ids($post->ID);
        if ($effective) {
            echo '<div class="tng-rel-effective"><strong>Effective destinations</strong><div>';
            foreach ($effective as $id) {
                printf('<span>%s</span>', esc_html(get_the_title($id)));
            }
            echo '</div></div>';
        }
        echo '</div>';
    }

    public function destination_box(WP_Post $post): void {
        wp_nonce_field('tng_destination_relationships_destination_save', 'tng_destination_relationships_destination_nonce');

        $parent = absint(get_post_meta($post->ID, '_tng_destination_parent_id', true));
        $type = (string)get_post_meta($post->ID, '_tng_destination_kind', true);
        $fields = [
            '_tng_destination_lat' => ['Latitude', 'text'],
            '_tng_destination_lng' => ['Longitude', 'text'],
            '_tng_destination_radius' => ['Near Me radius (miles)', 'number'],
            '_tng_destination_weather_location' => ['Weather location', 'text'],
            '_tng_destination_season' => ['Best season', 'text'],
            '_tng_destination_crowd_baseline' => ['Typical crowd level (1–5)', 'number'],
            '_tng_destination_tagline' => ['Short tagline', 'text'],
        ];

        $destinations = get_posts([
            'post_type'=>'tng_destination',
            'post_status'=>['publish','draft','private'],
            'posts_per_page'=>-1,
            'post__not_in'=>[$post->ID],
            'orderby'=>'title',
            'order'=>'ASC',
        ]);

        echo '<div class="tng-destination-admin-grid tng-destination-hierarchy-grid">';
        echo '<p><label><strong>Destination type</strong><select class="widefat" name="_tng_destination_kind">';
        foreach ([
            ''=>'— Select type —',
            'region'=>'Region',
            'city'=>'City or town',
            'venue'=>'Venue',
            'park'=>'State or national park',
            'trail-system'=>'Trail system',
            'district'=>'District or neighborhood',
            'resort'=>'Resort',
            'lake'=>'Lake or river area',
            'attraction'=>'Major attraction',
        ] as $value=>$label) {
            printf('<option value="%s" %s>%s</option>', esc_attr($value), selected($type,$value,false), esc_html($label));
        }
        echo '</select></label></p>';

        echo '<p><label><strong>Parent destination</strong><select class="widefat" name="_tng_destination_parent_id"><option value="0">— No parent —</option>';
        foreach ($destinations as $destination) {
            printf(
                '<option value="%d" %s>%s</option>',
                (int)$destination->ID,
                selected($parent,$destination->ID,false),
                esc_html($destination->post_title)
            );
        }
        echo '</select></label><span class="description">Example: The Caverns → Pelham → South Cumberland.</span></p>';

        foreach ($fields as $key => [$label, $input_type]) {
            printf(
                '<p><label><strong>%s</strong><input class="widefat" type="%s" name="%s" value="%s"></label></p>',
                esc_html($label),
                esc_attr($input_type),
                esc_attr($key),
                esc_attr((string)get_post_meta($post->ID, $key, true))
            );
        }
        echo '</div>';
        echo '<p>Listings may use this destination as primary or related. Parent destinations are inherited automatically for discovery, counts, and trip recommendations.</p>';
    }

    public function save_listing_relationships(int $post_id, WP_Post $post): void {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;
        if (!in_array($post->post_type, $this->supported_post_types(), true)) return;
        if (!isset($_POST['tng_destination_relationships_nonce'])) return;
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['tng_destination_relationships_nonce'])), 'tng_destination_relationships_save')) return;
        if (!current_user_can('edit_post', $post_id)) return;

        $primary = isset($_POST['_tng_destination_id']) ? absint($_POST['_tng_destination_id']) : 0;
        $related = isset($_POST['_tng_related_destination_ids'])
            ? array_values(array_unique(array_filter(array_map('absint', (array)wp_unslash($_POST['_tng_related_destination_ids'])))))
            : [];

        $related = array_values(array_diff($related, [$primary]));
        update_post_meta($post_id, '_tng_destination_id', $primary);
        update_post_meta($post_id, '_tng_related_destination_ids', $related);

        $all_ids = array_values(array_unique(array_merge($primary ? [$primary] : [], $related)));
        $all_ids = $this->expand_with_parents($all_ids);
        update_post_meta($post_id, '_tng_effective_destination_ids', $all_ids);

        $term_ids = [];
        foreach ($all_ids as $destination_id) {
            $term_id = $this->ensure_term($destination_id);
            if ($term_id) $term_ids[] = $term_id;
        }
        wp_set_object_terms($post_id, array_values(array_unique($term_ids)), 'tng_destination_ref', false);
    }

    public function save_destination_hierarchy(int $post_id, WP_Post $post): void {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;
        if (!isset($_POST['tng_destination_relationships_destination_nonce'])) return;
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['tng_destination_relationships_destination_nonce'])), 'tng_destination_relationships_destination_save')) return;
        if (!current_user_can('edit_post', $post_id)) return;

        $parent = isset($_POST['_tng_destination_parent_id']) ? absint($_POST['_tng_destination_parent_id']) : 0;
        if ($parent === $post_id || in_array($post_id, $this->ancestor_ids($parent), true)) $parent = 0;

        update_post_meta($post_id, '_tng_destination_parent_id', $parent);
        update_post_meta($post_id, '_tng_destination_kind', isset($_POST['_tng_destination_kind']) ? sanitize_key($_POST['_tng_destination_kind']) : '');

        foreach (['_tng_destination_lat','_tng_destination_lng','_tng_destination_radius','_tng_destination_weather_location','_tng_destination_season','_tng_destination_crowd_baseline','_tng_destination_tagline'] as $key) {
            if (isset($_POST[$key])) update_post_meta($post_id, $key, sanitize_text_field(wp_unslash($_POST[$key])));
        }

        $this->ensure_term($post_id);
        $this->resync_linked_listings($post_id);
    }

    public function effective_destination_ids(int $post_id): array {
        $saved = get_post_meta($post_id, '_tng_effective_destination_ids', true);
        if (is_array($saved) && $saved) return array_values(array_unique(array_map('absint', $saved)));

        $primary = absint(get_post_meta($post_id, '_tng_destination_id', true));
        $related = get_post_meta($post_id, '_tng_related_destination_ids', true);
        $related = is_array($related) ? array_map('absint', $related) : [];
        return $this->expand_with_parents(array_values(array_filter(array_merge($primary ? [$primary] : [], $related))));
    }

    public function expand_with_parents(array $destination_ids): array {
        $expanded = [];
        foreach ($destination_ids as $destination_id) {
            $destination_id = absint($destination_id);
            if (!$destination_id) continue;
            $expanded[] = $destination_id;
            $expanded = array_merge($expanded, $this->ancestor_ids($destination_id));
        }
        return array_values(array_unique(array_filter(array_map('absint', $expanded))));
    }

    public function ancestor_ids(int $destination_id): array {
        $ancestors = [];
        $seen = [];
        $current = $destination_id;

        for ($i=0; $i<20 && $current; $i++) {
            if (isset($seen[$current])) break;
            $seen[$current] = true;
            $parent = absint(get_post_meta($current, '_tng_destination_parent_id', true));
            if (!$parent) break;
            $ancestors[] = $parent;
            $current = $parent;
        }
        return $ancestors;
    }

    public function destination_term_ids(array $destination_ids): array {
        $ids = [];
        foreach ($this->expand_with_parents($destination_ids) as $destination_id) {
            $term_id = $this->ensure_term($destination_id);
            if ($term_id) $ids[] = $term_id;
        }
        return array_values(array_unique($ids));
    }

    public function assign(int $post_id, int $primary, array $related=[]): void {
        $primary = absint($primary);
        $related = array_values(array_unique(array_diff(array_filter(array_map('absint', $related)), [$primary])));

        update_post_meta($post_id, '_tng_destination_id', $primary);
        update_post_meta($post_id, '_tng_related_destination_ids', $related);

        $all = $this->expand_with_parents(array_merge($primary ? [$primary] : [], $related));
        update_post_meta($post_id, '_tng_effective_destination_ids', $all);
        wp_set_object_terms($post_id, $this->destination_term_ids($all), 'tng_destination_ref', false);
    }

    private function ensure_term(int $destination_id): int {
        if (!$destination_id || get_post_type($destination_id) !== 'tng_destination') return 0;

        $term_id = absint(get_post_meta($destination_id, '_tng_destination_term_id', true));
        if ($term_id && term_exists($term_id, 'tng_destination_ref')) return $term_id;

        $slug = 'destination-' . $destination_id;
        $term = get_term_by('slug', $slug, 'tng_destination_ref');
        if ($term && !is_wp_error($term)) {
            $term_id = (int)$term->term_id;
        } else {
            $created = wp_insert_term(get_the_title($destination_id), 'tng_destination_ref', ['slug'=>$slug]);
            if (is_wp_error($created)) return 0;
            $term_id = (int)$created['term_id'];
        }

        update_term_meta($term_id, 'tng_destination_id', $destination_id);
        update_post_meta($destination_id, '_tng_destination_term_id', $term_id);
        return $term_id;
    }

    private function resync_linked_listings(int $destination_id): void {
        $query = new \WP_Query([
            'post_type'=>$this->supported_post_types(),
            'post_status'=>['publish','draft','pending','private'],
            'posts_per_page'=>-1,
            'fields'=>'ids',
            'meta_query'=>[
                'relation'=>'OR',
                ['key'=>'_tng_destination_id','value'=>$destination_id,'compare'=>'=','type'=>'NUMERIC'],
                ['key'=>'_tng_related_destination_ids','value'=>'"' . $destination_id . '"','compare'=>'LIKE'],
            ],
        ]);

        foreach ($query->posts as $post_id) {
            $primary = absint(get_post_meta($post_id, '_tng_destination_id', true));
            $related = get_post_meta($post_id, '_tng_related_destination_ids', true);
            $this->assign((int)$post_id, $primary, is_array($related) ? $related : []);
        }
    }
}
