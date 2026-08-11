<?php
/**
 * Plugin Name: TN Game Admin Route Compatibility
 * Description: Redirects legacy pretty wp-admin TN Game tool URLs to registered WordPress admin pages.
 * Version: 0.1.0
 * Author: The TN Game
 */

if (!defined('ABSPATH')) exit;

add_action('plugins_loaded', static function (): void {
    if (headers_sent()) return;

    $request_uri = (string)($_SERVER['REQUEST_URI'] ?? '');
    $path = (string)parse_url($request_uri, PHP_URL_PATH);
    $path = rtrim($path, '/');

    $routes = [
        '/wp-admin/tng-local-discovery' => 'tng-local-discovery',
        '/wp-admin/tng-town-scanner'    => 'tng-town-scanner',
    ];

    if (!isset($routes[$path])) return;

    wp_safe_redirect(admin_url('admin.php?page=' . $routes[$path]));
    exit;
}, 1);
