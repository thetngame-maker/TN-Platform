<?php
namespace TNG_OS\Modules\Destinations;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class Destinations implements Module_Interface {
    public function id(): string { return 'destinations'; }

    public function register(Container $container): void {
        $container->set('destinations', $this);
        add_action('init', [$this, 'register_types'], 8);
    }

    public function boot(Container $container): void {}

    public function register_types(): void {
        register_post_type('tng_destination', [
            'labels' => [
                'name' => 'Destinations',
                'singular_name' => 'Destination',
                'add_new' => 'Add Destination',
                'add_new_item' => 'Build Destination',
                'edit_item' => 'Edit Destination',
                'new_item' => 'New Destination',
                'view_item' => 'View Destination',
                'search_items' => 'Search Destinations',
            ],
            'public' => true,
            'show_ui' => true,
            'show_in_menu' => 'tn-game-os',
            'menu_icon' => 'dashicons-location-alt',
            'has_archive' => true,
            'rewrite' => ['slug' => 'destinations', 'with_front' => false],
            'supports' => ['title', 'editor', 'excerpt', 'thumbnail', 'revisions', 'custom-fields'],
            'show_in_rest' => true,
        ]);

        register_taxonomy('tng_destination_type', 'tng_destination', [
            'labels' => ['name' => 'Destination Types', 'singular_name' => 'Destination Type'],
            'public' => true,
            'show_ui' => true,
            'hierarchical' => true,
            'show_admin_column' => true,
            'show_in_rest' => true,
            'rewrite' => ['slug' => 'destination-type'],
        ]);

        register_taxonomy('tng_destination_ref', $this->supported_post_types(), [
            'labels' => [
                'name' => 'TN Game Destinations',
                'singular_name' => 'TN Game Destination',
            ],
            'public' => false,
            'show_ui' => false,
            'show_in_rest' => true,
            'hierarchical' => false,
            'rewrite' => false,
        ]);

        register_post_type('tng_local_alert', [
            'labels' => [
                'name' => 'Local Alerts',
                'singular_name' => 'Local Alert',
                'add_new_item' => 'Add Local Alert',
                'edit_item' => 'Edit Local Alert',
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => 'tn-game-os',
            'supports' => ['title', 'editor', 'excerpt', 'revisions'],
            'show_in_rest' => true,
        ]);
    }

    public function supported_post_types(): array {
        $types = [
            'post', 'page', 'st_activity', 'st_hotel', 'st_tours', 'st_tour',
            'st_rental', 'st_cars', 'st_car', 'top_sight', 'tng_top_sight',
        ];
        return array_values(array_filter(array_unique($types), 'post_type_exists'));
    }
}
