<?php
/**
 * Plugin Name: TN Game Local Discovery
 * Description: Safe standalone bootstrap for Local Discovery, Town Scanner, Changes Inbox, and Town Monitoring on TN Game OS recovery-era installations.
 * Version: 0.1.3
 * Author: The TN Game
 */

if (!defined('ABSPATH')) exit;

add_action('admin_menu', static function (): void {
    if (!current_user_can('edit_posts')) return;

    global $menu;
    foreach ((array)$menu as $item) {
        if (($item[2] ?? '') === 'tng-content-studio') return;
    }

    add_menu_page(
        'Content Studio',
        'Content Studio',
        'edit_posts',
        'tng-content-studio',
        static function (): void {
            ?>
            <div class="wrap">
                <h1>Content Studio</h1>
                <p>TN Game discovery, planning, and editorial tools.</p>
                <p>
                    <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=tng-local-discovery')); ?>">Local Discovery</a>
                    <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=tng-town-scanner')); ?>">Town Scanner</a>
                    <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=tng-town-changes')); ?>">Changes Inbox</a>
                    <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=tng-town-monitor')); ?>">Town Monitoring</a>
                </p>
            </div>
            <?php
        },
        'dashicons-megaphone',
        26
    );
}, 20);

add_action('plugins_loaded', static function (): void {
    if (!defined('TNG_OS_PATH') || !class_exists('TNG_OS\\Core\\Container')) {
        add_action('admin_notices', static function (): void {
            if (!current_user_can('activate_plugins')) return;
            echo '<div class="notice notice-error"><p><strong>TN Game Local Discovery:</strong> TN Game OS must be active and loaded first.</p></div>';
        });
        return;
    }

    if (has_action('admin_post_tng_maps_discovery_search') && has_action('admin_post_tng_town_scan')) {
        return;
    }

    $files = [
        'app/Modules/Sources/class-local-discovery.php',
        'app/Modules/Sources/class-local-discovery-destination-linker.php',
        'app/Modules/Sources/class-town-scanner.php',
        'app/Modules/Sources/class-town-changes-inbox.php',
        'app/Modules/Sources/class-town-monitor.php',
    ];

    foreach ($files as $file) {
        $path = TNG_OS_PATH . $file;
        if (!is_readable($path)) {
            add_action('admin_notices', static function () use ($file): void {
                if (!current_user_can('activate_plugins')) return;
                echo '<div class="notice notice-error"><p><strong>TN Game Local Discovery:</strong> Missing module file: ' . esc_html($file) . '</p></div>';
            });
            return;
        }
        require_once $path;
    }

    $container = new \TNG_OS\Core\Container();

    $container->set('settings', new class {
        public function get(string $key, $default = '') {
            $settings = get_option('tng_os_settings', []);
            if (is_array($settings) && array_key_exists($key, $settings)) return $settings[$key];
            if ($key === 'google_places_key') {
                $legacy = get_option('tng_food_google_places_api_key', '');
                if ($legacy !== '') return $legacy;
            }
            return $default;
        }
    });

    $container->set('destination_relationships', new class {
        public function assign(int $post_id, int $primary_id, array $related_ids = []): void {
            $primary_id = absint($primary_id);
            $related_ids = array_values(array_unique(array_filter(array_map('absint', $related_ids))));
            if (!$post_id || !$primary_id) return;

            update_post_meta($post_id, '_tng_destination_id', $primary_id);
            update_post_meta($post_id, '_tng_related_destination_ids', $related_ids);

            $effective = array_values(array_unique(array_merge([$primary_id], $related_ids, $this->ancestors($primary_id))));
            update_post_meta($post_id, '_tng_effective_destination_ids', $effective);

            if (!taxonomy_exists('tng_destination_ref')) return;

            $term_ids = [];
            foreach ($effective as $destination_id) {
                $term_id = absint(get_post_meta($destination_id, '_tng_destination_term_id', true));
                if ($term_id && term_exists($term_id, 'tng_destination_ref')) {
                    $term_ids[] = $term_id;
                    continue;
                }

                $slug = 'destination-' . $destination_id;
                $term = get_term_by('slug', $slug, 'tng_destination_ref');
                if (!$term) {
                    $created = wp_insert_term(get_the_title($destination_id), 'tng_destination_ref', ['slug' => $slug]);
                    if (is_wp_error($created)) continue;
                    $term_id = (int)$created['term_id'];
                } else {
                    $term_id = (int)$term->term_id;
                }

                update_term_meta($term_id, 'tng_destination_id', $destination_id);
                update_post_meta($destination_id, '_tng_destination_term_id', $term_id);
                $term_ids[] = $term_id;
            }

            if ($term_ids) {
                wp_set_object_terms($post_id, array_values(array_unique($term_ids)), 'tng_destination_ref', false);
            }
        }

        private function ancestors(int $destination_id): array {
            $ancestors = [];
            $seen = [];
            $current = $destination_id;

            for ($i = 0; $i < 20 && $current; $i++) {
                if (isset($seen[$current])) break;
                $seen[$current] = true;
                $parent = absint(get_post_meta($current, '_tng_destination_parent_id', true));
                if (!$parent) break;
                $ancestors[] = $parent;
                $current = $parent;
            }

            return array_values(array_unique($ancestors));
        }
    });

    $modules = [
        new \TNG_OS\Modules\Sources\Local_Discovery(),
        new \TNG_OS\Modules\Sources\Local_Discovery_Destination_Linker(),
        new \TNG_OS\Modules\Sources\Town_Scanner(),
        new \TNG_OS\Modules\Sources\Town_Changes_Inbox(),
        new \TNG_OS\Modules\Sources\Town_Monitor(),
    ];

    foreach ($modules as $module) $module->register($container);
    foreach ($modules as $module) $module->boot($container);

    do_action('tng_local_discovery_overlay_booted', $container);
}, 50);
