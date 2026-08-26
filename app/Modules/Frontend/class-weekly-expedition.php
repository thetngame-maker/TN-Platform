<?php
namespace TNG_OS\Modules\Frontend;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;
use TNG_OS\Modules\Gameplay\Gameplay_Event_Service;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) exit;

final class Weekly_Expedition implements Module_Interface {
    private const REST_NS = 'tng-game/v1';
    private const META_KEY = '_tng_weekly_expedition';
    private const WALLET_META = '_tng_daily_missions';
    private ?Gameplay_Event_Service $events = null;

    public function id(): string { return 'weekly_expedition'; }

    public function register(Container $container): void {
        $container->set('weekly_expedition', $this);
        if ($container->has('gameplay_event_service')) $this->events = $container->get('gameplay_event_service');
        add_action('wp_enqueue_scripts', [$this, 'enqueue']);
        add_action('rest_api_init', [$this, 'register_rest']);
    }

    public function boot(Container $container): void {}

    public function enqueue(): void {
        if (is_admin() || !isset($_GET['tng_quest_runtime_id'])) return;
        wp_enqueue_style('tng-weekly-expedition', TNG_OS_URL . 'assets/frontend/weekly-expedition.css', [], TNG_OS_VERSION);
        wp_enqueue_script('tng-weekly-expedition', TNG_OS_URL . 'assets/frontend/weekly-expedition.js', [], TNG_OS_VERSION, true);
        wp_localize_script('tng-weekly-expedition', 'TNGWeeklyExpedition', [
            'loggedIn' => is_user_logged_in(),
            'stateUrl' => rest_url(self::REST_NS . '/weekly-expedition'),
            'restNonce' => is_user_logged_in() ? wp_create_nonce('wp_rest') : '',
            'initialState' => is_user_logged_in() ? $this->state(get_current_user_id()) : null,
        ]);
    }

    public function register_rest(): void {
        register_rest_route(self::REST_NS, '/weekly-expedition', [
            ['methods' => 'GET', 'callback' => [$this, 'get_state'], 'permission_callback' => static fn(): bool => is_user_logged_in()],
            ['methods' => 'POST', 'callback' => [$this, 'claim_reward'], 'permission_callback' => static fn(): bool => is_user_logged_in()],
        ]);
    }

    public function get_state(): WP_REST_Response { return new WP_REST_Response($this->state(get_current_user_id()), 200); }

    public function claim_reward(WP_REST_Request $request): WP_REST_Response {
        $user_id = get_current_user_id();
        $state = $this->state($user_id);
        $tier = sanitize_key((string)$request->get_param('tier'));
        $rewards = ['trail' => 20, 'journey' => 45, 'summit' => 90];
        $targets = ['trail' => 2, 'journey' => 5, 'summit' => 9];
        if (!isset($rewards[$tier]) || in_array($tier, $state['claimed'], true) || $state['points'] < $targets[$tier]) return new WP_REST_Response($state, 200);
        $saved = get_user_meta($user_id, self::META_KEY, true);
        if (!is_array($saved)) $saved = [];
        $saved['weekKey'] = $state['weekKey'];
        $saved['resetAt'] = $state['resetAt'];
        $saved['claimed'] = array_values(array_unique(array_merge((array)($saved['claimed'] ?? []), [$tier])));
        update_user_meta($user_id, self::META_KEY, $saved);
        $wallet = get_user_meta($user_id, self::WALLET_META, true);
        if (!is_array($wallet)) $wallet = [];
        $wallet['tokens'] = absint($wallet['tokens'] ?? 0) + $rewards[$tier];
        update_user_meta($user_id, self::WALLET_META, $wallet);
        $state['claimed'] = $saved['claimed'];
        $state['tokens'] = $wallet['tokens'];
        return new WP_REST_Response($state, 200);
    }

    private function state(int $user_id): array {
        $week = gmdate('o-\WW');
        $week_start = gmdate('Y-m-d H:i:s', $this->week_start_timestamp());
        $saved = get_user_meta($user_id, self::META_KEY, true);
        if (!is_array($saved)) $saved = [];
        if (($saved['weekKey'] ?? '') !== $week) {
            $saved = ['weekKey' => $week, 'resetAt' => $week_start, 'claimed' => []];
            update_user_meta($user_id, self::META_KEY, $saved);
        }
        $reset_at = (string)($saved['resetAt'] ?? $week_start);
        if (strtotime($reset_at) < strtotime($week_start)) $reset_at = $week_start;
        $checkpoint_events = $this->events ? $this->events->count_user_events($user_id, 'checkpoint_completed', $reset_at) : 0;
        $quest_events = $this->events ? $this->events->count_user_events($user_id, 'quest_completed', $reset_at) : 0;
        $wallet = get_user_meta($user_id, self::WALLET_META, true);
        return [
            'weekKey' => $week,
            'resetAt' => $reset_at,
            'points' => $checkpoint_events + ($quest_events * 3),
            'checkpointEvents' => $checkpoint_events,
            'questEvents' => $quest_events,
            'claimed' => array_values(array_unique(array_map('sanitize_key', (array)($saved['claimed'] ?? [])))),
            'tokens' => is_array($wallet) ? absint($wallet['tokens'] ?? 0) : 0,
            'source' => 'gameplay_events',
        ];
    }

    private function week_start_timestamp(): int {
        $now = current_time('timestamp', true);
        $day = (int)gmdate('N', $now);
        return strtotime('-' . ($day - 1) . ' days midnight', $now);
    }
}
