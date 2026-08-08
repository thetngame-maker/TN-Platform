<?php
namespace TNG_OS\Modules\Frontend;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) exit;

final class Daily_Missions implements Module_Interface {
    private const REST_NS = 'tng-game/v1';
    private const META_KEY = '_tng_daily_missions';

    public function id(): string { return 'daily_missions'; }

    public function register(Container $container): void {
        $container->set('daily_missions', $this);
        add_action('wp_enqueue_scripts', [$this, 'enqueue']);
        add_action('rest_api_init', [$this, 'register_rest']);
    }

    public function boot(Container $container): void {}

    public function enqueue(): void {
        if (is_admin() || !isset($_GET['tng_quest_runtime_id'])) return;
        wp_enqueue_style('tng-daily-missions', TNG_OS_URL . 'assets/frontend/daily-missions.css', [], TNG_OS_VERSION);
        wp_enqueue_script('tng-daily-missions', TNG_OS_URL . 'assets/frontend/daily-missions.js', [], TNG_OS_VERSION, true);
        wp_localize_script('tng-daily-missions', 'TNGDailyMissions', [
            'loggedIn' => is_user_logged_in(),
            'stateUrl' => rest_url(self::REST_NS . '/daily-missions'),
            'restNonce' => is_user_logged_in() ? wp_create_nonce('wp_rest') : '',
            'initialState' => is_user_logged_in() ? $this->state(get_current_user_id()) : null,
        ]);
    }

    public function register_rest(): void {
        register_rest_route(self::REST_NS, '/daily-missions', [
            ['methods' => 'GET', 'callback' => [$this, 'get_state'], 'permission_callback' => static fn(): bool => is_user_logged_in()],
            ['methods' => 'POST', 'callback' => [$this, 'save_state'], 'permission_callback' => static fn(): bool => is_user_logged_in()],
        ]);
    }

    public function get_state(): WP_REST_Response {
        return new WP_REST_Response($this->state(get_current_user_id()), 200);
    }

    public function save_state(WP_REST_Request $request): WP_REST_Response {
        $current = $this->state(get_current_user_id());
        $incoming = (array)$request->get_json_params();
        $state = [
            'date' => sanitize_text_field((string)($incoming['date'] ?? gmdate('Y-m-d'))),
            'tokens' => max((int)$current['tokens'], absint($incoming['tokens'] ?? 0)),
            'completed' => $this->clean_keys($incoming['completed'] ?? []),
            'claimed' => $this->clean_keys($incoming['claimed'] ?? []),
            'missionStreak' => max((int)$current['missionStreak'], absint($incoming['missionStreak'] ?? 0)),
            'lastCompletedDate' => sanitize_text_field((string)($incoming['lastCompletedDate'] ?? $current['lastCompletedDate'] ?? '')),
        ];
        update_user_meta(get_current_user_id(), self::META_KEY, $state);
        return new WP_REST_Response($state, 200);
    }

    private function state(int $user_id): array {
        $state = get_user_meta($user_id, self::META_KEY, true);
        if (!is_array($state)) $state = [];
        return [
            'date' => sanitize_text_field((string)($state['date'] ?? '')),
            'tokens' => absint($state['tokens'] ?? 0),
            'completed' => $this->clean_keys($state['completed'] ?? []),
            'claimed' => $this->clean_keys($state['claimed'] ?? []),
            'missionStreak' => absint($state['missionStreak'] ?? 0),
            'lastCompletedDate' => sanitize_text_field((string)($state['lastCompletedDate'] ?? '')),
        ];
    }

    private function clean_keys($values): array {
        return array_values(array_unique(array_filter(array_map(static fn($value): string => sanitize_key((string)$value), (array)$values))));
    }
}
