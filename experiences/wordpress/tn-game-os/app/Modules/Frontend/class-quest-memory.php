<?php
namespace TNG_OS\Modules\Frontend;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class Quest_Memory implements Module_Interface {
    public function id(): string { return 'quest_memory'; }

    public function register(Container $container): void {
        $container->set('quest_memory', $this);
        add_action('wp_enqueue_scripts', [$this, 'enqueue']);
    }

    public function boot(Container $container): void {}

    public function enqueue(): void {
        if (is_admin() || !isset($_GET['tng_quest_runtime_id'])) return;
        wp_enqueue_style('tng-quest-memory', TNG_OS_URL . 'assets/frontend/quest-memory.css', [], TNG_OS_VERSION);
        wp_enqueue_script('tng-quest-memory', TNG_OS_URL . 'assets/frontend/quest-memory.js', [], TNG_OS_VERSION, true);
        wp_localize_script('tng-quest-memory', 'TNGQuestMemory', [
            'questId' => absint($_GET['tng_quest_runtime_id']),
            'worldUrl' => add_query_arg('tng_world', 1, home_url('/')),
        ]);
    }
}
