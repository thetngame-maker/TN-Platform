<?php
namespace TNG_OS\Modules\Frontend;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) exit;

final class Weekly_Expedition implements Module_Interface {
    private const REST_NS = 'tng-game/v1';
    private const META_KEY = '_tng_weekly_expedition';
    private const EXPLORER_META = '_tng_explorer_profile';
    private const WALLET_META = '_tng_daily_missions';

    public function id(): string { return 'weekly_expedition'; }

    public function register(Container $container): void {
        $container->set('weekly_expedition', $this);
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

        $state['claimed'][] = $tier;
        update_user_meta($user_id, self::META_KEY, [
            'weekKey' => $state['weekKey'], 'baselineCheckpoints' => $state['baselineCheckpoints'],
            'baselineQuests' => $state['baselineQuests'], 'claimed' => $state['claimed'],
        ]);
        $wallet = get_user_meta($user_id, self::WALLET_META, true);
        if (!is_array($wallet)) $wallet = [];
        $wallet['tokens'] = absint($wallet['tokens'] ?? 0) + $rewards[$tier];
        update_user_meta($user_id, self::WALLET_META, $wallet);
        $state['tokens'] = $wallet['tokens'];
        return new WP_REST_Response($state, 200);
    }

    private function state(int $user_id): array {
        $week = gmdate('o-\WW');
        $saved = get_user_meta($user_id, self::META_KEY, true);
        if (!is_array($saved)) $saved = [];
        $profile = get_user_meta($user_id, self::EXPLORER_META, true);
        if (!is_array($profile)) $profile = [];
        $checkpoints = count((array)($profile['completedCheckpoints'] ?? []));
        $quests = count((array)($profile['completedQuests'] ?? []));
        if (($saved['weekKey'] ?? '') !== $week) {
            $saved = ['weekKey' => $week, 'baselineCheckpoints' => $checkpoints, 'baselineQuests' => $quests, 'claimed' => []];
            update_user_meta($user_id, self::META_KEY, $saved);
        }
        $points = max(0, $checkpoints - absint($saved['baselineCheckpoints'] ?? 0)) + (max(0, $quests - absint($saved['baselineQuests'] ?? 0)) * 3);
        $wallet = get_user_meta($user_id, self::WALLET_META, true);
        return [
            'weekKey' => $week,
            'baselineCheckpoints' => absint($saved['baselineCheckpoints'] ?? $checkpoints),
            'baselineQuests' => absint($saved['baselineQuests'] ?? $quests),
            'points' => $points,
            'claimed' => array_values(array_unique(array_map('sanitize_key', (array)($saved['claimed'] ?? [])))),
            'tokens' => is_array($wallet) ? absint($wallet['tokens'] ?? 0) : 0,
        ];
    }
}
