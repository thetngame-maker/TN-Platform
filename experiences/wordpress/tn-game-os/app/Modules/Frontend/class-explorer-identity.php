<?php
namespace TNG_OS\Modules\Frontend;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) exit;

final class Explorer_Identity implements Module_Interface {
    private const REST_NS = 'tng-game/v1';
    private const META_KEY = '_tng_explorer_profile';

    public function id(): string { return 'explorer_identity'; }

    public function register(Container $container): void {
        $container->set('explorer_identity', $this);
        add_action('wp_enqueue_scripts', [$this, 'enqueue']);
        add_action('rest_api_init', [$this, 'register_rest']);
    }

    public function boot(Container $container): void {}

    public function enqueue(): void {
        if (is_admin() || !isset($_GET['tng_quest_runtime_id'])) return;
        wp_enqueue_style('tng-explorer-identity', TNG_OS_URL . 'assets/frontend/explorer-identity.css', [], TNG_OS_VERSION);
        wp_enqueue_style('tng-explorer-share-card', TNG_OS_URL . 'assets/frontend/explorer-share-card.css', ['tng-explorer-identity'], TNG_OS_VERSION);
        wp_enqueue_script('tng-explorer-identity', TNG_OS_URL . 'assets/frontend/explorer-identity.js', [], TNG_OS_VERSION, true);
        wp_enqueue_script('tng-explorer-share-card', TNG_OS_URL . 'assets/frontend/explorer-share-card.js', ['tng-explorer-identity'], TNG_OS_VERSION, true);

        $user = wp_get_current_user();
        wp_localize_script('tng-explorer-identity', 'TNGExplorerIdentity', [
            'loggedIn' => is_user_logged_in(),
            'displayName' => is_user_logged_in() ? $user->display_name : 'Explorer',
            'avatarUrl' => is_user_logged_in() ? get_avatar_url($user->ID, ['size' => 160]) : '',
            'profileUrl' => rest_url(self::REST_NS . '/explorer-profile'),
            'restNonce' => is_user_logged_in() ? wp_create_nonce('wp_rest') : '',
            'initialProfile' => is_user_logged_in() ? $this->profile($user->ID) : null,
        ]);
    }

    public function register_rest(): void {
        register_rest_route(self::REST_NS, '/explorer-profile', [
            ['methods' => 'GET', 'callback' => [$this, 'get_profile'], 'permission_callback' => static fn(): bool => is_user_logged_in()],
            ['methods' => 'POST', 'callback' => [$this, 'save_profile'], 'permission_callback' => static fn(): bool => is_user_logged_in()],
        ]);
    }

    public function get_profile(): WP_REST_Response {
        return new WP_REST_Response($this->profile(get_current_user_id()), 200);
    }

    public function save_profile(WP_REST_Request $request): WP_REST_Response {
        $current = $this->profile(get_current_user_id());
        $incoming = (array)$request->get_json_params();
        $profile = [
            'totalXp' => max((int)$current['totalXp'], absint($incoming['totalXp'] ?? 0)),
            'completedCheckpoints' => $this->clean_keys($incoming['completedCheckpoints'] ?? $current['completedCheckpoints']),
            'completedQuests' => $this->clean_keys($incoming['completedQuests'] ?? $current['completedQuests']),
            'collections' => $this->clean_counts($incoming['collections'] ?? $current['collections']),
            'badges' => $this->clean_keys($incoming['badges'] ?? $current['badges']),
            'activityDays' => $this->clean_days($incoming['activityDays'] ?? $current['activityDays']),
            'recentActivity' => $this->clean_activity($incoming['recentActivity'] ?? $current['recentActivity']),
            'selectedTitle' => sanitize_key((string)($incoming['selectedTitle'] ?? $current['selectedTitle'] ?? 'explorer')),
            'featuredBadge' => sanitize_key((string)($incoming['featuredBadge'] ?? $current['featuredBadge'] ?? '')),
            'updatedAt' => current_time('mysql', true),
        ];
        update_user_meta(get_current_user_id(), self::META_KEY, $profile);
        return new WP_REST_Response($profile, 200);
    }

    private function profile(int $user_id): array {
        $profile = get_user_meta($user_id, self::META_KEY, true);
        if (!is_array($profile)) $profile = [];
        return [
            'totalXp' => absint($profile['totalXp'] ?? 0),
            'completedCheckpoints' => $this->clean_keys($profile['completedCheckpoints'] ?? []),
            'completedQuests' => $this->clean_keys($profile['completedQuests'] ?? []),
            'collections' => $this->clean_counts($profile['collections'] ?? []),
            'badges' => $this->clean_keys($profile['badges'] ?? []),
            'activityDays' => $this->clean_days($profile['activityDays'] ?? []),
            'recentActivity' => $this->clean_activity($profile['recentActivity'] ?? []),
            'selectedTitle' => sanitize_key((string)($profile['selectedTitle'] ?? 'explorer')),
            'featuredBadge' => sanitize_key((string)($profile['featuredBadge'] ?? '')),
        ];
    }

    private function clean_keys($values): array {
        return array_values(array_unique(array_filter(array_map(static fn($value): string => sanitize_text_field((string)$value), (array)$values))));
    }

    private function clean_counts($values): array {
        $clean = [];
        foreach ((array)$values as $key => $value) {
            $safe = sanitize_key((string)$key);
            if ($safe !== '') $clean[$safe] = absint($value);
        }
        return $clean;
    }

    private function clean_days($values): array {
        $days = [];
        foreach ((array)$values as $value) {
            $day = sanitize_text_field((string)$value);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) $days[] = $day;
        }
        rsort($days);
        return array_slice(array_values(array_unique($days)), 0, 365);
    }

    private function clean_activity($values): array {
        $clean = [];
        foreach ((array)$values as $value) {
            if (!is_array($value)) continue;
            $id = sanitize_text_field((string)($value['id'] ?? ''));
            if ($id === '') continue;
            $clean[$id] = [
                'id' => $id,
                'kind' => sanitize_key((string)($value['kind'] ?? 'checkpoint')),
                'title' => sanitize_text_field((string)($value['title'] ?? 'Adventure activity')),
                'subtitle' => sanitize_text_field((string)($value['subtitle'] ?? '')),
                'xp' => absint($value['xp'] ?? 0),
                'date' => sanitize_text_field((string)($value['date'] ?? '')),
            ];
        }
        $clean = array_values($clean);
        usort($clean, static fn(array $a, array $b): int => strcmp((string)$b['date'], (string)$a['date']));
        return array_slice($clean, 0, 40);
    }
}
