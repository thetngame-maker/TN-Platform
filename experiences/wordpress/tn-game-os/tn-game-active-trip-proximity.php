<?php
/**
 * TN Game Active Trip Proximity
 * Live distance, automatic arrival detection, and next-stop proximity guidance for Trip Mode.
 */
if (!defined('ABSPATH')) exit;

final class TNG_Active_Trip_Proximity {
    private const VERSION = '0.2.0';

    public static function boot(): void {
        add_action('wp_enqueue_scripts', [self::class, 'enqueue'], 125);
    }

    private static function is_active_trip_request(): bool {
        if (is_admin()) return false;
        if (class_exists('TNG_OS\\Platform\\App_Router')) {
            $route = TNG_OS\Platform\App_Router::current_route();
            if (in_array($route, ['active-trip', 'trip-mode'], true)) return true;
        }
        $path = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
        return strpos($path, '/active-trip/') !== false || strpos($path, '/trip-mode/') !== false;
    }

    public static function enqueue(): void {
        if (!self::is_active_trip_request()) return;
        wp_enqueue_style('tng-active-trip-proximity', TNG_OS_URL . 'assets/frontend/active-trip-proximity.css', [], self::VERSION);
        wp_enqueue_script('tng-active-trip-proximity', TNG_OS_URL . 'assets/frontend/active-trip-proximity.js', [], self::VERSION, true);
        wp_localize_script('tng-active-trip-proximity', 'TNGTripProximity', [
            'arrivalRadius' => 300,
            'watchOptions' => [
                'enableHighAccuracy' => true,
                'timeout' => 15000,
                'maximumAge' => 10000,
            ],
        ]);
    }
}

TNG_Active_Trip_Proximity::boot();
