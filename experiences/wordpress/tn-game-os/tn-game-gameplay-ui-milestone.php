<?php
/**
 * TN Game Gameplay UI Milestones
 * Loads player-first presentation layers only on the native /game-play/ runtime.
 */
if (!defined('ABSPATH')) exit;

final class TNG_Gameplay_UI_Milestone {
    public static function boot(): void {
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue'), 90);
        add_filter('body_class', array(__CLASS__, 'body_class'));
    }

    private static function is_gameplay_request(): bool {
        if (is_admin()) return false;
        $path = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
        if (strpos($path, '/game-play/') !== false) return true;
        if (function_exists('is_page') && is_page('game-play')) return true;
        return false;
    }

    public static function enqueue(): void {
        if (!self::is_gameplay_request()) return;
        wp_enqueue_style('tng-gameplay-milestone-1',TNG_OS_URL . 'assets/frontend/gameplay-milestone-1.css',array(),TNG_OS_VERSION);
        wp_enqueue_style('tng-gameplay-milestone-2',TNG_OS_URL . 'assets/frontend/gameplay-milestone-2.css',array('tng-gameplay-milestone-1'),TNG_OS_VERSION);
        wp_enqueue_style('tng-gameplay-success-transition',TNG_OS_URL . 'assets/frontend/gameplay-success-transition.css',array('tng-gameplay-milestone-2'),TNG_OS_VERSION);
        wp_enqueue_style('tng-gameplay-adventure-complete',TNG_OS_URL . 'assets/frontend/gameplay-adventure-complete.css',array('tng-gameplay-success-transition'),TNG_OS_VERSION);
        wp_enqueue_script('tng-gameplay-success-transition',TNG_OS_URL . 'assets/frontend/gameplay-success-transition.js',array(),TNG_OS_VERSION,true);
        wp_enqueue_script('tng-gameplay-adventure-complete',TNG_OS_URL . 'assets/frontend/gameplay-adventure-complete.js',array('tng-gameplay-success-transition'),TNG_OS_VERSION,true);
    }

    public static function body_class(array $classes): array {
        if (self::is_gameplay_request()) {
            $classes[] = 'tng-gameplay-ui-v1';
            $classes[] = 'tng-gameplay-ui-v2';
        }
        return $classes;
    }
}

TNG_Gameplay_UI_Milestone::boot();

// Gameplay owns its dock while the native runtime is open.
require_once TNG_OS_PATH . 'tn-game-gameplay-dock-context.php';

// Trip Mode live distance and arrival-readiness guidance.
require_once TNG_OS_PATH . 'tn-game-active-trip-proximity.php';
