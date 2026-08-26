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
        $ids = array_values(array_filter((array)get_post_meta($quest_id, '_tng_quest_entity_ids', true)));
        $mechanics = (array)get_post_meta($quest_id, '_tng_game_checkpoint_mechanics', true);
        $xp = absint(get_post_meta($quest_id, '_tng_quest_xp', true) ?: get_post_meta($quest_id, '_tng_quest_estimated_xp', true));
        if (!$xp) {
            foreach ($ids as $id) {
                $item = is_array($mechanics[(string)$id] ?? null) ? $mechanics[(string)$id] : [];
                $xp += absint($item['xp'] ?? 25);
            }
        }
        $minutes = absint(get_post_meta($quest_id, '_tng_quest_estimated_minutes', true));
        $duration = $minutes ? ($minutes < 60 ? $minutes . ' min' : rtrim(rtrim(number_format($minutes / 60, 1), '0'), '.') . ' hr') : sanitize_text_field((string)get_post_meta($quest_id, '_tng_quest_duration', true));

        wp_enqueue_style('tng-quest-finale', TNG_OS_URL . 'assets/frontend/quest-finale.css', [], TNG_OS_VERSION);
        wp_enqueue_script('tng-quest-finale', TNG_OS_URL . 'assets/frontend/quest-finale.js', [], TNG_OS_VERSION, true);
        wp_localize_script('tng-quest-finale', 'TNGQuestFinale', [
            'questId' => $quest_id,
            'questTitle' => get_the_title($quest_id),
            'checkpointCount' => count($ids),
            'rewardXp' => $xp,
            'duration' => $duration,
            'worldUrl' => add_query_arg('tng_world', 1, home_url('/')),
            'homeUrl' => home_url('/'),
        ]);
    }
}
