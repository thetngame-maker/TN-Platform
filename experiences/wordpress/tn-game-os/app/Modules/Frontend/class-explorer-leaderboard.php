<?php
namespace TNG_OS\Modules\Frontend;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;
use WP_REST_Request;
use WP_REST_Response;
use WP_User_Query;

if (!defined('ABSPATH')) exit;

final class Explorer_Leaderboard implements Module_Interface {
    private const REST_NS = 'tng-game/v1';
    private const PROFILE_META = '_tng_explorer_profile';
    private const CACHE_PREFIX = 'tng_explorer_leaderboard_';

    public function id(): string { return 'explorer_leaderboard'; }

    public function register(Container $container): void {
        $container->set('explorer_leaderboard', $this);
        add_action('wp_enqueue_scripts', [$this, 'enqueue']);
        add_action('rest_api_init', [$this, 'register_rest']);
        add_action('updated_user_meta', [$this, 'invalidate'], 10, 4);
        add_action('added_user_meta', [$this, 'invalidate'], 10, 4);
        add_action('deleted_user_meta', [$this, 'invalidate'], 10, 4);
    }

    public function boot(Container $container): void {}

    public function enqueue(): void {
        if (is_admin() || !isset($_GET['tng_quest_runtime_id'])) return;
        wp_enqueue_style('tng-explorer-leaderboard', TNG_OS_URL . 'assets/frontend/explorer-leaderboard.css', [], TNG_OS_VERSION);
        wp_enqueue_script('tng-explorer-leaderboard', TNG_OS_URL . 'assets/frontend/explorer-leaderboard.js', [], TNG_OS_VERSION, true);
        wp_localize_script('tng-explorer-leaderboard', 'TNGExplorerLeaderboard', [
            'endpoint' => rest_url(self::REST_NS . '/explorer-leaderboard'),
            'currentUserId' => get_current_user_id(),
            'loggedIn' => is_user_logged_in(),
        ]);
    }

    public function register_rest(): void {
        register_rest_route(self::REST_NS, '/explorer-leaderboard', [
            'methods' => 'GET', 'callback' => [$this, 'leaderboard'], 'permission_callback' => '__return_true',
            'args' => ['metric' => ['sanitize_callback' => 'sanitize_key']],
        ]);
    }

    public function invalidate($meta_id, $user_id, $meta_key, $meta_value): void {
        if ($meta_key !== self::PROFILE_META) return;
        foreach (['xp', 'checkpoints', 'quests'] as $metric) delete_transient(self::CACHE_PREFIX . $metric);
    }

    public function leaderboard(WP_REST_Request $request): WP_REST_Response {
        $metric = sanitize_key((string)$request->get_param('metric'));
        if (!in_array($metric, ['xp', 'checkpoints', 'quests'], true)) $metric = 'xp';
        $cache_key = self::CACHE_PREFIX . $metric;
        $cached = get_transient($cache_key);
        if (is_array($cached)) return new WP_REST_Response($cached + ['cached' => true], 200);

        $settings = wp_parse_args((array)get_option('tng_gameplay_settings', []), ['leaderboard_limit' => 50, 'community_cache_seconds' => 60]);
        $limit = max(10, min(200, absint($settings['leaderboard_limit'] ?? 50)));
        $ttl = max(15, min(3600, absint($settings['community_cache_seconds'] ?? 60)));
        $query = new WP_User_Query(['number' => 250, 'fields' => ['ID', 'display_name'], 'meta_key' => self::PROFILE_META]);
        $rows = [];
        foreach ((array)$query->get_results() as $user) {
            $profile = get_user_meta((int)$user->ID, self::PROFILE_META, true);
            if (!is_array($profile)) continue;
            $xp = absint($profile['totalXp'] ?? 0);
            $checkpoints = count(array_unique((array)($profile['completedCheckpoints'] ?? [])));
            $quests = count(array_unique((array)($profile['completedQuests'] ?? [])));
            if ($xp <= 0 && $checkpoints <= 0 && $quests <= 0) continue;
            $value = $metric === 'checkpoints' ? $checkpoints : ($metric === 'quests' ? $quests : $xp);
            $rows[] = [
                'userId' => (int)$user->ID, 'name' => sanitize_text_field((string)$user->display_name),
                'avatar' => get_avatar_url((int)$user->ID, ['size' => 96]),
                'title' => sanitize_text_field((string)($profile['selectedTitle'] ?? 'explorer')),
                'xp' => $xp, 'checkpoints' => $checkpoints, 'quests' => $quests, 'value' => $value,
            ];
        }
        usort($rows, static function(array $a, array $b): int {
            if ($a['value'] === $b['value']) return $b['xp'] <=> $a['xp'];
            return $b['value'] <=> $a['value'];
        });
        $rows = array_slice($rows, 0, $limit);
        foreach ($rows as $index => &$row) $row['rank'] = $index + 1;
        unset($row);
        $payload = ['metric' => $metric, 'leaders' => $rows, 'updatedAt' => current_time('mysql', true), 'cached' => false];
        set_transient($cache_key, $payload, $ttl);
        return new WP_REST_Response($payload, 200);
    }
}
