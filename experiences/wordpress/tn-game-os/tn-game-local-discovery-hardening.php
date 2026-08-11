<?php
/**
 * Plugin Name: TN Game Local Discovery Hardening
 * Description: Production alerts and scan-scope controls for the TN Game Local Discovery stack.
 * Version: 0.1.0
 * Author: The TN Game
 */

if (!defined('ABSPATH')) exit;

add_action('plugins_loaded', static function (): void {
    if (!defined('TNG_OS_PATH') || !class_exists('TNG_OS\\Core\\Container')) {
        add_action('admin_notices', static function (): void {
            if (!current_user_can('activate_plugins')) return;
            echo '<div class="notice notice-error"><p><strong>TN Game Local Discovery Hardening:</strong> TN Game OS must be active and loaded first.</p></div>';
        });
        return;
    }

    $files = [
        'app/Modules/Sources/class-content-studio-alerts.php',
        'app/Modules/Sources/class-town-scan-scope.php',
    ];

    foreach ($files as $file) {
        $path = TNG_OS_PATH . $file;
        if (!is_readable($path)) {
            add_action('admin_notices', static function () use ($file): void {
                if (!current_user_can('activate_plugins')) return;
                echo '<div class="notice notice-error"><p><strong>TN Game Local Discovery Hardening:</strong> Missing module file: ' . esc_html($file) . '</p></div>';
            });
            return;
        }
        require_once $path;
    }

    $container = new \TNG_OS\Core\Container();
    $modules = [
        new \TNG_OS\Modules\Sources\Content_Studio_Alerts(),
        new \TNG_OS\Modules\Sources\Town_Scan_Scope(),
    ];

    foreach ($modules as $module) $module->register($container);
    foreach ($modules as $module) $module->boot($container);
}, 65);
