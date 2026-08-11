<?php
namespace TNG_OS\Modules\Frontend;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;
use TNG_OS\Modules\Gameplay\Gameplay_Event_Service;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) exit;

final class Community_Expedition implements Module_Interface {
    private const REST_NS = 'tng-game/v1';
    private const CLAIM_META = '_tng_community_expedition_claims';
    private const WALLET_META = '_tng_daily_missions';
    private const CACHE_KEY = 'tng_community_expedition_totals';
    public const DEV_BASELINE_OPTION = 'tng_community_expedition_dev_baseline';
    private ?Gameplay_Event_Service $events = null;

    public function id(): string { return 'community_expedition'; }

    public function register(Container $container): void {
        $container->set('community_expedition', $this);
        if ($container->has('gameplay_event_service')) $this->events = $container->get('gameplay_event_service');
        add_action('wp_enqueue_scripts', [$this, 'enqueue']);
        add_action('rest_api_init', [$this, 'register_rest']);
        add_action('tng_gameplay_event_recorded', [self::class, 'clear_cache']);
    }

    public function boot(Container $container): void {}

    public function enqueue(): void {
        if (is_admin() || !isset($_GET['tng_quest_runtime_id'])) return;
        wp_enqueue_style('tng-community-expedition', TNG_OS_URL . 'assets/frontend/community-expedition.css', [], TNG_OS_VERSION);
        wp_enqueue_script('tng-community-expedition', TNG_OS_URL . 'assets/frontend/community-expedition.js', [], TNG_OS_VERSION, true);
        wp_localize_script('tng-community-expedition', 'TNGCommunityExpedition', [
            'statusUrl' => rest_url(self::REST_NS . '/community-expedition'),
            'claimUrl' => rest_url(self::REST_NS . '/community-expedition/claim'),
            'loggedIn' => is_user_logged_in(),
            'restNonce' => is_user_logged_in() ? wp_create_nonce('wp_rest') : '',
        ]);
    }

    public function register_rest(): void {
        register_rest_route(self::REST_NS, '/community-expedition', ['methods' => 'GET', 'callback' => [$this, 'get_status'], 'permission_callback' => '__return_true']);
        register_rest_route(self::REST_NS, '/community-expedition/claim', ['methods' => 'POST', 'callback' => [$this, 'claim_reward'], 'permission_callback' => static fn(): bool => is_user_logged_in()]);
    }

    public function get_status(): WP_REST_Response {
        $week = $this->week_key();
        $totals = $this->community_totals();
        return new WP_REST_Response([
            'week' => $week,
            'totals' => $totals,
            'claimed' => is_user_logged_in() ? $this->claimed(get_current_user_id(), $week) : [],
            'rewards' => $this->rewards(),
            'explorers' => $totals['explorers'],
            'cached' => !empty($totals['_cached']),
            'source' => 'gameplay_events',
        ], 200);
    }

    public function claim_reward(WP_REST_Request $request): WP_REST_Response {
        $key = sanitize_key((string)$request->get_param('key'));
        $rewards = $this->rewards();
        if (!isset($rewards[$key])) return new WP_REST_Response(['message' => 'Unknown reward.'], 400);
        $totals = $this->community_totals();
        $reward = $rewards[$key];
        if (($totals[$reward['metric']] ?? 0) < $reward['target']) return new WP_REST_Response(['message' => 'Community goal not reached yet.'], 409);
        $user_id = get_current_user_id();
        $week = $this->week_key();
        $claimed = $this->claimed($user_id, $week);
        if (in_array($key, $claimed, true)) return new WP_REST_Response(['message' => 'Already claimed.'], 409);
        $claimed[] = $key;
        update_user_meta($user_id, self::CLAIM_META, ['week' => $week, 'claimed' => array_values(array_unique($claimed))]);
        $wallet = get_user_meta($user_id, self::WALLET_META, true);
        if (!is_array($wallet)) $wallet = [];
        $wallet['tokens'] = absint($wallet['tokens'] ?? 0) + absint($reward['tokens']);
        update_user_meta($user_id, self::WALLET_META, $wallet);
        return new WP_REST_Response(['claimed' => array_values(array_unique($claimed)), 'tokensAwarded' => absint($reward['tokens']), 'walletTokens' => absint($wallet['tokens'])], 200);
    }

    public static function clear_cache(): void {
        $now = current_time('timestamp', true);
        $day = (int)gmdate('N', $now);
        $start = strtotime('-' . ($day - 1) . ' days midnight', $now);
        delete_transient(self::CACHE_KEY . '_' . gmdate('o-\WW', $start));
    }

    private function rewards(): array {
        return [
            'checkpoint_rally' => ['title' => 'Checkpoint Rally', 'metric' => 'checkpoints', 'target' => 25, 'tokens' => 20, 'icon' => '◆'],
            'quest_wave' => ['title' => 'Quest Wave', 'metric' => 'quests', 'target' => 10, 'tokens' => 35, 'icon' => '★'],
            'xp_summit' => ['title' => 'XP Summit', 'metric' => 'xp', 'target' => 2500, 'tokens' => 60, 'icon' => '✦'],
        ];
    }

    private function cache_seconds(): int {
        $settings = wp_parse_args((array)get_option('tng_gameplay_settings', []), ['community_cache_seconds' => 60]);
        return max(15, min(3600, absint($settings['community_cache_seconds'] ?? 60)));
    }

    private function community_totals(): array {
        $cache_key = self::CACHE_KEY . '_' . $this->week_key();
        $cached = get_transient($cache_key);
        if (is_array($cached)) { $cached['_cached'] = true; return $cached; }
        $since = gmdate('Y-m-d H:i:s', $this->week_start_timestamp());
        $baseline = get_option(self::DEV_BASELINE_OPTION, []);
        if (is_array($baseline) && ($baseline['week'] ?? '') === $this->week_key() && !empty($baseline['resetAt'])) {
            $reset = strtotime((string)$baseline['resetAt']);
            if ($reset && $reset > strtotime($since)) $since = gmdate('Y-m-d H:i:s', $reset);
        }
        $totals = $this->events ? $this->events->community_totals($since) : ['checkpoints' => 0, 'quests' => 0, 'xp' => 0, 'explorers' => 0];
        $totals['since'] = $since;
        set_transient($cache_key, $totals, $this->cache_seconds());
        $totals['_cached'] = false;
        return $totals;
    }

    private function claimed(int $user_id, string $week): array {
        $state = get_user_meta($user_id, self::CLAIM_META, true);
        if (!is_array($state) || ($state['week'] ?? '') !== $week) return [];
        return array_values(array_unique(array_filter(array_map('sanitize_key', (array)($state['claimed'] ?? [])))));
    }

    private function week_key(): string { return gmdate('o-\WW', $this->week_start_timestamp()); }
    private function week_start_timestamp(): int {
        $now = current_time('timestamp', true);
        $day = (int)gmdate('N', $now);
        return strtotime('-' . ($day - 1) . ' days midnight', $now);
    }
}
