<?php
namespace TNG_OS\Modules\Frontend;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class Quest_Finale implements Module_Interface {
    public function id(): string { return 'quest_finale'; }

    public function register(Container $container): void {
        $container->set('quest_finale', $this);
        add_action('wp_enqueue_scripts', [$this, 'enqueue']);
    }

    public function boot(Container $container): void {}

    public function enqueue(): void {
        if (is_admin() || !isset($_GET['tng_quest_runtime_id'])) return;
        $quest_id = absint($_GET['tng_quest_runtime_id']);
        wp_enqueue_style('tng-quest-finale', TNG_OS_URL . 'assets/frontend/quest-finale.css', [], TNG_OS_VERSION);
        wp_enqueue_script('tng-quest-finale', TNG_OS_URL . 'assets/frontend/quest-finale.js', [], TNG_OS_VERSION, true);
        wp_localize_script('tng-quest-finale', 'TNGQuestFinale', [
            'questId' => $quest_id,
            'worldUrl' => add_query_arg('tng_world', 1, home_url('/')),
            'homeUrl' => home_url('/'),
        ]);
    }
}
