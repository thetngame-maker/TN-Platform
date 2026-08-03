<?php
namespace TNG_OS\Modules\Frontend;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class Gameplay_Notifications implements Module_Interface {
    public function id(): string { return 'gameplay_notifications'; }

    public function register(Container $container): void {
        $container->set('gameplay_notifications', $this);
        add_action('wp_enqueue_scripts', [$this, 'enqueue'], 40);
    }

    public function boot(Container $container): void {}

    public function enqueue(): void {
        if (is_admin() || !isset($_GET['tng_quest_runtime_id'])) return;
        wp_enqueue_style('tng-gameplay-notifications', TNG_OS_URL . 'assets/frontend/gameplay-notifications.css', [], TNG_OS_VERSION);
        wp_enqueue_script('tng-gameplay-notifications', TNG_OS_URL . 'assets/frontend/gameplay-notifications.js', [], TNG_OS_VERSION, true);
        wp_localize_script('tng-gameplay-notifications', 'TNGGameplayNotifications', [
            'reducedMotion' => false,
            'defaultDuration' => 4200,
            'labels' => [
                'close' => __('Dismiss notification', 'tn-game-os'),
                'offlineTitle' => __('You are offline', 'tn-game-os'),
                'offlineMessage' => __('Your quest progress will remain on this device until the connection returns.', 'tn-game-os'),
                'onlineTitle' => __('Back online', 'tn-game-os'),
                'onlineMessage' => __('TN Game can sync your latest progress again.', 'tn-game-os'),
            ],
        ]);
    }
}
