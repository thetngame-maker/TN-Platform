<?php
namespace TNG_OS\Modules\Services;
use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;
if (!defined('ABSPATH')) exit;

final class Service_Registry implements Module_Interface {
    private array $services = [];
    public function id(): string { return 'services'; }

    public function register(Container $container): void {
        $this->services = apply_filters('tng_os_service_definitions', [
            'trails'=>['label'=>'Trails','singular'=>'Trail','icon'=>'dashicons-location-alt','term'=>'hiking-trails'],
            'food'=>['label'=>'Food & Drink','singular'=>'Restaurant','icon'=>'dashicons-food','term'=>'food-and-drink'],
            'concerts'=>['label'=>'Concerts','singular'=>'Concert','icon'=>'dashicons-format-audio','term'=>'concerts'],
            'shops'=>['label'=>'Shops','singular'=>'Shop','icon'=>'dashicons-store','term'=>'shops'],
            'history'=>['label'=>'Historic Sites','singular'=>'Historic Site','icon'=>'dashicons-building','term'=>'historic-sites'],
            'waterfalls'=>['label'=>'Waterfalls','singular'=>'Waterfall','icon'=>'dashicons-image-filter','term'=>'waterfalls'],
            'campgrounds'=>['label'=>'Campgrounds','singular'=>'Campground','icon'=>'dashicons-palmtree','term'=>'campgrounds'],
            'lodging'=>['label'=>'Lodging','singular'=>'Lodging','icon'=>'dashicons-admin-home','term'=>'lodging'],
            'events'=>['label'=>'Events','singular'=>'Event','icon'=>'dashicons-calendar-alt','term'=>'events'],
            'scenic'=>['label'=>'Scenic Views','singular'=>'Scenic View','icon'=>'dashicons-format-image','term'=>'scenic-views'],
        ]);
        $container->set('services', $this);
        add_action('init', [$this,'ensure_terms'], 30);
    }

    public function boot(Container $container): void {}
    public function all(): array { return $this->services; }

    public function taxonomy(): string {
        foreach (['st_activity_type','activity_type','st_activity_types'] as $taxonomy) {
            if (taxonomy_exists($taxonomy)) return $taxonomy;
        }
        foreach (get_object_taxonomies('st_activity','objects') as $taxonomy) {
            if (!empty($taxonomy->hierarchical)) return $taxonomy->name;
        }
        return '';
    }

    public function ensure_terms(): void {
        $taxonomy = $this->taxonomy();
        if (!$taxonomy) return;
        foreach ($this->services as $service) {
            if (!term_exists($service['term'], $taxonomy)) {
                wp_insert_term($service['label'], $taxonomy, ['slug'=>$service['term']]);
            }
        }
    }
}
