<?php
/**
 * Plugin Name: TN Game Apify Budget Safeguards
 * Description: Monthly usage budgets, warning thresholds, per-town caps, and automatic scheduled-monitor pauses for TN Game Local Discovery.
 * Version: 0.1.0
 * Author: The TN Game
 */

if (!defined('ABSPATH')) exit;

add_action('plugins_loaded', static function (): void {
    if (!defined('TNG_OS_PATH') || !class_exists('TNG_OS\\Core\\Container')) {
        add_action('admin_notices', static function (): void {
            if (!current_user_can('activate_plugins')) return;
            echo '<div class="notice notice-error"><p><strong>TN Game Apify Budget Safeguards:</strong> TN Game OS must be active and loaded first.</p></div>';
        });
        return;
    }

    $file = TNG_OS_PATH . 'app/Modules/Sources/class-apify-budget-safeguards.php';
    if (!is_readable($file)) {
        add_action('admin_notices', static function (): void {
            if (!current_user_can('activate_plugins')) return;
            echo '<div class="notice notice-error"><p><strong>TN Game Apify Budget Safeguards:</strong> Budget module file is missing.</p></div>';
        });
        return;
    }

    require_once $file;

    if (!class_exists('TNG_OS\\Modules\\Sources\\Apify_Budget_Safeguards')) return;

    $container = new \TNG_OS\Core\Container();
    $module = new \TNG_OS\Modules\Sources\Apify_Budget_Safeguards();
    $module->register($container);
    $module->boot($container);
}, 60);
