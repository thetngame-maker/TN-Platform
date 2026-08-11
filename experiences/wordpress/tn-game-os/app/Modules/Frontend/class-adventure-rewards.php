<?php
namespace TNG_OS\Modules\Frontend;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

if (!defined('ABSPATH')) exit;

final class Adventure_Rewards implements Module_Interface {
    private const REST_NS = 'tng-game/v1';
    private const WALLET_META = '_tng_daily_missions';
    private const REWARDS_META = '_tng_adventure_rewards';

    private const CATALOG = [
        'violet_compass' => ['title' => 'Violet Compass', 'cost' => 40, 'kind' => 'marker'],
        'golden_frame' => ['title' => 'Golden Explorer Frame', 'cost' => 75, 'kind' => 'frame'],
        'campfire_glow' => ['title' => 'Campfire Glow', 'cost' => 120, 'kind' => 'aura'],
    ];

    public function id(): string { return 'adventure_rewards'; }

    public function register(Container $container): void {
        $container->set('adventure_rewards', $this);
        add_action('wp_enqueue_scripts', [$this, 'enqueue']);
        add_action('rest_api_init', [$this, 'register_rest']);
    }

    public function boot(Container $container): void {}

    public function enqueue(): void {
        if (is_admin() || !isset($_GET['tng_quest_runtime_id'])) return;
        wp_enqueue_style('tng-adventure-rewards', TNG_OS_URL . 'assets/frontend/adventure-rewards.css', [], TNG_OS_VERSION);
        wp_enqueue_script('tng-adventure-rewards', TNG_OS_URL . 'assets/frontend/adventure-rewards.js', [], TNG_OS_VERSION, true);
        wp_localize_script('tng-adventure-rewards', 'TNGAdventureRewards', [
            'loggedIn' => is_user_logged_in(),
            'rewardsUrl' => rest_url(self::REST_NS . '/adventure-rewards'),
            'restNonce' => is_user_logged_in() ? wp_create_nonce('wp_rest') : '',
            'initialState' => is_user_logged_in() ? $this->state(get_current_user_id()) : null,
        ]);
    }

    public function register_rest(): void {
        register_rest_route(self::REST_NS, '/adventure-rewards', [
            ['methods' => 'GET', 'callback' => [$this, 'get_state'], 'permission_callback' => static fn(): bool => is_user_logged_in()],
            ['methods' => 'POST', 'callback' => [$this, 'change_state'], 'permission_callback' => static fn(): bool => is_user_logged_in()],
        ]);
    }

    public function get_state(): WP_REST_Response {
        return new WP_REST_Response($this->state(get_current_user_id()), 200);
    }

    public function change_state(WP_REST_Request $request) {
        $user_id = get_current_user_id();
        $body = (array)$request->get_json_params();
        $action = sanitize_key((string)($body['action'] ?? ''));
        $reward_key = sanitize_key((string)($body['reward'] ?? ''));
        $state = $this->state($user_id);

        if ($action === 'redeem') {
            if (!isset(self::CATALOG[$reward_key])) return new WP_Error('tng_unknown_reward', 'Unknown reward.', ['status' => 400]);
            if (in_array($reward_key, $state['unlocked'], true)) return new WP_REST_Response($state, 200);
            $cost = (int)self::CATALOG[$reward_key]['cost'];
            if ($state['tokens'] < $cost) return new WP_Error('tng_not_enough_tokens', 'Not enough Adventure Tokens.', ['status' => 400]);
            $wallet = get_user_meta($user_id, self::WALLET_META, true);
            if (!is_array($wallet)) $wallet = [];
            $wallet['tokens'] = max(0, (int)$state['tokens'] - $cost);
            update_user_meta($user_id, self::WALLET_META, $wallet);
            $state['tokens'] = (int)$wallet['tokens'];
            $state['unlocked'][] = $reward_key;
            $state['equipped'] = $reward_key;
        } elseif ($action === 'equip') {
            if ($reward_key !== '' && !in_array($reward_key, $state['unlocked'], true)) return new WP_Error('tng_reward_locked', 'Unlock this reward first.', ['status' => 400]);
            $state['equipped'] = $reward_key;
        } else {
            return new WP_Error('tng_invalid_reward_action', 'Invalid reward action.', ['status' => 400]);
        }

        $reward_state = ['unlocked' => array_values(array_unique($state['unlocked'])), 'equipped' => $state['equipped']];
        update_user_meta($user_id, self::REWARDS_META, $reward_state);
        return new WP_REST_Response($state, 200);
    }

    private function state(int $user_id): array {
        $wallet = get_user_meta($user_id, self::WALLET_META, true);
        $rewards = get_user_meta($user_id, self::REWARDS_META, true);
        if (!is_array($wallet)) $wallet = [];
        if (!is_array($rewards)) $rewards = [];
        $unlocked = array_values(array_filter(array_unique(array_map('sanitize_key', (array)($rewards['unlocked'] ?? []))), static fn(string $key): bool => isset(self::CATALOG[$key])));
        $equipped = sanitize_key((string)($rewards['equipped'] ?? ''));
        if ($equipped !== '' && !in_array($equipped, $unlocked, true)) $equipped = '';
        return ['tokens' => absint($wallet['tokens'] ?? 0), 'unlocked' => $unlocked, 'equipped' => $equipped];
    }
}
