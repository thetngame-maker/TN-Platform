<?php
namespace TNG_OS\Modules\Frontend;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class Mobile_Session_Recovery implements Module_Interface {
    public function id(): string { return 'mobile_session_recovery'; }

    public function register(Container $container): void {
        $container->set('mobile_session_recovery', $this);
        add_action('wp_enqueue_scripts', [$this, 'enqueue'], 45);
    }

    public function boot(Container $container): void {}

    public function enqueue(): void {
        if (is_admin() || !isset($_GET['tng_quest_runtime_id'])) return;

        wp_enqueue_style(
            'tng-mobile-session-recovery',
            TNG_OS_URL . 'assets/frontend/mobile-session-recovery.css',
            [],
            TNG_OS_VERSION
        );
        wp_enqueue_script(
            'tng-mobile-session-recovery',
            TNG_OS_URL . 'assets/frontend/mobile-session-recovery.js',
            ['tng-gameplay-notifications'],
            TNG_OS_VERSION,
            true
        );
        wp_localize_script('tng-mobile-session-recovery', 'TNGMobileRecovery', [
            'maxAge' => DAY_IN_SECONDS,
            'backgroundThreshold' => 30,
            'heartbeat' => 15,
            'labels' => [
                'restoredTitle' => __('Journey restored', 'tn-game-os'),
                'restoredMessage' => __('Your quest progress is safe and ready to continue.', 'tn-game-os'),
                'gpsTitle' => __('Resume live location', 'tn-game-os'),
                'gpsMessage' => __('Safari paused location updates while the quest was in the background.', 'tn-game-os'),
                'resumeGps' => __('Resume GPS', 'tn-game-os'),
                'dismiss' => __('Not now', 'tn-game-os'),
            ],
        ]);
    }
}
