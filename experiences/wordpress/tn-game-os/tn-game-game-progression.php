<?php
/**
 * Plugin Name: TN Game Game Progression
 * Description: Bridges completed TN Game checkpoints into Explorer XP and Top Sight progression.
 * Version: 0.1.0
 * Author: The TN Game
 */
if (!defined('ABSPATH')) exit;

final class TNG_Game_Progression {
    public static function boot(): void {
        add_action('added_user_meta', [self::class, 'user_meta_added'], 10, 4);
        add_action('updated_user_meta', [self::class, 'user_meta_updated'], 10, 4);
    }

    public static function user_meta_added($meta_id, $user_id, $meta_key, $meta_value): void {
        self::handle_user_meta((int) $user_id, (string) $meta_key, $meta_value);
    }

    public static function user_meta_updated($meta_id, $user_id, $meta_key, $meta_value): void {
        self::handle_user_meta((int) $user_id, (string) $meta_key, $meta_value);
    }

    private static function handle_user_meta(int $user_id, string $meta_key, $meta_value): void {
        if (!$user_id) return;

        if ($meta_key === '_tng_completed_games') {
            self::process_completed_games($user_id, $meta_value);
            return;
        }

        if (strpos($meta_key, '_tng_game_progress_') === 0) {
            $game_id = absint(substr($meta_key, strlen('_tng_game_progress_')));
            if ($game_id) self::process_completed_sights($user_id, $game_id, $meta_value);
        }
    }

    private static function process_completed_games(int $user_id, $value): void {
        if (!is_array($value)) return;
        foreach ($value as $game_id) {
            $game_id = absint($game_id);
            if ($game_id) self::award_game_xp($user_id, $game_id);
        }
    }

    private static function xp_type(): string {
        $configured = sanitize_key((string) get_option('tng_gamipress_points_type', ''));
        if ($configured !== '') return $configured;

        if (function_exists('gamipress_get_points_types')) {
            $types = gamipress_get_points_types();
            if (is_array($types) && !empty($types)) {
                foreach (['xp', 'explorer-xp', 'points'] as $preferred) {
                    if (isset($types[$preferred])) return $preferred;
                }
                foreach ($types as $slug => $data) {
                    $text = strtolower((string) $slug . ' ' . wp_json_encode($data));
                    if (strpos($text, 'explorer') !== false && strpos($text, 'xp') !== false) return sanitize_key((string) $slug);
                }
                if (count($types) === 1) return sanitize_key((string) array_key_first($types));
            }
        }

        return 'xp';
    }

    private static function award_game_xp(int $user_id, int $game_id): void {
        $award_key = '_tng_game_xp_awarded_' . $game_id;
        if (get_user_meta($user_id, $award_key, true)) return;

        $amount = absint(get_post_meta($game_id, 'xp_available', true));
        if (!$amount) $amount = absint(get_post_meta($game_id, 'xp', true));
        if (!$amount) return;

        if (!function_exists('gamipress_award_points_to_user')) {
            update_user_meta($user_id, '_tng_game_xp_pending_' . $game_id, $amount);
            return;
        }

        $type = self::xp_type();
        if ($type === '') {
            update_user_meta($user_id, '_tng_game_xp_pending_' . $game_id, $amount);
            return;
        }

        $result = gamipress_award_points_to_user($user_id, $amount, $type);
        if ($result === false) {
            update_user_meta($user_id, '_tng_game_xp_pending_' . $game_id, $amount);
            return;
        }

        update_user_meta($user_id, $award_key, [
            'amount' => $amount,
            'type' => $type,
            'awarded_at' => current_time('mysql'),
        ]);
        delete_user_meta($user_id, '_tng_game_xp_pending_' . $game_id);
    }

    private static function process_completed_sights(int $user_id, int $game_id, $progress): void {
        if (!is_array($progress)) return;
        $checkpoints = get_post_meta($game_id, 'tng_game_checkpoints', true);
        if (!is_array($checkpoints)) return;

        $visited = get_user_meta($user_id, '_tng_visited_top_sights', true);
        if (!is_array($visited)) $visited = [];
        $visited = array_map('absint', $visited);

        foreach ($progress as $index) {
            $index = absint($index);
            if (!isset($checkpoints[$index]) || !is_array($checkpoints[$index])) continue;
            $sight_id = absint($checkpoints[$index]['sight_id'] ?? 0);
            if (!$sight_id) continue;
            if (!in_array($sight_id, $visited, true)) $visited[] = $sight_id;
            update_user_meta($user_id, '_tng_top_sight_visited_at_' . $sight_id, current_time('mysql'));
        }

        update_user_meta($user_id, '_tng_visited_top_sights', array_values(array_unique($visited)));
    }
}

TNG_Game_Progression::boot();
